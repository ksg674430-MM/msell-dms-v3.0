<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThresholdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $where    = ["t.dealer_id = ?", "t.company_id = ?"];
        $bindings = [$dealerId, $companyId];

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

    public function show(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $row = DB::selectOne(
            "SELECT t.id, t.product_id, t.qty, t.max_qty, t.retailer_id,
                    cp.name AS product_name, cp.itemcode
               FROM threshold t
               LEFT JOIN catalog_product cp ON cp.id = t.product_id
              WHERE t.id = ? AND t.dealer_id = ? AND t.company_id = ?
              LIMIT 1",
            [$id, $dealerId, $companyId]
        );

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        return response()->json(['status' => true, 'row' => $row]);
    }

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

        $retailerId = (string) $request->input('retailer_id', '');

        // ✅ FIX #2: Duplicate check
        $dup = DB::selectOne(
            "SELECT id FROM threshold
              WHERE dealer_id = ? AND company_id = ? AND product_id = ? AND retailer_id = ?
              LIMIT 1",
            [$dealerId, $companyId, (int) $request->product_id, $retailerId]
        );
        if ($dup) {
            return response()->json([
                'status'  => false,
                'message' => 'Threshold already exists for this product and retailer.'
            ], 422);
        }

        DB::insert(
            "INSERT INTO threshold
                (company_id, dealer_id, product_id, qty, max_qty, retailer_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $companyId, $dealerId,
                (int) $request->product_id,
                (int) $request->qty,
                (int) ($request->max_qty ?? 0),
                $retailerId,
            ]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Threshold saved successfully.',
            'id'      => DB::getPdo()->lastInsertId(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT id FROM threshold
              WHERE id = ? AND dealer_id = ? AND company_id = ?
              LIMIT 1",
            [$id, $dealerId, $companyId]
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

        $retailerId = (string) $request->input('retailer_id', '');

        // ✅ FIX #2: Duplicate check (exclude current)
        $dup = DB::selectOne(
            "SELECT id FROM threshold
              WHERE dealer_id = ? AND company_id = ? AND product_id = ? AND retailer_id = ? AND id != ?
              LIMIT 1",
            [$dealerId, $companyId, (int) $request->product_id, $retailerId, $id]
        );
        if ($dup) {
            return response()->json([
                'status'  => false,
                'message' => 'Another threshold already exists for this product and retailer.'
            ], 422);
        }

        // ✅ FIX #1: dealer_id + company_id in WHERE
        DB::update(
            "UPDATE threshold SET
                product_id = ?, qty = ?, max_qty = ?, retailer_id = ?
              WHERE id = ? AND dealer_id = ? AND company_id = ?",
            [
                (int) $request->product_id,
                (int) $request->qty,
                (int) ($request->max_qty ?? 0),
                $retailerId,
                $id, $dealerId, $companyId,
            ]
        );

        return response()->json(['status' => true, 'message' => 'Threshold updated successfully.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $deleted = DB::table('threshold')
            ->where('id', $id)
            ->where('dealer_id', $dealerId)
            ->where('company_id', $companyId)
            ->delete();

        if (!$deleted) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Threshold deleted successfully.']);
    }
}