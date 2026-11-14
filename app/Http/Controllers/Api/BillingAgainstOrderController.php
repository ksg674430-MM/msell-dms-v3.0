<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockService;
use App\Support\GstState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BillingAgainstOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->context($request)) return $this->denied();

        $from = $this->parseDate($request->input('from_date')) ?: date('Y-m-d');
        $to   = $this->parseDate($request->input('to_date')) ?: date('Y-m-d');
        if ($from > $to) {
            return response()->json(['status' => false, 'message' => 'From Date cannot be after To Date.'], 422);
        }

        $orders = DB::select(
            "SELECT o.order_id, o.order_date, o.dealer_id, o.order_remark, d.name AS firm_name
               FROM demand_order o
               JOIN dealer d ON d.id = o.dealer_id
              WHERE o.company_id = ? AND o.order_date >= ? AND o.order_date < ?
                AND o.status <> 2 AND d.csa_id = ? AND d.id <> ?
              ORDER BY o.order_date DESC, o.id DESC",
            [$ctx['company_id'], $from, date('Y-m-d', strtotime($to . ' +1 day')), $ctx['csa_id'], $ctx['dealer_id']]
        );
        if (!$orders) return response()->json(['status' => true, 'rows' => []]);

        $orderIds = array_map(fn ($o) => (string) $o->order_id, $orders);
        $sums = collect(DB::select(
            "SELECT order_id, COUNT(*) AS items, SUM(total_rs) AS taxable, SUM(t1_rate) AS discount
               FROM demand_order_details
              WHERE order_id IN (" . implode(',', array_fill(0, count($orderIds), '?')) . ")
              GROUP BY order_id",
            $orderIds
        ))->keyBy('order_id');

        $dealerIds = array_values(array_unique(array_map(fn ($o) => (int) $o->dealer_id, $orders)));
        $outstanding = DB::table('challan_order')
            ->where('company_id', $ctx['company_id'])
            ->where('ch_dealer_id', $ctx['dealer_id'])
            ->whereIn('ch_created_for_dealer_id', $dealerIds)
            ->where('payment_status', '!=', 1)
            ->groupBy('ch_created_for_dealer_id')
            ->pluck(DB::raw('SUM(remaining)'), 'ch_created_for_dealer_id');

        $rows = [];
        foreach ($orders as $o) {
            $s = $sums[(string) $o->order_id] ?? null;
            if (!$s) continue;
            $taxable  = round((float) $s->taxable, 2);
            $discount = round((float) $s->discount, 2);
            $rows[] = [
                'order_id'    => (string) $o->order_id,
                'order_date'  => $o->order_date,
                'dealer_id'   => (int) $o->dealer_id,
                'firm_name'   => $o->firm_name,
                'remarks'     => (string) $o->order_remark,
                'outstanding' => round((float) ($outstanding[$o->dealer_id] ?? 0), 2),
                'items'       => (int) $s->items,
                'taxable'     => $taxable,
                'discount'    => $discount,
                'amount'      => round($taxable - $discount, 2),
            ];
        }

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        if (!$ctx = $this->context($request)) return $this->denied();

        $order = $this->findOrder($ctx, $orderId);
        if (!$order) return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
        if ((int) $order->status === 2) {
            return response()->json(['status' => false, 'message' => 'This order is already billed or cancelled.'], 409);
        }

        $lines = DB::select(
            "SELECT dd.id AS line_id, dd.product_id, dd.rate, dd.mrp, dd.quantity, dd.free_qty, dd.total_rs, dd.remarks,
                    cp.name AS product_name, cp.hsn_code, cp.gst_percent
               FROM demand_order_details dd
               LEFT JOIN catalog_product cp ON cp.id = dd.product_id
              WHERE dd.order_id = ?
              ORDER BY dd.id",
            [(string) $order->order_id]
        );

        $productIds = array_values(array_unique(array_map(fn ($l) => (int) $l->product_id, $lines)));
        $batches = $productIds ? DB::select(
            "SELECT product_id, batch_no, IF(mfg < '1971-01-01', NULL, DATE_FORMAT(mfg, '%Y-%m-%d')) AS mfg_date, mrp, qty
               FROM stock
              WHERE dealer_id = ? AND product_id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ") AND qty > 0
              ORDER BY product_id, mfg, id",
            array_merge([$ctx['dealer_id']], $productIds)
        ) : [];

        return response()->json([
            'status' => true,
            'header' => [
                'order_id'   => (string) $order->order_id,
                'order_date' => $order->order_date,
                'dealer_id'  => (int) $order->dealer_id,
                'firm_name'  => $order->firm_name,
                'remarks'    => (string) $order->order_remark,
                'same_state' => $this->sameState($ctx, $order),
                'ch_no'      => ChallanController::generateChNo($ctx['dp_id'], $ctx['dealer_id'], $ctx['company_id']),
            ],
            'items'   => array_map(fn ($l) => [
                'line_id'      => (int) $l->line_id,
                'product_id'   => (int) $l->product_id,
                'product_name' => $l->product_name ?? ('Product #' . $l->product_id),
                'hsn_code'     => (string) $l->hsn_code,
                'gst'          => (float) $l->gst_percent,
                'rate'         => (float) $l->rate,
                'mrp'          => (float) $l->mrp,
                'qty'          => (float) $l->quantity,
                'free_qty'     => (float) $l->free_qty,
                'remarks'      => (string) $l->remarks,
            ], $lines),
            'batches' => array_map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'batch_no'   => (string) $b->batch_no,
                'mfg_date'   => $b->mfg_date,
                'mrp'        => (float) $b->mrp,
                'qty'        => (float) $b->qty,
            ], $batches),
        ]);
    }

    public function bill(Request $request, string $orderId): JsonResponse
    {
        if (!$ctx = $this->context($request)) return $this->denied();

        $v = Validator::make($request->all(), [
            'ch_date'            => ['required', 'string'],
            'remark'             => ['nullable', 'string', 'max:255'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.line_id'    => ['required', 'integer'],
            'items.*.qty'        => ['required', 'numeric', 'min:0'],
            'items.*.free_qty'   => ['nullable', 'numeric', 'min:0'],
            'items.*.batch_no'   => ['required', 'string', 'max:50'],
            'items.*.mfg_date'   => ['nullable', 'string'],
            'items.*.mrp'        => ['required', 'numeric', 'min:0'],
            'items.*.td'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cd'         => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'items.*.batch_no.required' => 'Select a batch for every item.',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }

        $chDate = $this->parseDate($request->input('ch_date'));
        if (!$chDate) return response()->json(['status' => false, 'message' => 'Invalid invoice date.'], 422);

        $items = array_values(array_filter($request->input('items'), fn ($it) => (float) $it['qty'] + (float) ($it['free_qty'] ?? 0) > 0));
        if (!$items) return response()->json(['status' => false, 'message' => 'Enter a quantity for at least one item.'], 422);

        DB::beginTransaction();
        try {
            $order = $this->findOrder($ctx, $orderId, true);
            if (!$order) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
            }
            $chId = (int) $order->order_id;
            if ((int) $order->status === 2 || DB::selectOne("SELECT 1 FROM challan_order WHERE id = ? LIMIT 1", [$chId])) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'This order is already billed or cancelled.'], 409);
            }

            $orderLines = collect(DB::select(
                "SELECT dd.id, dd.product_id, dd.rate, cp.name, cp.hsn_code, cp.gst_percent, cp.itemcode
                   FROM demand_order_details dd
                   LEFT JOIN catalog_product cp ON cp.id = dd.product_id
                  WHERE dd.order_id = ?",
                [(string) $order->order_id]
            ))->keyBy('id');

            $same  = $this->sameState($ctx, $order);
            $lines = [];
            foreach ($items as $it) {
                $ol = $orderLines[(int) $it['line_id']] ?? null;
                if (!$ol) throw new \RuntimeException('An item does not belong to this order.');

                $qty     = (float) $it['qty'];
                $free    = (float) ($it['free_qty'] ?? 0);
                $rate    = (float) $ol->rate;
                $gst     = (float) $ol->gst_percent;
                $td      = (float) ($it['td'] ?? 0);
                $cd      = (float) ($it['cd'] ?? 0);
                $gross   = round($qty * $rate, 2);
                $tdAmt   = round($gross * $td / 100, 2);
                $cdAmt   = round($gross * $cd / 100, 2);
                $taxable = round($gross - $tdAmt - $cdAmt, 2);
                $vat     = GstState::tax($taxable, $gst, $same);
                $mfg     = $this->parseDate($it['mfg_date'] ?? null);

                $lines[] = [
                    'product_id' => (int) $ol->product_id,
                    'name'       => (string) ($ol->name ?? ''),
                    'hsn'        => (string) ($ol->hsn_code ?? ''),
                    'itemcode'   => (string) ($ol->itemcode ?? ''),
                    'qty'        => $qty,
                    'free_qty'   => $free,
                    'rate'       => $rate,
                    'mrp'        => (float) $it['mrp'],
                    'batch_no'   => (string) $it['batch_no'],
                    'mfg_date'   => $mfg && $mfg > '1971-01-01' ? $mfg : null,
                    'gst'        => $gst,
                    'td'         => $td,
                    'td_amt'     => $tdAmt,
                    'cd'         => $cd,
                    'cd_amt'     => $cdAmt,
                    'gross'      => $gross,
                    'taxable'    => $taxable,
                    'vat'        => $vat,
                    'amount'     => round($taxable + $vat, 2),
                ];
            }

            $total = round(array_sum(array_column($lines, 'amount')), 2);
            $chNo  = ChallanController::generateChNo($ctx['dp_id'], $ctx['dealer_id'], $ctx['company_id']);
            $sesId = (int) (DB::selectOne("SELECT sesId FROM session_year ORDER BY sesId DESC LIMIT 1")->sesId ?? 0);

            DB::insert(
                "INSERT INTO challan_order
                    (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id, ch_created_for_dealer_id, ch_user_id,
                     ch_date, date_added, company_id, sesId, invoice_type, remark, book_status, crdr_status,
                     dispatch_status, payment_status, sync_status, isclaim, istarget_claim,
                     discount_per, discount_amt, amount, remaining, amount_round)
                 VALUES (?, ?, ?, ?, '0', ?, 0, ?, NOW(), ?, ?, 2, ?, 0, 0, 0, 0, 1, 0, 0, 0, 0, ?, ?, ?)",
                [
                    $chId, $chNo, $ctx['dp_id'], $ctx['dealer_id'], (int) $order->dealer_id,
                    $chDate, $ctx['company_id'], $sesId, (string) $request->input('remark', ''),
                    $total, $total, round($total),
                ]
            );

            foreach ($lines as $l) {
                DB::insert(
                    "INSERT INTO challan_order_details
                        (ch_id, product_id, hsn_code, catalog_details_id, batch_no, mfg_date, tax, vat_amt, qty, product_rate,
                         free_qty, order_id, user_id, mrp, cd, cd_type, cd_amt, spl_disc_type, spl_disc_val, spl_disc_amt, cash_amt,
                         dis_type, dis_amt, dis_percent, taxable_amt, remain_amount, sale_order_id, sync_status)
                     VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 1, ?, 0, 0, 0, 0, 1, ?, ?, ?, ?, '', 1)",
                    [
                        $chId, $l['product_id'], $l['hsn'], $l['batch_no'], $l['mfg_date'] ?? '1970-01-01', $l['gst'], $l['vat'],
                        $l['qty'], $l['rate'], $l['free_qty'], (string) $order->order_id, $l['mrp'], $l['cd'], $l['cd_amt'],
                        $l['td_amt'], $l['td'], $l['amount'], $l['amount'],
                    ]
                );
            }

            app(StockService::class)->sync($ctx['dealer_id'], [], array_map(fn ($l) => [
                'product_id' => $l['product_id'],
                'batch_no'   => $l['batch_no'],
                'mfg_date'   => $l['mfg_date'],
                'mrp'        => $l['mrp'],
                'qty'        => $l['qty'] + $l['free_qty'],
                'name'       => $l['name'],
            ], $lines));

            $this->writePurchaseOrder($ctx, $order, $lines, $chNo, $chDate);

            DB::update(
                "UPDATE demand_order SET status = 2, challan_no = ?, ch_date = ? WHERE id = ?",
                [$chNo, $chDate, (int) $order->id]
            );

            DB::commit();

            return response()->json([
                'status'     => true,
                'message'    => "Invoice {$chNo} saved for {$order->firm_name}.",
                'challan_id' => (string) $chId,
                'ch_no'      => $chNo,
                'amount'     => $total,
            ], 201);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => safe_error_message($e)], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Invoice could not be saved: ' . safe_error_message($e)], 500);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        if (!$ctx = $this->context($request)) return $this->denied();

        $request->validate([
            'orders'            => ['required', 'array', 'min:1'],
            'orders.*.order_id' => ['required', 'string'],
            'orders.*.reason'   => ['required', 'integer', 'min:1'],
            'orders.*.remark'   => ['nullable', 'string', 'max:250'],
        ], [
            'orders.*.reason.required' => 'Select a Reason For Cancellation for every order.',
        ]);

        try {
            $count = DB::transaction(function () use ($request, $ctx) {
                $count = 0;
                foreach ($request->input('orders') as $o) {
                    $order = $this->findOrder($ctx, (string) $o['order_id'], true);
                    if (!$order) throw new \RuntimeException("Order {$o['order_id']} not found.");
                    if ((int) $order->status === 2) throw new \RuntimeException("Order {$o['order_id']} is already billed or cancelled.");

                    DB::update("UPDATE demand_order SET status = 2 WHERE id = ?", [(int) $order->id]);
                    DB::update(
                        "UPDATE purchase_order
                            SET cancel_order_reason_id = ?, against_order_reasons = ?, against_order_remarks = ?
                          WHERE id = ? AND dealer_id = ?",
                        [(int) $o['reason'], (string) $o['reason'], mb_substr(trim((string) ($o['remark'] ?? '')), 0, 250), (string) $order->order_id, (int) $order->dealer_id]
                    );
                    $count++;
                }
                return $count;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['status' => false, 'message' => safe_error_message($e)], 422);
        }

        return response()->json(['status' => true, 'message' => $count === 1 ? 'Order cancelled.' : "{$count} orders cancelled."]);
    }

    private function writePurchaseOrder(array $ctx, object $order, array $lines, string $chNo, string $chDate): void
    {
        $poId = (string) $order->order_id;
        $po = DB::selectOne(
            "SELECT auto FROM purchase_order WHERE id = ? AND dealer_id = ? LIMIT 1 FOR UPDATE",
            [$poId, (int) $order->dealer_id]
        );

        if ($po) {
            DB::update(
                "UPDATE purchase_order SET challan_no = ?, ch_date = ?, csa_id = ?, firm_name = ? WHERE auto = ?",
                [$chNo, $chDate, $ctx['csa_id'], $ctx['dealer_name'], (int) $po->auto]
            );
        } else {
            DB::table('purchase_order')->insert([
                'id' => $poId, 'order_id' => $poId, 'dealer_id' => (int) $order->dealer_id,
                'created_date' => now(), 'created_person_id' => $ctx['dealer_id'],
                'sale_date' => $order->order_date, 'order_date' => $order->order_date, 'receive_date' => null,
                'date_time' => now(), 'company_id' => $ctx['company_id'],
                'challan_no' => $chNo, 'ch_date' => $chDate, 'csa_id' => $ctx['csa_id'], 'firm_name' => $ctx['dealer_name'],
            ]);
        }

        $igst = !$this->sameState($ctx, $order);
        DB::delete("DELETE FROM purchase_order_details WHERE order_id = ?", [$poId]);
        DB::table('purchase_order_details')->insert(array_map(fn ($l) => [
            'id' => $poId, 'order_id' => $poId, 'company_id' => $ctx['company_id'], 'product_id' => $l['product_id'],
            'item_code' => mb_substr($l['itemcode'], 0, 10), 'rate' => $l['rate'], 'pr_rate' => $l['rate'],
            'quantity' => (int) round($l['qty']), 'scheme_qty' => (int) round($l['free_qty']), 'mrp' => $l['mrp'],
            'batch_no' => $l['batch_no'], 'mfg_date' => $l['mfg_date'], 'cases' => 0, 'pcs' => 0,
            'gross_amt' => $l['gross'], 'td_amount' => $l['td_amt'], 'cd_amount' => $l['cd_amt'],
            'sch_amt' => 0, 'spl_amt' => 0, 'atd_amt' => 0, 'taxable_amount' => $l['taxable'],
            'cgst_amount' => $igst ? 0 : round($l['vat'] / 2, 2),
            'sgst_amount' => $igst ? 0 : round($l['vat'] / 2, 2),
            'igst_amount' => $igst ? $l['vat'] : 0,
            'gst_percentage' => $l['gst'], 'total_amount' => $l['amount'],
        ], $lines));
    }

    private function sameState(array $ctx, object $order): bool
    {
        if ($ctx['company_id'] === 213) return true;
        return (int) $ctx['state_id'] > 0 && (int) $ctx['state_id'] === (int) $order->state_id;
    }

    private function findOrder(array $ctx, string $orderId, bool $lock = false): ?object
    {
        return DB::selectOne(
            "SELECT o.id, o.order_id, o.order_date, o.dealer_id, o.order_remark, o.status, d.name AS firm_name, d.state_id
               FROM demand_order o
               JOIN dealer d ON d.id = o.dealer_id
              WHERE o.order_id = ? AND o.company_id = ? AND d.csa_id = ? AND d.id <> ?
              LIMIT 1" . ($lock ? ' FOR UPDATE' : ''),
            [$orderId, $ctx['company_id'], $ctx['csa_id'], $ctx['dealer_id']]
        );
    }

    private function context(Request $request): ?array
    {
        $user = $request->user();
        $role = trim((string) DB::table('_role')->where('role_id', $user->role_id)->value('rolename'));
        if (!in_array($role, ['DEPO', 'Depo'], true) || (int) $user->csa_id <= 0) return null;

        $dealer = DB::selectOne("SELECT name, state_id FROM dealer WHERE id = ? LIMIT 1", [(int) $user->dealer_id]);

        return [
            'dp_id'       => (int) $user->getKey(),
            'dealer_id'   => (int) $user->dealer_id,
            'company_id'  => (int) $user->company_id,
            'csa_id'      => (int) $user->csa_id,
            'state_id'    => (int) ($dealer->state_id ?? 0),
            'dealer_name' => (string) ($dealer->name ?? ''),
        ];
    }

    private function denied(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Billing Against Order is only for super stockist logins.'], 403);
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) return null;
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) return $dt->format('Y-m-d');
        }
        return null;
    }
}
