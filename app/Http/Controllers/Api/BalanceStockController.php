<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceStockController extends Controller
{
    /* =========================================================
     |  REPORT
     |  GET /api/reports/balance-stock
     |  Optional: catalog_0_id, catalog_1_id, catalog_2_id, product_id
     | ========================================================= */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $stateId   = (string) ($user->state_id ?? '');

        $dealerRow    = DB::selectOne(
            "SELECT edit_stock FROM dealer WHERE id = ? LIMIT 1",
            [$dealerId]
        );
        $canEditStock = (int) ($dealerRow->edit_stock ?? 0) === 1;

        [$catalogJoinSql, $catalogWhereSql, $catalogBindings] =
            $this->buildCatalogFilter($request);

        $rows = DB::select(
            "SELECT
                s.id                            AS stock_id,
                s.batch_no,
                s.rate                          AS stock_rate,
                s.mrp                           AS stock_mrp,
                s.dealer_rate                   AS stock_dealer_rate,
                s.qty,
                s.nonsalable_damage             AS nonsale,
                s.salable_damage                AS saleable_damage,
                DATE_FORMAT(s.mfg, '%b-%Y')     AS mfg,
                DATE_FORMAT(s.mfg, '%d-%b-%Y')  AS mfg_cus,
                s.mfg                           AS mfg_raw,

                c.id                            AS pid,
                c.name                          AS product_name,
                c.itemcode,
                c.quantity_per_case,
                c.weight,
                c.division,
                c.gst_percent                   AS product_gst,

                c2.id                           AS c2_id,
                c2.name                         AS cat,

                oc.cp_name                      AS company_name,
                oc.cp_short_name                AS cp_short_name,

                prl.mrp                         AS product_mrp,
                prl.dealer_rate                 AS default_dealer_rate
               FROM catalog_product c
               INNER JOIN catalog_2 c2 ON c2.id = c.catalog_id
               INNER JOIN stock    s  ON s.product_id = c.id AND s.dealer_id = ?
               LEFT  JOIN other_company oc ON oc.id = c.company_id
               LEFT  JOIN (
                    SELECT product_id,
                           MAX(mrp)          AS mrp,
                           MAX(dealer_rate)  AS dealer_rate
                      FROM product_rate_list
                     WHERE state_id = ?
                     GROUP BY product_id
               ) prl ON prl.product_id = c.id
               {$catalogJoinSql}
              WHERE 1=1 {$catalogWhereSql}
              ORDER BY c.name ASC",
            array_merge([$dealerId, $stateId], $catalogBindings)
        );

        $productIds = array_values(array_unique(array_map(fn ($r) => (int) $r->pid, $rows)));
        $intransit  = $this->intransitMap($dealerId, $productIds);
        $threshold  = $this->thresholdMap($dealerId, $productIds);

        $totalQty       = 0.0;
        $totalNonSale   = 0.0;
        $totalIntransit = 0.0;
        $totalValue     = 0.0;

        foreach ($rows as $r) {
            $qty  = (float) ($r->qty ?? 0);
            $non  = (float) ($r->nonsale ?? 0);
            $intr = (float) ($intransit[$r->pid] ?? 0);
            if (!$r->stock_mrp) {
                $rates = $this->ratesFromMrp((float) ($r->product_mrp ?? 0), $r->product_gst, $r->division, $stateId);
                if ($rates['dealer_rate'] !== null) {
                    $r->default_dealer_rate = $rates['dealer_rate'];
                }
            }
            $rate = (float) ($r->stock_mrp ? $r->stock_dealer_rate : $r->default_dealer_rate);

            $r->intransit   = $intr;
            $r->thresh      = (float) ($threshold[$r->pid] ?? 0);
            $r->stock_value = round($qty * $rate, 2);

            $totalQty       += $qty;
            $totalNonSale   += $non;
            $totalIntransit += $intr;
            $totalValue     += $r->stock_value;
        }

        return response()->json([
            'status'   => true,
            'can_edit' => $canEditStock,
            'rows'     => $rows,
            'totals'   => [
                'qty'         => $totalQty,
                'nonsale'     => $totalNonSale,
                'intransit'   => $totalIntransit,
                'stock_value' => round($totalValue, 2),
            ],
        ]);
    }

    /* =========================================================
     |  FILTER — Product Classification
     |  GET /api/filters/classifications
     | ========================================================= */
    public function classifications(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        $rows = DB::select(
            "SELECT id, name FROM catalog_0
              WHERE company_id = ? AND status = 1
              ORDER BY sequence, name",
            [$companyId]
        );

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — Product Category
     |  GET /api/filters/categories?classification_id=X
     | ========================================================= */
    public function categories(Request $request): JsonResponse
    {
        $classificationId = (int) $request->input('classification_id');
        if (!$classificationId) {
            return response()->json([
                'status'  => false,
                'message' => 'classification_id is required.',
            ], 422);
        }

        $companyId = (int) $request->user()->company_id;

        $rows = DB::select(
            "SELECT id, name FROM catalog_1
              WHERE catalog_0_id = ? AND company_id = ? AND status = 1
              ORDER BY catalog_1_sequence, name",
            [$classificationId, $companyId]
        );

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — Product Name
     |  GET /api/filters/product-names?category_id=X
     | ========================================================= */
    public function productNames(Request $request): JsonResponse
    {
        $categoryId = (int) $request->input('category_id');
        if (!$categoryId) {
            return response()->json([
                'status'  => false,
                'message' => 'category_id is required.',
            ], 422);
        }

        $companyId = (int) $request->user()->company_id;

        $rows = DB::select(
            "SELECT id, name FROM catalog_2
              WHERE catalog_1_id = ? AND company_id = ? AND status = 1
              ORDER BY sequence, name",
            [$categoryId, $companyId]
        );

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — SKU Name
     |  GET /api/filters/skus?product_name_id=X
     | ========================================================= */
    public function skus(Request $request): JsonResponse
    {
        $productNameId = (int) $request->input('product_name_id');
        if (!$productNameId) {
            return response()->json([
                'status'  => false,
                'message' => 'product_name_id is required.',
            ], 422);
        }

        $companyId = (int) $request->user()->company_id;

        $rows = DB::select(
            "SELECT id, CONCAT(name, ' [', quantity_per_case, ']') AS name
               FROM catalog_product
              WHERE catalog_id = ? AND company_id = ? AND status = 1
              ORDER BY name",
            [$productNameId, $companyId]
        );

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  HELPERS
     | ========================================================= */

    /**
     * Build the optional catalog filter for the report.
     * Returns [$joinSql, $whereSql, $bindings].
     */
    private function buildCatalogFilter(Request $request): array
    {
        $joinSql  = '';
        $whereSql = '';
        $bindings = [];

        $l0 = $request->input('catalog_0_id');
        $l1 = $request->input('catalog_1_id');
        $l2 = $request->input('catalog_2_id');
        $p  = $request->input('product_id');

        if ($l0 !== null && $l0 !== '') {
            $joinSql     .= " INNER JOIN catalog_1 c1 ON c1.id = c2.catalog_1_id";
            $joinSql     .= " INNER JOIN catalog_0 c0 ON c0.id = c1.catalog_0_id";
            $whereSql    .= " AND c0.id = ?";
            $bindings[]   = (int) $l0;
        }
        if ($l1 !== null && $l1 !== '') {
            if (strpos($joinSql, 'catalog_1 c1') === false) {
                $joinSql .= " INNER JOIN catalog_1 c1 ON c1.id = c2.catalog_1_id";
            }
            $whereSql  .= " AND c1.id = ?";
            $bindings[] = (int) $l1;
        }
        if ($l2 !== null && $l2 !== '') {
            $whereSql  .= " AND c2.id = ?";
            $bindings[] = (int) $l2;
        }
        if ($p !== null && $p !== '') {
            $whereSql  .= " AND c.id = ?";
            $bindings[] = (int) $p;
        }

        return [$joinSql, $whereSql, $bindings];
    }

    /**
     * In-transit stock — POs placed but not yet received.
     */
    private function intransitMap(int $dealerId, array $productIds): array
    {
        if (empty($productIds)) return [];

        $in = implode(',', array_map('intval', $productIds));

        $rows = DB::select(
            "SELECT pod.product_id,
                    SUM(pod.quantity + COALESCE(pod.scheme_qty, 0)) AS qty
               FROM purchase_order_details pod
               INNER JOIN purchase_order po ON po.order_id = pod.order_id
              WHERE po.dealer_id = ?
                AND po.receive_date IS NULL
                AND pod.product_id IN ({$in})
              GROUP BY pod.product_id",
            [$dealerId]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = (float) $r->qty;
        }
        return $map;
    }

    /**
     * Threshold stock per product.
     */
    private function thresholdMap(int $dealerId, array $productIds): array
    {
        if (empty($productIds)) return [];

        $in = implode(',', array_map('intval', $productIds));

        $rows = DB::select(
            "SELECT product_id, SUM(qty) AS qty
               FROM threshold
              WHERE dealer_id = ?
                AND product_id IN ({$in})
              GROUP BY product_id",
            [$dealerId]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = (float) $r->qty;
        }
        return $map;
    }
    private function ratesFromMrp(float $mrp, $gst, $division, string $stateId): array
    {
        $rate       = round($mrp - ($mrp * ((float) $gst ? 25 : 18) / 100), 2);
        $dealerRate = null;
        if (in_array($stateId, ['1', '2', '3', '4', '5', '6'], true) || (int) $division === 1) {
            $dealerRate = round($rate - ($rate * 9 / 100), 2);
        } elseif ((int) $division === 2) {
            $dealerRate = round($rate - ($rate * 7 / 100), 2);
        }
        return ['rate' => $rate, 'dealer_rate' => $dealerRate];
    }

    public function destroy(Request $request, string $id): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    // Legacy rule: company 44 → delete blocked
    if ($companyId === 44) {
        return response()->json(['status' => false, 'message' => 'Delete not allowed.'], 403);
    }

    $stock = DB::selectOne(
        "SELECT id, product_id, batch_no, mfg, qty
           FROM stock
          WHERE id = ? AND dealer_id = ?
          LIMIT 1",
        [$id, $dealerId]
    );
    if (!$stock) {
        return response()->json(['status' => false, 'message' => 'Stock row not found.'], 404);
    }

    DB::transaction(function () use ($stock, $dealerId) {
        DB::delete(
            "DELETE FROM stock WHERE id = ? AND dealer_id = ?",
            [$stock->id, $dealerId]
        );
    });

    return response()->json([
        'status'  => true,
        'message' => 'Stock successfully deleted',
    ]);
}

   public function bulkUpdate(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    // Rule 1: company 44 block
    if ($companyId === 44) {
        return response()->json(['status' => false, 'message' => 'Edit not allowed.'], 403);
    }

    // Rule 2: legacy — sirf edit_stock = 1 wale dealer edit kar sakte hain
    $dealer = DB::selectOne(
        "SELECT edit_stock FROM dealer WHERE id = ? LIMIT 1",
        [$dealerId]
    );
    if (!$dealer || (int) $dealer->edit_stock !== 1) {
        return response()->json(['status' => false, 'message' => 'Not allowed to edit stock.'], 403);
    }

    $validated = $request->validate([
        'rows'             => 'required|array|min:1',
        'rows.*.id'        => 'required|integer',
        'rows.*.qty'       => 'required|numeric|min:0',
        'rows.*.nonsale'   => 'nullable|numeric|min:0',
        'rows.*.batch_no'  => 'nullable|string|max:11',
        'rows.*.mfg'       => 'nullable|date',
        'rows.*.mrp'       => 'nullable|numeric|min:0',
    ]);

    $stateId = (string) ($user->state_id ?? '');

    DB::transaction(function () use ($validated, $dealerId, $companyId, $stateId) {

        foreach ($validated['rows'] as $row) {
            $existing = DB::selectOne(
                "SELECT s.*, cp.division, cp.gst_percent FROM stock s
                   LEFT JOIN catalog_product cp ON cp.id = s.product_id
                  WHERE s.id = ? AND s.dealer_id = ?
                  LIMIT 1",
                [(int) $row['id'], $dealerId]
            );
            if (!$existing) {
                throw new \RuntimeException("Stock row {$row['id']} not found.");
            }

            $update = ['qty' => (float) $row['qty']];
            if (array_key_exists('nonsale', $row))   $update['nonsalable_damage'] = (int) $row['nonsale'];
            if (array_key_exists('batch_no', $row))  $update['batch_no']          = (string) $row['batch_no'];
            if (array_key_exists('mfg', $row))       $update['mfg']               = (string) $row['mfg'];

            if (array_key_exists('mrp', $row) && $row['mrp'] !== null) {
                $mrp   = (float) $row['mrp'];
                $rates = $this->ratesFromMrp($mrp, $existing->gst_percent, $existing->division, $stateId);
                $update['mrp']         = $mrp;
                $update['rate']        = $rates['rate'];
                $update['dealer_rate'] = $rates['dealer_rate'] ?? (float) $existing->dealer_rate;
                $update['date']        = now();

                if ((float) $existing->mrp > 0 && $mrp != (float) $existing->mrp) {
                    DB::table('stock')->insert($update + [
                        'product_id'        => $existing->product_id,
                        'batch_no'          => $existing->batch_no,
                        'mfg'               => $existing->mfg,
                        'nonsalable_damage' => (int) $existing->nonsalable_damage,
                        'csa_id'            => $existing->csa_id,
                        'dealer_id'         => $dealerId,
                        'salable_damage'    => 0,
                        'remaining'         => 0,
                        'expire'            => date('Y-m-d', strtotime('+1 years')),
                        'pr_rate'           => 0,
                        'company_id'        => $companyId,
                    ]);
                    DB::table('stock')
                        ->where('id', $existing->id)
                        ->where('dealer_id', $dealerId)
                        ->update(['qty' => 0, 'nonsalable_damage' => 0, 'date' => now()]);
                    continue;
                }
            }

            DB::table('stock')
                ->where('id', $row['id'])
                ->where('dealer_id', $dealerId)
                ->update($update);
        }
    });

    return response()->json(['status' => true, 'message' => 'Stock updated successfully.']);
}

   
}