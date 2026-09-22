<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceStockController extends Controller
{
    /**
     * GET /api/reports/balance-stock
     * Optional query params: catalog_0_id, catalog_1_id, catalog_2_id, product_id
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $stateId   = (string) ($user->state_id ?? '');
        $companyId = (int) ($user->company_id ?? 0);

        $dealerRow    = DB::selectOne(
            "SELECT edit_stock FROM dealer WHERE id = ? LIMIT 1",
            [$dealerId]
        );
        $canEditStock = (int) ($dealerRow->edit_stock ?? 0) === 1;

        [$whereSql, $bindings] = $this->buildFilter($request);

        $rows = DB::select(
            "SELECT
                s.id                 AS stock_id,
                s.batch_no,
                s.rate,
                s.mrp,
                s.dealer_rate,
                s.qty,
                s.nonsalable_damage  AS nonsale,
                DATE_FORMAT(s.mfg, '%b-%Y')    AS mfg,
                DATE_FORMAT(s.mfg, '%d-%b-%Y') AS mfg_cus,

                cv.product_id        AS pid,
                cv.product_name      AS product_name,
                cv.itemcode          AS itemcode,
                cv.c1_id             AS c1_id,
                cv.c1_name           AS cat,
                cv.division          AS division,
                cv.weight            AS weight,
                cv.qty_per_case      AS quantity_per_case,
                cv.igst              AS product_gst,

                oc.cp_name           AS company_name,
                oc.cp_short_name     AS cp_short_name,

                prl.mrp              AS product_mrp,
                prl.dealer_rate      AS default_dealer_rate
               FROM catalog_view cv
               LEFT JOIN stock s
                       ON s.product_id = cv.product_id
                      AND s.dealer_id  = ?
               LEFT JOIN other_company oc
                       ON oc.id = cv.c0_company_id
               LEFT JOIN product_rate_list prl
                       ON prl.product_id = cv.product_id
                      AND prl.state_id   = ?
               WHERE cv.c0_company_id = ?
                 {$whereSql}
               ORDER BY cv.product_name ASC",
            array_merge([$dealerId, $stateId, $companyId], $bindings)
        );

        $productIds = array_map(fn($r) => (int) $r->pid, $rows);
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
            $rate = (float) ($r->mrp ? $r->dealer_rate : $r->default_dealer_rate);

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

    /**
     * GET /api/reports/balance-stock/filters
     */
    public function filters(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $level0 = DB::select(
            "SELECT DISTINCT c0_id AS id, c0_name AS name
               FROM catalog_view
              WHERE c0_company_id = ?
              ORDER BY c0_name",
            [$companyId]
        );

        $level1 = DB::select(
            "SELECT DISTINCT c1_id AS id, c1_name AS name, c0_id
               FROM catalog_view
              WHERE c0_company_id = ?
              ORDER BY c1_name",
            [$companyId]
        );

        $level2 = DB::select(
            "SELECT DISTINCT c2_id AS id, c2_name AS name, c1_id
               FROM catalog_view
              WHERE c0_company_id = ?
              ORDER BY c2_name",
            [$companyId]
        );

        $products = DB::select(
            "SELECT DISTINCT product_id AS id, product_name AS name,
                    itemcode, qty_per_case AS quantity_per_case, c2_id
               FROM catalog_view
              WHERE c0_company_id = ?
              ORDER BY product_name",
            [$companyId]
        );

        return response()->json([
            'status'   => true,
            'level_0'  => $level0,
            'level_1'  => $level1,
            'level_2'  => $level2,
            'products' => $products,
        ]);
    }

    private function buildFilter(Request $request): array
    {
        $where    = '';
        $bindings = [];

        if ($id = $request->input('catalog_0_id')) {
            $where     .= " AND cv.c0_id = ?";
            $bindings[] = (int) $id;
        }
        if ($id = $request->input('catalog_1_id')) {
            $where     .= " AND cv.c1_id = ?";
            $bindings[] = (int) $id;
        }
        if ($id = $request->input('catalog_2_id')) {
            $where     .= " AND cv.c2_id = ?";
            $bindings[] = (int) $id;
        }
        if ($id = $request->input('product_id')) {
            $where     .= " AND cv.product_id = ?";
            $bindings[] = (int) $id;
        }

        return [$where, $bindings];
    }

    private function intransitMap(int $dealerId, array $productIds): array
    {
        if (empty($productIds)) return [];
        $in = implode(',', array_map('intval', $productIds));

        $rows = DB::select(
            "SELECT pod.product_id,
                    SUM(pod.quantity + COALESCE(pod.scheme_qty,0)) AS qty
               FROM purchase_order_details pod
               INNER JOIN purchase_order po ON po.order_id = pod.order_id
              WHERE po.dealer_id = ?
                AND po.ch_date   = '1970-01-01'
                AND pod.product_id IN ({$in})
              GROUP BY pod.product_id",
            [$dealerId]
        );

        $map = [];
        foreach ($rows as $r) $map[(int)$r->product_id] = (float) $r->qty;
        return $map;
    }

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
        foreach ($rows as $r) $map[(int)$r->product_id] = (float) $r->qty;
        return $map;
    }
}