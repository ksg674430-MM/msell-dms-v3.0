<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    private const LEVELS = [1, 2, 3, 4, 5, 6, 7];

    /* =========================================================
     |  READ — flat list per level
     ========================================================= */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'mtype' => ['nullable', 'integer', Rule::in(self::LEVELS)],
        ]);

        $mtype = (int) ($validated['mtype'] ?? 1);
        $table = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => true, 'mtype' => $mtype, 'data' => []]);
        }

        $rows = DB::table($table)
            ->where('company_id', $companyId)
            ->select($this->listColumns($mtype))
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'mtype' => $mtype, 'data' => $rows]);
    }

    /* =========================================================
     |  READ — joined hierarchy (level 1..N names + ids)
     ========================================================= */
    public function categories(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'mtype' => ['required', 'integer', Rule::in(self::LEVELS)],
        ]);

        $mtype = (int) $validated['mtype'];
        $table = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => true, 'mtype' => $mtype, 'data' => []]);
        }

        $selects = ["{$table}.id AS id"];
        for ($k = 1; $k <= $mtype; $k++) {
            $lk = 'location_' . $k;
            $selects[] = "{$lk}.id   AS location_{$k}_id";
            $selects[] = "{$lk}.name AS location_{$k}_name";
        }
        $selects[] = "{$table}.company_id";
        if (Schema::hasColumn($table, 'status')) {
            $selects[] = "{$table}.status";
        }

        $query = DB::table($table)
            ->where("{$table}.company_id", $companyId)
            ->selectRaw(implode(', ', $selects));

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

        return response()->json(['success' => true, 'mtype' => $mtype, 'data' => $rows]);
    }

    /* =========================================================
     |  READ — show one
     ========================================================= */
    public function show(Request $request, int $id): JsonResponse
    {
        return $this->showOne($request, 1, $id);
    }

    public function showCategory(Request $request, int $mtype, int $id): JsonResponse
    {
        if (!in_array($mtype, self::LEVELS, true)) {
            return response()->json(['success' => false, 'myreason' => 'Invalid level; must be 1..7'], 422);
        }
        return $this->showOne($request, $mtype, $id);
    }

    private function showOne(Request $request, int $mtype, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $table = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => false, 'myreason' => 'Record not found'], 404);
        }

        $row = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->select($this->listColumns($mtype))
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'myreason' => 'Record not found'], 404);
        }
        return response()->json(['success' => true, 'data' => $row]);
    }

    /* =========================================================
     |  WRITE — create
     ========================================================= */
    public function store(Request $request): JsonResponse
    {
        return $this->storeOne($request, 1);
    }

    public function storeCategory(Request $request, int $mtype): JsonResponse
    {
        if (!in_array($mtype, self::LEVELS, true)) {
            return response()->json(['success' => false, 'myreason' => 'Invalid level; must be 1..7'], 422);
        }
        return $this->storeOne($request, $mtype);
    }

    private function storeOne(Request $request, int $mtype): JsonResponse
    {
        $companyId = $this->companyId($request);
        $user      = $request->user();
        $dealerId  = (int) ($user->dealer_id ?? 0);
        $userId    = (int) $user->id;
        $table     = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => false, 'myreason' => "Table {$table} not present"], 500);
        }

        $rules = ['name' => ['required', 'string', 'max:255']];
        if ($mtype > 1) {
            $rules['parent_id'] = ['required', 'integer', 'min:1'];
        }
        $validated = $request->validate($rules);

        $parentCol = null;
        if ($mtype > 1) {
            $parentCol   = 'location_' . ($mtype - 1) . '_id';
            $parentTable = 'location_' . ($mtype - 1);
            $parentOk = DB::table($parentTable)
                ->where('id', $validated['parent_id'])
                ->where('company_id', $companyId)
                ->exists();
            if (!$parentOk) {
                return response()->json([
                    'success'  => false,
                    'myreason' => "Parent #{$validated['parent_id']} not found at level " . ($mtype - 1),
                ], 422);
            }
        }

        // Duplicate name at same parent + company
        $dupQ = DB::table($table)
            ->where('name', $validated['name'])
            ->where('company_id', $companyId);
        if ($parentCol) {
            $dupQ->where($parentCol, $validated['parent_id']);
        }
        if ($dupQ->exists()) {
            return response()->json([
                'success'  => false,
                'myreason' => 'A record with this name already exists at the same parent.',
            ], 409);
        }

        // Legacy id format: user_id+timestamp for L1, dealer_id+timestamp for L2..7
        $idPrefix = $mtype === 1 ? $userId : $dealerId;
        $newId    = $idPrefix . date('YmdHis');

        DB::beginTransaction();
        try {
            $payload = [
                'id'         => $newId,
                'name'       => $validated['name'],
                'company_id' => $companyId,
            ];
            if ($parentCol) {
                $payload[$parentCol] = $validated['parent_id'];
            }

            DB::table($table)->insert($payload);

            // Legacy side-effect: beat_save() inserts a rate-list row for every level >= 2
            if ($mtype >= 2 && $dealerId > 0 && Schema::hasTable('dealer_location_rate_list')) {
                DB::table('dealer_location_rate_list')->insert([
                    'dealer_id'    => $dealerId,
                    'location_id'  => $newId,
                    'rate_list_id' => 1,
                    'company_id'   => $companyId,
                ]);
            }

            DB::commit();
            return response()->json([
                'success'  => true,
                'myreason' => 'Saved successfully',
                'id'       => $newId,
                'mtype'    => $mtype,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Location store failed', ['mtype' => $mtype, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'myreason' => 'Save failed: ' . $e->getMessage()], 500);
        }
    }

    /* =========================================================
     |  WRITE — update (with duplicate check + schema guard)
     ========================================================= */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->performUpdate($request, 1, $id);
    }

    public function updateCategory(Request $request, int $mtype, int $id): JsonResponse
    {
        if (!in_array($mtype, self::LEVELS, true)) {
            return response()->json(['success' => false, 'myreason' => 'Invalid level; must be 1..7'], 422);
        }
        return $this->performUpdate($request, $mtype, $id);
    }

    private function performUpdate(Request $request, int $mtype, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $table = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => false, 'myreason' => 'Record not found'], 404);
        }

        $hasStatus = Schema::hasColumn($table, 'status');

        $rules = ['name' => ['required', 'string', 'max:255']];
        if ($hasStatus) {
            $rules['status'] = ['nullable', 'integer', 'in:0,1'];
        }
        if ($mtype > 1) {
            $rules['parent_id'] = ['nullable', 'integer', 'min:1'];
        }
        $validated = $request->validate($rules);

        $exists = DB::table($table)
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->exists();

        if (!$exists) {
            return response()->json(['success' => false, 'myreason' => 'Record not found or not in your company.'], 404);
        }

        // Duplicate name at same parent (excluding self)
        $parentCol = $mtype > 1 ? 'location_' . ($mtype - 1) . '_id' : null;
        $parentVal = $parentCol
            ? ($validated['parent_id'] ?? DB::table($table)->where('id', $id)->value($parentCol))
            : null;

        $dupQ = DB::table($table)
            ->where('name', $validated['name'])
            ->where('id', '!=', $id)
            ->where('company_id', $companyId);
        if ($parentCol && $parentVal) {
            $dupQ->where($parentCol, $parentVal);
        }
        if ($dupQ->exists()) {
            return response()->json([
                'success'  => false,
                'myreason' => 'Another record with this name already exists at the same parent.',
            ], 409);
        }

        $update = ['name' => $validated['name']];

        if ($hasStatus && array_key_exists('status', $validated) && $validated['status'] !== null) {
            $update['status'] = (int) $validated['status'];
        }

        if ($mtype > 1 && !empty($validated['parent_id'])) {
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

        try {
            DB::table($table)->where('id', $id)->where('company_id', $companyId)->update($update);
            return response()->json([
                'success'  => true,
                'myreason' => 'Updated successfully',
                'data'     => array_merge(['id' => $id, 'mtype' => $mtype], $update),
            ]);
        } catch (\Throwable $e) {
            \Log::error('Location update failed', ['mtype' => $mtype, 'id' => $id, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'myreason' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /* =========================================================
     |  WRITE — delete (cascade-safe)
     ========================================================= */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->deleteOne($request, 1, $id);
    }

    public function destroyCategory(Request $request, int $mtype, int $id): JsonResponse
    {
        if (!in_array($mtype, self::LEVELS, true)) {
            return response()->json(['success' => false, 'myreason' => 'Invalid level; must be 1..7'], 422);
        }
        return $this->deleteOne($request, $mtype, $id);
    }

    private function deleteOne(Request $request, int $mtype, int $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $table = 'location_' . $mtype;

        if (!Schema::hasTable($table)) {
            return response()->json(['success' => false, 'myreason' => 'Record not found'], 404);
        }

        $row = DB::table($table)->where('id', $id)->where('company_id', $companyId)->first();
        if (!$row) {
            return response()->json(['success' => false, 'myreason' => 'Record not found'], 404);
        }

        // 1. Children at next level
        if ($mtype < 7) {
            $next = 'location_' . ($mtype + 1);
            $nextParentCol = 'location_' . $mtype . '_id';
            if (Schema::hasTable($next) && Schema::hasColumn($next, $nextParentCol)) {
                $children = DB::table($next)->where($nextParentCol, $id)->count();
                if ($children > 0) {
                    return response()->json([
                        'success'  => false,
                        'myreason' => "Cannot delete — {$children} child record(s) exist at level " . ($mtype + 1),
                    ], 409);
                }
            }
        }

        // 2. Dealer mapping
        if (Schema::hasTable('dealer_location_rate_list')) {
            $dealers = DB::table('dealer_location_rate_list')->where('location_id', $id)->count();
            if ($dealers > 0) {
                return response()->json([
                    'success'  => false,
                    'myreason' => "Cannot delete — {$dealers} dealer(s) are mapped to this location",
                ], 409);
            }
        }

        // 3. Retailer mapping (level 7 only)
        if ($mtype === 7 && Schema::hasTable('retailer')) {
            $retailers = DB::table('retailer')->where('location_id', $id)->count();
            if ($retailers > 0) {
                return response()->json([
                    'success'  => false,
                    'myreason' => "Cannot delete — {$retailers} retailer(s) are mapped to this beat",
                ], 409);
            }
        }

        try {
            DB::table($table)->where('id', $id)->where('company_id', $companyId)->delete();
            return response()->json(['success' => true, 'myreason' => 'Deleted successfully']);
        } catch (\Throwable $e) {
            \Log::error('Location delete failed', ['mtype' => $mtype, 'id' => $id, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'myreason' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }

    /* =========================================================
     |  FORM DATA — all parent levels + options for a given mtype
     ========================================================= */
    public function formData(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate([
            'mtype' => ['required', 'integer', Rule::in(self::LEVELS)],
        ]);
        $mtype = (int) $validated['mtype'];

        $parents = [];
        for ($i = 1; $i < $mtype; $i++) {
            $t = 'location_' . $i;
            if (!Schema::hasTable($t)) continue;
            $opts = DB::table($t)
                ->where('company_id', $companyId)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();
            $parents[] = [
                'level'   => $i,
                'field'   => "location_{$i}_id",
                'options' => $opts,
            ];
        }

        return response()->json(['success' => true, 'mtype' => $mtype, 'parents' => $parents]);
    }

    /* =========================================================
     |  Helpers
     ========================================================= */

    /**
     * Resolve company id with fallback chain.
     *
     * 1. $request->user()->company_id   — trusted when set and non-zero
     * 2. dealer.company_id              — authoritative fallback for stale login rows
     * 3. X-Company-Id request header    — for internal/service-to-service calls
     *
     * Aborts only when all three are empty.
     */
    private function companyId(Request $request): int
    {
        $auth = $request->user();
        abort_if(! $auth, 403, 'Unauthenticated.');

        // 1. Auth user — the token model's own column
        $companyId = (int) ($auth->company_id ?? 0);

        // 2. Dealer row — authoritative when login row is stale
        if ($companyId === 0 && ! empty($auth->dealer_id)) {
            $companyId = (int) (DB::table('dealer')
                ->where('id', $auth->dealer_id)
                ->value('company_id') ?? 0);
        }

        // 3. Explicit header — for internal service calls
        if ($companyId === 0) {
            $companyId = (int) $request->header('X-Company-Id', 0);
        }

        abort_if(
            $companyId === 0,
            403,
            'Company context missing. dealer_person_login.company_id and dealer.company_id are both empty for this user.'
        );

        return $companyId;
    }

    /** Columns to return per level — schema-guarded. */
    private function listColumns(int $mtype): array
    {
        $table = 'location_' . $mtype;
        $cols  = ['id', 'name', 'company_id'];

        if (Schema::hasColumn($table, 'status')) {
            $cols[] = 'status';
        }

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

        // Only include extras that actually exist
        $existingExtras = array_filter($extras, fn ($c) => Schema::hasColumn($table, $c));

        return array_merge($cols, $existingExtras);
    }
}