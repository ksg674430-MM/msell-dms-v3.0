<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThresholdController extends Controller
{
    /**
     * GET /api/thresholds
     * Legacy: filter by dealer only, active products only.
     */
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $where    = ["t.dealer_id = ?", "cp.status = 1"];   // ✅ FIX #1 + #3
        $bindings = [$dealerId];

        if ($request->filled('retailer_id')) {
            $where[]    = "t.retailer_id = ?";
            $bindings[] = (string) $request->input('retailer_id');
        }
        if ($productId = $request->input('product_id')) {
            $where[]    = "t.product_id = ?";
            $bindings[] = (int) $productId;
        }
        if ($search = $request->input('search')) {
            $where[]    = "(cp.name LIKE ? OR cp.itemcode LIKE ?)";
            $bindings[] = "%{$search}%";
            $bindings[] = "%{$search}%";
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT t.id, t.product_id, t.qty, t.max_qty, t.retailer_id,
                    cp.name AS product_name, cp.itemcode
               FROM threshold t
               INNER JOIN catalog_product cp ON cp.id = t.product_id
              WHERE {$whereSql}
              ORDER BY cp.name ASC",
            $bindings
        );

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    /**
     * GET /api/thresholds/products
     */
    public function products(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $rows = DB::select(
            "SELECT id, name FROM catalog_product
              WHERE company_id = ? AND status = 1
              ORDER BY name ASC",
            [$companyId]
        );

        return response()->json(['status' => true, 'products' => $rows]);
    }

    /**
     * GET /api/thresholds/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        // ✅ FIX #3: no company_id filter — legacy rows might have different company
        $row = DB::selectOne(
            "SELECT t.id, t.product_id, t.qty, t.max_qty, t.retailer_id,
                    cp.name AS product_name, cp.itemcode
               FROM threshold t
               LEFT JOIN catalog_product cp ON cp.id = t.product_id
              WHERE t.id = ? AND t.dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        return response()->json(['status' => true, 'row' => $row]);
    }

    /**
     * POST /api/thresholds
     * Upsert on (dealer_id, product_id) — matches legacy behaviour.
     */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $request->validate([
            'product_id'  => ['required', 'integer'],
            'qty'         => ['required', 'integer', 'min:0'],
            'max_qty'     => ['nullable', 'integer', 'min:0'],
            'retailer_id' => ['nullable', 'string', 'max:50'],
        ]);

        $product = DB::selectOne(
            "SELECT id FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [(int) $request->product_id, $companyId]
        );
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Invalid product selected.'], 422);
        }

        $qty        = (int) $request->qty;
        $maxQty     = (int) ($request->max_qty ?? 0);
        $retailerId = (string) $request->input('retailer_id', '');

        // ✅ FIX #2: match on dealer + product only (ignore retailer_id)
        $existing = DB::selectOne(
            "SELECT id FROM threshold
              WHERE dealer_id = ? AND product_id = ?
              LIMIT 1",
            [$dealerId, (int) $request->product_id]
        );

        if ($existing) {
            // Update qty + max_qty only — retailer_id untouched (legacy doesn't overwrite)
            DB::update(
                "UPDATE threshold SET qty = ?, max_qty = ? WHERE id = ?",
                [$qty, $maxQty, (int) $existing->id]
            );

            return response()->json([
                'status'  => true,
                'message' => 'Threshold updated successfully.',
                'id'      => (int) $existing->id,
                'mode'    => 'update',
            ]);
        }

        DB::insert(
            "INSERT INTO threshold
                (company_id, dealer_id, product_id, qty, max_qty, retailer_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $companyId,
                $dealerId,
                (int) $request->product_id,
                $qty,
                $maxQty,
                $retailerId,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Threshold saved successfully.',
            'id'      => DB::getPdo()->lastInsertId(),
            'mode'    => 'insert',
        ]);
    }

    /**
     * PUT /api/thresholds/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        // ✅ FIX #3: dealer only
        $existing = DB::selectOne(
            "SELECT id FROM threshold WHERE id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        $request->validate([
            'product_id'  => ['required', 'integer'],
            'qty'         => ['required', 'integer', 'min:0'],
            'max_qty'     => ['nullable', 'integer', 'min:0'],
            'retailer_id' => ['nullable', 'string', 'max:50'],
        ]);

        $product = DB::selectOne(
            "SELECT id FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [(int) $request->product_id, $companyId]
        );
        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Invalid product selected.'], 422);
        }

        // Duplicate check: another row for same dealer+product?
        $dup = DB::selectOne(
            "SELECT id FROM threshold
              WHERE dealer_id = ? AND product_id = ? AND id != ?
              LIMIT 1",
            [$dealerId, (int) $request->product_id, $id]
        );
        if ($dup) {
            return response()->json([
                'status'  => false,
                'message' => 'Another threshold already exists for this product.'
            ], 422);
        }

        $update = [
            'product_id' => (int) $request->product_id,
            'qty'        => (int) $request->qty,
            'max_qty'    => (int) ($request->max_qty ?? 0),
        ];
        // Only overwrite retailer_id if explicitly provided (non-null)
        if ($request->has('retailer_id')) {
            $update['retailer_id'] = (string) $request->input('retailer_id');
        }

        DB::table('threshold')
            ->where('id', $id)
            ->where('dealer_id', $dealerId)
            ->update($update);

        return response()->json(['status' => true, 'message' => 'Threshold updated successfully.']);
    }

    /**
     * DELETE /api/thresholds/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        // ✅ FIX #3: dealer only
        $deleted = DB::table('threshold')
            ->where('id', $id)
            ->where('dealer_id', $dealerId)
            ->delete();

        if (!$deleted) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Threshold deleted successfully.']);
    }
}