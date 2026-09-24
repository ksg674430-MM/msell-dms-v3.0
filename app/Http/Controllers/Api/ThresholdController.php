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
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $rows = DB::select(
            "SELECT t.id, t.product_id, t.qty, t.max_qty, t.retailer_id,
                    cp.name AS product_name, cp.itemcode
               FROM threshold t
               INNER JOIN catalog_product cp ON cp.id = t.product_id
              WHERE t.dealer_id = ? AND t.company_id = ?
              ORDER BY cp.name ASC",
            [$dealerId, $companyId]
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /**
     * GET /api/thresholds/products
     * Dropdown source for "New" form
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

        return response()->json([
            'status'   => true,
            'products' => $rows,
        ]);
    }

    /**
     * GET /api/thresholds/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $row = DB::selectOne(
            "SELECT id, product_id, qty, max_qty, retailer_id
               FROM threshold
              WHERE id = ? AND dealer_id = ? AND company_id = ? LIMIT 1",
            [$id, $dealerId, $companyId]
        );

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Threshold not found.'], 404);
        }

        return response()->json(['status' => true, 'row' => $row]);
    }

    /**
     * POST /api/thresholds
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

        // Verify product exists in this company
        $product = DB::selectOne(
            "SELECT id FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [$request->product_id, $companyId]
        );

        if (!$product) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid product selected.'
            ], 422);
        }

        // retailer_id: default to '' if not sent (DB is NOT NULL varchar(50))
        $retailerId = $request->input('retailer_id', '');

        DB::insert(
            "INSERT INTO threshold
                (company_id, dealer_id, product_id, qty, max_qty, retailer_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $companyId,
                $dealerId,
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

    /**
     * PUT /api/thresholds/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT id FROM threshold WHERE id = ? AND dealer_id = ? AND company_id = ? LIMIT 1",
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
            [$request->product_id, $companyId]
        );

        if (!$product) {
            return response()->json(['status' => false, 'message' => 'Invalid product selected.'], 422);
        }

        $retailerId = $request->input('retailer_id', '');

        DB::update(
            "UPDATE threshold SET
                product_id = ?, qty = ?, max_qty = ?, retailer_id = ?
             WHERE id = ?",
            [
                (int) $request->product_id,
                (int) $request->qty,
                (int) ($request->max_qty ?? 0),
                $retailerId,
                $id,
            ]
        );

        return response()->json(['status' => true, 'message' => 'Threshold updated successfully.']);
    }

    /**
     * DELETE /api/thresholds/{id}
     */
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