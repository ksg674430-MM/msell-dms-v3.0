<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\InsufficientStockException;
use App\Services\InvoiceService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChallanController extends Controller
{
    /* =========================================================
     |  LIST
     |  GET /api/challans
     |  Query: from_date, to_date, ch_no, retailer_id, page
     | ========================================================= */
    // public function index(Request $request): JsonResponse
    // {
        // $user     = $request->user();
        // $dealerId = (int) $user->dealer_id;

        // $where    = ["co.ch_dealer_id = ?"];
        // $bindings = [$dealerId];

        // if ($from = $request->input('from_date')) {
            // $where[]    = "DATE(co.ch_date) >= ?";
            // $bindings[] = $this->parseDate($from) ?: $from;
        // }
        // if ($to = $request->input('to_date')) {
            // $where[]    = "DATE(co.ch_date) <= ?";
            // $bindings[] = $this->parseDate($to) ?: $to;
        // }
        // if ($chNo = $request->input('ch_no')) {
            // $where[]    = "co.ch_no LIKE ?";
            // $bindings[] = "%{$chNo}%";
        // }
        // if ($retailerId = $request->input('retailer_id')) {
            // $where[]    = "co.ch_retailer_id = ?";
            // $bindings[] = (int) $retailerId;
        // }

        // $whereSql = implode(' AND ', $where);

        // $rows = DB::select(
            // "SELECT co.auto, co.id, co.ch_no, co.ch_created_by, co.ch_dealer_id,
                    // co.ch_retailer_id, co.ch_date, co.company_id, co.dispatch_status,
                    // co.sesId, co.remark, co.invoice_type, co.payment_status,
                    // co.discount_per, co.discount_amt, co.amount, co.remaining,
                    // r.name AS retailer_name,
                    // d.name AS dealer_name
               // FROM challan_order co
               // LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               // LEFT JOIN dealer   d ON d.id = co.ch_dealer_id
              // WHERE {$whereSql}
              // ORDER BY co.auto DESC
              // LIMIT 200",
            // $bindings
        // );

        // return response()->json([
            // 'status' => true,
            // 'rows'   => $rows,
        // ]);
    // }

    /* =========================================================
     |  SHOW ONE
     |  GET /api/challans/{id}
     | ========================================================= */
    // public function show(Request $request, $id): JsonResponse
    // {
        // $user     = $request->user();
        // $dealerId = (int) $user->dealer_id;

        // $header = DB::selectOne(
            // "SELECT co.*, r.name AS retailer_name, d.name AS dealer_name
               // FROM challan_order co
               // LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               // LEFT JOIN dealer   d ON d.id = co.ch_dealer_id
              // WHERE co.id = ? AND co.ch_dealer_id = ?
              // LIMIT 1",
            // [$id, $dealerId]
        // );

        // if (!$header) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Challan not found.',
            // ], 404);
        // }

        // // HSN is stored per line; fall back to catalog_product when blank
        // $items = DB::select(
            // "SELECT cod.*,
                    // COALESCE(cp.name, cv.product_name)  AS product_name,
                    // COALESCE(cp.itemcode, cv.itemcode)  AS itemcode,
                    // cp.quantity_per_case,
                    // COALESCE(cod.hsn_code, cp.hsn_code) AS hsn_code_resolved
               // FROM challan_order_details cod
               // LEFT JOIN catalog_product cp ON cp.id = cod.product_id
               // LEFT JOIN catalog_view    cv ON cv.product_id = cod.product_id
              // WHERE cod.ch_id = ?
              // ORDER BY cod.id ASC",
            // [$id]
        // );

        // return response()->json([
            // 'status' => true,
            // 'header' => $header,
            // 'items'  => $items,
        // ]);
    // }

    /* =========================================================
     |  NEXT NUMBER
     |  GET /api/challans/next-number
     | ========================================================= */
    public function nextNumber(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        return response()->json([
            'status'    => true,
            'ch_no'     => $this->generateChNo($dpId, $dealerId, $companyId),
            'dpId'      => $dpId,
            'dealer_id' => $dealerId,
            'company_id'=> $companyId,
        ]);
    }

    /* =========================================================
     |  FORM BOOTSTRAP
     |  GET /api/challans/form-data
     | ========================================================= */
    public function formData(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;
        $stateId   = (int) $user->state_id;
        $roleName  = $this->resolveRoleName($user->role_id);

        // ---- Retailers (DISTRIBUTOR)
        $retailers = DB::select(
            "SELECT r.id, CONCAT_WS(' ', r.name, '[', l7.name, ']') AS name
               FROM retailer r
               INNER JOIN location_7 l7 ON l7.id = r.location_id
              WHERE r.dealer_id = ? AND r.retailer_status = '1'
              GROUP BY r.id
              ORDER BY r.name ASC",
            [$dealerId]
        );

        // ---- Beats (field force) — DISTRIBUTOR only
        $beats = [];
        if ($roleName === 'DISTRIBUTOR') {
            $beats = DB::select(
                "SELECT DISTINCT d.user_id AS id,
                        CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name,
                                  '[', rl.rolename, ']') AS name
                   FROM dealer_location_rate_list d
                   INNER JOIN person       p  ON p.id  = d.user_id
                   INNER JOIN person_login pl ON pl.person_id = d.user_id
                   INNER JOIN _role        rl ON rl.role_id   = p.role_id
                  WHERE pl.person_status = 1
                    AND d.dealer_id = ?
                  ORDER BY p.first_name",
                [$dealerId]
            );
        }

        $beatList = DB::select(
    "SELECT DISTINCT l7.id, l7.name
       FROM location_7 l7
       INNER JOIN dealer_location_rate_list r ON r.location_id = l7.id
      WHERE r.dealer_id = ?
      ORDER BY l7.name",
    [$dealerId]
);
        $products = DB::select(
            "SELECT cp.id,
                    CONCAT(cp.name, '[', cp.quantity_per_case, ']') AS name,
                    cp.itemcode,
                    cp.quantity_per_case,
                    cp.hsn_code,
                    SUM(s.qty) AS available_stock
               FROM catalog_product cp
               INNER JOIN stock s ON s.product_id = cp.id AND s.dealer_id = ? AND s.qty > 0
              WHERE cp.company_id = ?
              GROUP BY cp.id
              ORDER BY cp.id",
            [$dealerId, $companyId]
        );

        // ---- Session year
        $sessionRow  = DB::selectOne("SELECT * FROM session WHERE action = '1' LIMIT 1");
        $sessionYear = DB::selectOne("SELECT * FROM session_year ORDER BY sesId DESC LIMIT 1");

        // ---- State — PK is `stateid`
        $stateRow = DB::selectOne(
            "SELECT * FROM state WHERE stateid = ? LIMIT 1",
            [$stateId]
        );

        return response()->json([
            'status'       => true,
            'role'         => $roleName,
            'retailers'    => $retailers,
            'field_force_name'        => $beats,
            'products'     => $products,
            'session'      => $sessionRow,
            'session_year' => $sessionYear,
            'state'        => $stateRow,
            'next_ch_no'   => $this->generateChNo($dpId, $dealerId, $companyId),
            'dealer_id'    => $dealerId,
            'company_id'   => $companyId,
            'state_id'     => $stateId,
            'dp_id'        => $dpId,
            'beats'=>$beatList,
        ]);
    }

    /* =========================================================
     |  SAVE
     |  POST /api/challans
     |
     |  Accepts both d/m/Y and Y-m-d for ch_date / mfg_date.
     |  Derived fields (vat_amt, taxable_amt, amount) are
     |  optional — server computes when missing.
     | ========================================================= */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $v = Validator::make($request->all(), [
            'ch_date'                     => ['required', 'string'],
            'retailer_id'                 => ['required', 'integer'],
            'remark'                      => ['nullable', 'string', 'max:255'],
            'crdr_status'                 => ['nullable', 'in:0,1,2'],
            'user_id'                     => ['nullable', 'integer'],
            'discount_per'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.product_id'          => ['required', 'integer'],
            'items.*.qty'                 => ['required', 'numeric', 'min:0.01'],
            'items.*.rate'                => ['required', 'numeric', 'gt:0'],
            'items.*.mrp'                 => ['nullable', 'numeric'],
            'items.*.vat'                 => ['nullable', 'numeric'],
            'items.*.vat_amt'             => ['nullable', 'numeric'],
            'items.*.taxable_amt'         => ['nullable', 'numeric'],
            'items.*.amount'              => ['nullable', 'numeric', 'gt:0'],
        ], [
            'items.*.rate.gt'   => 'Every item needs a rate above 0.',
            'items.*.amount.gt' => 'Every item needs an amount above 0. A 0 amount invoice cannot be saved.',
        ]);
        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        // Normalize date
        $chDate = $this->parseDate($request->input('ch_date'));
        if (!$chDate) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid ch_date. Use dd/mm/yyyy or yyyy-mm-dd.',
            ], 422);
        }

        $retailerId = (int) $request->input('retailer_id');

        // Retailer must belong to this dealer
        $retailerOk = DB::selectOne(
            "SELECT id FROM retailer WHERE id = ? AND dealer_id = ? LIMIT 1",
            [$retailerId, $dealerId]
        );
        if (!$retailerOk) {
            return response()->json([
                'status'  => false,
                'message' => "Retailer {$retailerId} not found for this dealer.",
            ], 404);
        }

        if ($creditBlock = app(InvoiceService::class)->creditBlock($dealerId, (string) $retailerId)) {
            return response()->json(['status' => false, 'message' => $creditBlock], 422);
        }

        $items       = $request->input('items');
        $discountPer = (float) ($request->input('discount_per') ?? 0);
        $remark      = (string) $request->input('remark', '');

        // Session year
        $sessionYear = DB::selectOne("SELECT sesId FROM session_year ORDER BY sesId DESC LIMIT 1");
        $sesId       = (int) ($sessionYear->sesId ?? 0);

        // Allocate a unique challan id
        $chId = null;
        $baseId = (int) ($dealerId . date('ymdHis'));
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $baseId + $attempt;
            $exists = DB::selectOne(
                "SELECT 1 AS x FROM challan_order WHERE id = ? LIMIT 1",
                [$candidate]
            );
            if (!$exists) {
                $chId = $candidate;
                break;
            }
        }
        if ($chId === null) {
            return response()->json([
                'status'  => false,
                'message' => 'Could not allocate a unique challan id.',
            ], 500);
        }

        $chNo = $this->generateChNo($dpId, $dealerId, $companyId);

        DB::beginTransaction();
        try {
            // ── Recompute line totals server-side
            $normalizedItems = [];
            $subTotal = 0.0;
            foreach ($items as $it) {
                $productId = (int)   $it['product_id'];

                // FIX: ensure product belongs to this company and is active.
                $this->assertProductForCompany($productId, $companyId);

                $qty       = (float) $it['qty'];
                $rate      = (float) $it['rate'];
                $mrp       = (float) ($it['mrp']         ?? 0);
                $freeQty   = (float) ($it['free_qty']    ?? 0);
                $tax       = (float) ($it['vat']         ?? 18);
                $cd        = (float) ($it['cd']          ?? 0);
                $cdType    = (int)   ($it['cd_type']     ?? 1);
                // FIX: previously `cd_amt` was ignored and 0.0 was hardcoded
                // into the insert. Now we honour the client value.
                $cdAmt     = (float) ($it['cd_amt']      ?? 0);
                $disType   = (int)   ($it['dis_type']    ?? 1);
                $disAmt    = (float) ($it['dis_amt']     ?? 0);
                $disPct    = (float) ($it['dis_percent'] ?? 0);
                $splDiscAmt = (float) ($it['spl_disc_amt'] ?? 0);
                $cashAmt   = (float) ($it['cash_amt']    ?? 0);
                $batchNo   = (string)($it['batch_no']    ?? '');
                $mfgDate   = $this->parseDate($it['mfg_date'] ?? null) ?: '1970-01-01';
                $orderId   = (string)($it['order_id']    ?? '');
                $userId    = (int)   ($it['user_id']     ?? 0);

                $gross    = round($qty * $rate, 2);
                $taxable  = isset($it['taxable_amt']) && $it['taxable_amt'] !== null
                    ? (float) $it['taxable_amt']
                    : round($gross - $cdAmt - $disAmt - $splDiscAmt - $cashAmt, 2);
                $vatAmt   = isset($it['vat_amt']) && $it['vat_amt'] !== null
                    ? (float) $it['vat_amt']
                    : round($taxable * $tax / 100, 2);
                $amount   = round($taxable + $vatAmt, 2);

                // Product name + HSN lookup (single query)
                $cp = DB::selectOne(
                    "SELECT name, hsn_code FROM catalog_product WHERE id = ? LIMIT 1",
                    [$productId]
                );
                $productName = (string) ($cp->name ?? ('Product #' . $productId));

                $hsn = (string) ($it['hsn_code'] ?? '');
                if ($hsn === '') {
                    $hsn = (string) ($cp->hsn_code ?? '');
                }

                $normalizedItems[] = compact(
                    'productId', 'productName', 'qty', 'rate', 'mrp', 'freeQty', 'tax', 'vatAmt',
                    'taxable', 'amount', 'batchNo', 'mfgDate', 'hsn',
                    'cd', 'cdType', 'cdAmt', 'disType', 'disAmt', 'disPct',
                    'splDiscAmt', 'cashAmt', 'orderId', 'userId'
                );
                $subTotal += $amount;
            }

            $discountAmt = round($subTotal * $discountPer / 100, 2);
            $finalAmount = round($subTotal - $discountAmt, 2);
            $amountRound = round($finalAmount);

            // ── Header
            // FIX: added book_status = 0 so the challan participates in
            // generateChNo()'s `book_status = '0'` lookup.
            DB::insert(
                "INSERT INTO challan_order
                    (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id, ch_user_id,
                     ch_date, date_added, company_id, sesId,
                     invoice_type, remark, book_status, crdr_status,
                     dispatch_status, payment_status, sync_status,
                     isclaim, istarget_claim,
                     discount_per, discount_amt, amount, remaining, amount_round)
                 VALUES
                    (?, ?, ?, ?, ?, ?,
                     ?, NOW(), ?, ?,
                     '1', ?, 0, ?,
                     0, '0', 1,
                     0, 0,
                     ?, ?, ?, ?, ?)",
                [
                    $chId, $chNo, $dpId, $dealerId, $retailerId, (int) $request->input('user_id', 0),
                    $chDate, $companyId, $sesId,
                    $remark, (int) $request->input('crdr_status', 0),
                    $discountPer, $discountAmt, $finalAmount, $finalAmount, $amountRound,
                ]
            );

            // ── Lines
            foreach ($normalizedItems as $l) {
                // FIX: use $l['cdAmt'] and $l['amount'] instead of hardcoded 0.0
                // and taxable. reduceStock now throws on insufficient stock,
                // which triggers the outer rollback.
                DB::insert(
                    "INSERT INTO challan_order_details
                        (ch_id, product_id, hsn_code, catalog_details_id,
                         batch_no, mfg_date, tax, vat_amt, qty, product_rate,
                         free_qty, order_id, user_id, mrp,
                         cd, cd_type, cd_amt,
                         spl_disc_type, spl_disc_val, spl_disc_amt, cash_amt,
                         dis_type, dis_amt, dis_percent,
                         taxable_amt, remain_amount, sale_order_id, sync_status)
                     VALUES
                        (?, ?, ?, 0,
                         ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         ?, ?, ?,
                         0, 0, ?, ?,
                         ?, ?, ?,
                         ?, ?, '', 1)",
                    [
                        $chId, $l['productId'], $l['hsn'],
                        $l['batchNo'], $l['mfgDate'], $l['tax'], $l['vatAmt'],
                        $l['qty'], $l['rate'], $l['freeQty'],
                        $l['orderId'], $l['userId'], $l['mrp'],
                        $l['cd'], $l['cdType'], $l['cdAmt'],
                        $l['splDiscAmt'], $l['cashAmt'],
                        $l['disType'], $l['disAmt'], $l['disPct'],
                        $l['amount'], $l['amount'],
                    ]
                );

            }

            app(StockService::class)->sync($dealerId, [], array_map(fn ($l) => [
                'product_id' => $l['productId'],
                'batch_no'   => $l['batchNo'],
                'mfg_date'   => $l['mfgDate'],
                'mrp'        => $l['mrp'],
                'qty'        => $l['qty'] + $l['freeQty'],
                'name'       => $l['productName'],
            ], $normalizedItems));

            $invoicedByOrder = [];
            foreach ($normalizedItems as $l) {
                if ($l['orderId'] !== '') {
                    $invoicedByOrder[$l['orderId']][$l['productId']] = ($invoicedByOrder[$l['orderId']][$l['productId']] ?? 0) + $l['qty'];
                }
            }
            foreach ($invoicedByOrder as $sfaOrderId => $invoiced) {
                app(InvoiceService::class)->fulfilSalesOrder($dealerId, (string) $sfaOrderId, $invoiced);
            }

            DB::commit();

            return response()->json([
                'status'       => true,
                'message'      => 'Challan saved successfully.',
                'challan_id'   => $chId,
                'ch_no'        => $chNo,
                'amount'       => $finalAmount,
                'amount_round' => $amountRound,
                'discount_amt' => $discountAmt,
                'items_count'  => count($normalizedItems),
            ], 201);

        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => safe_error_message($e),
            ], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Challan could not be saved: ' . safe_error_message($e),
            ], 500);
        }
    }

    /* =========================================================
     |  FROM ORDERS
     |  POST /api/challans/from-orders
     |  Builds challans from one or more sales orders.
     | ========================================================= */
    // public function fromOrders(Request $request): JsonResponse
    // {
        // $v = Validator::make($request->all(), [
            // 'ch_date'                     => ['required', 'string'],
            // 'discount_per'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 'orders'                      => ['required', 'array', 'min:1'],
            // 'orders.*.order_id'           => ['required', 'string'],
            // 'orders.*.items'              => ['required', 'array', 'min:1'],
            // 'orders.*.items.*.product_id' => ['required', 'integer'],
            // 'orders.*.items.*.qty'        => ['required', 'numeric', 'min:0.01'],
            // 'orders.*.items.*.rate'       => ['required', 'numeric', 'min:0'],
        // ]);
        // if ($v->fails()) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Validation failed.',
                // 'errors'  => $v->errors(),
            // ], 422);
        // }

        // $user      = $request->user();
        // $dpId      = (int) $user->getKey();
        // $dealerId  = (int) $user->dealer_id;
        // $companyId = (int) $user->company_id;

        // $chDate = $this->parseDate($request->input('ch_date'));
        // if (!$chDate) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Invalid ch_date. Use dd/mm/yyyy or yyyy-mm-dd.',
            // ], 422);
        // }

        // $discountPer = (float) $request->input('discount_per', 0);

        // $sessionYear = DB::selectOne("SELECT sesId FROM session_year ORDER BY sesId DESC LIMIT 1");
        // $sesId       = (int) ($sessionYear->sesId ?? 0);

        // $created = [];
        // $failed  = [];

        // foreach ($request->input('orders') as $order) {
            // try {
                // $result = DB::transaction(function () use (
                    // $order, $chDate, $discountPer, $sesId,
                    // $dealerId, $dpId, $companyId
                // ) {
                    // $orderId = $order['order_id'];
                    // $items   = $order['items'];

                    // $orderRow = DB::selectOne(
                        // "SELECT * FROM user_sales_order
                          // WHERE order_id = ? AND dealer_id = ?
                          // LIMIT 1",
                        // [$orderId, $dealerId]
                    // );
                    // if (!$orderRow) {
                        // throw new \RuntimeException("Order {$orderId} not found.");
                    // }

                    // // FIX: reject already-processed orders so the same order
                    // // cannot be turned into two challans.
                    // if ((string) $orderRow->order_status === '1') {
                        // throw new \RuntimeException("Order {$orderId} is already processed.");
                    // }

                    // if ($creditBlock = app(InvoiceService::class)->creditBlock($dealerId, (string) $orderRow->retailer_id)) {
                        // throw new \RuntimeException("Order {$orderId}: {$creditBlock}");
                    // }

                    // // Allocate unique challan id
                    // $chId = null;
                    // $baseId = (int) ($dealerId . date('ymdHis'));
                    // for ($attempt = 0; $attempt < 50; $attempt++) {
                        // $candidate = $baseId + $attempt;
                        // $exists = DB::selectOne(
                            // "SELECT 1 AS x FROM challan_order WHERE id = ? LIMIT 1",
                            // [$candidate]
                        // );
                        // if (!$exists) { $chId = $candidate; break; }
                    // }
                    // if ($chId === null) {
                        // throw new \RuntimeException('Could not allocate unique challan id.');
                    // }

                    // $chNo = $this->generateChNo($dpId, $dealerId, $companyId);

                    // $subTotal   = 0.0;
                    // $orderLines = [];

                    // foreach ($items as $it) {
                        // $productId = (int)   $it['product_id'];

                        // // FIX: validate product belongs to this company.
                        // $this->assertProductForCompany($productId, $companyId);

                        // $qty       = (float) $it['qty'];
                        // $schQty    = (float) ($it['scheme_qty'] ?? $it['free_qty'] ?? 0);
                        // $rate      = (float) $it['rate'];
                        // $mrp       = (float) ($it['mrp']         ?? 0);
                        // $tax       = (float) ($it['vat']         ?? 18);
                        // $taxable   = (float) ($it['taxable_amt'] ?? ($qty * $rate));
                        // $vatAmt    = (float) ($it['vat_amt']     ?? round($taxable * $tax / 100, 2));
                        // $amount    = round($taxable + $vatAmt, 2);
                        // $batchNo   = (string)($it['batch_no']    ?? '');
                        // $mfgDate   = $this->parseDate($it['mfg_date'] ?? null) ?: '1970-01-01';

                        // // HSN — critical, otherwise IRN will fail on this challan
                        // $hsn = (string) ($it['hsn_code'] ?? '');
                        // if ($hsn === '') {
                            // $cp  = DB::selectOne(
                                // "SELECT hsn_code FROM catalog_product WHERE id = ? LIMIT 1",
                                // [$productId]
                            // );
                            // $hsn = (string) ($cp->hsn_code ?? '');
                        // }

                        // if ($batchNo !== '') {
                            // app(StockService::class)->take($dealerId, $productId, $batchNo, $mfgDate, $mrp, $qty + $schQty);
                        // } else {
                            // app(StockService::class)->takeFifo($dealerId, $productId, $qty + $schQty);
                        // }

                        // DB::update(
                            // "UPDATE user_sales_order_details
                                // SET status = 2
                              // WHERE order_id = ? AND product_id = ?",
                            // [$orderId, $productId]
                        // );

                        // $orderLines[] = compact(
                            // 'productId', 'qty', 'schQty', 'rate', 'mrp',
                            // 'tax', 'vatAmt', 'taxable', 'amount',
                            // 'batchNo', 'mfgDate', 'hsn'
                        // );
                        // $subTotal += $amount;
                    // }

                    // $discAmt     = round($subTotal * $discountPer / 100, 2);
                    // $finalAmount = round($subTotal - $discAmt, 2);
                    // $amountRound = round($finalAmount);

                    // DB::insert(
                        // "INSERT INTO challan_order
                            // (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id, ch_user_id,
                             // ch_date, date_added, company_id, sesId,
                             // invoice_type, book_status,
                             // dispatch_status, sync_status, payment_status,
                             // remark,
                             // discount_per, discount_amt,
                             // amount, remaining, amount_round)
                         // VALUES
                            // (?, ?, ?, ?, ?, ?,
                             // ?, NOW(), ?, ?,
                             // 1, 0,
                             // 0, 1, '0',
                             // ?,
                             // ?, ?,
                             // ?, ?, ?)",
                        // [
                            // $chId, $chNo, $dpId, $dealerId,
                            // $orderRow->retailer_id, $orderRow->user_id,
                            // $chDate, $companyId, $sesId,
                            // $orderRow->remarks ?? '',
                            // $discountPer, $discAmt,
                            // $finalAmount, $finalAmount, $amountRound,
                        // ]
                    // );

                    // foreach ($orderLines as $l) {
                        // // FIX: remain_amount = line amount (not taxable)
                        // DB::insert(
                            // "INSERT INTO challan_order_details
                                // (ch_id, product_id, hsn_code, supply_status, catalog_details_id,
                                 // batch_no, tax, vat_amt, qty, product_rate, free_qty,
                                 // order_id, user_id, mrp,
                                 // dis_type, dis_amt, dis_percent,
                                 // taxable_amt, remain_amount, mfg_date, sync_status)
                             // VALUES
                                // (?, ?, ?, 0, 0,
                                 // ?, ?, ?, ?, ?, ?,
                                 // ?, ?, ?,
                                 // 1, 0, 0,
                                 // ?, ?, ?, 1)",
                            // [
                                // $chId, $l['productId'], $l['hsn'],
                                // $l['batchNo'], $l['tax'], $l['vatAmt'],
                                // $l['qty'], $l['rate'], $l['schQty'],
                                // $orderId, $orderRow->user_id, $l['mrp'],
                                // $l['amount'], $l['amount'], $l['mfgDate'],
                            // ]
                        // );
                    // }

                    // DB::update(
                        // "UPDATE user_sales_order SET order_status = '1' WHERE order_id = ?",
                        // [$orderId]
                    // );

                    // return [
                        // 'order_id' => $orderId,
                        // 'ch_id'    => $chId,
                        // 'ch_no'    => $chNo,
                        // 'amount'   => $finalAmount,
                    // ];
                // });

                // $created[] = $result;

            // } catch (\Throwable $e) {
                // $failed[] = [
                    // 'order_id' => $order['order_id'] ?? '?',
                    // 'reason'   => $e->getMessage(),
                // ];
            // }
        // }

        // return response()->json([
            // 'status'   => count($failed) === 0,
            // 'message'  => sprintf('%d challan(s) created, %d failed.', count($created), count($failed)),
            // 'challans' => $created,
            // 'failed'   => $failed,
        // ], count($failed) > 0 && count($created) === 0 ? 500 : 200);
    // }

    /* =========================================================
     |  EDIT
     |  PUT /api/challans/{id}
     |
     |  Replaces all lines. Adjusts stock.qty by the delta
     |  between new qty and the original qty (per product), and
     |  restores stock for any product removed entirely.
     | ========================================================= */
    // public function update(Request $request, $id): JsonResponse
    // {
        // $user      = $request->user();
        // $dealerId  = (int) $user->dealer_id;
        // $companyId = (int) $user->company_id;

        // // Load current header (dealer-scoped). Include dispatch_status so we
        // // can refuse to edit cancelled challans.
        // $header = DB::selectOne(
            // "SELECT id, ch_no, ch_dealer_id, ch_retailer_id, ch_date, dispatch_status
               // FROM challan_order
              // WHERE id = ? AND ch_dealer_id = ?
              // LIMIT 1",
            // [$id, $dealerId]
        // );
        // if (!$header) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Challan not found.',
            // ], 404);
        // }

        // if ($lock = app(InvoiceService::class)->reason((string) $header->id, $dealerId)) {
            // return response()->json(['status' => false, 'message' => $lock], 409);
        // }

        // $v = Validator::make($request->all(), [
            // 'ch_date'                     => ['required', 'string'],
            // 'retailer_id'                 => ['required', 'integer'],
            // 'remark'                      => ['nullable', 'string', 'max:255'],
            // 'crdr_status'                 => ['nullable', 'in:0,1,2'],
            // 'discount_per'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            // 'items'                       => ['required', 'array', 'min:1'],
            // 'items.*.product_id'          => ['required', 'integer'],
            // 'items.*.qty'                 => ['required', 'numeric', 'min:0.01'],
            // 'items.*.rate'                => ['required', 'numeric', 'gt:0'],
        // ], [
            // 'items.*.rate.gt' => 'Every item needs a rate above 0.',
        // ]);
        // if ($v->fails()) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Validation failed.',
                // 'errors'  => $v->errors(),
            // ], 422);
        // }

        // $chDate = $this->parseDate($request->input('ch_date'));
        // if (!$chDate) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Invalid ch_date.',
            // ], 422);
        // }

        // // Retailer must belong to this dealer
        // $retailerId = (int) $request->input('retailer_id');
        // $retailerOk = DB::selectOne(
            // "SELECT id FROM retailer WHERE id = ? AND dealer_id = ? LIMIT 1",
            // [$retailerId, $dealerId]
        // );
        // if (!$retailerOk) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => "Retailer {$retailerId} not found for this dealer.",
            // ], 404);
        // }

        // // Load current lines so we can compute stock deltas
        // $oldLines = DB::select(
            // "SELECT id, product_id, qty, free_qty, mrp, batch_no, mfg_date
               // FROM challan_order_details
              // WHERE ch_id = ?",
            // [$id]
        // );

        // $items       = $request->input('items');
        // $discountPer = (float) ($request->input('discount_per') ?? 0);
        // $remark      = (string) $request->input('remark', '');

        // DB::beginTransaction();
        // try {
            // // ── Normalize incoming items (server-side fallbacks)
            // $normalized = [];
            // $subTotal   = 0.0;
            // $stockLines = [];

            // foreach ($items as $it) {
                // $productId  = (int)   $it['product_id'];

                // // FIX: validate product belongs to this company.
                // $this->assertProductForCompany($productId, $companyId);

                // $qty        = (float) $it['qty'];
                // $rate       = (float) $it['rate'];
                // $mrp        = (float) ($it['mrp']         ?? 0);
                // $freeQty    = (float) ($it['free_qty']    ?? $it['scheme'] ?? 0);
                // $tax        = (float) ($it['vat']         ?? 18);
                // $cd         = (float) ($it['cd']          ?? 0);
                // $cdType     = (int)   ($it['cd_type']     ?? 1);
                // $cdAmt      = (float) ($it['cd_amt']      ?? 0);
                // $disType    = (int)   ($it['dis_type']    ?? 1);
                // $disAmt     = (float) ($it['dis_amt']     ?? 0);
                // $disPct     = (float) ($it['dis_percent'] ?? 0);
                // $splDiscAmt = (float) ($it['spl_disc_amt'] ?? 0);
                // $cashAmt    = (float) ($it['cash_amt']    ?? 0);
                // $batchNo    = (string)($it['batch_no']    ?? '');
                // $mfgDate    = $this->parseDate($it['mfg_date'] ?? null) ?: '1970-01-01';
                // $orderId    = (string)($it['order_id']    ?? '');
                // $userId     = (int)   ($it['user_id']     ?? 0);

                // $gross   = round($qty * $rate, 2);
                // $taxable = isset($it['taxable_amt']) && $it['taxable_amt'] !== null
                    // ? (float) $it['taxable_amt']
                    // : round($gross - $cdAmt - $disAmt - $splDiscAmt - $cashAmt, 2);
                // $vatAmt  = isset($it['vat_amt']) && $it['vat_amt'] !== null
                    // ? (float) $it['vat_amt']
                    // : round($taxable * $tax / 100, 2);
                // $amount  = round($taxable + $vatAmt, 2);

                // // HSN
                // $hsn = (string) ($it['hsn_code'] ?? '');
                // if ($hsn === '') {
                    // $cp  = DB::selectOne("SELECT hsn_code FROM catalog_product WHERE id = ? LIMIT 1", [$productId]);
                    // $hsn = (string) ($cp->hsn_code ?? '');
                // }

                // $normalized[] = compact(
                    // 'productId', 'qty', 'rate', 'mrp', 'freeQty', 'tax', 'vatAmt',
                    // 'taxable', 'amount', 'batchNo', 'mfgDate', 'hsn',
                    // 'cd', 'cdType', 'cdAmt', 'disType', 'disAmt', 'disPct',
                    // 'splDiscAmt', 'cashAmt', 'orderId', 'userId'
                // );
                // $subTotal += $amount;

                // $stockLines[] = [
                    // 'product_id' => $productId,
                    // 'batch_no'   => $batchNo,
                    // 'mfg_date'   => $mfgDate,
                    // 'mrp'        => $mrp,
                    // 'qty'        => $qty + $freeQty,
                // ];
            // }

            // app(StockService::class)->sync($dealerId, $oldLines, $stockLines);

            // $discountAmt = round($subTotal * $discountPer / 100, 2);
            // $finalAmount = round($subTotal - $discountAmt, 2);
            // $amountRound = round($finalAmount);

            // // ── Update header
            // DB::update(
                // "UPDATE challan_order
                    // SET ch_retailer_id = ?,
                        // ch_date        = ?,
                        // remark         = ?,
                        // crdr_status    = ?,
                        // discount_per   = ?,
                        // discount_amt   = ?,
                        // amount         = ?,
                        // remaining      = ?,
                        // amount_round   = ?
                  // WHERE id = ? AND ch_dealer_id = ?",
                // [
                    // $retailerId, $chDate, $remark, (int) $request->input('crdr_status', 0),
                    // $discountPer, $discountAmt,
                    // $finalAmount, $finalAmount, $amountRound,
                    // $id, $dealerId,
                // ]
            // );

            // // ── Delete old lines, insert new
            // DB::delete("DELETE FROM challan_order_details WHERE ch_id = ?", [$id]);

            // foreach ($normalized as $l) {
                // // FIX: cd_amt bound to $l['cdAmt']; remain_amount = $l['amount'].
                // DB::insert(
                    // "INSERT INTO challan_order_details
                        // (ch_id, product_id, hsn_code, catalog_details_id,
                         // batch_no, mfg_date, tax, vat_amt, qty, product_rate,
                         // free_qty, order_id, user_id, mrp,
                         // cd, cd_type, cd_amt,
                         // spl_disc_type, spl_disc_val, spl_disc_amt, cash_amt,
                         // dis_type, dis_amt, dis_percent,
                         // taxable_amt, remain_amount, sale_order_id, sync_status)
                     // VALUES
                        // (?, ?, ?, 0,
                         // ?, ?, ?, ?, ?, ?,
                         // ?, ?, ?, ?,
                         // ?, ?, ?,
                         // 0, 0, ?, ?,
                         // ?, ?, ?,
                         // ?, ?, '', 1)",
                    // [
                        // $id, $l['productId'], $l['hsn'],
                        // $l['batchNo'], $l['mfgDate'], $l['tax'], $l['vatAmt'],
                        // $l['qty'], $l['rate'], $l['freeQty'],
                        // $l['orderId'], $l['userId'], $l['mrp'],
                        // $l['cd'], $l['cdType'], $l['cdAmt'],
                        // $l['splDiscAmt'], $l['cashAmt'],
                        // $l['disType'], $l['disAmt'], $l['disPct'],
                        // $l['amount'], $l['amount'],
                    // ]
                // );
            // }

            // DB::commit();

            // return response()->json([
                // 'status'       => true,
                // 'message'      => 'Challan updated.',
                // 'challan_id'   => $id,
                // 'amount'       => $finalAmount,
                // 'amount_round' => $amountRound,
                // 'discount_amt' => $discountAmt,
                // 'items_count'  => count($normalized),
            // ]);

        // } catch (InsufficientStockException $e) {
            // DB::rollBack();
            // return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Challan update failed: ' . $e->getMessage(),
            // ], 500);
        // }
    // }

    /* =========================================================
     |  CANCEL
     |  POST /api/challans/{id}/cancel
     |
     |  Marks the challan void + restores stock. Uses a
     |  `reason` body field for the audit trail.
     | ========================================================= */
    // public function cancel(Request $request, $id): JsonResponse
    // {
        // $user     = $request->user();
        // $dealerId = (int) $user->dealer_id;
        // $reason   = (string) $request->input('reason', 'No reason given');

        // $header = DB::selectOne(
            // "SELECT id, ch_no FROM challan_order
              // WHERE id = ? AND ch_dealer_id = ?
              // LIMIT 1",
            // [$id, $dealerId]
        // );
        // if (!$header) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Challan not found.',
            // ], 404);
        // }

        // if ($lock = app(InvoiceService::class)->reason((string) $header->id, $dealerId)) {
            // return response()->json(['status' => false, 'message' => $lock], 409);
        // }

        // // Already cancelled?
        // $already = DB::selectOne(
            // "SELECT 1 AS x FROM challan_order_cancel WHERE challan_id = ? LIMIT 1",
            // [$id]
        // );
        // if ($already) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Challan is already cancelled.',
            // ], 409);
        // }

        // DB::beginTransaction();
        // try {
            // app(InvoiceService::class)->reopenSalesOrders(
                // DB::select("SELECT order_id, product_id, qty FROM challan_order_details WHERE ch_id = ?", [$id])
            // );

            // // 1. Restore stock for every line
            // app(StockService::class)->sync(
                // $dealerId,
                // DB::select(
                    // "SELECT product_id, qty, free_qty, mrp, batch_no, mfg_date
                       // FROM challan_order_details
                      // WHERE ch_id = ?",
                    // [$id]
                // ),
                // []
            // );

            // // 2. Mirror the header into challan_order_cancel
            // DB::insert(
                // "INSERT INTO challan_order_cancel
                    // (challan_id, ch_no, dealer_id, cancel_reason,
                     // cancelled_by, cancelled_date)
                 // VALUES (?, ?, ?, ?, ?, NOW())",
                // [
                    // $id,
                    // $header->ch_no,
                    // $dealerId,
                    // $reason,
                    // (int) $user->getKey(),
                // ]
            // );

            // // 3. Mark challan
            // DB::update(
                // "UPDATE challan_order
                    // SET dispatch_status = 3,
                        // payment_status  = '0'
                  // WHERE id = ? AND ch_dealer_id = ?",
                // [$id, $dealerId]
            // );

            // DB::commit();

            // return response()->json([
                // 'status'  => true,
                // 'message' => 'Challan cancelled.',
                // 'ch_id'   => $id,
                // 'ch_no'   => $header->ch_no,
            // ]);

        // } catch (InsufficientStockException $e) {
            // DB::rollBack();
            // return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Cancel failed: ' . $e->getMessage(),
            // ], 500);
        // }
    // }

    /* =========================================================
     |  HELPERS
     | ========================================================= */

    /**
     * Role name lookup — table `_role`, PK `role_id`, label `rolename`.
     */
    private function resolveRoleName($roleId): string
    {
        if (empty($roleId)) return 'Unknown';
        $row = DB::selectOne("SELECT rolename FROM _role WHERE role_id = ? LIMIT 1", [$roleId]);
        return trim($row->rolename ?? 'Unknown');
    }

    /**
     * Accepts d/m/Y, d-m-Y, Y-m-d, d/M/Y; returns Y-m-d or null.
     */
    private function parseDate($value): ?string
    {
        if (empty($value)) return null;
        $value = trim((string) $value);
        if (str_starts_with($value, '0000-00-00')) return '0000-00-00';
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/M/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Next challan number, per-company format:
     *   213  → {dpId}/{FY}/00001           (5-digit seq)
     *   331  → EF/{dealerId}/{year}/000001 (6-digit seq)
     *   else → RPL/{dealerId}/{year}/000001 (6-digit seq)
     *
     * FOR UPDATE locks the last row so two concurrent saves cannot pick
     * the same sequence number. (Still recommend a UNIQUE index on ch_no.)
     */
    private function generateChNo(int $dpId, int $dealerId, int $companyId): string
    {
        $sessionRow = DB::selectOne("SELECT session FROM session WHERE action = '1' LIMIT 1");
        $year       = $sessionRow->session ?? (date('Y') . '-' . (date('Y') + 1));

        $expyear = explode('-', $year);
        $ystr    = substr($expyear[0] ?? '', 2) . '-' . substr($expyear[1] ?? '', 2);

        $last = DB::selectOne(
            "SELECT ch_no FROM challan_order
              WHERE ch_dealer_id = ? AND book_status = '0'
              ORDER BY auto DESC LIMIT 1
              FOR UPDATE",
            [$dealerId]
        );

        $nextNum = 1;
        if ($last && !empty($last->ch_no)) {
            $parts = explode('/', $last->ch_no);
            $tail  = end($parts);
            if (is_numeric($tail)) {
                $nextNum = ((int) $tail) + 1;
            }
        }

        if ($companyId === 213) {
            $num = str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
            return "{$dpId}/{$ystr}/{$num}";
        }

        $num = str_pad((string) $nextNum, 6, '0', STR_PAD_LEFT);
        $prefix = ($companyId === 331) ? 'EF' : 'RPL';
        return "{$prefix}/{$dealerId}/{$year}/{$num}";
    }

    /**
     * Ensure a product exists, is active and belongs to the given company.
     * Throws so the surrounding transaction rolls back on violation.
     */
    private function assertProductForCompany(int $productId, int $companyId): void
    {
        $row = DB::selectOne(
            "SELECT id FROM catalog_product
              WHERE id = ? AND company_id = ?
              LIMIT 1",
            [$productId, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException(
                "Product {$productId} is not valid for this company."
            );
        }
    }

    /**
     * FIFO stock reduction by mfg ascending.
     *
     * FIX: throws when the requested quantity exceeds available stock, so
     * the caller's transaction rolls back instead of silently allowing
     * negative / missing stock.
     */
    // public function query(Request $request): JsonResponse
    // {
    //     $user = $request->user();
    //
    //     $fb = FilterBuilder::make('purchase_order', $request)      // ← change key
    //         ->hardScope('po.dealer_id = ?', [(int) $user->dealer_id]);
    //
    //     if ((int) $user->company_id > 0) {
    //         $fb->hardScope('po.company_id = ?', [(int) $user->company_id]);
    //     }
    //
    //     if (!$fb->isValid()) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Invalid filter.',
    //             'errors'  => $fb->errors(),
    //         ], 422);
    //     }
    //
    //     $result = $fb->run();
    //
    //     return response()->json([
    //         'status' => true,
    //         'data'   => $result['rows'],
    //         'meta'   => $result['meta'],
    //     ]);
    // }

    /* =========================================================
 |  CLAIM FROM PROCESSED CHALLANS
 |  POST /api/challans/process-claim
 | ========================================================= */
public function takeActionOnProcessChallanClaim(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $validated = $request->validate([
        'checkedVals'   => ['required', 'array', 'min:1'],
        'checkedVals.*' => ['required', 'string', 'regex:/^\d+\|\d+$/'],
    ], [
        'checkedVals.required' => 'Select Check Box First!!',
    ]);

    $details = [];
    $loopData = [];
    foreach (array_unique($validated['checkedVals']) as $val) {
        [$chId, $productId] = array_map('intval', explode('|', $val));

        $lines = DB::select(
            "SELECT cod.qty, cod.product_rate, cod.old_rate
               FROM challan_order_details cod
               JOIN challan_order co ON co.id = cod.ch_id
              WHERE cod.ch_id = ? AND cod.product_id = ? AND co.ch_dealer_id = ?
                AND NOT EXISTS (SELECT 1 FROM dms_challan_claim_details d WHERE d.challan_id = cod.ch_id AND d.product_id = cod.product_id)",
            [$chId, $productId, $dealerId]
        );

        foreach ($lines as $line) {
            $qty   = (float) $line->qty;
            $rate  = (float) $line->product_rate;
            $old   = (float) $line->old_rate;
            $claim = round($old * $qty - $rate * $qty, 2);
            if ($claim <= 0) continue;
            $details[] = [
                'challan_id'    => $chId,
                'product_id'    => $productId,
                'challan_rate'  => $rate,
                'challan_qty'   => $qty,
                'purchase_rate' => $old,
                'claim_amount'  => $claim,
            ];
            $loopData[$val] = true;
        }
    }

    if (!$details) {
        return response()->json(['status' => false, 'message' => 'Selected items have no pending claim.'], 422);
    }

    $orderId  = $dealerId . date('YmdHis') . mt_rand(1000, 9999);

    DB::beginTransaction();
    try {
        DB::table('dms_challan_claim')->insert([
            'order_id'        => $orderId,
            'company_id'      => $companyId,
            'dealer_id'       => $dealerId,
            'date_time'       => now()->format('Y-m-d H:i:s'),
            'credit_note_pdf' => '',
            'credit_note_no'  => '',
        ]);
        DB::table('dms_challan_claim_details')->insert(array_map(fn ($d) => ['order_id' => $orderId] + $d, $details));
        DB::commit();

        return response()->json([
            'status'    => true,
            'message'   => 'Done!',
            'order_id'  => $orderId,
            'loop_data' => array_keys($loopData),
        ]);
    } catch (\Throwable $e) {
        DB::rollBack();
        report($e);
        return response()->json(['status' => false, 'message' => 'Could not process the claim.'], 500);
    }
}
}