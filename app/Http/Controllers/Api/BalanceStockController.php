<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RateList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class BalanceStockController extends Controller
{
    /**
     * Only company 405 uses the legacy report (catalog_view based).
     * All other companies keep the current behaviour.
     */
    private const LEGACY_COMPANY_ID = 405;

    /**
     * Legacy catalog_level for company 405 (catalog_1 → catalog_2 → product).
     * Matches $_SESSION[SESS.'constant']['catalog_level'] on the legacy page.
     * Change here if 405's catalog_level ever changes.
     */
    private const LEGACY_CATALOG_LEVEL = 2;

    /* =========================================================
     |  REPORT
     |  GET /api/reports/balance-stock
     |  Optional: catalog_1_id, catalog_2_id (for 405)
     |            catalog_0_id, catalog_1_id, catalog_2_id, product_id (others)
     | ========================================================= */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        if ($companyId === self::LEGACY_COMPANY_ID) {
            return $this->indexLegacy($request);
        }

        // ---------- existing behaviour (all other companies) ----------
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
                oc.cp_short_name                AS cp_short_name
               FROM catalog_product c
               LEFT  JOIN catalog_2 c2 ON c2.id = c.catalog_id
               INNER JOIN stock    s  ON s.product_id = c.id AND s.dealer_id = ?
               LEFT  JOIN other_company oc ON oc.id = c.company_id
               {$catalogJoinSql}
              WHERE 1=1 {$catalogWhereSql}
              ORDER BY c.name ASC",
            array_merge([$dealerId], $catalogBindings)
        );

        $productIds = array_values(array_unique(array_map(fn ($r) => (int) $r->pid, $rows)));
        $rateRows   = DB::table('catalog_product')->whereIn('id', $productIds)->get(['id', 'company_id'])
            ->groupBy('company_id')
            ->reduce(fn ($all, $products, $companyId) => $all->union(RateList::forDealer($dealerId, (int) $companyId)->rows($products->pluck('id')->all())), collect());
        foreach ($rows as $r) {
            $r->product_mrp         = $rateRows->get((int) $r->pid)?->mrp;
            $r->default_dealer_rate = $rateRows->get((int) $r->pid)?->dealer_rate;
        }
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
     |  LEGACY REPORT (company 405)
     |  Runs the exact catalog_view query + legacy helpers.
     | ========================================================= */
    private function indexLegacy(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $stateId   = (string) ($user->state_id ?? '');

        $dealerRow    = DB::selectOne(
            "SELECT edit_stock FROM dealer WHERE id = ? LIMIT 1",
            [$dealerId]
        );
        $canEditStock = (int) ($dealerRow->edit_stock ?? 0) === 1;

        // Resolve legacy $ch_filter (product_id IN (...) expansion)
        $chFilter = $this->buildLegacyChFilter($request, self::LEGACY_CATALOG_LEVEL);

        // ---- EXACT legacy query from balance_stock.php ----
        $rows = DB::select(
            "SELECT  s.batch_no,
                     oc.cp_name        AS company_name,
                     oc.cp_short_name,
                     s.id              AS stock_id,
                     c.c2_name         AS cat,
                     c.product_id      AS pid,
                     c.c2_id           AS c1_id,
                     s.rate,
                     c.product_name,
                     s.qty,
                     s.nonsalable_damage AS nonsale,
                     s.mrp,
                     s.dealer_rate,
                     p.division,
                     DATE_FORMAT(s.mfg,'%b-%Y') AS mfg
                FROM  catalog_view       c
                LEFT JOIN stock           s  ON c.product_id = s.product_id AND s.dealer_id = ?
                LEFT JOIN catalog_product p  ON c.product_id = p.id
                LEFT JOIN other_company   oc ON oc.id = p.company_id
               WHERE s.dealer_id = ? {$chFilter}
               GROUP BY s.dealer_id, s.product_id, s.batch_no, s.mfg
               ORDER BY c.c2_id, pid ASC",
            [$dealerId, $dealerId]
        );

        // ---- Legacy per-row enrichment ----
        $productIds = array_values(array_unique(array_map(fn ($r) => (int) $r->pid, $rows)));
        $mrpMap     = $this->legacyProductMrpMap($productIds, $stateId);   // product_rate_list by state_id
        $gstMap     = $this->legacyProductGstMap($productIds);             // _gst.igst via hsn_code
        $intrMap    = $this->legacyIntransitMap($dealerId, $rows);         // keyed by (pid|mrp)
        $threshMap  = $this->thresholdMap($dealerId, $productIds);         // unchanged

        $totalQty     = 0.0;
        $totalNonSale = 0.0;
        $totalIntr    = 0.0;
        $totalValue   = 0.0;
        $byC1         = [];   // legacy $value_sale keyed by catalog_2.id

        foreach ($rows as $r) {
            $pid   = (int) $r->pid;
            $qty   = (float) ($r->qty ?? 0);
            $non   = (float) ($r->nonsale ?? 0);
            $mrp   = (float) ($r->mrp ?? 0);
            $mrpEntry = $mrpMap[$pid] ?? ['mrp' => 0, 'd_rate' => 0];

            $r->product_mrp         = $mrpEntry['mrp'];
            $r->default_dealer_rate = $mrpEntry['d_rate'];
            $r->product_gst         = $gstMap[$pid] ?? 0;
            $r->intransit           = $intrMap[$pid . '|' . $mrp] ?? 0;
            $r->thresh              = (float) ($threshMap[$pid] ?? 0);

            // Legacy price logic (mirrors balance_stock.php)
            if ($mrp) {
                $d_rate = (float) ($r->dealer_rate ?? 0);
            } else {
                $mrp = (float) $r->product_mrp;
                if ((float) $r->product_gst) {
                    $r_rate = $mrp - ($mrp * 25 / 100);
                } else {
                    $r_rate = $mrp - ($mrp * 18 / 100);
                }
                $rates  = $this->ratesFromMrp($mrp, $r->product_gst, $r->division, $stateId);
                $d_rate = $rates['dealer_rate'] ?? $r_rate;
                $r->mrp = $mrp; // legacy overwrites local $mrp after re-derivation
            }

            $r->stock_value  = round($qty * $d_rate, 2);
            $r->display_name = $r->product_name . '[' . ($r->cp_short_name ?? '') . ']';
            $r->cat_color    = $this->legacyCatColor($r->cat);

            $key = (string) ($r->c1_id ?? '');   // catalog_view.c2_id
            if ($key !== '') {
                $byC1[$key] = ($byC1[$key] ?? 0) + $r->stock_value;
            }

            $totalQty     += $qty;
            $totalNonSale += $non;
            $totalIntr    += $r->intransit;
            $totalValue   += $r->stock_value;
        }

        return response()->json([
            'status'   => true,
            'can_edit' => $canEditStock,
            'rows'     => $rows,
            'by_c1_id' => $byC1,          // legacy $value_sale['1'], ['2'], ['4'], ...
            'totals'   => [
                'qty'         => $totalQty,
                'nonsale'     => $totalNonSale,
                'intransit'   => $totalIntr,
                'stock_value' => round($totalValue, 2),
            ],
        ]);
    }

    /* =========================================================
     |  FILTER — Product Classification
     |  GET /api/filters/classifications
     |  For 405 → catalog_1 (level 1). For others → catalog_0.
     | ========================================================= */
    public function classifications(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $dealerId  = (int) $request->user()->dealer_id;
        $isLegacy  = $companyId === self::LEGACY_COMPANY_ID;

        if ($isLegacy) {
            // Legacy: no status/stock filter, directly from catalog_1
            $rows = DB::select(
                "SELECT id, name FROM catalog_1
                  WHERE company_id = ?
                  ORDER BY catalog_1_sequence, name",
                [$companyId]
            );
        } else {
            $rows = DB::select(
                "SELECT id, name FROM catalog_0 c0
                  WHERE company_id = ?
                    AND (status = 1 OR EXISTS (
                         SELECT 1 FROM catalog_1 c1
                          INNER JOIN catalog_2 c2 ON c2.catalog_1_id = c1.id
                          INNER JOIN catalog_product p ON p.catalog_id = c2.id
                          INNER JOIN stock s ON s.product_id = p.id AND s.dealer_id = ?
                          WHERE c1.catalog_0_id = c0.id))
                  ORDER BY sequence, name",
                [$companyId, $dealerId]
            );
        }

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — Product Category
     |  GET /api/filters/categories?classification_id=X
     |  For 405 → catalog_2 (filtered by catalog_1_id).
     |  For others → catalog_1 (filtered by catalog_0_id).
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
        $dealerId  = (int) $request->user()->dealer_id;
        $isLegacy  = $companyId === self::LEGACY_COMPANY_ID;

        if ($isLegacy) {
            $rows = DB::select(
                "SELECT id, name FROM catalog_2 c2
                  WHERE catalog_1_id = ? AND company_id = ?
                  ORDER BY sequence, name",
                [$classificationId, $companyId]
            );
        } else {
            $rows = DB::select(
                "SELECT id, name FROM catalog_1 c1
                  WHERE catalog_0_id = ? AND company_id = ?
                    AND (status = 1 OR EXISTS (
                         SELECT 1 FROM catalog_2 c2
                          INNER JOIN catalog_product p ON p.catalog_id = c2.id
                          INNER JOIN stock s ON s.product_id = p.id AND s.dealer_id = ?
                          WHERE c2.catalog_1_id = c1.id))
                  ORDER BY catalog_1_sequence, name",
                [$classificationId, $companyId, $dealerId]
            );
        }

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — Product Name
     |  GET /api/filters/product-names?category_id=X
     |  For 405 → products (filtered by catalog_2_id) — TERMINAL level.
     |  For others → catalog_2 (filtered by catalog_1_id).
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
        $dealerId  = (int) $request->user()->dealer_id;
        $isLegacy  = $companyId === self::LEGACY_COMPANY_ID;

        if ($isLegacy) {
            // 405: catalog_2 is the deepest catalog level → return products
            $rows = DB::select(
                "SELECT id, CONCAT(name, '[', quantity_per_case, ']') AS name
                   FROM catalog_product p
                  WHERE catalog_id = ? AND company_id = ?
                  ORDER BY name",
                [$categoryId, $companyId]
            );
        } else {
            $rows = DB::select(
                "SELECT id, name FROM catalog_2 c2
                  WHERE catalog_1_id = ? AND company_id = ?
                    AND (status = 1 OR EXISTS (
                         SELECT 1 FROM catalog_product p
                          INNER JOIN stock s ON s.product_id = p.id AND s.dealer_id = ?
                          WHERE p.catalog_id = c2.id))
                  ORDER BY sequence, name",
                [$categoryId, $companyId, $dealerId]
            );
        }

        return response()->json([
            'status'  => true,
            'options' => $rows,
            'count'   => count($rows),
        ]);
    }

    /* =========================================================
     |  FILTER — SKU Name
     |  GET /api/filters/skus?product_name_id=X
     |  Not used for 405 (terminal is productNames).
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
        $dealerId  = (int) $request->user()->dealer_id;
        $isLegacy  = $companyId === self::LEGACY_COMPANY_ID;

        if ($isLegacy) {
            // 405 has no level-4; return empty so the frontend knows to stop
            return response()->json(['status' => true, 'options' => [], 'count' => 0]);
        }

        $rows = DB::select(
            "SELECT id, CONCAT(name, '[', quantity_per_case, ']') AS name
               FROM catalog_product p
              WHERE catalog_id = ? AND company_id = ?
                AND (status = 1 OR EXISTS (
                     SELECT 1 FROM stock s WHERE s.product_id = p.id AND s.dealer_id = ?))
              ORDER BY name",
            [$productNameId, $companyId, $dealerId]
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
     * Replicates sale::get_last_level_catalog_id_list() from the legacy page:
     * walk from the deepest selected catalog_N down to catalog_{$catalogLevel}
     * then catalog_product, and return " AND c.product_id IN (…) ".
     *
     * Returns '' when no catalog_*_id param is set.
     */
    private function buildLegacyChFilter(Request $request, int $catalogLevel): string
    {
        $count = 0;
        for ($i = 1; $i <= $catalogLevel; $i++) {
            if ($request->filled("catalog_{$i}_id")) {
                $count++;
            } else {
                break;
            }
        }
        if ($count === 0) {
            return '';
        }

        $lastLevelId = (int) $request->input("catalog_{$count}_id");
        if (!$lastLevelId) {
            return '';
        }

        $q = "SELECT catalog_product.id FROM catalog_{$count}";
        for ($i = $count; $i < $catalogLevel; $i++) {
            $j = $i + 1;
            $q .= " INNER JOIN catalog_{$j} ON catalog_{$j}.catalog_{$i}_id = catalog_{$i}.id";
        }
        $q .= " INNER JOIN catalog_product ON catalog_product.catalog_id = catalog_{$catalogLevel}.id";
        $q .= " WHERE catalog_{$count}.id = ?";

        $rows = DB::select($q, [$lastLevelId]);
        $ids  = array_map(fn ($r) => (int) $r->id, $rows);

        if (empty($ids)) {
            return ' AND 1=0'; // force empty result set
        }
        return ' AND c.product_id IN (' . implode(',', $ids) . ')';
    }

    /**
     * Legacy get_product_mrp(): product_rate_list filtered by state_id only.
     * Returns [pid => ['mrp' => X, 'd_rate' => Y]].
     */
    private function legacyProductMrpMap(array $productIds, string $stateId): array
    {
        if (empty($productIds)) return [];
        $in = implode(',', array_map('intval', $productIds));

        $rows = DB::select(
            "SELECT product_id, mrp, dealer_rate
               FROM product_rate_list
              WHERE product_id IN ({$in}) AND state_id = ?",
            [$stateId]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id] = [
                'mrp'    => (float) ($r->mrp ?: 0),
                'd_rate' => (float) ($r->dealer_rate ?: 0),
            ];
        }
        return $map;
    }

    /**
     * Legacy get_product_gst(): _gst.igst via catalog_product.hsn_code.
     * Returns [pid => igst].
     */
    private function legacyProductGstMap(array $productIds): array
    {
        if (empty($productIds)) return [];
        $in = implode(',', array_map('intval', $productIds));

        $rows = DB::select(
            "SELECT p.id AS pid, g.igst
               FROM catalog_product p
               LEFT JOIN _gst g ON g.hsn_code = p.hsn_code
              WHERE p.id IN ({$in})"
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->pid] = (float) ($r->igst ?: 0);
        }
        return $map;
    }

    /**
     * Legacy intransit_stock(): keyed by (pid|mrp), filtered by
     * ch_date = '1970-01-01' on purchase_order and matching mrp on details.
     */
    private function legacyIntransitMap(int $dealerId, array $stockRows): array
    {
        // Collect distinct (product_id, mrp) pairs from the stock rows
        $pairs = [];
        foreach ($stockRows as $r) {
            $pid = (int) $r->pid;
            $mrp = (float) ($r->mrp ?? 0);
            $pairs[$pid . '|' . $mrp] = [$pid, $mrp];
        }
        if (empty($pairs)) return [];

        $pids = array_values(array_unique(array_map(fn ($p) => $p[0], array_values($pairs))));
        $in   = implode(',', array_map('intval', $pids));

        $rows = DB::select(
            "SELECT pod.product_id, pod.mrp,
                    SUM(pod.quantity + COALESCE(pod.scheme_qty, 0)) AS qty
               FROM purchase_order_details pod
               INNER JOIN purchase_order po ON po.order_id = pod.order_id
              WHERE po.dealer_id = ?
                AND po.ch_date   = '1970-01-01'
                AND pod.product_id IN ({$in})
              GROUP BY pod.product_id, pod.mrp",
            [$dealerId]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r->product_id . '|' . (float) $r->mrp] = (float) $r->qty;
        }
        return $map;
    }

    /**
     * Legacy colour map for catalog_view.c2_name.
     */
    private function legacyCatColor(?string $cat): ?string
    {
        return match ($cat) {
            'ASV'  => '#F67C0F',
            'CLA'  => '#46154D',
            'GEN'  => '#0A87B2',
            'GLD'  => '#AD0606',
            'JPS'  => '#D2DC13',
            'OTC'  => '#4D59F0',
            'OT2'  => '#4D59F0',
            'FMCG' => '#4D051D',
            default => null,
        };
    }

    /**
     * Build the optional catalog filter for the CURRENT (non-405) report.
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
     * In-transit stock — used by the CURRENT (non-405) path.
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

    /* =========================================================
     |  DELETE / BULK EDIT  (unchanged — no 405-specific rules)
     | ========================================================= */

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

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

        DB::transaction(fn () => app(StockService::class)->deleteRow($dealerId, (int) $stock->id));

        return response()->json([
            'status'  => true,
            'message' => 'Stock successfully deleted',
        ]);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $user = $request->user();

        if ((int) ($user->company_id ?? 0) === 44) {
            return response()->json(['status' => false, 'message' => 'Delete not allowed.'], 403);
        }

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = DB::transaction(fn () => app(StockService::class)->deleteRows((int) $user->dealer_id, $data['ids']));
        if (!$count) {
            return response()->json(['status' => false, 'message' => 'Stock row not found.'], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => "{$count} stock rows deleted.",
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        if ($companyId === 44) {
            return response()->json(['status' => false, 'message' => 'Edit not allowed.'], 403);
        }

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
            'rows.*.batch_no'  => 'nullable|string|max:50',
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

                $mrpChanged = false;
                if (array_key_exists('mrp', $row) && $row['mrp'] !== null) {
                    $mrp   = (float) $row['mrp'];
                    $rates = $this->ratesFromMrp($mrp, $existing->gst_percent, $existing->division, $stateId);
                    $update['mrp']         = $mrp;
                    $update['rate']        = $rates['rate'];
                    $update['dealer_rate'] = $rates['dealer_rate'] ?? (float) $existing->dealer_rate;
                    $update['date']        = now();
                    $mrpChanged = (float) $existing->mrp > 0 && abs($mrp - (float) $existing->mrp) >= 0.001;
                }

                app(StockService::class)->editRow($dealerId, $companyId, $existing, $update, $mrpChanged);
            }
        });

        return response()->json(['status' => true, 'message' => 'Stock updated successfully.']);
    }
}