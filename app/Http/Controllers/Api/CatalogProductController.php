<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CatalogProductController extends Controller
{
    /**
     * GET /api/catalog-products
     * Optional filters: item_name, item_code, catalog_1_id, catalog_2_id, catalog_3_id
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);
        $stateId   = (int) ($user->state_id ?? 0);

        $where    = ["cp.company_id = ?"];
        $bindings = [$companyId];

        if ($itemName = $request->input('item_name')) {
            $where[] = "cv.product_name LIKE ?";
            $bindings[] = "%{$itemName}%";
        }
        if ($itemCode = $request->input('item_code')) {
            $where[] = "cv.itemcode LIKE ?";
            $bindings[] = "%{$itemCode}%";
        }

        // Dynamic Catalog Filters
        for ($i = 1; $i <= 4; $i++) { // Assuming max level 4
            if ($catId = $request->input("catalog_{$i}_id")) {
                $where[] = "cp.catalog_{$i}_id = ?";
                $bindings[] = (int) $catId;
            }
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT 
                cp.id, 
                cp.itemcode, 
                cp.name AS item_name, 
                cp.hsn_code, 
                cp.weight, 
                cp.unit, 
                cp.mrp, 
                cp.dealer_rate, 
                cp.image_name,
                cv.c1 AS category_name,
                cv.product_name,
                prl.rate AS state_rate
             FROM catalog_product cp
             INNER JOIN catalog_view cv ON cv.product_id = cp.id
             LEFT JOIN product_rate_list prl ON prl.product_id = cp.id AND prl.state_id = ?
             WHERE {$whereSql}
             ORDER BY cp.name ASC",
            array_merge([$stateId], $bindings)
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
        ]);
    }

    /**
     * GET /api/catalog-products/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $row = DB::selectOne(
            "SELECT * FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [$id, $companyId]
        );

        if (!$row) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        }

        // Fetch category hierarchy names if needed
        $categoryDetails = DB::selectOne(
            "SELECT 
                c1.name AS c1_name, 
                c2.name AS c2_name, 
                c3.name AS c3_name 
             FROM catalog_product cp
             LEFT JOIN catalog_1 c1 ON c1.id = cp.catalog_1_id
             LEFT JOIN catalog_2 c2 ON c2.id = cp.catalog_2_id
             LEFT JOIN catalog_3 c3 ON c3.id = cp.catalog_3_id
             WHERE cp.id = ?",
            [$id]
        );

        return response()->json([
            'status' => true,
            'row'    => $row,
            'categories' => $categoryDetails
        ]);
    }

    /**
     * GET /api/catalog-products/filters
     * Returns the cascading dropdown data for the form
     */
    public function filters(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        // Fetch Level 1
        $level1 = DB::select("SELECT id, name FROM catalog_1 WHERE company_id = ? ORDER BY name", [$companyId]);
        // Fetch Level 2
        $level2 = DB::select("SELECT id, name, catalog_1_id FROM catalog_2 WHERE company_id = ? ORDER BY name", [$companyId]);
        // Fetch Level 3
        $level3 = DB::select("SELECT id, name, catalog_2_id FROM catalog_3 WHERE company_id = ? ORDER BY name", [$companyId]);

        return response()->json([
            'status' => true,
            'level_1' => $level1,
            'level_2' => $level2,
            'level_3' => $level3,
        ]);
    }

    /**
     * POST /api/catalog-products
     */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $request->validate([
            'itemcode'      => ['required', 'string', 'max:50', 'unique:catalog_product,itemcode'],
            'name'          => ['required', 'string', 'max:255'],
            'image'         => ['nullable', 'image', 'max:2048'], // Max 2MB
            'weight'        => ['nullable', 'numeric'],
            'unit'          => ['required', 'string', 'max:20'],
            'catalog_1_id'  => ['required', 'integer'],
            'catalog_2_id'  => ['required', 'integer'],
            'catalog_3_id'  => ['nullable', 'integer'],
            'mrp'           => ['required', 'numeric'],
            'dealer_rate'   => ['required', 'numeric'],
        ]);

        DB::beginTransaction();
        try {
            $imageName = null;
            if ($request->hasFile('image')) {
                // Store in a public directory or S3 depending on your setup
                $path = $request->file('image')->store('product_images', 'public');
                $imageName = basename($path);
            }

            DB::insert(
                "INSERT INTO catalog_product 
                    (itemcode, name, image_name, weight, unit, catalog_1_id, catalog_2_id, catalog_3_id, mrp, dealer_rate, company_id, status, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())",
                [
                    $request->itemcode,
                    $request->name,
                    $imageName,
                    $request->weight ?? 0,
                    $request->unit,
                    $request->catalog_1_id,
                    $request->catalog_2_id,
                    $request->catalog_3_id,
                    $request->mrp,
                    $request->dealer_rate,
                    $companyId
                ]
            );

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Product saved successfully.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/catalog-products/{id}
     * Note: For file uploads via PUT, Laravel requires POST with _method=PUT. 
     * Frontend should send POST request with `_method` = 'PUT' when uploading files.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT id, image_name FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [$id, $companyId]
        );

        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        }

        $request->validate([
            'itemcode'      => ['required', 'string', 'max:50', "unique:catalog_product,itemcode,{$id}"],
            'name'          => ['required', 'string', 'max:255'],
            'image'         => ['nullable', 'image', 'max:2048'],
            'weight'        => ['nullable', 'numeric'],
            'unit'          => ['required', 'string', 'max:20'],
            'catalog_1_id'  => ['required', 'integer'],
            'catalog_2_id'  => ['required', 'integer'],
            'catalog_3_id'  => ['nullable', 'integer'],
            'mrp'           => ['required', 'numeric'],
            'dealer_rate'   => ['required', 'numeric'],
        ]);

        DB::beginTransaction();
        try {
            $imageName = $existing->image_name;
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($imageName && Storage::disk('public')->exists('product_images/' . $imageName)) {
                    Storage::disk('public')->delete('product_images/' . $imageName);
                }
                $path = $request->file('image')->store('product_images', 'public');
                $imageName = basename($path);
            }

            DB::update(
                "UPDATE catalog_product SET 
                    itemcode = ?, name = ?, image_name = ?, weight = ?, unit = ?, 
                    catalog_1_id = ?, catalog_2_id = ?, catalog_3_id = ?, 
                    mrp = ?, dealer_rate = ?, updated_at = NOW() 
                 WHERE id = ?",
                [
                    $request->itemcode,
                    $request->name,
                    $imageName,
                    $request->weight ?? 0,
                    $request->unit,
                    $request->catalog_1_id,
                    $request->catalog_2_id,
                    $request->catalog_3_id,
                    $request->mrp,
                    $request->dealer_rate,
                    $id
                ]
            );

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Product updated successfully.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/catalog-products/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT image_name FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            [$id, $companyId]
        );

        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        }

        DB::beginTransaction();
        try {
            if ($existing->image_name && Storage::disk('public')->exists('product_images/' . $existing->image_name)) {
                Storage::disk('public')->delete('product_images/' . $existing->image_name);
            }

            DB::delete("DELETE FROM catalog_product WHERE id = ?", [$id]);

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Product deleted successfully.']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }
}