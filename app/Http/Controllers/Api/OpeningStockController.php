<?php

namespace App\Http\Controllers\Api;
use App\Support\FilterBuilder;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpeningStockController extends Controller
{
    /**
     * GET /api/opening-stocks
     * Optional filters: order_no (product_id search), from_date, to_date
     */

public function query(Request $request): JsonResponse
{
    $user = $request->user();

    $fb = FilterBuilder::make('opening_stock', $request)
        ->hardScope('os.dealer_id = ?', [(int) $user->dealer_id]);

    if ((int) $user->company_id > 0) {
        $fb->hardScope('os.company_id = ?', [(int) $user->company_id]);
    }

    if (!$fb->isValid()) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid filter.',
            'errors'  => $fb->errors(),
        ], 422);
    }

    $result = $fb->run();

    return response()->json([
        'status' => true,
        'data'   => $result['rows'],
        'meta'   => $result['meta'],
    ]);
}
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $where    = ["os.dealer_id = ?"];
        $bindings = [$dealerId];

        if ($from = $request->input('from_date')) {
            $where[] = "DATE_FORMAT(os.order_date, '%Y-%m-%d') >= ?";
            $bindings[] = $from;
        }
        if ($to = $request->input('to_date')) {
            $where[] = "DATE_FORMAT(os.order_date, '%Y-%m-%d') <= ?";
            $bindings[] = $to;
        }
        if ($orderNo = $request->input('order_no')) {
            $where[] = "os.product_id = ?";
            $bindings[] = (int) $orderNo;
        }

        $whereSql = implode(' AND ', $where);

        // company_id_cus == 213 → name includes [qty_per_case]
        $nameSql = $companyId === 213
            ? "CONCAT(cv.product_name, '[', cv.qty_per_case, ']')"
            : "cv.product_name";

        $rows = DB::select(
            "SELECT
                os.*,
                os.id               AS id,
                {$nameSql}          AS product_name,
                cv.itemcode         AS item_code,
                cv.qty_per_case     AS quantity_per_case
               FROM opening_stocks os
               INNER JOIN catalog_view cv ON cv.product_id = os.product_id
              WHERE {$whereSql}
              ORDER BY cv.product_name ASC",
            $bindings
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /**
     * GET /api/opening-stocks/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $row = DB::selectOne(
            "SELECT os.*, os.id AS id,
                    cv.product_name, cv.itemcode AS item_code,
                    cv.qty_per_case AS quantity_per_case
               FROM opening_stocks os
               INNER JOIN catalog_view cv ON cv.product_id = os.product_id
              WHERE os.id = ? AND os.dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        if (!$row) {
            return response()->json([
                'status'  => false,
                'message' => 'Opening stock record not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'row'    => $row,
        ]);
    }

    /**
     * POST /api/opening-stocks
     *
     * Body:
     * {
     *   "items": [
     *     {
     *       "product_id": 12438,
     *       "batch_no": "B123",
     *       "mrp": 100.00,
     *       "dealer_rate": 85.00,
     *       "qty": 50,
     *       "nonsalable_damage": 0,
     *       "mfg": "2026-01-01"
     *     }
     *   ]
     * }
     */
        public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 1);

        $request->validate([
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer'],
            'items.*.batch_no'          => ['required', 'string', 'max:50'],
            'items.*.mrp'               => ['required', 'numeric'],
            'items.*.dealer_rate'       => ['required', 'numeric'],
            'items.*.qty'               => ['required', 'numeric', 'min:0'],
            'items.*.salable_damage'    => ['nullable', 'numeric', 'min:0'],
            'items.*.nonsalable_damage' => ['nullable', 'numeric', 'min:0'],
            'items.*.mfg'               => ['required', 'date_format:Y-m-d'],
        ]);

        $items = $request->input('items');

        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $batchNo   = $item['batch_no'];
                $mrp       = (float) $item['mrp'];
                $rate      = (float) $item['dealer_rate'];
                $qty       = (float) $item['qty'];
                $salableQty   = (int) ($item['salable_damage'] ?? 0);
                $nsQty        = (int) ($item['nonsalable_damage'] ?? 0);
                $mfg       = $item['mfg'];   // 'YYYY-MM-DD'

                // 1. INSERT into opening_stocks (Removed `expire`, added `salable_damage`)
                DB::insert(
                    "INSERT INTO opening_stocks
                        (product_id, batch_no, rate, dealer_rate, mrp,
                         person_id, csa_id, dealer_id, qty,
                         salable_damage, nonsalable_damage, remaining,
                         mfg, date, pr_rate, company_id,
                         action, sync_status, update_date_time)
                     VALUES
                        (?, ?, ?, ?, ?,
                         0, 0, ?, ?,
                         ?, ?, ?,
                         ?, NOW(), 0, ?,
                         1, 1, NOW())",
                    [
                        $productId, $batchNo, $rate, $rate, $mrp,
                        $dealerId, $qty,
                        $salableQty, $nsQty, $qty,
                        $mfg, $companyId,
                    ]
                );

                // 2. Sync into `stock`
                $existing = DB::selectOne(
                    "SELECT id, qty
                       FROM stock
                      WHERE dealer_id  = ?
                        AND product_id = ?
                        AND batch_no   = ?
                        AND mrp        = ?
                        AND DATE_FORMAT(mfg, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                      LIMIT 1",
                    [$dealerId, $productId, $batchNo, $mrp, $mfg]
                );

                if ($existing) {
                    // Updated: Removed `expire`, added `salable_damage`
                    DB::update(
                        "UPDATE stock
                            SET qty              = ?,
                                salable_damage   = ?,
                                nonsalable_damage = ?,
                                dealer_rate      = ?,
                                mrp              = ?,
                                date             = NOW(),
                                update_date_time = NOW()
                          WHERE id = ?",
                        [
                            $existing->qty + $qty,
                            $salableQty,
                            $nsQty,
                            $rate,
                            $mrp,
                            $existing->id,
                        ]
                    );
                } else {
                    // Insert into stock (Removed `expire`, added `salable_damage`)
                    DB::insert(
                        "INSERT INTO stock
                            (product_id, batch_no, rate, dealer_rate, mrp,
                             person_id, csa_id, dealer_id, qty,
                             salable_damage, nonsalable_damage, remaining,
                             mfg, date, pr_rate, company_id,
                             action, sync_status, update_date_time)
                         VALUES
                            (?, ?, ?, ?, ?,
                             0, 0, ?, ?,
                             ?, ?, ?,
                             ?, NOW(), 0, ?,
                             1, 1, NOW())",
                        [
                            $productId, $batchNo, $rate, $rate, $mrp,
                            $dealerId, $qty,
                            $salableQty, $nsQty, $qty,
                            $mfg, $companyId,
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Opening stock saved successfully.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Save failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/opening-stocks/{id}
     *
     * Simplest port: legacy's edit was incomplete, so we do a full replace.
     * Body is same shape as store().
     */
         public function update(Request $request, int $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT id FROM opening_stocks WHERE id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );

        if (!$existing) {
            return response()->json([
                'status'  => false,
                'message' => 'Opening stock record not found.',
            ], 404);
        }

        $request->validate([
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer'],
            'items.*.batch_no'          => ['required', 'string', 'max:50'],
            'items.*.mrp'               => ['required', 'numeric'],
            'items.*.dealer_rate'       => ['required', 'numeric'],
            'items.*.qty'               => ['required', 'numeric', 'min:0'],
            'items.*.salable_damage'    => ['nullable', 'numeric', 'min:0'],
            'items.*.nonsalable_damage' => ['nullable', 'numeric', 'min:0'],
            'items.*.mfg'               => ['required', 'date_format:Y-m-d'],
        ]);

        DB::beginTransaction();
        try {
            // Full replace strategy
            $old = DB::selectOne("SELECT * FROM opening_stocks WHERE id = ?", [$id]);
            DB::delete("DELETE FROM opening_stocks WHERE id = ?", [$id]);

            $item = $request->input('items.0');

            // Removed `expire`, added `salable_damage`
            DB::insert(
                "INSERT INTO opening_stocks
                    (product_id, batch_no, rate, dealer_rate, mrp,
                     person_id, csa_id, dealer_id, qty,
                     salable_damage, nonsalable_damage, remaining,
                     mfg, date, pr_rate, company_id,
                     action, sync_status, update_date_time)
                 VALUES
                    (?, ?, ?, ?, ?,
                     0, 0, ?, ?,
                     ?, ?, ?,
                     ?, NOW(), 0, ?,
                     1, 1, NOW())",
                [
                    (int)   $item['product_id'],
                    $item['batch_no'],
                    (float) $item['dealer_rate'],
                    (float) $item['dealer_rate'],
                    (float) $item['mrp'],
                    $dealerId,
                    (float) $item['qty'],
                    (int)   ($item['salable_damage'] ?? 0),
                    (int)   ($item['nonsalable_damage'] ?? 0),
                    (float) $item['qty'],
                    $item['mfg'],
                    (int) ($user->company_id ?? 1),
                ]
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Opening stock updated successfully.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/opening-stocks/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $deleted = DB::table('opening_stocks')
            ->where('id', $id)
            ->where('dealer_id', $dealerId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'status'  => false,
                'message' => 'Record not found.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Opening stock deleted.',
        ]);
    }

    /**
     * GET /api/opening-stocks/products
     * Dropdown source for the "New" form.
     */
   public function products(Request $request): JsonResponse
{
    $user      = $request->user();
    $companyId = (int) ($user->company_id ?? 0);

    $rows = DB::select(
        "SELECT cp.id,
                CONCAT(cp.name, '[', cp.quantity_per_case, ']') AS name
           FROM catalog_product cp
           INNER JOIN catalog_2 c2 ON c2.id = cp.catalog_id
          WHERE cp.company_id = ?
            AND cp.status = 1
          ORDER BY cp.name ASC",
        [$companyId]
    );

    return response()->json([
        'status'   => true,
        'products' => $rows,
    ]);
}
}