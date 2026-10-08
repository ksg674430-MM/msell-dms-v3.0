<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogProductController extends Controller
{
    /**
     * GET /api/catalog-products
     * Filters: item_name, item_code, catalog_1_id, catalog_2_id, catalog_3_id
     */
    public function index(Request $request): JsonResponse
{
    $user            = $request->user();
    $companyId       = (int) ($user->company_id ?? 0);
    $stateId         = (string) ($user->state_id ?? 0);
    $ownershipTypeId = (int) ($user->ownership_type_id ?? 0);

    // Per-piece rate column depends on login type
    // DB/SS (ownership_type_id = 245) → ss_pcs_rate, else dealer_pcs_rate
    $rateCol = ($ownershipTypeId === 245) ? 'ss_pcs_rate' : 'dealer_pcs_rate';

    // Company 213 only counts active rate rows
    $statusFilter = ($companyId === 213) ? " AND prl.status = 1" : "";

    // Bindings order: subquery1 state, subquery2 state, company WHERE, EXISTS state
    $bindings = [$stateId, $stateId, $companyId];

    $where = ["cp.company_id = ?"];

    // Legacy: only products that have a rate row for user's state
    $where[]    = "EXISTS (SELECT 1 FROM product_rate_list prl
                            WHERE prl.product_id = cp.id
                              AND prl.state_id = ?{$statusFilter})";
    $bindings[] = $stateId;

    if ($itemName = $request->input('item_name')) {
        $where[]    = "cv.product_name LIKE ?";
        $bindings[] = "%{$itemName}%";
    }
    if ($itemCode = $request->input('item_code')) {
        $where[]    = "cp.itemcode LIKE ?";
        $bindings[] = "%{$itemCode}%";
    }
    if ($c1 = $request->input('catalog_1_id')) {
        $where[]    = "cv.c0_id = ?";
        $bindings[] = (int) $c1;
    }
    if ($c2 = $request->input('catalog_2_id')) {
        $where[]    = "cv.c1_id = ?";
        $bindings[] = (int) $c2;
    }
    if ($c3 = $request->input('catalog_3_id')) {
        $where[]    = "cv.c2_id = ?";
        $bindings[] = (int) $c3;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT
                cp.id,
                cp.itemcode,
                cp.name              AS item_name,
                cp.hsn_code,
                cp.weight,
                cp.unit,
                cp.image_name,
                cp.quantity_per_case,
                cp.catalog_id,
                cv.product_name,
                cv.c0_id, cv.c0_name,
                cv.c1_id, cv.c1_name,
                cv.c2_id, cv.c2_name,
                (SELECT prl.mrp_pcs FROM product_rate_list prl
                    WHERE prl.product_id = cp.id AND prl.state_id = ?{$statusFilter}
                    LIMIT 1) AS mrp,
                (SELECT prl.{$rateCol} FROM product_rate_list prl
                    WHERE prl.product_id = cp.id AND prl.state_id = ?{$statusFilter}
                    LIMIT 1) AS dealer_rate
            FROM catalog_product cp
            LEFT JOIN catalog_view cv ON cv.product_id = cp.id
            WHERE {$whereSql}
            ORDER BY COALESCE(cv.c0_name,'') ASC,
                     COALESCE(cv.c1_name,'') ASC,
                     COALESCE(cv.c2_name,'') ASC,
                     cp.name ASC";

    $rows = DB::select($sql, $bindings);

    foreach ($rows as $row) {
        $row->image_url = legacy_public_url($row->image_name);
    }

    return response()->json([
        'status' => true,
        'rows'   => $rows,
    ]);
}
    // /**
     // * GET /api/catalog-products/{id}
     // */
   // public function show(Request $request, int $id): JsonResponse
// {
    // $user            = $request->user();
    // $companyId       = (int) ($user->company_id ?? 0);
    // $stateId         = (string) ($user->state_id ?? 0);
    // $ownershipTypeId = (int) ($user->ownership_type_id ?? 0);

    // $rateCol      = ($ownershipTypeId === 245) ? 'ss_pcs_rate' : 'dealer_pcs_rate';
    // $statusFilter = ($companyId === 213) ? " AND prl.status = 1" : "";

    // $row = DB::selectOne(
        // "SELECT
            // cp.*,
            // cv.product_name,
            // cv.c0_id, cv.c0_name,
            // cv.c1_id, cv.c1_name,
            // cv.c2_id, cv.c2_name,
            // (SELECT prl.mrp_pcs FROM product_rate_list prl
                // WHERE prl.product_id = cp.id AND prl.state_id = ?{$statusFilter}
                // LIMIT 1) AS mrp,
            // (SELECT prl.{$rateCol} FROM product_rate_list prl
                // WHERE prl.product_id = cp.id AND prl.state_id = ?{$statusFilter}
                // LIMIT 1) AS dealer_rate
         // FROM catalog_product cp
         // LEFT JOIN catalog_view cv ON cv.product_id = cp.id
         // WHERE cp.id = ? AND cp.company_id = ?
         // LIMIT 1",
        // [$stateId, $stateId, $id, $companyId]
    // );

    // if (!$row) {
        // return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
    // }

    // $row->image_url = ($row->image_name && $row->image_name !== 'NA')
        // ? url("myuploads/product/{$row->image_name}")
        // : null;

    // return response()->json(['status' => true, 'row' => $row]);
// }

    // /**
     // * GET /api/catalog-products/filters
     // * Cascading dropdown data
     // */
    // public function filters(Request $request): JsonResponse
    // {
        // $user      = $request->user();
        // $companyId = (int) ($user->company_id ?? 0);

        // $level0 = DB::select(
            // "SELECT id, name FROM catalog_1 WHERE company_id = ? AND status = 1 ORDER BY name ASC",
            // [$companyId]
        // );
        // $level1 = DB::select(
            // "SELECT id, name, catalog_1_id AS c0_id FROM catalog_2 WHERE company_id = ? AND status = 1 ORDER BY name ASC",
            // [$companyId]
        // );
        // $level2 = DB::select(
            // "SELECT id, name, catalog_2_id AS c1_id FROM catalog_3 WHERE company_id = ? AND status = 1 ORDER BY name ASC",
            // [$companyId]
        // );

        // return response()->json([
            // 'status'  => true,
            // 'level_0' => $level0,
            // 'level_1' => $level1,
            // 'level_2' => $level2,
        // ]);
    // }

    // /**
     // * POST /api/catalog-products
     // */
    // public function store(Request $request): JsonResponse
    // {
        // $user       = $request->user();
        // $companyId  = (int) ($user->company_id ?? 0);
        // $stateId    = (string) ($user->state_id ?? 0);
        // $templateId = (int) ($user->template_id ?? 0);

        // $request->validate([
            // 'itemcode'          => ['required', 'string', 'max:20'],
            // 'name'              => ['required', 'string', 'max:255'],
            // 'image'             => ['nullable', 'image', 'max:2048'],
            // 'weight'            => ['nullable', 'string', 'max:50'],
            // 'unit'              => ['nullable', 'string', 'max:10'],
            // 'hsn_code'          => ['nullable', 'string', 'max:20'],
            // 'catalog_1_id'      => ['required', 'integer'],
            // 'catalog_2_id'      => ['required', 'integer'],
            // 'catalog_3_id'      => ['required', 'integer'],
            // 'quantity_per_case' => ['nullable', 'integer'],
            // 'mrp'               => ['nullable', 'numeric'],
            // 'dealer_rate'       => ['nullable', 'numeric'],
            // 'gst_percent'       => ['nullable', 'numeric'],
        // ]);

        // // Duplicate check on itemcode + company
        // $dup = DB::selectOne(
            // "SELECT id FROM catalog_product WHERE itemcode = ? AND company_id = ? LIMIT 1",
            // [$request->itemcode, $companyId]
        // );
        // if ($dup) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Item Code already exists, please provide a different value.'
            // ], 422);
        // }

        // DB::beginTransaction();
        // try {
            // // Handle image upload (save to public/myuploads/product/, same as legacy)
            // $imageName = 'NA';
            // if ($request->hasFile('image')) {
                // $file     = $request->file('image');
                // $fileName = time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                // $file->move(public_path('myuploads/product'), $fileName);
                // $imageName = $fileName;
            // }

            // // catalog_product.catalog_id = deepest level category (catalog_3)
            // $catalogId = (int) $request->catalog_3_id;

            // DB::insert(
                // "INSERT INTO catalog_product
                    // (name, itemcode, hsn_code, weight, unit, catalog_id, image_name,
                     // quantity_per_case, base_price, gst_percent, company_id, status,
                     // created_at, updated_at)
                 // VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                // [
                    // $request->name,
                    // $request->itemcode,
                    // $request->hsn_code ?? '',
                    // $request->weight ?? '0',
                    // $request->unit ?? '100',
                    // $catalogId,
                    // $imageName,
                    // $request->quantity_per_case ?? 1,
                    // $request->dealer_rate ?? 0,
                    // $request->gst_percent ?? 0,
                    // $companyId,
                // ]
            // );

            // $newProductId = DB::getPdo()->lastInsertId();

            // // Insert rates into product_rate_list (only if provided)
            // if ($request->filled('mrp') || $request->filled('dealer_rate')) {
                // DB::insert(
                    // "INSERT INTO product_rate_list
                        // (template_id, product_id, state_id, distributor_id, ss_id,
                         // mrp, mrp_pcs, dealer_rate, dealer_pcs_rate,
                         // retailer_rate, retailer_pcs_rate,
                         // company_id, status, created_at, updated_at)
                     // VALUES (?, ?, ?, 0, 0,
                             // ?, ?, ?, ?,
                             // 0, 0,
                             // ?, 1, NOW(), NOW())",
                    // [
                        // $templateId,
                        // (string) $newProductId,
                        // $stateId,
                        // $request->mrp ?? 0,
                        // $request->mrp ?? 0,
                        // $request->dealer_rate ?? 0,
                        // $request->dealer_rate ?? 0,
                        // $companyId,
                    // ]
                // );
            // }

            // DB::commit();
            // return response()->json([
                // 'status'     => true,
                // 'message'    => 'Product saved successfully.',
                // 'product_id' => $newProductId,
            // ]);

        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Save failed: ' . $e->getMessage(),
            // ], 500);
        // }
    // }

    // /**
     // * PUT /api/catalog-products/{id}
     // * Note: For file upload, frontend should send POST with _method=PUT
     // */
    // public function update(Request $request, int $id): JsonResponse
    // {
        // $user       = $request->user();
        // $companyId  = (int) ($user->company_id ?? 0);
        // $stateId    = (string) ($user->state_id ?? 0);
        // $templateId = (int) ($user->template_id ?? 0);

        // $existing = DB::selectOne(
            // "SELECT id, image_name FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            // [$id, $companyId]
        // );
        // if (!$existing) {
            // return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        // }

        // $request->validate([
            // 'itemcode'          => ['required', 'string', 'max:20'],
            // 'name'              => ['required', 'string', 'max:255'],
            // 'image'             => ['nullable', 'image', 'max:2048'],
            // 'weight'            => ['nullable', 'string', 'max:50'],
            // 'unit'              => ['nullable', 'string', 'max:10'],
            // 'hsn_code'          => ['nullable', 'string', 'max:20'],
            // 'catalog_1_id'      => ['required', 'integer'],
            // 'catalog_2_id'      => ['required', 'integer'],
            // 'catalog_3_id'      => ['required', 'integer'],
            // 'quantity_per_case' => ['nullable', 'integer'],
            // 'mrp'               => ['nullable', 'numeric'],
            // 'dealer_rate'       => ['nullable', 'numeric'],
            // 'gst_percent'       => ['nullable', 'numeric'],
        // ]);

        // // Duplicate check excluding this row
        // $dup = DB::selectOne(
            // "SELECT id FROM catalog_product WHERE itemcode = ? AND company_id = ? AND id != ? LIMIT 1",
            // [$request->itemcode, $companyId, $id]
        // );
        // if ($dup) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Item Code already exists, please provide a different value.'
            // ], 422);
        // }

        // DB::beginTransaction();
        // try {
            // // Handle image replacement
            // $imageName = $existing->image_name;
            // if ($request->hasFile('image')) {
                // // Delete old image
                // if ($imageName && $imageName !== 'NA') {
                    // $oldPath = public_path("myuploads/product/{$imageName}");
                    // if (file_exists($oldPath)) @unlink($oldPath);
                // }
                // $file     = $request->file('image');
                // $fileName = time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                // $file->move(public_path('myuploads/product'), $fileName);
                // $imageName = $fileName;
            // }

            // $catalogId = (int) $request->catalog_3_id;

            // DB::update(
                // "UPDATE catalog_product SET
                    // name = ?, itemcode = ?, hsn_code = ?, weight = ?, unit = ?,
                    // catalog_id = ?, image_name = ?, quantity_per_case = ?,
                    // base_price = ?, gst_percent = ?, updated_at = NOW()
                 // WHERE id = ?",
                // [
                    // $request->name,
                    // $request->itemcode,
                    // $request->hsn_code ?? '',
                    // $request->weight ?? '0',
                    // $request->unit ?? '100',
                    // $catalogId,
                    // $imageName,
                    // $request->quantity_per_case ?? 1,
                    // $request->dealer_rate ?? 0,
                    // $request->gst_percent ?? 0,
                    // $id,
                // ]
            // );

            // // Update or insert rate
            // if ($request->filled('mrp') || $request->filled('dealer_rate')) {
                // $existingRate = DB::selectOne(
                    // "SELECT id FROM product_rate_list
                     // WHERE product_id = ? AND state_id = ? AND status = 1 LIMIT 1",
                    // [(string) $id, $stateId]
                // );

                // if ($existingRate) {
                    // DB::update(
                        // "UPDATE product_rate_list SET
                            // mrp = ?, mrp_pcs = ?, dealer_rate = ?, dealer_pcs_rate = ?,
                            // updated_at = NOW()
                         // WHERE id = ?",
                        // [
                            // $request->mrp ?? 0,
                            // $request->mrp ?? 0,
                            // $request->dealer_rate ?? 0,
                            // $request->dealer_rate ?? 0,
                            // $existingRate->id,
                        // ]
                    // );
                // } else {
                    // DB::insert(
                        // "INSERT INTO product_rate_list
                            // (template_id, product_id, state_id, distributor_id, ss_id,
                             // mrp, mrp_pcs, dealer_rate, dealer_pcs_rate,
                             // retailer_rate, retailer_pcs_rate,
                             // company_id, status, created_at, updated_at)
                         // VALUES (?, ?, ?, 0, 0,
                                 // ?, ?, ?, ?,
                                 // 0, 0,
                                 // ?, 1, NOW(), NOW())",
                        // [
                            // $templateId,
                            // (string) $id,
                            // $stateId,
                            // $request->mrp ?? 0,
                            // $request->mrp ?? 0,
                            // $request->dealer_rate ?? 0,
                            // $request->dealer_rate ?? 0,
                            // $companyId,
                        // ]
                    // );
                // }
            // }

            // DB::commit();
            // return response()->json([
                // 'status'  => true,
                // 'message' => 'Product updated successfully.',
            // ]);

        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Update failed: ' . $e->getMessage(),
            // ], 500);
        // }
    // }

    // /**
     // * DELETE /api/catalog-products/{id}
     // */
    // public function destroy(Request $request, int $id): JsonResponse
    // {
        // $user      = $request->user();
        // $companyId = (int) ($user->company_id ?? 0);

        // $existing = DB::selectOne(
            // "SELECT image_name FROM catalog_product WHERE id = ? AND company_id = ? LIMIT 1",
            // [$id, $companyId]
        // );
        // if (!$existing) {
            // return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
        // }

        // DB::beginTransaction();
        // try {
            // // Delete image
            // if ($existing->image_name && $existing->image_name !== 'NA') {
                // $path = public_path("myuploads/product/{$existing->image_name}");
                // if (file_exists($path)) @unlink($path);
            // }

            // // Delete rates
            // DB::delete("DELETE FROM product_rate_list WHERE product_id = ?", [(string) $id]);

            // // Delete product
            // DB::delete("DELETE FROM catalog_product WHERE id = ?", [$id]);

            // DB::commit();
            // return response()->json(['status' => true, 'message' => 'Product deleted successfully.']);

        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json(['status' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        // }
    // }
}