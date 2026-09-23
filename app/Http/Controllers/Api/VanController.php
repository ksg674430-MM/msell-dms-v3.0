<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class VanContoller extends Controller{
    // Master -> Supply Vehicle -> Main Table
    public function index(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $where    = ["dealer_id = ?", "company_id = ?"];
    $bindings = [$dealerId, $companyId];

    if ($vanNo = $request->input('van_no')) {
        $where[] = "van_no LIKE ?";
        $bindings[] = "%{$vanNo}%";
    }

    $whereSql = implode(' AND ', $where);

    $rows = DB::select(
        "SELECT id, van_no, driver_name, mobile, license_no, vtype, capacity, status, address
           FROM van
          WHERE {$whereSql}
          ORDER BY van_no ASC",
        $bindings
    );

    return response()->json([
        'status' => true,
        'rows'   => $rows,
    ]);
}

// Show for edit 
public function show(Request $request, int $id): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $row = DB::selectOne(
        "SELECT id, van_no, driver_name, mobile, license_no, vtype, capacity, status, address
           FROM van
          WHERE id = ? AND dealer_id = ? AND company_id = ? LIMIT 1",
        [$id, $dealerId, $companyId]
    );

    if (!$row) {
        return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
    }

    return response()->json(['status' => true, 'row' => $row]);
}

//create controller
public function store(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $request->validate([
        'van_no'      => ['required', 'string', 'max:50'],
        'driver_name' => ['nullable', 'string', 'max:100'],
        'mobile'      => ['nullable', 'string', 'max:15'],
        'license_no'  => ['nullable', 'string', 'max:50'],
        'vtype'       => ['nullable', 'string', 'max:50'],
        'capacity'    => ['nullable', 'numeric'],
        'status'      => ['required', 'integer', 'in:0,1'],
        'address'     => ['nullable', 'string'],
    ]);

    // Check duplicate van_no for this company
    $exists = DB::selectOne(
        "SELECT id FROM van WHERE van_no = ? AND company_id = ? LIMIT 1",
        [$request->van_no, $companyId]
    );

    if ($exists) {
        return response()->json([
            'status' => false,
            'message' => 'Van No already exists, please provide a different value.'
        ], 422);
    }

    DB::insert(
        "INSERT INTO van (van_no, driver_name, mobile, license_no, vtype, capacity, status, address, dealer_id, company_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $request->van_no,
            $request->driver_name,
            $request->mobile,
            $request->license_no,
            $request->vtype,
            $request->capacity ?? 0,
            $request->status,
            $request->address,
            $dealerId,
            $companyId
        ]
    );

    return response()->json(['status' => true, 'message' => 'Van saved successfully.']);
}

//Edit Controller 
public function update(Request $request, int $id): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) $user->dealer_id;
    $companyId = (int) ($user->company_id ?? 0);

    $existing = DB::selectOne(
        "SELECT id FROM van WHERE id = ? AND dealer_id = ? AND company_id = ? LIMIT 1",
        [$id, $dealerId, $companyId]
    );

    if (!$existing) {
        return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
    }

    $request->validate([
        'van_no'      => ['required', 'string', 'max:50'],
        'driver_name' => ['nullable', 'string', 'max:100'],
        'mobile'      => ['nullable', 'string', 'max:15'],
        'license_no'  => ['nullable', 'string', 'max:50'],
        'vtype'       => ['nullable', 'string', 'max:50'],
        'capacity'    => ['nullable', 'numeric'],
        'status'      => ['required', 'integer', 'in:0,1'],
        'address'     => ['nullable', 'string'],
    ]);

    // Check duplicate van_no for this company excluding current ID
    $exists = DB::selectOne(
        "SELECT id FROM van WHERE van_no = ? AND company_id = ? AND id != ? LIMIT 1",
        [$request->van_no, $companyId, $id]
    );

    if ($exists) {
        return response()->json([
            'status' => false,
            'message' => 'Van No already exists, please provide a different value.'
        ], 422);
    }

    DB::update(
        "UPDATE van SET van_no = ?, driver_name = ?, mobile = ?, license_no = ?, vtype = ?, capacity = ?, status = ?, address = ?
         WHERE id = ?",
        [
            $request->van_no,
            $request->driver_name,
            $request->mobile,
            $request->license_no,
            $request->vtype,
            $request->capacity ?? 0,
            $request->status,
            $request->address,
            $id
        ]
    );

    return response()->json(['status' => true, 'message' => 'Van updated successfully.']);
}
}