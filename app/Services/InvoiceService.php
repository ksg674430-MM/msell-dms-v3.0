<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function reason(string $invoiceId, int $dealerId): ?string
    {
        $inv = DB::selectOne(
            "SELECT id, dispatch_status, payment_status, isclaim
               FROM challan_order
              WHERE id = ? AND ch_dealer_id = ?
              LIMIT 1",
            [$invoiceId, $dealerId]
        );
        if (!$inv) return 'Invoice not found.';

        if ((int) $inv->dispatch_status === 3) return 'Invoice already cancelled.';
        if ((int) $inv->dispatch_status === 1) return 'Invoice already dispatched.';
        if (in_array((int) $inv->payment_status, [1, 2], true)) return 'Payment already recorded against this invoice.';
        if ((int) $inv->isclaim === 1) return 'Claim already filed against this invoice.';

        if (DB::selectOne("SELECT id FROM einvoice_api_logs WHERE challan_id = ? AND status = 1 LIMIT 1", [(string) $inv->id])) {
            return 'E-invoice (IRN) already generated for this invoice.';
        }

        if (DB::selectOne(
            "SELECT id FROM dms_retailer_images_amount_claim WHERE order_id = ? AND dealer_id = ? LIMIT 1",
            [(string) $inv->id, $dealerId]
        )) {
            return 'Claim exists for this invoice.';
        }

        return null;
    }

    public function outstanding(int $dealerId, array $retailerIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $retailerIds)));
        if (!$ids) return [];

        $invoices = DB::table('challan_order')
            ->where('ch_dealer_id', $dealerId)
            ->where('payment_status', '!=', '1')
            ->whereIn('ch_retailer_id', $ids)
            ->groupBy('ch_retailer_id')
            ->pluck(DB::raw('SUM(remaining)'), 'ch_retailer_id');

        $oldDues = DB::table('old_payment_dues')
            ->where('ch_dealer_id', $dealerId)
            ->where('payment_status', '0')
            ->whereIn('ch_retailer_id', $ids)
            ->groupBy('ch_retailer_id')
            ->pluck(DB::raw('SUM(remaining)'), 'ch_retailer_id');

        $map = [];
        foreach ($ids as $id) {
            $map[$id] = round((float) ($invoices[$id] ?? 0) + (float) ($oldDues[$id] ?? 0), 2);
        }
        return $map;
    }

    public static function parseCreditLimit($value): ?float
    {
        $v = trim((string) $value);
        return is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null;
    }

    public function creditLimits(array $retailerIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $retailerIds)));
        if (!$ids) return [];

        return DB::table('retailer')
            ->whereIn('id', $ids)
            ->pluck('credit_time_limit', 'id')
            ->map(fn ($v) => self::parseCreditLimit($v))
            ->all();
    }

    public function creditBlock(int $dealerId, string $retailerId): ?string
    {
        $limit = $this->creditLimits([$retailerId])[$retailerId] ?? null;
        if ($limit === null) return null;

        $outstanding = $this->outstanding($dealerId, [$retailerId])[$retailerId] ?? 0.0;
        if ($outstanding <= $limit) return null;

        return sprintf(
            'Credit limit exceeded: outstanding balance Rs. %s is more than the credit limit Rs. %s. Collect payment or raise the credit limit before making an invoice.',
            number_format($outstanding, 2),
            number_format($limit, 2)
        );
    }

    public function fulfilSalesOrder(int $dealerId, string $orderId, array $invoicedQty): void
    {
        $order = DB::selectOne(
            "SELECT order_status FROM user_sales_order WHERE order_id = ? AND dealer_id = ? LIMIT 1 FOR UPDATE",
            [$orderId, $dealerId]
        );
        if (!$order || !in_array((string) $order->order_status, ['0', '7'], true)) {
            throw new \RuntimeException("Order {$orderId} is not open for invoicing.");
        }

        foreach ($invoicedQty as $productId => $qty) {
            $line = DB::selectOne(
                "SELECT d.id, d.quantity, d.remaining_qty, d.status, p.name
                   FROM user_sales_order_details d
                   LEFT JOIN catalog_product p ON p.id = d.product_id
                  WHERE d.order_id = ? AND d.product_id = ?
                  ORDER BY d.status = 2, d.id
                  LIMIT 1",
                [$orderId, $productId]
            );
            if (!$line) {
                continue;
            }
            $open = (int) $line->status === 2 ? 0.0 : ((float) $line->remaining_qty > 0 ? (float) $line->remaining_qty : (float) $line->quantity);
            if ((float) $qty > $open + 0.0000001) {
                throw new \RuntimeException(sprintf(
                    'Order %s has only %s open for "%s", but %s is being invoiced.',
                    $orderId,
                    rtrim(rtrim(number_format($open, 3, '.', ''), '0'), '.'),
                    $line->name ?? $productId,
                    rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.')
                ));
            }
            $left = $open - (float) $qty;
            if ($left > 0.0000001) {
                DB::update("UPDATE user_sales_order_details SET remaining_qty = ? WHERE id = ?", [$left, $line->id]);
            } else {
                DB::update("UPDATE user_sales_order_details SET status = 2, remaining_qty = 0 WHERE id = ?", [$line->id]);
            }
        }

        $this->refreshOrderStatus($orderId, $dealerId);
    }

    public function reopenSalesOrders(array $lines, int $dealerId): void
    {
        $byOrder = [];
        foreach ($lines as $l) {
            $l = (array) $l;
            $orderId = trim((string) ($l['order_id'] ?? ''));
            if ($orderId === '') continue;
            $byOrder[$orderId][(int) $l['product_id']] = ($byOrder[$orderId][(int) $l['product_id']] ?? 0) + (float) $l['qty'];
        }

        foreach ($byOrder as $orderId => $products) {
            $order = DB::selectOne("SELECT order_status FROM user_sales_order WHERE order_id = ? AND dealer_id = ? LIMIT 1 FOR UPDATE", [$orderId, $dealerId]);
            if (!$order || !in_array((string) $order->order_status, ['0', '1', '7'], true)) continue;

            foreach ($products as $productId => $qty) {
                $line = DB::selectOne(
                    "SELECT id, quantity, remaining_qty, status FROM user_sales_order_details
                      WHERE order_id = ? AND product_id = ?
                      ORDER BY id LIMIT 1",
                    [$orderId, $productId]
                );
                if (!$line) continue;
                $open = (int) $line->status === 2 ? 0.0 : ((float) $line->remaining_qty > 0 ? (float) $line->remaining_qty : (float) $line->quantity);
                $open = min((float) $line->quantity, $open + $qty);
                $remaining = $open >= (float) $line->quantity - 0.0000001 ? 0 : $open;
                DB::update("UPDATE user_sales_order_details SET status = 0, remaining_qty = ? WHERE id = ?", [$remaining, $line->id]);
            }

            $this->refreshOrderStatus($orderId, $dealerId);
        }
    }

    protected function refreshOrderStatus(string $orderId, int $dealerId): void
    {
        $row = DB::selectOne(
            "SELECT SUM(status = 2) AS done, SUM(status <> 2 AND remaining_qty > 0) AS partial, COUNT(*) AS n
               FROM user_sales_order_details WHERE order_id = ?",
            [$orderId]
        );
        $status = (int) $row->done === (int) $row->n ? '1' : ((int) $row->done > 0 || (int) $row->partial > 0 ? '7' : '0');
        DB::update("UPDATE user_sales_order SET order_status = ? WHERE order_id = ? AND dealer_id = ?", [$status, $orderId, $dealerId]);
    }
}
