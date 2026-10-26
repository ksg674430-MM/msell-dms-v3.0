<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    private const LEVELS = [1, 2, 3, 4, 5, 6, 7];

    /**
     * GET /api/locations?mtype=N
     * Returns a flat list from location_<N>. Defaults to location_1
     * (merges legacy get_location_list + get_location_deletion_list).
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'mtype' => ['nullable', 'integer', Rule::in(self::LEVELS)],
        ]);

        $mtype = (int) ($validated['mtype'] ?? 1);
        $table = 'location_' . $mtype;

        $rows = DB::table($table)
            ->where('company_id', $companyId)
            ->select($this->listColumns($mtype))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'mtype'   => $mtype,
            'data'    => $rows,
        ]);
    }

    /**
     * GET /api/location-categories?mtype=N  (N in 1..7)
     * Joined hierarchy: returns each row with location_1..N names/ids.
     */
    public function categories(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'mtype' => ['required', 'integer', Rule::in(self::LEVELS)],
        ]);

        $mtype = (int) $validated['mtype'];
        $table = 'location_' . $mtype;

        // Explicit selects only — legacy used SELECT * plus re-aliasing,
        // which caused PDO to clobber id/name/company_id.
        $selects = ["{$table}.id AS id"];
        for ($k = 1; $k <= $mtype; $k++) {
            $lk = 'location_' . $k;
            $selects[] = "{$lk}.id   AS location_{$k}_id";
            $selects[] = "{$lk}.name AS location_{$k}_name";
        }
        $selects[] = "{$table}.company_id";
        $selects[] = "{$table}.status";

        $query = DB::table($table)
            ->where("{$table}.company_id", $companyId)
            ->selectRaw(implode(', ', $selects));

        // location_<i>.location_<j>_id = location_<j>.id
        // (location_3.location_2_id is varchar — MySQL casts implicitly)
        for ($i = $mtype; $i > 1; $i--) {
            $j = $i - 1;
            $query->join(
                "location_{$j}",
                "location_{$j}.id",
                '=',
                "location_{$i}.location_{$j}_id"
            );
        }

        $rows = $query->orderBy("{$table}.name")->get();

        return response()->json([
            'success' => true,
            'mtype'   => $mtype,
            'data'    => $rows,
        ]);
    }

    /**
     * PUT /api/locations/{id}
     * Edit a level-1 location.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->performUpdate($request, 1, $id);
    }

    /**
     * PUT /api/location-categories/{mtype}/{id}
     * Edit any level; mtype in the route prevents id/mtype mismatch.
     */
    public function updateCategory(Request $request, int $mtype, int $id): JsonResponse
    {
        if (!in_array($mtype, self::LEVELS, true)) {
            return response()->json([
                'success'  => false,
                'myreason' => 'Invalid level; must be 1..7',
            ], 422);
        }
        return $this->performUpdate($request, $mtype, $id);
    }

    // ─── shared update logic ──────────────────────────────────────────
    private function performUpdate(Request $request, int $mtype, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $table = 'location_' . $mtype;

        $rules = [
            'name'   => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ];
        if ($mtype > 1) {
            $rules['parent_id'] = ['nullable', 'integer', 'min:1'];
        }
        $validated = $request->validate($rules);

        $exists = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json([
                'success'  => false,
                'myreason' => 'Record not found or not in your company.',
            ], 404);
        }

        $update = ['name' => $validated['name']];

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $update['status'] = (int) $validated['status'];
        }

        if ($mtype > 1 && !empty($validated['parent_id'])) {
            $parentCol   = 'location_' . ($mtype - 1) . '_id';
            $parentTable = 'location_' . ($mtype - 1);

            $parentOk = DB::table($parentTable)
                ->where('id', $validated['parent_id'])
                ->where('company_id', $companyId)
                ->exists();

            if (!$parentOk) {
                return response()->json([
                    'success'  => false,
                    'myreason' => "Parent {$parentTable} #{$validated['parent_id']} not found or not in your company.",
                ], 422);
            }
            $update[$parentCol] = $validated['parent_id'];
        }

        DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->update($update);

        return response()->json([
            'success'  => true,
            'myreason' => 'Updated successfully',
            'data'     => array_merge(['id' => $id, 'mtype' => $mtype], $update),
        ]);
    }

    // ─── helpers ──────────────────────────────────────────────────────
    private function companyId(Request $request): int
    {
        $auth = $request->user();
        abort_if(
            ! $auth || empty($auth->company_id),
            403,
            'Company context missing from auth token.'
        );
        return (int) $auth->company_id;
    }

    /** Columns to return per level (no timestamps — use ?include=timestamps later if needed). */
    private function listColumns(int $mtype): array
    {
        $cols = ['id', 'name', 'company_id', 'status'];

        if ($mtype > 1) {
            $cols[] = 'location_' . ($mtype - 1) . '_id';
        }

        $extras = match ($mtype) {
            1 => ['st_id'],
            2 => ['sequence'],
            3 => ['state_abbriviation', 'state_code', 'sequence'],
            5 => ['day'],
            6 => ['created_by', 'updated_by', 'location_5_6_0_id',
                  'min_workforce', 'max_workforce', 'population'],
            7 => ['created_by', 'updated_by', 'beat_no', 'beat_frequency',
                  'kms', 'class_type_id', 'time', 'visit_count_con',
                  'delivery_day', 'supply_day',
                  'beat_wise_retailer_count_max', 'visit_count'],
            default => [],
        };

        return array_merge($cols, $extras);
    }
}