<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VanController extends Controller
{
    /**
     * GET /api/vans
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $where    = ["dealer_id = ?"];
        $bindings = [$dealerId];

        if ($vanNo = $request->input('van_no')) {
            $where[]    = "van_no LIKE ?";
            $bindings[] = "%{$vanNo}%";
        }
        if ($request->filled('status')) {
            $where[]    = "status = ?";
            $bindings[] = (int) $request->input('status');
        }
        if ($driver = $request->input('driver_name')) {
            $where[]    = "driver_name LIKE ?";
            $bindings[] = "%{$driver}%";
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT id, vanId, van_no, driver_name, mobile, license_no,
                    vtype, capacity, status, address, dealer_id, company_id
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

    // /**
     // * GET /api/vans/{id}
     // */
    // public function show(Request $request, int $id): JsonResponse
    // {
        // $user      = $request->user();
        // $dealerId  = (int) $user->dealer_id;

        // $row = DB::selectOne(
            // "SELECT id, vanId, van_no, driver_name, mobile, license_no,
                    // vtype, capacity, status, address
               // FROM van
              // WHERE id = ? AND dealer_id = ? LIMIT 1",
            // [$id, $dealerId]
        // );

        // if (!$row) {
            // return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
        // }

        // return response()->json(['status' => true, 'row' => $row]);
    // }

    /**
     * POST /api/vans
     */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $request->validate([
            'van_no'      => ['required', 'string', 'max:15'],
            'driver_name' => ['required', 'string', 'max:60'],
            'mobile'      => ['required', 'string', 'max:15'],
            'license_no'  => ['required', 'string', 'max:30'],
            'vtype'       => ['required', 'string', 'max:200'],
            'address'     => ['required', 'string'],
            'capacity'    => ['nullable', 'integer', 'min:0'],
            'status'      => ['nullable', 'integer', 'in:0,1'],
        ]);

        // Duplicate: dealer + company scoped
        $exists = DB::selectOne(
            "SELECT id FROM van
              WHERE van_no = ? AND dealer_id = ? AND company_id = ?
              LIMIT 1",
            [$request->van_no, $dealerId, $companyId]
        );
        if ($exists) {
            return response()->json([
                'status'  => false,
                'message' => 'Van No already exists, please provide a different value.'
            ], 422);
        }

        // Generate vanId: dealer_id (5-6 digits) + YmdHis (14 digits) = 19-20 digits
        // Bigint(20) safe. Retry on collision (rare).
        $vanId     = null;
        $attempts  = 0;
        do {
            $vanId = (int) ($dealerId . now()->format('YmdHis'));
            if (!DB::selectOne("SELECT id FROM van WHERE vanId = ? LIMIT 1", [$vanId])) {
                break;
            }
            usleep(200000); // 200ms
            $attempts++;
        } while ($attempts < 5);

        if ($attempts >= 5) {
            return response()->json([
                'status'  => false,
                'message' => 'Could not generate unique van ID. Please retry.'
            ], 500);
        }

        DB::insert(
            "INSERT INTO van
                (vanId, van_no, driver_name, mobile, license_no, vtype,
                 capacity, status, address, dealer_id, company_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $vanId,
                $request->van_no,
                $request->driver_name,
                $request->mobile,
                $request->license_no,
                $request->vtype,
                (int) ($request->capacity ?? 0),
                (int) ($request->status ?? 1),
                $request->address,
                $dealerId,
                $companyId,
            ]
        );

        $newId = DB::getPdo()->lastInsertId();

        return response()->json([
            'status'  => true,
            'message' => 'Van saved successfully.',
            'id'      => $newId,
            'vanId'   => $vanId,
        ]);
    }

    /**
     * PUT /api/vans/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT id FROM van
              WHERE id = ? AND dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
        }

        $request->validate([
            'van_no'      => ['required', 'string', 'max:15'],
            'driver_name' => ['required', 'string', 'max:60'],
            'mobile'      => ['required', 'string', 'max:15'],
            'license_no'  => ['required', 'string', 'max:30'],
            'vtype'       => ['required', 'string', 'max:200'],
            'address'     => ['required', 'string'],
            'capacity'    => ['nullable', 'integer', 'min:0'],
            'status'      => ['nullable', 'integer', 'in:0,1'],
        ]);

        $exists = DB::selectOne(
            "SELECT id FROM van
              WHERE van_no = ? AND dealer_id = ? AND company_id = ? AND id != ?
              LIMIT 1",
            [$request->van_no, $dealerId, $companyId, $id]
        );
        if ($exists) {
            return response()->json([
                'status'  => false,
                'message' => 'Van No already exists, please provide a different value.'
            ], 422);
        }

        DB::update(
            "UPDATE van SET
                van_no = ?, driver_name = ?, mobile = ?, license_no = ?,
                vtype = ?, capacity = ?, status = ?, address = ?
             WHERE id = ? AND dealer_id = ?",
            [
                $request->van_no,
                $request->driver_name,
                $request->mobile,
                $request->license_no,
                $request->vtype,
                (int) ($request->capacity ?? 0),
                (int) ($request->status ?? 1),
                $request->address,
                $id,
                $dealerId,
            ]
        );

        return response()->json(['status' => true, 'message' => 'Van updated successfully.']);
    }

    // /**
     // * DELETE /api/vans/{id}
     // * Soft delete — sets status = 0
     // */
    // public function destroy(Request $request, int $id): JsonResponse
    // {
        // $user      = $request->user();
        // $dealerId  = (int) $user->dealer_id;

        // $exists = DB::selectOne(
            // "SELECT id FROM van
              // WHERE id = ? AND dealer_id = ?
              // LIMIT 1",
            // [$id, $dealerId]
        // );
        // if (!$exists) {
            // return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
        // }

        // DB::update(
            // "UPDATE van SET status = 0
              // WHERE id = ? AND dealer_id = ?",
            // [$id, $dealerId]
        // );

        // return response()->json(['status' => true, 'message' => 'Van deleted successfully.']);
    // }
}