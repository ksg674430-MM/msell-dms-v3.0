<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DirectChallanController extends Controller
{
    /* =========================================================
     |  FORM BOOTSTRAP
     |  GET /api/direct-challans/form
     |
     |  Mirrors the inline queries the legacy page runs before
     |  rendering the form (retailer list, field force list,
     |  product list, session, next ch_no).
     ========================================================= */
    public function formData(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;
        $stateId   = (int) ($user->state_id ?? 0);

        // Retailer list — exact JOIN from the legacy page.
        // Retailer name is packed as "name [address] [outstanding]".
        $retailers = DB::select(
            "SELECT r.id,
                    CONCAT_WS(' ', r.name, '[', r.address, ']',
                              '[', COALESCE(SUM(co.remaining), 0), ']') AS name
               FROM retailer r
               LEFT  JOIN challan_order co
                       ON co.ch_retailer_id = r.id
               INNER JOIN dealer_location_rate_list dlrl
                       ON dlrl.location_id = r.location_id
              WHERE dlrl.dealer_id = ?
                AND r.retailer_status = '1'
              GROUP BY r.id
              ORDER BY r.name ASC",
            [$dealerId]
        );

        // Field force — legacy user_dealer_retailer + person + _role
        $fieldForce = DB::select(
            "SELECT d.user_id AS id,
                    CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name,
                              '[', rl.rolename, ']') AS name
               FROM user_dealer_retailer d
               INNER JOIN person p  ON p.id       = d.user_id
               INNER JOIN _role  rl ON rl.role_id = p.role_id
              WHERE d.dealer_id = ?
              ORDER BY p.first_name",
            [$dealerId]
        );

        // Products — legacy filters by stock.dealer_id ONLY
        // (it does not join company). Preserving that behaviour.
        $products = DB::select(
            "SELECT cp.id,
                    CONCAT_WS(' ', cp.name, '[BAID]', '[', SUM(s.qty), ']') AS name
               FROM catalog_product cp
               INNER JOIN stock s
                       ON s.product_id = cp.id
              WHERE s.dealer_id = ?
                AND s.qty > 0
              GROUP BY cp.id
              ORDER BY cp.id",
            [$dealerId]
        );

        $session = DB::selectOne(
            "SELECT session FROM session WHERE action = '1' LIMIT 1"
        );

        $state = null;
        if ($stateId > 0) {
            $state = DB::selectOne(
                "SELECT * FROM state WHERE stateid = ? LIMIT 1",
                [$stateId]
            );
        }

        return response()->json([
            'status'      => true,
            'retailers'   => $retailers,
            'field_force' => $fieldForce,
            'products'    => $products,
            'session'     => $session,
            'state'       => $state,
            'next_ch_no'  => $this->generateLegacyChNo($dealerId),
            'dp_id'       => $dpId,
            'dealer_id'   => $dealerId,
            'company_id'  => $companyId,
            'state_id'    => $stateId,
        ]);
    }

    /* =========================================================
     |  NEXT CH-NO
     |  GET /api/direct-challans/next-number
     |
     |  Legacy format: BDN/{dealer_id}/{session_year}/{000001}
     ========================================================= */
    public function nextNumber(Request $request): JsonResponse
    {
        $dealerId = (int) $request->user()->dealer_id;

        return response()->json([
            'status' => true,
            'ch_no'  => $this->generateLegacyChNo($dealerId),
        ]);
    }

    /* =========================================================
     |  SAVE
     |  POST /api/direct-challans
     |
     |  Mirrors direct_challan_save(). Legacy quirks kept:
     |   - invoice_type = '10'
     |   - taxable_amt column stores the line GROSS (qty*rate incl. tax)
     |   - remain_amount stores the same GROSS value
     |   - tax   = vat ?? (cgst * 2)
     |   - vat_amt = vat_amt ?? (cgst_amt * 2)
     |   - id format: dealer_id + date('ymdHis')
     |   - book_status / crdr_status come from the form
     |   - stock.remaining decremented per batch row
     |   - user_sales_order_details.status = 2 for matched order_id
     ========================================================= */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $v = Validator::make($request->all(), [
            'ch_date'          => ['required', 'string'],
            'retailer_id'      => ['required', 'integer'],
            'user_id'          => ['nullable', 'integer'],
            'ch_no'            => ['nullable', 'string', 'max:64'],
            'book_status'      => ['nullable', 'in:0,1'],
            'crdr'             => ['nullable', 'in:0,1,2'],
            'dis'              => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items'            => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty'        => ['required', 'numeric', 'gt:0'],
            'items.*.rate'       => ['required', 'numeric', 'gt:0'],
            'items.*.mrp'        => ['nullable', 'numeric'],
            'items.*.batch_no'   => ['nullable', 'string'],
            'items.*.mfg_date'   => ['nullable', 'string'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $chDate = $this->parseDate($request->input('ch_date'));
        if (!$chDate) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid ch_date.',
            ], 422);
        }

        $retailerId = (int) $request->input('retailer_id');
        $retailerOk = DB::selectOne(
            "SELECT id FROM retailer
              WHERE id = ? AND dealer_id = ?
              LIMIT 1",
            [$retailerId, $dealerId]
        );
        if (!$retailerOk) {
            return response()->json([
                'status'  => false,
                'message' => "Retailer {$retailerId} not found for this dealer.",
            ], 404);
        }

        // Allocate unique challan id (legacy format)
        $chId = $this->allocateChallanId($dealerId);

        // ch_no: honour client value; generate if empty and not book-billing
        $bookStatus = (int) $request->input('book_status', 0);
        $crdrStatus = (int) $request->input('crdr', 0);
        $chNo       = (string) $request->input('ch_no', '');
        if ($bookStatus === 0 && $chNo === '') {
            $chNo = $this->generateLegacyChNo($dealerId);
        }

        $discPer = (float) ($request->input('dis') ?: 0);
        $userId  = (int)  ($request->input('user_id') ?: $dpId);

        $sessionYear = DB::selectOne(
            "SELECT sesId FROM session_year ORDER BY sesId DESC LIMIT 1"
        );
        $sesId = (int) ($sessionYear->sesId ?? 0);

        DB::beginTransaction();
        try {
            // ── Build lines (legacy parsing rules)
            $amount = 0.0;
            $lines  = [];

            foreach ($request->input('items') as $it) {
                $productId = (int)   $it['product_id'];
                $qty       = (float) $it['qty'];
                $freeQty   = (float) ($it['free_qty'] ?? 0);
                $rate      = (float) $it['rate'];
                $mrp       = (float) ($it['mrp'] ?? 0);

                // Batch / mfg: accept legacy packed string OR separate fields
                $batchNo = (string) ($it['batch_no'] ?? '');
                $mfgDate = $this->parseDate($it['mfg_date'] ?? null);
                if ($batchNo === '' && isset($it['mrp']) && is_string($it['mrp']) && str_contains($it['mrp'], '/')) {
                    // Legacy packed: "mrp/stock/batch/mfg"
                    $parts   = explode('/', (string) $it['mrp']);
                    $mrp     = (float) ($parts[0] ?? 0);
                    $batchNo = (string) ($parts[2] ?? '');
                    $mfgDate = $this->parseDate($parts[3] ?? null) ?: '1970-01-01';
                }
                $mfgDate = $mfgDate ?: '1970-01-01';

                // Tax fallbacks — legacy save path
                $vat    = (float) ($it['vat']      ?? 0);
                $cgst   = (float) ($it['cgst']     ?? 0);
                $tax    = $vat > 0 ? $vat : ($cgst * 2);

                $vatAmt = (float) ($it['vat_amt']  ?? 0);
                $cgstAmt = (float) ($it['cgst_amt'] ?? 0);
                if ($vatAmt == 0) {
                    $vatAmt = $cgstAmt * 2;
                }

                // Legacy: `amount` (from form's amount[]) is the GROSS.
                // Fallback to taxable_amt, then qty*rate.
                $gross = (float) (
                    $it['amount']
                        ?? $it['taxable_amt']
                        ?? round($qty * $rate + $vatAmt, 2)
                );

                $line = [
                    'product_id'    => $productId,
                    'qty'           => $qty,
                    'free_qty'      => $freeQty,
                    'rate'          => $rate,
                    'mrp'           => $mrp,
                    'batch_no'      => $batchNo,
                    'mfg_date'      => $mfgDate,
                    'tax'           => $tax,
                    'vat_amt'       => $vatAmt,
                    'cd'            => (float) ($it['cd']             ?? 0),
                    'cd_type'       => (int)   ($it['cd_type']        ?? 1),
                    'cd_amt'        => (float) ($it['cd_amt']         ?? 0),
                    'spl_disc_type' => (int)   ($it['spl_disc_type']  ?? 1),
                    'spl_disc_val'  => (float) ($it['spl_disc_val']   ?? 0),
                    'spl_disc_amt'  => (float) ($it['spl_disc_amt']   ?? 0),
                    'dis_type'      => (int)   ($it['trade_disc_type'] ?? 1),
                    'dis_amt'       => (float) ($it['trade_disc_amt'] ?? 0),
                    'dis_percent'   => (float) ($it['trade_disc_val'] ?? 0),
                    'cash_amt'      => (float) ($it['cash_amt']       ?? 0),
                    'taxable_amt'   => $gross,   // legacy quirk: gross goes here
                    'remain_amount' => $gross,
                    'supply_status' => (int)   ($it['bos']            ?? 0),
                    'order_id'      => (string)($it['order_id']       ?? ''),
                    'user_id'       => (int)   ($it['user_id']        ?? $userId),
                ];

                $lines[] = $line;
                $amount += $gross;
            }

            if (empty($lines)) {
                throw new \RuntimeException('No valid items were provided.');
            }

            $discAmt = round($amount * $discPer / 100, 2);
            $final   = round($amount - $discAmt, 2);

            // ── Header
            DB::insert(
                "INSERT INTO challan_order
                    (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id,
                     ch_user_id, ch_date, date_added, company_id, sesId,
                     invoice_type, book_status, crdr_status,
                     dispatch_status, payment_status, sync_status,
                     isclaim, istarget_claim,
                     discount_per, discount_amt, amount, remaining, amount_round)
                 VALUES
                    (?, ?, ?, ?, ?,
                     ?, ?, NOW(), ?, ?,
                     '10', ?, ?,
                     0, '0', 1,
                     0, 0,
                     ?, ?, ?, ?, ?)",
                [
                    $chId, $chNo, $dpId, $dealerId, $retailerId,
                    $userId, $chDate, $companyId, $sesId,
                    $bookStatus, $crdrStatus,
                    $discPer, $discAmt, $final, $final, $final,
                ]
            );

            // ── Lines + stock decrement + sales-order status
            foreach ($lines as $l) {
                DB::insert(
                    "INSERT INTO challan_order_details
                        (ch_id, product_id, supply_status, qty, product_rate,
                         free_qty, tax, mrp, cd, cd_type, cd_amt,
                         spl_disc_type, spl_disc_val, spl_disc_amt,
                         batch_no, mfg_date, dis_type, dis_amt, dis_percent,
                         taxable_amt, remain_amount, vat_amt, cash_amt,
                         order_id, user_id, sync_status)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [
                        $chId, $l['product_id'], $l['supply_status'], $l['qty'], $l['rate'],
                        $l['free_qty'], $l['tax'], $l['mrp'], $l['cd'], $l['cd_type'], $l['cd_amt'],
                        $l['spl_disc_type'], $l['spl_disc_val'], $l['spl_disc_amt'],
                        $l['batch_no'], $l['mfg_date'], $l['dis_type'], $l['dis_amt'], $l['dis_percent'],
                        $l['taxable_amt'], $l['remain_amount'], $l['vat_amt'], $l['cash_amt'],
                        $l['order_id'], $l['user_id'],
                    ]
                );

                $this->decrementStock(
                    $dealerId,
                    $l['product_id'],
                    $l['mrp'],
                    $l['batch_no'],
                    $l['mfg_date'],
                    $l['qty'] + $l['free_qty']
                );

                if ($l['order_id'] !== '') {
                    DB::update(
                        "UPDATE user_sales_order_details
                            SET status = 2
                          WHERE order_id = ? AND product_id = ?",
                        [$l['order_id'], $l['product_id']]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status'       => true,
                'message'      => 'Challan saved successfully.',
                'challan_id'   => $chId,
                'ch_no'        => $chNo,
                'amount'       => $final,
                'discount_amt' => $discAmt,
                'items_count'  => count($lines),
            ], 201);

        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => false,
                'message' => 'Challan could not be saved: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  SHOW (load for edit — mirrors get_challan_list mode=1)
     |  GET /api/direct-challans/{id}
     ========================================================= */
    public function show(Request $request, $id): JsonResponse
    {
        $dealerId = (int) $request->user()->dealer_id;

        $header = DB::selectOne(
            "SELECT co.*, DATE_FORMAT(co.ch_date, '%Y-%m-%d') AS ch_date_fmt
               FROM challan_order co
              WHERE co.id = ? AND co.ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$header) {
            return response()->json([
                'status'  => false,
                'message' => 'Challan not found.',
            ], 404);
        }

        $items = DB::select(
            "SELECT cod.*,
                    cp.name     AS product_name,
                    cp.hsn_code AS hsn_code
               FROM challan_order_details cod
               LEFT JOIN catalog_product cp ON cp.id = cod.product_id
              WHERE cod.ch_id = ?
              ORDER BY cod.id ASC",
            [$id]
        );

        return response()->json([
            'status' => true,
            'header' => $header,
            'items'  => $items,
        ]);
    }

    /* =========================================================
     |  UPDATE
     |  PUT /api/direct-challans/{id}
     |
     |  Mirrors direct_challan_edit():
     |   - refuses to edit cancelled challans
     |   - restores old stock, deletes old lines, inserts new
     |     lines, decrements new stock (transaction-safe; the
     |     legacy "delta vs. client aval_stock" logic was
     |     client-trust and is not preserved)
     |   - updates header totals
     |  Legacy quirks kept:
     |   - invoice_type unchanged (not touched on update)
     |   - taxable_amt / remain_amount = GROSS
     ========================================================= */
    public function update(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dpId     = (int) $user->getKey();
        $dealerId = (int) $user->dealer_id;

        $header = DB::selectOne(
            "SELECT id, ch_no, dispatch_status FROM challan_order
              WHERE id = ? AND ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$header) {
            return response()->json([
                'status'  => false,
                'message' => 'Challan not found.',
            ], 404);
        }
        if ((int) $header->dispatch_status === 3) {
            return response()->json([
                'status'  => false,
                'message' => 'Cancelled challans cannot be edited.',
            ], 409);
        }

        $v = Validator::make($request->all(), [
            'ch_date'            => ['required', 'string'],
            'retailer_id'        => ['required', 'integer'],
            'user_id'            => ['nullable', 'integer'],
            'ch_no'              => ['nullable', 'string', 'max:64'],
            'book_status'        => ['nullable', 'in:0,1'],
            'crdr'               => ['nullable', 'in:0,1,2'],
            'dis'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty'        => ['required', 'numeric', 'gt:0'],
            'items.*.rate'       => ['required', 'numeric', 'gt:0'],
        ]);
        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $chDate = $this->parseDate($request->input('ch_date'));
        if (!$chDate) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid ch_date.',
            ], 422);
        }

        $retailerId = (int) $request->input('retailer_id');
        $userId     = (int)  ($request->input('user_id') ?: $dpId);
        $bookStatus = (int)  $request->input('book_status', 0);
        $crdrStatus = (int)  $request->input('crdr', 0);
        $discPer    = (float) ($request->input('dis') ?: 0);
        $chNo       = (string) $request->input('ch_no', $header->ch_no);

        DB::beginTransaction();
        try {
            // ── Restore old stock
            $oldLines = DB::select(
                "SELECT product_id, batch_no, mfg_date, mrp, qty, free_qty
                   FROM challan_order_details
                  WHERE ch_id = ?",
                [$id]
            );
            foreach ($oldLines as $l) {
                $this->restoreStock(
                    $dealerId,
                    (int) $l->product_id,
                    (float) $l->mrp,
                    (string) $l->batch_no,
                    (string) $l->mfg_date,
                    (float) $l->qty + (float) $l->free_qty
                );
            }

            // ── Delete old lines
            DB::delete("DELETE FROM challan_order_details WHERE ch_id = ?", [$id]);

            // ── Rebuild lines (same rules as store)
            $amount = 0.0;
            $lines  = [];
            foreach ($request->input('items') as $it) {
                $productId = (int)   $it['product_id'];
                $qty       = (float) $it['qty'];
                $freeQty   = (float) ($it['free_qty'] ?? 0);
                $rate      = (float) $it['rate'];
                $mrp       = (float) ($it['mrp'] ?? 0);
                $batchNo   = (string) ($it['batch_no'] ?? '');
                $mfgDate   = $this->parseDate($it['mfg_date'] ?? null) ?: '1970-01-01';

                $vat    = (float) ($it['vat']      ?? 0);
                $cgst   = (float) ($it['cgst']     ?? 0);
                $tax    = $vat > 0 ? $vat : ($cgst * 2);
                $vatAmt = (float) ($it['vat_amt']  ?? 0);
                $cgstAmt = (float) ($it['cgst_amt'] ?? 0);
                if ($vatAmt == 0) $vatAmt = $cgstAmt * 2;

                $gross = (float) (
                    $it['amount'] ?? $it['taxable_amt'] ?? round($qty * $rate + $vatAmt, 2)
                );

                $lines[] = [
                    'product_id'    => $productId,
                    'qty'           => $qty,
                    'free_qty'      => $freeQty,
                    'rate'          => $rate,
                    'mrp'           => $mrp,
                    'batch_no'      => $batchNo,
                    'mfg_date'      => $mfgDate,
                    'tax'           => $tax,
                    'vat_amt'       => $vatAmt,
                    'cd'            => (float) ($it['cd']             ?? 0),
                    'cd_type'       => (int)   ($it['cd_type']        ?? 1),
                    'cd_amt'        => (float) ($it['cd_amt']         ?? 0),
                    'spl_disc_type' => (int)   ($it['spl_disc_type']  ?? 1),
                    'spl_disc_val'  => (float) ($it['spl_disc_val']   ?? 0),
                    'spl_disc_amt'  => (float) ($it['spl_disc_amt']   ?? 0),
                    'dis_type'      => (int)   ($it['trade_disc_type'] ?? 1),
                    'dis_amt'       => (float) ($it['trade_disc_amt'] ?? 0),
                    'dis_percent'   => (float) ($it['trade_disc_val'] ?? 0),
                    'cash_amt'      => (float) ($it['cash_amt']       ?? 0),
                    'taxable_amt'   => $gross,
                    'remain_amount' => $gross,
                    'supply_status' => (int)   ($it['bos']            ?? 0),
                    'order_id'      => (string)($it['order_id']       ?? ''),
                    'user_id'       => (int)   ($it['user_id']        ?? $userId),
                ];
                $amount += $gross;
            }

            if (empty($lines)) {
                throw new \RuntimeException('No valid items were provided.');
            }

            $discAmt = round($amount * $discPer / 100, 2);
            $final   = round($amount - $discAmt, 2);

            // ── Insert new lines + decrement stock
            foreach ($lines as $l) {
                DB::insert(
                    "INSERT INTO challan_order_details
                        (ch_id, product_id, supply_status, qty, product_rate,
                         free_qty, tax, mrp, cd, cd_type, cd_amt,
                         spl_disc_type, spl_disc_val, spl_disc_amt,
                         batch_no, mfg_date, dis_type, dis_amt, dis_percent,
                         taxable_amt, remain_amount, vat_amt, cash_amt,
                         order_id, user_id, sync_status)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                    [
                        $id, $l['product_id'], $l['supply_status'], $l['qty'], $l['rate'],
                        $l['free_qty'], $l['tax'], $l['mrp'], $l['cd'], $l['cd_type'], $l['cd_amt'],
                        $l['spl_disc_type'], $l['spl_disc_val'], $l['spl_disc_amt'],
                        $l['batch_no'], $l['mfg_date'], $l['dis_type'], $l['dis_amt'], $l['dis_percent'],
                        $l['taxable_amt'], $l['remain_amount'], $l['vat_amt'], $l['cash_amt'],
                        $l['order_id'], $l['user_id'],
                    ]
                );

                $this->decrementStock(
                    $dealerId,
                    $l['product_id'],
                    $l['mrp'],
                    $l['batch_no'],
                    $l['mfg_date'],
                    $l['qty'] + $l['free_qty']
                );
            }

            // ── Update header
            DB::update(
                "UPDATE challan_order
                    SET ch_no          = ?,
                        ch_retailer_id = ?,
                        ch_user_id     = ?,
                        ch_date        = ?,
                        book_status    = ?,
                        crdr_status    = ?,
                        discount_per   = ?,
                        discount_amt   = ?,
                        amount         = ?,
                        remaining      = ?,
                        amount_round   = ?
                  WHERE id = ? AND ch_dealer_id = ?",
                [
                    $chNo, $retailerId, $userId, $chDate,
                    $bookStatus, $crdrStatus, $discPer, $discAmt,
                    $final, $final, $final,
                    $id, $dealerId,
                ]
            );

            DB::commit();

            return response()->json([
                'status'       => true,
                'message'      => 'Challan updated.',
                'challan_id'   => $id,
                'amount'       => $final,
                'discount_amt' => $discAmt,
                'items_count'  => count($lines),
            ]);

        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => false,
                'message' => 'Challan update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  CANCEL
     |  POST /api/direct-challans/{id}/cancel
     |
     |  Not present in the legacy class (it only had a batch
     |  cancel on user_sales_order), but required for the API
     |  to be usable. Restores stock + mirrors header into
     |  challan_order_cancel + marks challan void.
     ========================================================= */
    public function cancel(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;
        $reason   = (string) $request->input('reason', 'No reason given');

        $header = DB::selectOne(
            "SELECT id, ch_no, dispatch_status FROM challan_order
              WHERE id = ? AND ch_dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$header) {
            return response()->json([
                'status'  => false,
                'message' => 'Challan not found.',
            ], 404);
        }
        if ((int) $header->dispatch_status === 3) {
            return response()->json([
                'status'  => false,
                'message' => 'Challan is already cancelled.',
            ], 409);
        }

        DB::beginTransaction();
        try {
            $lines = DB::select(
                "SELECT product_id, batch_no, mfg_date, mrp, qty, free_qty, order_id
                   FROM challan_order_details
                  WHERE ch_id = ?",
                [$id]
            );

            foreach ($lines as $l) {
                $this->restoreStock(
                    $dealerId,
                    (int) $l->product_id,
                    (float) $l->mrp,
                    (string) $l->batch_no,
                    (string) $l->mfg_date,
                    (float) $l->qty + (float) $l->free_qty
                );

                // Reopen any sales-order line that this challan had consumed
                if ($l->order_id) {
                    DB::update(
                        "UPDATE user_sales_order_details
                            SET status = 0
                          WHERE order_id = ? AND product_id = ?",
                        [$l->order_id, $l->product_id]
                    );
                }
            }

            DB::insert(
                "INSERT INTO challan_order_cancel
                    (challan_id, ch_no, dealer_id, cancel_reason,
                     cancelled_by, cancelled_date)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$id, $header->ch_no, $dealerId, $reason, (int) $user->getKey()]
            );

            DB::update(
                "UPDATE challan_order
                    SET dispatch_status = 3,
                        payment_status  = '0'
                  WHERE id = ? AND ch_dealer_id = ?",
                [$id, $dealerId]
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Challan cancelled.',
                'ch_id'   => $id,
                'ch_no'   => $header->ch_no,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'status'  => false,
                'message' => 'Cancel failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  AJAX helpers the form JS uses
     |  (collapsed into two endpoints instead of four)
     ========================================================= */

    /** GET /api/direct-challans/retailers/{id}/location */
    public function retailerLocation(Request $request, $id): JsonResponse
    {
        $dealerId = (int) $request->user()->dealer_id;

        $row = DB::selectOne(
            "SELECT r.location_id
               FROM retailer r
               INNER JOIN dealer_location_rate_list dlrl
                       ON dlrl.location_id = r.location_id
              WHERE r.id = ? AND dlrl.dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        return response()->json([
            'status'      => (bool) $row,
            'location_id' => (int) ($row->location_id ?? 0),
        ]);
    }

    /**
     * GET /api/direct-challans/products/{id}/info?retailer_id=…
     * Returns: mrp options (with batch/mfg/stock), rate, gst, and
     * total available stock for the product.
     */
    public function productInfo(Request $request, $id): JsonResponse
{
    try {
        $user     = $request->user();
        $dealerId = (int) ($user?->dealer_id ?? 0);
        $stateId  = (int) ($user?->state_id ?? 0);

        $product = DB::selectOne(
            "SELECT cp.id, cp.name, cp.hsn_code, cp.gst_percent, cp.quantity_per_case
               FROM catalog_product cp
              WHERE cp.id = ? LIMIT 1",
            [$id]
        );
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        }

        $mrps = DB::select(
            "SELECT CAST(mrp AS DECIMAL(10,2)) AS mrp, batch_no, mfg,
                    SUM(qty) AS available_qty
               FROM stock
              WHERE product_id = ? AND dealer_id = ? AND qty > 0
              GROUP BY mrp, batch_no, mfg
              ORDER BY mrp ASC",
            [$id, $dealerId]
        );

        // ── rate/tax lookup is the fragile part — wrap it
        $rate = null;
        try {
            $rate = DB::selectOne(
                "SELECT rate, tax
                   FROM catalog_product_rate_list
                  WHERE catalog_product_id = ? AND stateId = ?
                  LIMIT 1",
                [$id, $stateId]
            );
        } catch (\Throwable $e) {
            report($e);   // log it, don't fail the whole endpoint
        }

        return response()->json([
            'status'  => true,
            'product' => $product,
            'mrps'    => $mrps,
            'rate'    => (float) ($rate->rate ?? $product->base_price ?? 0),
            'tax'     => (float) ($rate->tax  ?? $product->gst_percent ?? 0),
            'stock'   => (float) array_sum(array_map(fn ($r) => (float) $r->available_qty, $mrps)),
        ]);
    } catch (\Throwable $e) {
        report($e);
        return response()->json([
            'status'  => false,
            'message' => 'productInfo failed: ' . $e->getMessage(),
            'file'    => basename($e->getFile()) . ':' . $e->getLine(),
        ], 500);
    }
}
    /* =========================================================
     |  HELPERS
     ========================================================= */

    /**
     * Legacy ch_no format: BDN/{dealer_id}/{session_year}/{000001}
     */
    private function generateLegacyChNo(int $dealerId): string
    {
        $session = DB::selectOne(
            "SELECT session FROM session WHERE action = '1' LIMIT 1"
        );
        $year = $session->session ?? (date('Y') . '-' . (date('Y') + 1));

        $last = DB::selectOne(
            "SELECT ch_no FROM challan_order
              WHERE ch_dealer_id = ?
                AND book_status = '0'
                AND ch_no LIKE ?
              ORDER BY id DESC
              LIMIT 1
              FOR UPDATE",
            [$dealerId, "BDN/{$dealerId}/{$year}/%"]
        );

        $next = 1;
        if ($last && $last->ch_no) {
            $parts = explode('/', $last->ch_no);
            $tail  = end($parts);
            if (is_numeric($tail)) $next = ((int) $tail) + 1;
        }

        return sprintf('BDN/%d/%s/%06d', $dealerId, $year, $next);
    }

    /** Legacy id = dealer_id + date('ymdHis') with collision probe */
    private function allocateChallanId(int $dealerId): int
    {
        $base = (int) ($dealerId . date('ymdHis'));
        for ($i = 0; $i < 100; $i++) {
            $candidate = $base + $i;
            $exists = DB::selectOne(
                "SELECT 1 AS x FROM challan_order WHERE id = ? LIMIT 1",
                [$candidate]
            );
            if (!$exists) return $candidate;
        }
        throw new \RuntimeException('Could not allocate unique challan id.');
    }

    /**
     * Reduce stock.remaining / stock.qty per batch row, FIFO on mfg.
     * Transaction-safe: throws on insufficient stock.
     */
    private function decrementStock(
        int $dealerId, int $productId, float $mrp,
        string $batchNo, string $mfgDate, float $qty
    ): void {
        if ($qty <= 0) return;

        $rows = DB::select(
            "SELECT id, remaining FROM stock
              WHERE product_id = ?
                AND dealer_id  = ?
                AND mrp        = ?
                AND batch_no   = ?
                AND mfg        = ?
              ORDER BY mfg ASC
              FOR UPDATE",
            [$productId, $dealerId, $mrp, $batchNo, $mfgDate]
        );

        $need = $qty;
        foreach ($rows as $row) {
            if ($need <= 0) break;
            $take = min($need, (float) $row->remaining);
            if ($take <= 0) continue;
            DB::update(
                "UPDATE stock
                    SET remaining = remaining - ?,
                        qty       = qty       - ?
                  WHERE id = ?",
                [$take, $take, $row->id]
            );
            $need -= $take;
        }

        if ($need > 0) {
            throw new \RuntimeException(
                "Insufficient stock for product {$productId} batch {$batchNo}."
            );
        }
    }

    /** Restore stock (opposite of decrementStock, used by update/cancel). */
    private function restoreStock(
        int $dealerId, int $productId, float $mrp,
        string $batchNo, string $mfgDate, float $qty
    ): void {
        if ($qty <= 0) return;

        $affected = DB::update(
            "UPDATE stock
                SET remaining = remaining + ?,
                    qty       = qty       + ?
              WHERE product_id = ?
                AND dealer_id  = ?
                AND mrp        = ?
                AND batch_no   = ?
                AND mfg        = ?",
            [$qty, $qty, $productId, $dealerId, $mrp, $batchNo, $mfgDate]
        );

        if ($affected === 0) {
            // Row no longer exists (edge case) — re-create a stock row.
            DB::insert(
                "INSERT INTO stock
                    (product_id, dealer_id, mrp, batch_no, mfg, qty, remaining)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$productId, $dealerId, $mrp, $batchNo, $mfgDate, $qty, $qty]
            );
        }
    }

    /** Accepts d/m/Y, d-m-Y, Y-m-d, d/M/Y. */
    private function parseDate($value): ?string
    {
        if (empty($value)) return null;
        $value = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/M/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) return $dt->format('Y-m-d');
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : false;
    }
}