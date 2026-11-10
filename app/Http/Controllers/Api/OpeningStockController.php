<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\RateList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpeningStockController extends Controller
{
    /* ================================================================
     |  Company-405 dispatch helper
     ================================================================ */
    private function isLegacy405(Request $request): bool
    {
        return (int) ($request->user()->company_id ?? 0) === 405;
    }

    /**
     * Run the 405 legacy path; on any error (missing table/column,
     * query failure, etc.) log it and return $fallbackResponse from
     * the caller's standard code path. Never breaks non-405 flows.
     */
    private function withLegacyFallback(
        Request $request,
        string $context,
        callable $legacy,
        callable $fallback
    ): JsonResponse {
        if (!$this->isLegacy405($request)) {
            return $fallback();
        }

        try {
            return $legacy();
        } catch (\Throwable $e) {
            Log::warning(
                "OpeningStock[{$context}] 405 legacy path failed → falling back to standard. " .
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
            return $fallback();
        }
    }

    /* ================================================================
     |  GET /api/opening-stocks
     ================================================================ */
    public function index(Request $request): JsonResponse
    {
        return $this->withLegacyFallback(
            $request,
            'index',
            fn () => $this->indexLegacy405($request),
            function () use ($request) {
                $user      = $request->user();
                $dealerId  = (int) $user->dealer_id;
                $companyId = (int) ($user->company_id ?? 0);

                $where    = ["os.dealer_id = ?"];
                $bindings = [$dealerId];

                if ($from = $request->input('from_date')) {
                    $where[]    = "DATE_FORMAT(os.order_date, '%Y-%m-%d') >= ?";
                    $bindings[] = $from;
                }
                if ($to = $request->input('to_date')) {
                    $where[]    = "DATE_FORMAT(os.order_date, '%Y-%m-%d') <= ?";
                    $bindings[] = $to;
                }
                if ($orderNo = $request->input('order_no')) {
                    $where[]    = "os.product_id = ?";
                    $bindings[] = (int) $orderNo;
                }

                $whereSql = implode(' AND ', $where);

                $nameSql = $companyId === 213
                    ? "CONCAT(cp.name, '[', cp.quantity_per_case, ']')"
                    : "cp.name";

                $rows = DB::select(
                    "SELECT
                        os.*,
                        os.id                AS id,
                        {$nameSql}           AS product_name,
                        cp.itemcode          AS item_code,
                        cp.quantity_per_case AS quantity_per_case
                       FROM opening_stocks os
                       INNER JOIN catalog_product cp ON cp.id = os.product_id
                      WHERE {$whereSql}
                      ORDER BY cp.name ASC",
                    $bindings
                );

                return response()->json([
                    'status' => true,
                    'rows'   => $rows,
                ]);
            }
        );
    }

    /* ================================================================
     |  POST /api/opening-stocks
     ================================================================ */
    public function store(Request $request): JsonResponse
    {
        return $this->withLegacyFallback(
            $request,
            'store',
            fn () => $this->storeLegacy405($request),
            function () use ($request) {
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

                DB::beginTransaction();
                try {
                    foreach ($request->input('items') as $item) {
                        $productId    = (int)   $item['product_id'];
                        $batchNo      =         $item['batch_no'];
                        $mrp          = (float) $item['mrp'];
                        $rate         = (float) $item['dealer_rate'];
                        $qty          = (float) $item['qty'];
                        $salableQty   = (int)   ($item['salable_damage'] ?? 0);
                        $nsQty        = (int)   ($item['nonsalable_damage'] ?? 0);
                        $mfg          =         $item['mfg'];

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

                        app(StockService::class)->receive(
                            $dealerId, $productId, (string) $batchNo, $mfg, $mrp, $qty,
                            ['rate' => $rate, 'dealer_rate' => $rate, 'company_id' => $companyId],
                            ['dealer_rate' => $rate],
                            $salableQty,
                            $nsQty
                        );
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
                        'message' => 'Save failed: ' . safe_error_message($e),
                    ], 500);
                }
            }
        );
    }

    /* ================================================================
     |  PUT /api/opening-stocks/{id}
     ================================================================ */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->withLegacyFallback(
            $request,
            'update',
            fn () => $this->updateLegacy405($request, $id),
            function () use ($request, $id) {
                $user      = $request->user();
                $dealerId  = (int) $user->dealer_id;
                $companyId = (int) ($user->company_id ?? 1);

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
                    DB::delete("DELETE FROM opening_stocks WHERE id = ?", [$id]);

                    $item = $request->input('items.0');

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
                            $companyId,
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
                        'message' => 'Update failed: ' . safe_error_message($e),
                    ], 500);
                }
            }
        );
    }

    /* ================================================================
     |  DELETE endpoints
     ================================================================ */
    public function delete(Request $request, int $id): JsonResponse
    {
        return $this->deleteRows((int) $request->user()->dealer_id, [$id], $request);
    }

    public function deleteMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        return $this->deleteRows((int) $request->user()->dealer_id, $data['ids'], $request);
    }

    private function deleteRows(int $dealerId, array $ids, Request $request): JsonResponse
    {
        return $this->withLegacyFallback(
            $request,
            'delete',
            fn () => $this->deleteRowsLegacy405($dealerId, $ids),
            function () use ($ids, $dealerId) {
                return DB::transaction(function () use ($ids, $dealerId) {
                    $rows = DB::table('opening_stocks')
                        ->where('dealer_id', $dealerId)
                        ->whereIn('id', $ids)
                        ->lockForUpdate()
                        ->get();

                    if ($rows->isEmpty()) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'Opening stock record not found.',
                        ], 404);
                    }

                    foreach ($rows as $row) {
                        app(StockService::class)->unreceive(
                            $dealerId,
                            (int)    $row->product_id,
                            (string) $row->batch_no,
                            (string) $row->mfg,
                            (float)  $row->mrp,
                            (float)  $row->qty,
                            (float)  $row->nonsalable_damage
                        );
                    }

                    DB::table('opening_stocks')
                        ->where('dealer_id', $dealerId)
                        ->whereIn('id', $rows->pluck('id'))
                        ->delete();

                    $count = $rows->count();
                    return response()->json([
                        'status'  => true,
                        'message' => $count === 1
                            ? 'Opening stock deleted.'
                            : "{$count} opening stock entries deleted.",
                    ]);
                });
            }
        );
    }

    /* ================================================================
     |  GET /api/opening-stocks/products
     ================================================================ */
    public function products(Request $request): JsonResponse
    {
        return $this->withLegacyFallback(
            $request,
            'products',
            fn () => $this->productsLegacy405($request),
            function () use ($request) {
                $user      = $request->user();
                $companyId = (int) ($user->company_id ?? 0);

                $useSsRate = $user->rolename === 'DEPO'
                    || ($user->rolename === 'DISTRIBUTOR'
                        && (int) $user->ownership_type_id === 245
                        && $companyId === 213);
                $pcs       = (string) $user->quantity_type === '0';
                $rateCol   = $useSsRate
                    ? ($pcs ? 'ss_pcs_rate' : 'ss_case_rate')
                    : ($pcs ? 'dealer_pcs_rate' : 'dealer_rate');
                $mrpCol    = $pcs ? 'mrp_pcs' : 'mrp';
                $label     = $companyId === 213
                    ? "CONCAT_WS(' ', cp.name, '[', cp.quantity_per_case, ']')"
                    : "CONCAT_WS(' ', cp.itemcode, '-', cp.name, '[', c2.name, ']')";

                $rows = DB::select(
                    "SELECT cp.id,
                            {$label} AS name
                       FROM catalog_product cp
                       INNER JOIN catalog_2 c2 ON c2.id = cp.catalog_id
                      WHERE cp.company_id = ?
                        AND cp.status = 1
                      GROUP BY cp.id, cp.name, cp.itemcode, cp.quantity_per_case, c2.name
                      ORDER BY cp.name ASC",
                    [$companyId]
                );

                $rates = RateList::forDealer((int) $user->dealer_id, $companyId)
                    ->rows(array_map(fn ($r) => (int) $r->id, $rows));

                foreach ($rows as $row) {
                    $rate             = $rates->get($row->id);
                    $row->mrp         = $rate
                        ? number_format((float) $rate->{$mrpCol}, 2, '.', '')
                        : null;
                    $row->dealer_rate = $rate?->{$rateCol};
                }

                return response()->json([
                    'status'   => true,
                    'products' => $rows,
                ]);
            }
        );
    }

    /* ================================================================
     |  ███  COMPANY 405 — LEGACY "Opening Stock Details" IMPLEMENTATIONS
     |  Unchanged — this is what the fallback falls back FROM.
     ================================================================ */

    private function indexLegacy405(Request $request): JsonResponse
    {
        $user    = $request->user();
        $csaId   = (int) $user->dealer_id;

        $where    = ['cos.csa_id = ?'];
        $bindings = [$csaId];

        if ($from = $request->input('from_date')) {
            $where[]    = "DATE_FORMAT(cos.mfg, '%Y-%m-%d') >= ?";
            $bindings[] = $from;
        }
        if ($to = $request->input('to_date')) {
            $where[]    = "DATE_FORMAT(cos.mfg, '%Y-%m-%d') <= ?";
            $bindings[] = $to;
        }
        if ($pid = $request->input('order_no')) {
            $where[]    = 'cos.product_id = ?';
            $bindings[] = (int) $pid;
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT
                cos.*,
                cos.id                              AS id,
                cos.product_id                      AS product_id,
                cv.product_name                     AS product_name,
                cp.itemcode                         AS item_code,
                cp.quantity_per_case                AS quantity_per_case,
                ROUND(cos.qty * cos.dealer_rate, 2) AS stock_value
             FROM csa_opening_stocks cos
             INNER JOIN catalog_view    cv ON cv.product_id = cos.product_id
             LEFT  JOIN catalog_product cp ON cp.id         = cos.product_id
            WHERE {$whereSql}
            ORDER BY cv.product_name ASC",
            $bindings
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    private function storeLegacy405(Request $request): JsonResponse
    {
        $user     = $request->user();
        $csaId    = (int) $user->dealer_id;
        $company  = (int) ($user->company_id ?? 405);

        $request->validate([
            'items'                     => ['required', 'array', 'min:1'],
            'items.*.product_id'        => ['required', 'integer'],
            'items.*.batch_no'          => ['required', 'string', 'max:50'],
            'items.*.mrp'               => ['required', 'numeric'],
            'items.*.dealer_rate'       => ['required', 'numeric'],
            'items.*.qty'               => ['required', 'numeric', 'min:0'],
            'items.*.nonsalable_damage' => ['nullable', 'numeric', 'min:0'],
            'items.*.mfg'               => ['required', 'date_format:Y-m-d'],
        ]);

        DB::beginTransaction();
        try {
            foreach ($request->input('items') as $item) {
                $productId = (int)   $item['product_id'];
                $batchNo   =         $item['batch_no'];
                $mrp       = (float) $item['mrp'];
                $rate      = (float) $item['dealer_rate'];
                $qty       = (float) $item['qty'];
                $nsQty     = (float) ($item['nonsalable_damage'] ?? 0);
                $mfg       =         $item['mfg'];

                DB::insert(
                    "INSERT INTO csa_opening_stocks
                        (product_id, batch_no, rate, dealer_rate, mrp,
                         person_id, csa_id, dealer_id, qty,
                         salable_damage, nonsalable_damage, remaining,
                         mfg, expire, date, pr_rate, company_id,
                         action, sync_status, update_date_time)
                     VALUES
                        (?, ?, ?, ?, ?,
                         0, ?, 0, ?,
                         0, ?, ?,
                         ?, ?, NOW(), 0, ?,
                         1, 1, NOW())",
                    [
                        $productId, $batchNo, $rate, $rate, $mrp,
                        $csaId, $qty,
                        $nsQty, $qty,
                        $mfg, $mfg, $company,
                    ]
                );

                $existing = DB::selectOne(
                    "SELECT id FROM csa_stock
                      WHERE product_id = ? AND csa_id = ? AND batch_no = ? AND mfg = ?
                      LIMIT 1",
                    [$productId, $csaId, $batchNo, $mfg]
                );

                if ($existing) {
                    DB::update(
                        "UPDATE csa_stock
                            SET product_id = ?, batch_no = ?, dealer_rate = ?, mrp = ?,
                                qty = ?, nonsalable_damage = ?, mfg = ?, expire = ?,
                                date = NOW(), update_date_time = NOW()
                          WHERE csa_id = ? AND product_id = ? AND batch_no = ? AND mfg = ?",
                        [
                            $productId, $batchNo, $rate, $mrp,
                            $qty, $nsQty, $mfg, $mfg,
                            $csaId, $productId, $batchNo, $mfg,
                        ]
                    );
                } else {
                    DB::insert(
                        "INSERT INTO csa_stock
                            (product_id, batch_no, rate, dealer_rate, mrp,
                             person_id, csa_id, dealer_id, qty,
                             salable_damage, nonsalable_damage, remaining,
                             mfg, expire, date, pr_rate, company_id,
                             action, sync_status, update_date_time)
                         VALUES
                            (?, ?, ?, ?, ?,
                             0, ?, 0, ?,
                             0, ?, ?,
                             ?, ?, NOW(), 0, ?,
                             1, 1, NOW())",
                        [
                            $productId, $batchNo, $rate, $rate, $mrp,
                            $csaId, $qty,
                            $nsQty, $qty,
                            $mfg, $mfg, $company,
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
            throw $e; // let withLegacyFallback decide
        }
    }

    private function updateLegacy405(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $csaId   = (int) $user->dealer_id;
        $company = (int) ($user->company_id ?? 405);

        $existing = DB::selectOne(
            "SELECT * FROM csa_opening_stocks WHERE id = ? AND csa_id = ? LIMIT 1",
            [$id, $csaId]
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
            'items.*.nonsalable_damage' => ['nullable', 'numeric', 'min:0'],
            'items.*.mfg'               => ['required', 'date_format:Y-m-d'],
        ]);

        $item = $request->input('items.0');

        DB::beginTransaction();
        try {
            DB::table('csa_stock')
                ->where('csa_id', $csaId)
                ->where('product_id', $existing->product_id)
                ->where('batch_no',   $existing->batch_no)
                ->where('mfg',        $existing->mfg)
                ->delete();

            DB::update(
                "UPDATE csa_opening_stocks
                    SET product_id = ?, batch_no = ?, dealer_rate = ?, rate = ?, mrp = ?,
                        qty = ?, nonsalable_damage = ?, remaining = ?,
                        mfg = ?, expire = ?, update_date_time = NOW()
                  WHERE id = ? AND csa_id = ?",
                [
                    (int)   $item['product_id'],
                    $item['batch_no'],
                    (float) $item['dealer_rate'],
                    (float) $item['dealer_rate'],
                    (float) $item['mrp'],
                    (float) $item['qty'],
                    (float) ($item['nonsalable_damage'] ?? 0),
                    (float) $item['qty'],
                    $item['mfg'],
                    $item['mfg'],
                    $id, $csaId,
                ]
            );

            DB::insert(
                "INSERT INTO csa_stock
                    (product_id, batch_no, rate, dealer_rate, mrp,
                     person_id, csa_id, dealer_id, qty,
                     salable_damage, nonsalable_damage, remaining,
                     mfg, expire, date, pr_rate, company_id,
                     action, sync_status, update_date_time)
                 VALUES
                    (?, ?, ?, ?, ?,
                     0, ?, 0, ?,
                     0, ?, ?,
                     ?, ?, NOW(), 0, ?,
                     1, 1, NOW())",
                [
                    (int)   $item['product_id'],
                    $item['batch_no'],
                    (float) $item['dealer_rate'],
                    (float) $item['dealer_rate'],
                    (float) $item['mrp'],
                    $csaId,
                    (float) $item['qty'],
                    (float) ($item['nonsalable_damage'] ?? 0),
                    (float) $item['qty'],
                    $item['mfg'],
                    $item['mfg'],
                    $company,
                ]
            );

            DB::commit();
            return response()->json([
                'status'  => true,
                'message' => 'Opening stock updated successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function deleteRowsLegacy405(int $csaId, array $ids): JsonResponse
    {
        return DB::transaction(function () use ($ids, $csaId) {
            $rows = DB::table('csa_opening_stocks')
                ->where('csa_id', $csaId)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Opening stock record not found.',
                ], 404);
            }

            foreach ($rows as $r) {
                DB::table('csa_stock')
                    ->where('csa_id', $csaId)
                    ->where('product_id', $r->product_id)
                    ->where('batch_no',   $r->batch_no)
                    ->where('mfg',        $r->mfg)
                    ->delete();
            }

            DB::table('csa_opening_stocks')
                ->where('csa_id', $csaId)
                ->whereIn('id', $rows->pluck('id'))
                ->delete();

            $n = $rows->count();
            return response()->json([
                'status'  => true,
                'message' => $n === 1
                    ? 'Opening stock deleted.'
                    : "{$n} opening stock entries deleted.",
            ]);
        });
    }

    private function productsLegacy405(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 405);

        $rows = DB::select(
            "SELECT cp.id,
                    CONCAT_WS(' ', cp.name, '[', oc.cp_short_name, ']') AS name
               FROM catalog_product cp
               INNER JOIN catalog_3     c3 ON c3.id = cp.catalog_id
               INNER JOIN other_company oc ON oc.id = cp.company_id
              WHERE cp.company_id >= 1
              GROUP BY cp.id, cp.name, oc.cp_short_name
              ORDER BY cp.name ASC"
        );

        $rates = RateList::forDealer((int) $user->dealer_id, $companyId)
            ->rows(array_map(fn ($r) => (int) $r->id, $rows));

        $pcs = (string) $user->quantity_type === '0';
        foreach ($rows as $row) {
            $rate             = $rates->get($row->id);
            $row->mrp         = $rate
                ? number_format((float) ($pcs ? $rate->mrp_pcs : $rate->mrp), 2, '.', '')
                : null;
            $row->dealer_rate = $rate?->{ $pcs ? 'dealer_pcs_rate' : 'dealer_rate' };
        }

        return response()->json([
            'status'   => true,
            'products' => $rows,
        ]);
    }
}