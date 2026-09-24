<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * GET /api/invoices
     * Rich invoice list with per-row claim preview + item detail.
     */
//     use App\Support\FilterBuilder;

// public function query(Request $request): JsonResponse
// {
//     $user = $request->user();

//     $fb = FilterBuilder::make('purchase_order', $request)      // ← change key
//         ->hardScope('po.dealer_id = ?', [(int) $user->dealer_id]);

//     if ((int) $user->company_id > 0) {
//         $fb->hardScope('po.company_id = ?', [(int) $user->company_id]);
//     }

//     if (!$fb->isValid()) {
//         return response()->json([
//             'status'  => false,
//             'message' => 'Invalid filter.',
//             'errors'  => $fb->errors(),
//         ], 422);
//     }

//     $result = $fb->run();

//     return response()->json([
//         'status' => true,
//         'data'   => $result['rows'],
//         'meta'   => $result['meta'],
//     ]);
// }

private function tableExists(string $table): bool
{
    $row = DB::selectOne(
        "SELECT COUNT(*) AS c
           FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name   = ?",
        [$table]
    );

    return ((int) ($row->c ?? 0)) > 0;
}
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);
        $stateId   = (int) ($user->state_id ?? 0);

        $from = $request->input('from_date', date('Y-m-d'));
        $to   = $request->input('to_date',   date('Y-m-d'));

        $where    = [
            "co.ch_dealer_id = ?",
            "DATE_FORMAT(co.ch_date,'%Y-%m-%d') >= ?",
            "DATE_FORMAT(co.ch_date,'%Y-%m-%d') <= ?",
        ];
        $bindings = [$dealerId, $from, $to];

        if ($retailerId = $request->input('retailer_id')) {
            $where[]    = "co.ch_retailer_id = ?";
            $bindings[] = $retailerId;
        }
        if ($userId = $request->input('user_id')) {
            $where[]    = "co.ch_user_id = ?";
            $bindings[] = (int) $userId;
        }
        if ($chNo = $request->input('ch_no')) {
            $where[]    = "co.ch_no LIKE ?";
            $bindings[] = "%{$chNo}%";
        }
        if ($locId = $request->input('location_id')) {
            $where[]    = "r.location_id = ?";
            $bindings[] = (int) $locId;
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT co.auto, co.id, co.ch_no, co.ch_date, co.date_added,
                    co.ch_retailer_id, co.ch_user_id, co.ch_dealer_id,
                    co.company_id, co.invoice_type, co.book_status,
                    co.discount_per, co.discount_amt, co.amount, co.amount_round,
                    co.remark, co.remark AS ch_remark,
                    r.name AS retailer_name, r.address AS retailer_address,
                    r.landline, r.other_numbers, r.tin_no, r.class AS retailer_class,
                    r.contact_per_name,
                    d.name AS dealer_name,
                    l7.name AS beat_name,
                    p.first_name, p.middle_name, p.last_name,
                    co.book_status
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN dealer  d ON d.id = co.ch_dealer_id
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
               LEFT JOIN person p ON p.id = co.ch_user_id
              WHERE {$whereSql}
              ORDER BY co.auto DESC",
            $bindings
        );

        $invoiceIds = array_map(fn($r) => (string) $r->id, $rows);

        // Bulk fetch items for all invoices
        $itemsMap = $this->loadItems($invoiceIds, $stateId);

        // Bulk fetch claim statuses
        $claimMap = $this->loadClaimStatus($invoiceIds, $dealerId, $companyId);

        $out = [];
        foreach ($rows as $r) {
            $name = trim(($r->first_name ?? '') . ' ' . ($r->middle_name ?? '') . ' ' . ($r->last_name ?? ''));

            $items    = $itemsMap[$r->id] ?? [];
            $claim    = $claimMap[$r->id] ?? null;
            $itemSum  = array_sum(array_column($items, 'line_total'));
            $disc     = (float) ($r->discount_amt ?? 0);
            $v5       = round(abs($r->amount - ($itemSum - $disc)), 2);

            $out[] = [
                'id'                => $r->id,
                'ch_no'             => $r->ch_no,
                'ch_date'           => $r->ch_date,
                'created_date'      => $r->date_added,
                'retailer_id'       => $r->ch_retailer_id,
                'retailer_name'     => $r->retailer_name,
                'retailer_address'  => $r->retailer_address,
                'retailer_contact'  => $r->landline ?: $r->other_numbers,
                'retailer_gst'      => $r->tin_no,
                'retailer_category' => $r->retailer_class,
                'contact_per_name'  => $r->contact_per_name,
                'dealer_name'       => $r->dealer_name,
                'user_name'         => $name,
                'beat_name'         => $r->beat_name,
                'invoice_type'      => (int) $r->invoice_type,
                'invoice_type_text' => $this->invoiceTypeText($r),
                'book_status'       => (int) $r->book_status,
                'discount_amt'      => $disc,
                'discount_per'      => (float) $r->discount_per,
                'amount'            => (float) $r->amount_round,
                'items'             => $items,
                'claim_exists'      => $claim !== null,
                'claim_status'      => $claim->claim_status ?? 0,
                'claim_id'          => $claim->id ?? null,
            ];
        }

        return response()->json(['status' => true, 'rows' => $out]);
    }

    /**
     * GET /api/invoices/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);
        $stateId   = (int) ($user->state_id ?? 0);

        $header = DB::selectOne(
            "SELECT co.*, r.name AS retailer_name, r.address AS retailer_address,
                    r.landline, r.other_numbers, r.tin_no, r.class AS retailer_class,
                    r.contact_per_name,
                    d.name AS dealer_name,
                    l7.name AS beat_name,
                    p.first_name, p.middle_name, p.last_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN dealer  d ON d.id = co.ch_dealer_id
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
               LEFT JOIN person p ON p.id = co.ch_user_id
              WHERE co.id = ? AND co.ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        if (!$header) {
            return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
        }

        $items = DB::select(
            "SELECT cod.*,
                    cv.product_name, cv.itemcode, cv.hsn_code_view AS hsn_code,
                    cv.qty_per_case, cv.weight, cv.igst AS gst_percent
               FROM challan_order_details cod
               LEFT JOIN catalog_view cv ON cv.product_id = cod.product_id
              WHERE cod.ch_id = ?
              ORDER BY cod.id ASC",
            [$id]
        );

        $claim = DB::selectOne(
            "SELECT * FROM dms_retailer_images_amount_claim
              WHERE order_id = ? AND company_id = ? AND dealer_id = ?
              LIMIT 1",
            [$id, $companyId, $dealerId]
        );

        $irnLog = DB::selectOne(
            "SELECT irn, ackno, ackdate, qr_path, status
               FROM einvoice_api_logs
              WHERE challan_id = ? AND status = 1
              ORDER BY id DESC LIMIT 1",
            [$id]
        );

        return response()->json([
            'status'  => true,
            'header'  => $header,
            'items'   => $items,
            'claim'   => $claim,
            'irn'     => $irnLog,
        ]);
    }

    /* ================= helpers ================= */

    private function loadItems(array $invoiceIds, int $stateId): array
    {
        if (empty($invoiceIds)) return [];

        $ids = [];
        foreach ($invoiceIds as $id) $ids[] = "'" . addslashes($id) . "'";
        $in = implode(',', $ids);

        $rows = DB::select(
            "SELECT cod.ch_id, cod.product_id, cod.qty, cod.product_rate,
                    cod.free_qty, cod.tax, cod.vat_amt, cod.taxable_amt,
                    cod.mrp, cod.old_rate, cod.cd_amt, cod.spl_disc_amt,
                    cod.spl_disc_val, cod.dis_amt, cod.dis_percent,
                    cod.dis_type, cod.cd_type, cod.batch_no, cod.mfg_date,
                    cv.product_name, cv.qty_per_case, cv.weight, cv.itemcode,
                    cv.hsn_code_view AS hsn_code
               FROM challan_order_details cod
               LEFT JOIN catalog_view cv ON cv.product_id = cod.product_id
              WHERE cod.ch_id IN ({$in})
              ORDER BY cod.id ASC"
        );

        $map = [];
        foreach ($rows as $r) {
            $qty   = (float) $r->qty;
            $rate  = (float) $r->product_rate;
            $free  = (float) $r->free_qty;
            $lineTotal = round(($qty * $rate), 2);

            $map[$r->ch_id][] = [
                'product_id'   => (int) $r->product_id,
                'product_name' => $r->product_name,
                'itemcode'     => $r->itemcode,
                'hsn_code'     => $r->hsn_code,
                'qty'          => $qty,
                'free_qty'     => $free,
                'rate'         => $rate,
                'mrp'          => (float) $r->mrp,
                'old_rate'     => (float) $r->old_rate,
                'tax'          => (float) $r->tax,
                'vat_amt'      => (float) $r->vat_amt,
                'taxable_amt'  => (float) $r->taxable_amt,
                'batch_no'     => $r->batch_no,
                'mfg_date'     => $r->mfg_date,
                'cd_amt'       => (float) $r->cd_amt,
                'dis_amt'      => (float) $r->dis_amt,
                'dis_percent'  => (float) $r->dis_percent,
                'weight'       => (float) $r->weight,
                'qty_per_case' => (int) $r->qty_per_case,
                'line_total'   => $lineTotal,
            ];
        }
        return $map;
    }

    private function loadClaimStatus(array $invoiceIds, int $dealerId, int $companyId): array
    {
        if (empty($invoiceIds)) return [];

        $ids = [];
        foreach ($invoiceIds as $id) $ids[] = "'" . addslashes($id) . "'";
        $in = implode(',', $ids);

        $rows = DB::select(
            "SELECT id, order_id, claim_status
               FROM dms_retailer_images_amount_claim
              WHERE order_id IN ({$in}) AND dealer_id = ? AND company_id = ?",
            [$dealerId, $companyId]
        );

        $map = [];
        foreach ($rows as $r) $map[$r->order_id] = $r;
        return $map;
    }

    private function invoiceTypeText(object $r): string
    {
        if ((int) $r->invoice_type === 1) return 'Direct Invoice';
        if ((string) ($r->remark ?? '') === 'Order Submitted by whatsapp')
            return 'Secondary Sale Whatsapp Order Invoice';
        if ((int) $r->invoice_type === 2) return 'Secondary Sale Invoice';
        return '';
    }
 
public function cancel(Request $request, string $id): JsonResponse
{
    $validated = $request->validate([
        'reason' => 'required|string|max:255',
    ]);

    $user     = $request->user();
    $dealerId = (int) $user->dealer_id;

    $invoice = DB::selectOne(
        "SELECT id, ch_no, company_id, ch_dealer_id, dispatch_status,
                payment_status, isclaim, crdr_status, remark
           FROM challan_order
          WHERE id = ? AND ch_dealer_id = ?
          LIMIT 1",
        [$id, $dealerId]
    );

    if (!$invoice) {
        return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
    }

    if ($lock = $this->invoiceLockReason($invoice, $dealerId)) {
        return response()->json(['status' => false, 'message' => $lock], 409);
    }

    DB::transaction(function () use ($id, $invoice, $user, $validated, $dealerId) {

        // 1. Stock wapas
        $this->restoreStock($id, $dealerId, (int) $invoice->company_id);

        // 2. Details move — named insert, positional nahi
        $detailRows = DB::select(
            "SELECT * FROM challan_order_details WHERE ch_id = ?",
            [$id]
        );
        foreach ($detailRows as $r) {
            $data = (array) $r;
            $data['status'] = 1;
            DB::table('challan_order_details_cancel')->insert($data);
        }
        DB::table('challan_order_details')->where('ch_id', $id)->delete();

        // 3. Header move — named insert
        $headerRow = DB::selectOne(
            "SELECT * FROM challan_order WHERE id = ? AND ch_dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$headerRow) {
            throw new \RuntimeException('Header vanished mid-transaction.');
        }
        $h = (array) $headerRow;
        $h['status']  = 1;
        $h['remark']  = trim(($headerRow->remark ?? '') . ' | CANCELLED: ' . $validated['reason']);
        $h['sesId']   = (int) $user->id;

        DB::table('challan_order_cancel')->insert($h);
        DB::table('challan_order')->where('id', $id)->where('ch_dealer_id', $dealerId)->delete();
    });

    return response()->json([
        'status'  => true,
        'message' => 'Invoice cancelled and moved to challan_order_cancel.',
        'id'      => (string) $invoice->id,
        'ch_no'   => $invoice->ch_no,
    ]);
}


public function destroy(Request $request, string $id): JsonResponse
{
    $user     = $request->user();
    $dealerId = (int) $user->dealer_id;

    $invoice = DB::selectOne(
        "SELECT id, ch_no, company_id, dispatch_status, payment_status, isclaim
           FROM challan_order
          WHERE id = ? AND ch_dealer_id = ?
          LIMIT 1",
        [$id, $dealerId]
    );

    // Agar already cancel table me hai, kuch mat karo
    if (!$invoice) {
        $already = DB::selectOne(
            "SELECT id, ch_no FROM challan_order_cancel
              WHERE id = ? AND ch_dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if ($already) {
            return response()->json([
                'status'  => true,
                'message' => 'Invoice already cancelled.',
                'id'      => (string) $already->id,
                'ch_no'   => $already->ch_no,
            ]);
        }
        return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
    }

    if ($lock = $this->invoiceLockReason($invoice, $dealerId)) {
        return response()->json(['status' => false, 'message' => $lock], 409);
    }

    DB::transaction(function () use ($id, $invoice, $user, $dealerId) {
        $this->restoreStock($id, $dealerId, (int) $invoice->company_id);

        if ($this->tableExists('challan_order_details_cancel')) {
            DB::statement(
                "INSERT INTO challan_order_details_cancel
                 SELECT * FROM challan_order_details WHERE ch_id = ?",
                [$id]
            );
            DB::table('challan_order_details')->where('ch_id', $id)->delete();
        }

        DB::statement(
            "INSERT INTO challan_order_cancel
             SELECT *, 1 AS status FROM challan_order WHERE id = ? AND ch_dealer_id = ?",
            [$id, $dealerId]
        );

        DB::table('challan_order_cancel')
            ->where('id', $id)
            ->update([
                'remark' => trim(($invoice->remark ?? '') . ' | DELETED'),
                'sesId'  => (int) $user->id,
            ]);

        DB::table('challan_order')->where('id', $id)->where('ch_dealer_id', $dealerId)->delete();
    });

    return response()->json([
        'status'  => true,
        'message' => 'Invoice moved to challan_order_cancel.',
        'id'      => (string) $invoice->id,
        'ch_no'   => $invoice->ch_no,
    ]);
}
/**
 * Returns a human message if the invoice is locked from cancel/delete, else null.
 */
private function invoiceLockReason(object $inv, int $dealerId): ?string
{
    if ((int) $inv->dispatch_status === 1) return 'Invoice already dispatched.';
    if ((int) $inv->payment_status  === 1) return 'Payment already recorded against this invoice.';
    if ((int) $inv->isclaim         === 1) return 'Claim already filed against this invoice.';

    $irn = DB::selectOne(
        "SELECT id FROM einvoice_api_logs
          WHERE challan_id = ? AND status = 1
          LIMIT 1",
        [(string) $inv->id]
    );
    if ($irn) return 'E-invoice (IRN) already generated for this invoice.';

    $claim = DB::selectOne(
        "SELECT id FROM dms_retailer_images_amount_claim
          WHERE order_id = ? AND dealer_id = ?
          LIMIT 1",
        [(string) $inv->id, $dealerId]
    );
    if ($claim) return 'Claim exists for this invoice.';

    return null;
}

/**
 * Puts back the qty that the invoice had deducted from stock.
 *
 * ⚠ Assumes:
 *   - stock has columns: dealer_id, product_id, batch_no, mfg, qty, remaining
 *   - challan_order_details.mfg_date maps to stock.mfg
 *   - the same qty column(s) the save path decremented are restored here.
 *     If save only touched `remaining`, drop the `qty = qty + ?` part.
 */
private function restoreStock(string $chId, int $dealerId, int $companyId): void
{
    $items = DB::select(
        "SELECT product_id, batch_no, mfg_date, qty, free_qty
           FROM challan_order_details
          WHERE ch_id = ?",
        [$chId]
    );

    foreach ($items as $it) {
        $totalQty = (float) $it->qty + (float) $it->free_qty;
        if ($totalQty <= 0) continue;

        DB::update(
            "UPDATE stock
                SET qty       = qty       + ?,
                    remaining = remaining + ?
              WHERE dealer_id  = ?
                AND product_id = ?
                AND batch_no   = ?
                AND mfg        = ?
              LIMIT 1",
            [
                $totalQty,
                $totalQty,
                $dealerId,
                (int) $it->product_id,
                (string) $it->batch_no,
                $it->mfg_date,
            ]
        );
    }
}
}