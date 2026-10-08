<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\InvoiceService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
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
        $division = $request->filled('division') ? (int) $request->input('division') : null;
        if ($division) {
            $where[]    = "EXISTS (SELECT 1 FROM challan_order_details dcod JOIN catalog_product dcp ON dcp.id = dcod.product_id WHERE dcod.ch_id = co.id AND dcp.division = ?)";
            $bindings[] = $division;
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
                    p.first_name, p.middle_name, p.last_name
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
        $itemsMap   = $this->loadItems($invoiceIds, $stateId, $division);
        $claimMap   = $this->loadClaimStatus($invoiceIds, $dealerId, $companyId);

        $out = [];
        foreach ($rows as $r) {
            $name  = trim(($r->first_name ?? '') . ' ' . ($r->middle_name ?? '') . ' ' . ($r->last_name ?? ''));
            $items = $itemsMap[$r->id] ?? [];
            $claim = $claimMap[$r->id] ?? null;
            $disc  = (float) ($r->discount_amt ?? 0);

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
                'amount'            => (float) $r->amount,
                'items'             => $items,
                'claim_exists'      => $claim !== null,
                'claim_status'      => $claim->claim_status ?? 0,
                'claim_id'          => $claim->id ?? null,
            ];
        }

        return response()->json(['status' => true, 'rows' => $out]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $header = DB::selectOne(
            "SELECT co.*,
                    r.name AS retailer_name, r.address AS retailer_address,
                    r.landline, r.other_numbers, r.tin_no, r.class AS retailer_class,
                    r.contact_per_name,
                    r.state_id_retailer AS retailer_state_id,
                    r.pin_no            AS retailer_pin_no,
                    d.name AS dealer_name,
                    d.address        AS dealer_address,
                    d.tin_no         AS dealer_tin_no,
                    d.pan_no         AS dealer_pan_no,
                    d.landline       AS dealer_landline,
                    d.other_numbers  AS dealer_other_numbers,
                    d.pin_no         AS dealer_pin_no,
                    d.state_id       AS dealer_state_id,
                    d.dealer_code    AS dealer_code,
                    d.town_id        AS dealer_town_id,
                    l5.name          AS dealer_town_name,
                    l7.name          AS beat_name,
                    p.first_name, p.middle_name, p.last_name
               FROM challan_order co
               LEFT JOIN retailer   r  ON r.id  = co.ch_retailer_id
               LEFT JOIN dealer     d  ON d.id  = co.ch_dealer_id
               LEFT JOIN location_5 l5 ON l5.id = d.town_id
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
               LEFT JOIN person     p  ON p.id  = co.ch_user_id
              WHERE co.id = ? AND co.ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        if (!$header) {
            return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
        }

        // st: 1 if retailer state == dealer state (IGST vs CGST/SGST)
        $retailerState = (int) ($header->retailer_state_id ?? 0);
        $dealerState   = (int) ($header->dealer_state_id   ?? 0);
        $st = ($retailerState > 0 && $retailerState === $dealerState) ? 1 : 0;

        // ITEMS — catalog_product se naam/hsn/gst lo (catalog_view me sirf 21 rows thi)
        $items = DB::select(
            "SELECT cod.*,
                    cp.name              AS product_name,
                    cp.itemcode          AS itemcode,
                    cp.hsn_code          AS hsn_code,
                    cp.quantity_per_case AS qty_per_case,
                    cp.weight            AS weight,
                    cp.gst_percent       AS gst_percent
               FROM challan_order_details cod
               LEFT JOIN catalog_product cp ON cp.id = cod.product_id
              WHERE cod.ch_id = ?
              ORDER BY cod.id ASC",
            [$id]
        );

        // Drug licence — latest registration row for this dealer_code

        // po_no — company 213 only
        $poNo = null;
        if ($companyId === 213) {
            $invoiceType = (int) $header->invoice_type;

            if ($invoiceType === 2) {
                $row = DB::selectOne(
                    "SELECT sed.po_no
                       FROM challan_order_details cod
                       INNER JOIN user_sales_order_extra_details sed
                               ON sed.order_id = cod.order_id
                      WHERE cod.ch_id = ?
                        AND sed.po_no IS NOT NULL
                        AND sed.po_no <> ''
                      LIMIT 1",
                    [$id]
                );
                $poNo = $row->po_no ?? null;
            } elseif ($invoiceType === 1) {
                $poNo = $header->direct_invoice_po_no ?? null;
            }
        }

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
            'status' => true,
            'header' => $header,
            'items'  => $items,
            'claim'  => $claim,
            'irn'    => $irnLog,
            'lock'   => app(InvoiceService::class)->reason((string) $header->id, $dealerId),

            'st'     => $st,
            'seller' => [
                'name'              => $header->dealer_name,
                'address'           => $header->dealer_address,
                'tin_no'            => $header->dealer_tin_no,
                'pan_no'            => $header->dealer_pan_no,
                'landline'          => $header->dealer_landline,
                'other_numbers'     => $header->dealer_other_numbers,
                'pin_no'            => $header->dealer_pin_no,
            ],
            'retailer_pin_no' => $header->retailer_pin_no,
            'po_no'           => $poNo,

            // claim form fields
            'depot_name'        => $header->dealer_name,
            'ss_stockist_town'  => $header->dealer_town_name,
            'retailer_category' => $header->retailer_class,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $invoice = DB::selectOne(
            "SELECT * FROM challan_order WHERE id = ? AND ch_dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$invoice) {
            return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
        }
        if ($lock = app(InvoiceService::class)->reason((string) $invoice->id, $dealerId)) {
            return response()->json(['status' => false, 'message' => $lock], 409);
        }

        $validated = $request->validate([
            'ch_date'            => 'sometimes|date',
            'retailer_id'        => 'sometimes',
            'user_id'            => 'sometimes|integer',
            'remark'             => 'sometimes|nullable|string|max:255',
            'edited_remarks'     => 'sometimes|nullable|string|max:255',   // FIX #3
            'discount_per'       => 'sometimes|numeric|min:0|max:100',
            'crdr_status'        => 'sometimes|integer|in:0,1,2',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.mrp'        => 'required|numeric',
            'items.*.qty'        => 'required|numeric|min:0.001',
            'items.*.rate'       => 'required|numeric|gt:0',
            'items.*.free_qty'   => 'nullable|numeric|min:0',
            'items.*.cd_type'    => 'nullable|integer|in:1,2',
            'items.*.cd'         => 'nullable|numeric|min:0',
            'items.*.dis_type'   => 'nullable|integer|in:1,2',
            'items.*.dis_amt'    => 'nullable|numeric|min:0',
            'items.*.spl_disc_amt' => 'nullable|numeric|min:0',
            'items.*.cash_amt'   => 'nullable|numeric|min:0',
            'items.*.batch_no'   => 'nullable|string|max:11',
            'items.*.mfg_date'   => 'nullable|string|max:20',
        ]);

        try {
        $companyId = (int) $user->company_id;
        DB::transaction(function () use ($id, $invoice, $validated, $dealerId, $companyId) {

            $oldItems = DB::select(
                "SELECT product_id, qty, free_qty, batch_no, mfg_date, mrp, tax, old_rate, order_id
                   FROM challan_order_details WHERE ch_id = ?",
                [$id]
            );

            $orderOf = [];
            foreach ($oldItems as $o) {
                if ((string) $o->order_id !== '') $orderOf[(int) $o->product_id] = (string) $o->order_id;
            }
            $orderIds     = array_values(array_unique($orderOf));
            $defaultOrder = count($orderIds) === 1 ? $orderIds[0] : '';

            $oldMap = [];
            foreach ($oldItems as $o) {
                $key = $this->lineKey((int) $o->product_id, (string) $o->batch_no, (string) $o->mfg_date);
                $oldMap[$key] = [
                    'qty'        => (float) $o->qty + (float) $o->free_qty,
                    'product_id' => (int) $o->product_id,
                    'batch_no'   => (string) $o->batch_no,
                    'mfg_date'   => (string) $o->mfg_date,
                    'tax'        => (float) $o->tax,
                    'old_rate'   => $o->old_rate,
                ];
            }

            $lines        = [];
            $totalTaxable = 0.0;
            $totalVat     = 0.0;
            $stockLines   = [];

            foreach ($validated['items'] as $row) {
                $product = DB::selectOne(
                    "SELECT id, name, gst_percent FROM catalog_product p
                      WHERE p.id = ?
                        AND (p.company_id = ?
                             OR EXISTS (SELECT 1 FROM stock s WHERE s.product_id = p.id AND s.dealer_id = ?)
                             OR EXISTS (SELECT 1 FROM challan_order_details d WHERE d.ch_id = ? AND d.product_id = p.id))",
                    [(int) $row['product_id'], $companyId, $dealerId, $id]
                );
                if (!$product) {
                    throw new \RuntimeException("Product {$row['product_id']} not found.");
                }

                $qty       = (float) $row['qty'];
                $rate      = (float) $row['rate'];
                $mrp       = (float) $row['mrp'];
                $freeQty   = (float) ($row['free_qty'] ?? 0);
                $newQtyTot = $qty + $freeQty;
                $batchNo   = (string) ($row['batch_no'] ?? '');
                $mfgDate   = (string) ($row['mfg_date'] ?? '1970-01-01');
                $gross     = $qty * $rate;

                $key = $this->lineKey((int) $row['product_id'], $batchNo, $mfgDate);
                $taxPercent = isset($oldMap[$key]) ? $oldMap[$key]['tax'] : (float) $product->gst_percent;

                // Trade discount — on gross
                $disType = (int)   ($row['dis_type'] ?? 1);
                $disVal  = (float) ($row['dis_amt'] ?? 0);
                $disAmt  = $disType === 1 ? round($gross * $disVal / 100, 2) : round($disVal, 2);

                // FIX #2: ATD % applied on amount AFTER trade discount
                $splAmt  = round((float) ($row['spl_disc_amt'] ?? 0), 2);
                $cashAmt = round((float) ($row['cash_amt'] ?? 0), 2);
                $afterTrade = $gross - $disAmt - $splAmt - $cashAmt;
                $cdType = (int)   ($row['cd_type'] ?? 1);
                $cdVal  = (float) ($row['cd'] ?? 0);
                $cdAmt  = $cdType === 1 ? round($afterTrade * $cdVal / 100, 2) : round($cdVal, 2);

                $taxable = round($afterTrade - $cdAmt, 2);
                $vatAmt  = round($taxable * $taxPercent / 100, 2);

                // FIX #1: legacy stores GST-inclusive line total
                $lineTotal = round($taxable + $vatAmt, 2);

                $stockLines[] = [
                    'product_id' => (int) $row['product_id'],
                    'batch_no'   => $batchNo,
                    'mfg_date'   => $mfgDate,
                    'mrp'        => $mrp,
                    'qty'        => $newQtyTot,
                    'name'       => (string) $product->name,
                ];

                $lines[] = [
                    'ch_id'         => $id,
                    'product_id'    => (int) $row['product_id'],
                    'qty'           => $qty,
                    'product_rate'  => number_format($rate, 2, '.', ''),
                    'free_qty'      => (string) $freeQty,
                    'mrp'           => number_format($mrp, 2, '.', ''),
                    'tax'           => number_format($taxPercent, 2, '.', ''),
                    'vat_amt'       => number_format($vatAmt, 2, '.', ''),
                    // FIX #1: legacy stores GST-inclusive line total
                    'taxable_amt'   => number_format($lineTotal, 2, '.', ''),
                    'remain_amount' => number_format($lineTotal, 2, '.', ''),
                    'cd'            => number_format($cdVal, 2, '.', ''),
                    'cd_type'       => $cdType,
                    'cd_amt'        => $cdAmt,
                    'dis_type'      => $disType,
                    'dis_amt'       => $disAmt,
                    'dis_percent'   => $disType === 1 ? $disVal : 0,
                    'spl_disc_amt'  => $splAmt,
                    'cash_amt'      => $cashAmt,
                    'batch_no'      => $batchNo,
                    'mfg_date'      => $mfgDate,
                    'user_id'       => (int) ($validated['user_id'] ?? $invoice->ch_user_id),
                    'order_id'      => $orderOf[(int) $row['product_id']] ?? $defaultOrder,
                    'sync_status'   => 1,
                    'old_rate'      => $oldMap[$key]['old_rate'] ?? null,
                ];

                $totalTaxable += $taxable;
                $totalVat     += $vatAmt;
            }

            app(StockService::class)->sync($dealerId, $oldItems, $stockLines);

            $invoiceService = app(InvoiceService::class);
            $invoiceService->reopenSalesOrders($oldItems, $dealerId);
            $invoicedByOrder = [];
            foreach ($lines as $l) {
                if ($l['order_id'] !== '') {
                    $invoicedByOrder[$l['order_id']][$l['product_id']] = ($invoicedByOrder[$l['order_id']][$l['product_id']] ?? 0) + (float) $l['qty'];
                }
            }
            foreach ($invoicedByOrder as $orderId => $qtys) {
                $invoiceService->fulfilSalesOrder($dealerId, (string) $orderId, $qtys);
            }

            DB::table('challan_order_details')->where('ch_id', $id)->delete();
            DB::table('challan_order_details')->insert($lines);

            $subTotal    = round($totalTaxable + $totalVat, 2);
            $discountPer = (float) ($validated['discount_per'] ?? $invoice->discount_per ?? 0);
            $discountAmt = round($subTotal * $discountPer / 100, 2);
            $grandTotal  = round($subTotal - $discountAmt, 2);

            $headerUpdate = [
                'discount_per' => number_format($discountPer, 2, '.', ''),
                'discount_amt' => number_format($discountAmt, 2, '.', ''),
                'discount'     => number_format($discountAmt, 2, '.', ''),
                'amount'       => number_format($grandTotal, 2, '.', ''),
                'remaining'    => number_format($grandTotal, 2, '.', ''),
                'amount_round' => number_format($grandTotal, 2, '.', ''),
            ];
            if (isset($validated['ch_date']))           $headerUpdate['ch_date']        = $validated['ch_date'];
            if (isset($validated['retailer_id']))       $headerUpdate['ch_retailer_id'] = (string) $validated['retailer_id'];
            if (isset($validated['user_id']))           $headerUpdate['ch_user_id']     = (int) $validated['user_id'];
            if (isset($validated['crdr_status']))       $headerUpdate['crdr_status']    = (int) $validated['crdr_status'];
            if (array_key_exists('remark', $validated)) $headerUpdate['remark']         = $validated['remark'] ?? '';
            // FIX #3: persist edited_remarks
            if (array_key_exists('edited_remarks', $validated)) {
                $headerUpdate['edited_remarks'] = $validated['edited_remarks'] ?? '';
            }

            DB::table('challan_order')
                ->where('id', $id)
                ->where('ch_dealer_id', $dealerId)
                ->update($headerUpdate);
        });
        } catch (\RuntimeException $e) {
            return response()->json(['status' => false, 'message' => safe_error_message($e)], 422);
        }

        return $this->show($request, $id);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);
        $reason = $validated['reason'] ?? 'No reason provided';

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
        if ($lock = app(InvoiceService::class)->reason((string) $invoice->id, $dealerId)) {
            return response()->json(['status' => false, 'message' => $lock], 409);
        }

        try {
        DB::transaction(function () use ($id, $invoice, $user, $reason, $dealerId) {

            app(InvoiceService::class)->reopenSalesOrders(
                DB::select("SELECT order_id, product_id, qty FROM challan_order_details WHERE ch_id = ?", [$id]),
                $dealerId
            );
            app(StockService::class)->sync(
                $dealerId,
                DB::select("SELECT product_id, qty, free_qty, batch_no, mfg_date, mrp FROM challan_order_details WHERE ch_id = ?", [$id]),
                []
            );

            $detailRows = DB::select("SELECT * FROM challan_order_details WHERE ch_id = ?", [$id]);
            foreach ($detailRows as $r) {
                $data = (array) $r;
                $data['status'] = 'CANCEL';
                DB::table('challan_order_details_cancel')->insert($data);
            }
            DB::table('challan_order_details')->where('ch_id', $id)->delete();

            $headerRow = DB::selectOne(
                "SELECT * FROM challan_order WHERE id = ? AND ch_dealer_id = ? LIMIT 1",
                [$id, $dealerId]
            );
            if (!$headerRow) {
                throw new \RuntimeException('Header vanished mid-transaction.');
            }
            $h = (array) $headerRow;
            $h['status'] = 'CANCEL';
            $h['remark'] = trim(($headerRow->remark ?? '') . ' | CANCELLED: ' . $reason);
            $h['sesId']  = (int) $user->id;

            DB::table('challan_order_cancel')->insert($h);
            DB::table('challan_order')->where('id', $id)->where('ch_dealer_id', $dealerId)->delete();
        });
        } catch (\RuntimeException $e) {
            return response()->json(['status' => false, 'message' => safe_error_message($e)], 422);
        }

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
            "SELECT id, ch_no, company_id, dispatch_status, payment_status, isclaim, remark
               FROM challan_order
              WHERE id = ? AND ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

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

        if ($lock = app(InvoiceService::class)->reason((string) $invoice->id, $dealerId)) {
            return response()->json(['status' => false, 'message' => $lock], 409);
        }

        try {
        DB::transaction(function () use ($id, $invoice, $user, $dealerId) {

            app(InvoiceService::class)->reopenSalesOrders(
                DB::select("SELECT order_id, product_id, qty FROM challan_order_details WHERE ch_id = ?", [$id]),
                $dealerId
            );
            app(StockService::class)->sync(
                $dealerId,
                DB::select("SELECT product_id, qty, free_qty, batch_no, mfg_date, mrp FROM challan_order_details WHERE ch_id = ?", [$id]),
                []
            );

            $detailRows = DB::select("SELECT * FROM challan_order_details WHERE ch_id = ?", [$id]);
            foreach ($detailRows as $r) {
                $data = (array) $r;
                $data['status'] = 'DELETE';
                DB::table('challan_order_details_cancel')->insert($data);
            }
            DB::table('challan_order_details')->where('ch_id', $id)->delete();

            $headerRow = DB::selectOne(
                "SELECT * FROM challan_order WHERE id = ? AND ch_dealer_id = ? LIMIT 1",
                [$id, $dealerId]
            );
            if (!$headerRow) {
                throw new \RuntimeException('Header vanished mid-transaction.');
            }
            $h = (array) $headerRow;
            $h['status'] = 'DELETE';
            $h['remark'] = trim(($headerRow->remark ?? '') . ' | DELETED');
            $h['sesId']  = (int) $user->id;

            DB::table('challan_order_cancel')->insert($h);
            DB::table('challan_order')->where('id', $id)->where('ch_dealer_id', $dealerId)->delete();
        });
        } catch (\RuntimeException $e) {
            return response()->json(['status' => false, 'message' => safe_error_message($e)], 422);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Invoice moved to challan_order_cancel.',
            'id'      => (string) $invoice->id,
            'ch_no'   => $invoice->ch_no,
        ]);
    }

    /* ================= helpers ================= */

    private function loadItems(array $invoiceIds, int $stateId, ?int $division = null): array
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
                    cp.name              AS product_name,
                    cp.quantity_per_case AS qty_per_case,
                    cp.weight            AS weight,
                    cp.itemcode          AS itemcode,
                    cp.hsn_code          AS hsn_code,
                    EXISTS (SELECT 1 FROM dms_challan_claim_details dcd WHERE dcd.challan_id = cod.ch_id AND dcd.product_id = cod.product_id) AS claimed
               FROM challan_order_details cod
               LEFT JOIN catalog_product cp ON cp.id = cod.product_id
              WHERE cod.ch_id IN ({$in})" . ($division ? " AND cp.division = " . $division : '') . "
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
                'claimed'      => (bool) $r->claimed,
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

    private function lineKey(int $productId, string $batchNo, string $mfgDate): string
    {
        return $productId . '|' . $batchNo . '|' . $mfgDate;
    }

    public function claimDetails(Request $request, string $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $invoice = DB::selectOne(
            "SELECT co.id AS order_id, co.ch_no AS invoice_no,
                    DATE_FORMAT(co.ch_date, '%d-%m-%Y') AS invoice_date,
                    co.amount AS invoice_amount,
                    r.name AS retailer_name, roc.outlet_category AS retailer_category,
                    d.name AS dealer_name, l6.name AS town_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN _retailer_outlet_category roc ON roc.id = r.class
               LEFT JOIN dealer d ON d.id = co.ch_dealer_id
               LEFT JOIN location_6 l6 ON l6.id = d.town_id
              WHERE co.id = ? AND co.ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$invoice) {
            return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
        }

        $claim = DB::selectOne(
            "SELECT claim_status FROM dms_retailer_images_amount_claim
              WHERE order_id = ? AND dealer_id = ? AND company_id = ?
              LIMIT 1",
            [(string) $id, $dealerId, $companyId]
        );

        return response()->json([
            'status' => true,
            'data'   => array_merge((array) $invoice, [
                'claim_exists' => $claim !== null,
                'claim_status' => $claim ? (int) $claim->claim_status : null,
            ]),
        ]);
    }

    public function storeClaim(Request $request, string $id): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $invoice = DB::selectOne(
        "SELECT id, ch_no, ch_retailer_id, ch_dealer_id, amount
           FROM challan_order
          WHERE id = ? AND ch_dealer_id = ?
          LIMIT 1",
        [$id, $dealerId]
    );
    if (!$invoice) {
        return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
    }

    $existing = DB::selectOne(
        "SELECT id FROM dms_retailer_images_amount_claim
          WHERE order_id = ? AND dealer_id = ? AND company_id = ?
          LIMIT 1",
        [(string) $id, $dealerId, $companyId]
    );
    if ($existing) {
        return response()->json(['status' => false, 'message' => 'Claim already submitted for this invoice.'], 409);
    }

    $request->validate([
        'contract_form_image'   => ['required', 'image', 'max:4096'],
        'shop_display_images'   => ['required', 'array', 'min:1', 'max:14'],
        'shop_display_images.*' => ['image', 'max:4096'],
        'other_image_1'         => ['nullable', 'image', 'max:4096'],
        'other_image_2'         => ['nullable', 'image', 'max:4096'],
        'contract_date'         => ['nullable', 'date'],
        'start_date'            => ['nullable', 'date'],
        'display_period_days'   => ['nullable', 'string', 'max:50'],
    ]);

    DB::beginTransaction();
    try {
        // doc_1 = contract form
        $docs = [];
        $docs['doc_1'] = $this->saveClaimFile($request->file('contract_form_image'));

        // doc_2 .. doc_15 = shop display images (max 14)
        $i = 2;
        foreach ($request->file('shop_display_images') as $f) {
            if ($i > 15) break;
            $docs['doc_' . $i] = $this->saveClaimFile($f);
            $i++;
        }

        // doc_16 = other image 1, doc_17 = other image 2
        if ($request->hasFile('other_image_1')) {
            $docs['doc_16'] = $this->saveClaimFile($request->file('other_image_1'));
        }
        if ($request->hasFile('other_image_2')) {
            $docs['doc_17'] = $this->saveClaimFile($request->file('other_image_2'));
        }

        $row = array_merge([
            'order_id'      => (string) $id,
            'company_id'    => $companyId,
            'dealer_id'     => $dealerId,
            'retailer_id'   => (string) $invoice->ch_retailer_id,
            'date_time'     => date('Y-m-d H:i:s'),
            'contract_date' => $request->contract_date,
            'start_date'    => $request->start_date,
            'display_period'=> (string) ($request->display_period_days ?? ''),
            'claim_amount'  => '0',
            'claim_status'  => 0,
        ], $docs);

        DB::table('dms_retailer_images_amount_claim')->insert($row);

        DB::commit();
        return response()->json(['status' => true, 'message' => 'Claim submitted successfully.']);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json(['status' => false, 'message' => 'Claim failed: ' . safe_error_message($e)], 500);
    }
}

private function saveClaimFile($file): string
{
    $name = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
    $file->move(public_path('myupload/claim'), $name);
    return $name;
}
}