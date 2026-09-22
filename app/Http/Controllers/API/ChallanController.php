<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChallanController extends Controller
{
    /* ================== LIST ================== */

    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $where    = ["co.ch_dealer_id = ?"];
        $bindings = [$dealerId];

        if ($from = $request->input('from_date')) {
            $where[]    = "DATE(co.ch_date) >= ?";
            $bindings[] = $from;
        }
        if ($to = $request->input('to_date')) {
            $where[]    = "DATE(co.ch_date) <= ?";
            $bindings[] = $to;
        }
        if ($chNo = $request->input('ch_no')) {
            $where[]    = "co.ch_no LIKE ?";
            $bindings[] = "%{$chNo}%";
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT co.auto, co.id, co.ch_no, co.ch_created_by, co.ch_dealer_id,
                    co.ch_retailer_id, co.ch_date, co.company_id, co.dispatch_status,
                    co.sesId, co.remark, co.invoice_type, co.payment_status,
                    co.discount_per, co.discount_amt, co.amount, co.remaining,
                    r.name AS retailer_name,
                    d.name AS dealer_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN dealer   d ON d.id = co.ch_dealer_id
              WHERE {$whereSql}
              ORDER BY co.auto DESC
              LIMIT 200",
            $bindings
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /* ================== SHOW ONE ================== */

    public function show(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $header = DB::selectOne(
            "SELECT co.*, r.name AS retailer_name, d.name AS dealer_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN dealer   d ON d.id = co.ch_dealer_id
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
                    cv.product_name,
                    cv.itemcode,
                    cv.qty_per_case,
                    cv.hsn_code_view AS hsn_code
               FROM challan_order_details cod
               LEFT JOIN catalog_view cv ON cv.product_id = cod.product_id
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

    /* ================== NEXT CH NO ================== */

    public function nextNumber(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dpId     = (int) $user->getKey();
        $dealerId = (int) $user->dealer_id;

        return response()->json([
            'status'    => true,
            'ch_no'     => $this->generateChNo($dpId, $dealerId),
            'dpId'      => $dpId,
            'dealer_id' => $dealerId,
        ]);
    }

    /* ================== FORM BOOTSTRAP ================== */

    public function formData(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;
        $stateId   = (int) $user->state_id;

        // Retailers with beat (location_7)
        $retailers = DB::select(
            "SELECT r.id, CONCAT_WS(' ', r.name, '[', l7.name, ']') AS name
               FROM retailer r
               INNER JOIN location_7 l7 ON l7.id = r.location_id
              WHERE r.dealer_id = ? AND r.retailer_status = '1'
              GROUP BY r.id
              ORDER BY r.name ASC",
            [$dealerId]
        );

        // Field force users
        $users = DB::select(
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

        // Products with stock > 0 for this dealer
        $products = DB::select(
            "SELECT cp.id,
                    CONCAT_WS(' ', cp.name, '[', cp.quantity_per_case, ']') AS name
               FROM catalog_product cp
               INNER JOIN stock s ON s.product_id = cp.id
              WHERE s.dealer_id = ?
                AND s.qty > 0
                AND cp.status = 1
                AND cp.company_id = ?
              GROUP BY cp.id
              ORDER BY cp.id",
            [$dealerId, $companyId]
        );

        // Session year + state
        $sessionRow  = DB::selectOne("SELECT * FROM session WHERE action = '1' LIMIT 1");
        $sessionYear = DB::selectOne("SELECT * FROM session_year ORDER BY sesId DESC LIMIT 1");
        $stateRow    = DB::selectOne("SELECT * FROM state WHERE stateid = ? LIMIT 1", [$stateId]);

        return response()->json([
            'status'       => true,
            'retailers'    => $retailers,
            'users'        => $users,
            'products'     => $products,
            'session'      => $sessionRow,
            'session_year' => $sessionYear,
            'state'        => $stateRow,
            'next_ch_no'   => $this->generateChNo($dpId, $dealerId),
            'dealer_id'    => $dealerId,
            'company_id'   => $companyId,
            'state_id'     => $stateId,
            'dp_id'        => $dpId,
        ]);
    }

    /* ================== SAVE ================== */

    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dpId      = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $request->validate([
            'ch_date'                     => ['required', 'date_format:Y-m-d'],
            'retailer_id'                 => ['required', 'integer'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.product_id'          => ['required', 'integer'],
            'items.*.qty'                 => ['required', 'numeric', 'min:0.01'],
            'items.*.rate'                => ['required', 'numeric'],
            'items.*.mrp'                 => ['required', 'numeric'],
            'items.*.vat'                 => ['required', 'numeric'],
            'items.*.vat_amt'             => ['required', 'numeric'],
            'items.*.taxable_amt'         => ['required', 'numeric'],
            'items.*.amount'              => ['required', 'numeric'],
            'discount_per'                => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $items       = $request->input('items');
        $discountPer = (float) ($request->input('discount_per') ?? 0);
        $chDate      = $request->input('ch_date');
        $retailerId  = (int) $request->input('retailer_id');
        $remark      = (string) $request->input('remark', '');

        $sessionYear = DB::selectOne("SELECT sesId FROM session_year ORDER BY sesId DESC LIMIT 1");
        $sesId       = (int) ($sessionYear->sesId ?? 0);

        // Primary key for challan_order — mirrors legacy dealer_id + ymdHis
        $chId = $dealerId . date('ymdHis');
        $guard = 0;
        while (DB::table('challan_order')->where('id', $chId)->exists() && $guard < 20) {
            usleep(100000);
            $chId = $dealerId . date('ymdHis');
            $guard++;
        }

        $chNo = $this->generateChNo($dpId, $dealerId);

        $totalAmount = 0.0;
        foreach ($items as $it) {
            $totalAmount += (float) $it['amount'];
        }
        $discountAmt = round($totalAmount * $discountPer / 100, 2);
        $finalAmount = round($totalAmount - $discountAmt, 2);

        DB::beginTransaction();
        try {
            // 1. Header
            DB::insert(
                "INSERT INTO challan_order
                    (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id,
                     ch_date, company_id, sesId, invoice_type, remark,
                     dispatch_status, payment_status, sync_status,
                     isclaim, istarget_claim,
                     discount_per, discount_amt, amount, remaining)
                 VALUES
                    (?, ?, ?, ?, ?,
                     ?, ?, ?, '1', ?,
                     0, '0', 1,
                     0, 0,
                     ?, ?, ?, ?)",
                [
                    $chId, $chNo, $dpId, $dealerId, $retailerId,
                    $chDate, $companyId, $sesId, $remark,
                    $discountPer, $discountAmt, $finalAmount, $finalAmount,
                ]
            );

            // 2. Items
            foreach ($items as $it) {
                $productId   = (int)   $it['product_id'];
                $qty         = (float) $it['qty'];
                $rate        = (float) $it['rate'];
                $mrp         = (float) $it['mrp'];
                $freeQty     = (float) ($it['free_qty']      ?? 0);
                $tax         = (float) $it['vat'];
                $vatAmt      = (float) $it['vat_amt'];
                $taxableAmt  = (float) $it['taxable_amt'];
                $batchNo     = (string)($it['batch_no']      ?? '');
                $cd          = (float) ($it['cd']            ?? 0);
                $cdType      = (int)   ($it['cd_type']       ?? 1);
                $cdAmt       = (float) ($it['cd_amt']        ?? 0);
                $disType     = (int)   ($it['dis_type']      ?? 1);
                $disAmt      = (float) ($it['dis_amt']       ?? 0);
                $disPercent  = (float) ($it['dis_percent']   ?? 0);
                $splDiscType = (int)   ($it['spl_disc_type'] ?? 0);
                $splDiscVal  = (float) ($it['spl_disc_val']  ?? 0);
                $splDiscAmt  = (float) ($it['spl_disc_amt']  ?? 0);
                $cashAmt     = (float) ($it['cash_amt']      ?? 0);
                $mfgDate     = $it['mfg_date'] ?? null;
                $orderId     = (string)($it['order_id']      ?? '');
                $userId      = (int)   ($it['user_id']       ?? 0);

                if (empty($mfgDate)) {
                    $mfgDate = '1970-01-01';
                }

                $cv = DB::selectOne(
                    "SELECT hsn_code_view FROM catalog_view WHERE product_id = ? LIMIT 1",
                    [$productId]
                );
                $hsnCode = (string) ($cv->hsn_code_view ?? '');

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
                         ?, ?, ?, ?,
                         ?, ?, ?,
                         ?, ?, '', 1)",
                    [
                        $chId, $productId, $hsnCode,
                        $batchNo, $mfgDate, $tax, $vatAmt, $qty, $rate,
                        $freeQty, $orderId, $userId, $mrp,
                        $cd, $cdType, $cdAmt,
                        $splDiscType, $splDiscVal, $splDiscAmt, $cashAmt,
                        $disType, $disAmt, $disPercent,
                        $taxableAmt, $taxableAmt,
                    ]
                );

                // 3. Reduce `stock.remaining` FIFO by mfg asc
                $this->reduceStock($dealerId, $productId, $qty + $freeQty);

                // 4. Mark any linked sales order lines as used
                if ($orderId !== '') {
                    DB::update(
                        "UPDATE user_sales_order_details SET status = 2
                          WHERE order_id = ? AND product_id = ?",
                        [$orderId, $productId]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status'       => true,
                'message'      => 'Challan saved successfully.',
                'challan_id'   => $chId,
                'ch_no'        => $chNo,
                'amount'       => $finalAmount,
                'discount_amt' => $discountAmt,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Challan could not be saved: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* ================== HELPERS ================== */

    private function generateChNo(int $dpId, int $dealerId): string
    {
        $sessionRow = DB::selectOne("SELECT session FROM session WHERE action = '1' LIMIT 1");
        $year       = $sessionRow->session ?? (date('Y') . '-' . (date('Y') + 1));

        $expyear = explode('-', $year);
        $fyear   = substr($expyear[0] ?? '', 2);
        $lyear   = substr($expyear[1] ?? '', 2);
        $ystr    = $fyear . '-' . $lyear;

        $last = DB::selectOne(
            "SELECT ch_no FROM challan_order
              WHERE ch_dealer_id = ? AND book_status = '0'
              ORDER BY auto DESC LIMIT 1",
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

        $num = str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
        return "{$dpId}/{$ystr}/{$num}";
    }
    /**
 * POST /api/challans/from-orders
 * Body:
 * {
 *   "ch_date": "2026-09-19",
 *   "discount_per": 0,
 *   "orders": [
 *     { "order_id": "...", "items": [ { product_id, qty, scheme_qty, mrp, rate, vat, vat_amt, taxable_amt, ... } ] }
 *   ]
 * }
 */

public function fromOrders(Request $request): JsonResponse
{
    $request->validate([
        'ch_date'                  => ['required', 'date_format:Y-m-d'],
        'orders'                   => ['required', 'array', 'min:1'],
        'orders.*.order_id'        => ['required', 'string'],
        'orders.*.items'           => ['required', 'array', 'min:1'],
        'orders.*.items.*.product_id' => ['required', 'integer'],
        'orders.*.items.*.qty'        => ['required', 'numeric'],
        'orders.*.items.*.rate'       => ['required', 'numeric'],
    ]);

    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $userId    = (int) $user->getKey();
    $companyId = (int) $user->company_id;

    $chDate      = $request->input('ch_date');
    $discountPer = (float) $request->input('discount_per', 0);

    $created = [];

    DB::beginTransaction();
    try {
        foreach ($request->input('orders') as $order) {
            $orderId = $order['order_id'];
            $items   = $order['items'];

            // Look up the sales order
            $orderRow = DB::selectOne(
                "SELECT * FROM user_sales_order
                  WHERE order_id = ? AND dealer_id = ? LIMIT 1",
                [$orderId, $dealerId]
            );
            if (!$orderRow) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => "Order {$orderId} not found."], 404);
            }

            // ch_id — same pattern as legacy: dealer_id + ymdHis
            $chId = $dealerId . date('ymdHis');
            $guard = 0;
            while (DB::table('challan_order')->where('id', $chId)->exists() && $guard < 20) {
                usleep(100000);
                $chId = $dealerId . date('ymdHis');
                $guard++;
            }
            $chNo = $this->generateChNo($userId, $dealerId);

            $subTotal = 0.0;
            $orderLines = [];

            foreach ($items as $it) {
                $productId = (int) $it['product_id'];
                $qty       = (float) $it['qty'];
                $schQty    = (float) ($it['scheme_qty'] ?? 0);
                $rate      = (float) $it['rate'];
                $mrp       = (float) ($it['mrp'] ?? 0);
                $tax       = (float) ($it['vat'] ?? 0);
                $vatAmt    = (float) ($it['vat_amt'] ?? 0);
                $taxable   = (float) ($it['taxable_amt'] ?? $qty * $rate);
                $batchNo   = (string) ($it['batch_no'] ?? '');
                $mfgDate   = $it['mfg_date'] ?? null;

                // Reduce stock FIFO by mfg asc (same as single challan)
                $this->reduceStock($dealerId, $productId, $qty + $schQty);

                // Mark order line used
                DB::update(
                    "UPDATE user_sales_order_details SET status = 2
                      WHERE order_id = ? AND product_id = ?",
                    [$orderId, $productId]
                );

                $orderLines[] = [
                    'product_id' => $productId,
                    'qty'        => $qty,
                    'scheme_qty' => $schQty,
                    'rate'       => $rate,
                    'mrp'        => $mrp,
                    'tax'        => $tax,
                    'vat_amt'    => $vatAmt,
                    'taxable'    => $taxable,
                    'batch_no'   => $batchNo,
                    'mfg_date'   => $mfgDate ?: '1970-01-01',
                ];

                $subTotal += $taxable;
            }

            $discAmt = round($subTotal * $discountPer / 100, 2);
            $amount  = round($subTotal - $discAmt, 2);

            // Insert challan_order header
            DB::insert(
                "INSERT INTO challan_order
                    (id, ch_no, ch_created_by, ch_dealer_id, ch_retailer_id, ch_user_id,
                     ch_date, date_added, company_id, invoice_type, book_status,
                     dispatch_status, sesId, remark, sync_status, payment_status,
                     discount_per, discount_amt, amount, remaining, amount_round)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1, 0, 0, 0, ?, 1, '0', ?, ?, ?, ?, ?)",
                [
                    $chId, $chNo, $userId, $dealerId,
                    $orderRow->retailer_id, $orderRow->user_id,
                    $chDate, $companyId,
                    $orderRow->remarks ?? '',
                    $discountPer, $discAmt, $amount, $amount, $amount,
                ]
            );

            // Insert items
            foreach ($orderLines as $l) {
                DB::insert(
                    "INSERT INTO challan_order_details
                        (ch_id, product_id, supply_status, catalog_details_id,
                         batch_no, tax, vat_amt, qty, product_rate, free_qty,
                         order_id, user_id, mrp,
                         dis_type, dis_amt, dis_percent,
                         taxable_amt, remain_amount, mfg_date, sync_status)
                     VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 0, ?, ?, ?, 1)",
                    [
                        $chId, $l['product_id'], $l['batch_no'],
                        $l['tax'], $l['vat_amt'], $l['qty'], $l['rate'], $l['scheme_qty'],
                        $orderId, $orderRow->user_id, $l['mrp'],
                        $l['taxable'], $l['taxable'], $l['mfg_date'],
                    ]
                );
            }

            // Mark order as processed
            DB::update(
                "UPDATE user_sales_order SET order_status = '1'
                  WHERE order_id = ?",
                [$orderId]
            );

            $created[] = [
                'order_id' => $orderId,
                'ch_id'    => $chId,
                'ch_no'    => $chNo,
                'amount'   => $amount,
            ];
        }

        DB::commit();
        return response()->json([
            'status'   => true,
            'message'  => count($created) . ' challan(s) created.',
            'challans' => $created,
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'status'  => false,
            'message' => 'Challan creation failed: ' . $e->getMessage(),
        ], 500);
    }
}

    private function reduceStock(int $dealerId, int $productId, float $qty): void
    {
        $rows = DB::select(
            "SELECT id, remaining FROM stock
              WHERE dealer_id = ? AND product_id = ?
              ORDER BY mfg ASC",
            [$dealerId, $productId]
        );

        $remaining = (float) $qty;
        foreach ($rows as $row) {
            if ($remaining <= 0) break;

            $thisQty = (float) $row->remaining;
            if ($remaining >= $thisQty) {
                DB::update("UPDATE stock SET remaining = 0 WHERE id = ?", [$row->id]);
                $remaining -= $thisQty;
            } else {
                $newRemaining = $thisQty - $remaining;
                DB::update("UPDATE stock SET remaining = ? WHERE id = ?", [$newRemaining, $row->id]);
                $remaining = 0;
            }
        }
    }
}