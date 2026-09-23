<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ThresholdController extends Controller{
    //get controller 
    public function index(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $rows = DB::select(
        "SELECT t.id, t.product_id, t.qty, t.max_qty, cp.name AS product_name
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
// Stock threshold products  get
public function products(Request $request): JsonResponse
{
    $user      = $request->user();
    $companyId = (int) ($user->company_id ?? 0);

    $rows = DB::select(
        "SELECT id, name FROM catalog_product WHERE company_id = ? AND status = '1' ORDER BY name ASC",
        [$companyId]
    );

    return response()->json([
        'status'   => true,
        'products' => $rows,
    ]);
}

// Create controller 
public function store(Request $request): JsonResponse
{
    $user       = $request->user();
    $dealerId   = (int) $user->dealer_id;
    $companyId  = (int) ($user->company_id ?? 0);
    $retailerId = 0; // Legacy code sets this to 0

    $request->validate([
        'product_id' => ['required', 'integer'],
        'qty'        => ['required', 'numeric', 'min:0'],
        'max_qty'    => ['required', 'numeric', 'min:0'],
    ]);

    DB::insert(
        "INSERT INTO threshold (product_id, qty, max_qty, dealer_id, company_id, retailer_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [
            $request->product_id,
            $request->qty,
            $request->max_qty,
            $dealerId,
            $companyId,
            $retailerId
        ]
    );

    return response()->json(['status' => true, 'message' => 'Threshold saved successfully.']);
}

// Put in Threshold 
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
        return response()->json(['status' => false, 'message' => 'Threshold record not found.'], 404);
    }

    $request->validate([
        'product_id' => ['required', 'integer'],
        'qty'        => ['required', 'numeric', 'min:0'],
        'max_qty'    => ['required', 'numeric', 'min:0'],
    ]);

    DB::update(
        "UPDATE threshold SET product_id = ?, qty = ?, max_qty = ? WHERE id = ?",
        [
            $request->product_id,
            $request->qty,
            $request->max_qty,
            $id
        ]
    );

    return response()->json(['status' => true, 'message' => 'Threshold updated successfully.']);
}
// Delete Threshold
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
        return response()->json(['status' => false, 'message' => 'Record not found.'], 404);
    }

    return response()->json(['status' => true, 'message' => 'Threshold deleted successfully.']);
}
}