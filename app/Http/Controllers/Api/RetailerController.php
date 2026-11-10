<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class RetailerController extends Controller
{
    /* =========================================================
     |  LIST
     |  GET /api/retailers
     |  Query: name, outlet_type_id, location_id, state_id, active
     | ========================================================= */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $where    = ["r.dealer_id = ?"];
        $bindings = [$dealerId];

        if ($companyId > 0) {
            $where[]    = "r.company_id = ?";
            $bindings[] = $companyId;
        }

        if ($name = $request->input('name')) {
            $where[]    = "r.name LIKE ?";
            $bindings[] = "%{$name}%";
        }
        if ($outlet = $request->input('outlet_type_id')) {
            $where[]    = "r.outlet_type_id = ?";
            $bindings[] = (int) $outlet;
        }
        if ($loc = $request->input('location_id')) {
            $where[]    = "r.location_id = ?";
            $bindings[] = (int) $loc;
        }
        if ($state = $request->input('state_id')) {
            $where[]    = "r.state_id_retailer = ?";
            $bindings[] = (int) $state;
        }
        // Active filter (default: active only)
        $active = $request->input('active', 'yes');
        if ($active !== 'all') {
            $where[] = "r.retailer_status = " . ($active === 'no' ? '0' : '1');
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT r.id,
                    r.retailer_code,
                    r.name,
                    r.address,
                    r.email,
                    r.landline,
                    r.other_numbers,
                    r.pin_no,
                    r.tin_no,
                    r.class,
                    r.outlet_type_id,
                    r.image_name,
                    r.avg_per_month_pur,
                    r.location_id,
                    r.state_id_retailer AS state_id,
                    r.retailer_status,
                    r.retailer_approval,
                    r.lat_long,
                    r.mncmcclatcellid,
                    r.contact_per_name,
                    l7.name          AS beat_name,
                    l6.name          AS city_name,
                    l3.state_code    AS state_code,
                    ot.outlet_type
               FROM retailer r
               LEFT JOIN location_7 l7            ON l7.id = r.location_id
               LEFT JOIN location_6 l6            ON l6.id = l7.location_6_id
               LEFT JOIN location_5 l5            ON l5.id = l6.location_5_id
               LEFT JOIN location_4 l4            ON l4.id = l5.location_4_id
               LEFT JOIN location_3 l3            ON l3.id = l4.location_3_id
               LEFT JOIN _retailer_outlet_type ot ON ot.id = r.outlet_type_id
              WHERE {$whereSql}
              ORDER BY r.id DESC
              LIMIT 500",
            $bindings
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
            'count'  => count($rows),
        ]);
    }

    public function creditLimits(Request $request): JsonResponse
    {
        $dealerId = (int) $request->user()->dealer_id;

        $rows = DB::select(
            "SELECT r.id, r.retailer_code, r.name, l7.name AS beat_name, r.credit_time_limit
               FROM retailer r
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
              WHERE r.dealer_id = ? AND r.retailer_status = '1'
              ORDER BY r.name ASC",
            [$dealerId]
        );

        $outstanding = app(InvoiceService::class)->outstanding($dealerId, array_column($rows, 'id'));

        return response()->json([
            'status' => true,
            'rows'   => array_map(fn ($r) => [
                'id'            => (string) $r->id,
                'retailer_code' => $r->retailer_code,
                'name'          => $r->name,
                'beat_name'     => $r->beat_name,
                'credit_limit'  => InvoiceService::parseCreditLimit($r->credit_time_limit),
                'outstanding'   => $outstanding[(string) $r->id] ?? 0.0,
            ], $rows),
        ]);
    }

    public function saveCreditLimits(Request $request): JsonResponse
    {
        $dealerId = (int) $request->user()->dealer_id;

        $data = $request->validate([
            'limits'                => ['required', 'array', 'min:1', 'max:5000'],
            'limits.*.retailer_id'  => ['required', 'regex:/^\d+$/', 'distinct'],
            'limits.*.credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        $ids   = array_map(fn ($l) => (string) $l['retailer_id'], $data['limits']);
        $owned = DB::table('retailer')->where('dealer_id', $dealerId)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (string) $v)->all();
        if ($missing = array_values(array_diff($ids, $owned))) {
            return response()->json(['status' => false, 'message' => 'Retailer not found for this distributor: ' . implode(', ', array_slice($missing, 0, 5))], 422);
        }

        DB::transaction(function () use ($data, $dealerId) {
            foreach ($data['limits'] as $l) {
                $limit = $l['credit_limit'] ?? null;
                DB::table('retailer')
                    ->where('id', (string) $l['retailer_id'])
                    ->where('dealer_id', $dealerId)
                    ->update(['credit_time_limit' => $limit === null ? null : number_format((float) $limit, 2, '.', '')]);
            }
        });

        return response()->json([
            'status'  => true,
            'message' => count($data['limits']) . ' credit limit(s) saved.',
        ]);
    }

    /* =========================================================
     |  SHOW ONE
     |  GET /api/retailers/{id}
     | ========================================================= */
    public function show(Request $request, $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;

        $retailer = DB::selectOne(
            "SELECT r.*,
                    l7.name AS beat_name,
                    l6.name AS city_name,
                    l5.name AS area_name,
                    l4.name AS district_name,
                    l3.name AS state_name,
                    l3.state_code,
                    l2.name AS region_name,
                    l1.name AS zone_name,
                    ot.outlet_type
               FROM retailer r
               LEFT JOIN location_7 l7            ON l7.id = r.location_id
               LEFT JOIN location_6 l6            ON l6.id = l7.location_6_id
               LEFT JOIN location_5 l5            ON l5.id = l6.location_5_id
               LEFT JOIN location_4 l4            ON l4.id = l5.location_4_id
               LEFT JOIN location_3 l3            ON l3.id = l4.location_3_id
               LEFT JOIN location_2 l2            ON l2.id = l3.location_2_id
               LEFT JOIN location_1 l1            ON l1.id = l2.location_1_id
               LEFT JOIN _retailer_outlet_type ot ON ot.id = r.outlet_type_id
              WHERE r.id = ? AND r.dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );

        if (!$retailer) {
            return response()->json([
                'status'  => false,
                'message' => 'Retailer not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $retailer,
        ]);
    }

    /* =========================================================
     |  FORM DATA
     |  GET /api/retailers/form-data
     |  Returns:
     |    - outlet_types (filtered by company + status)
     |    - class tiers (static)
     |    - location_levels metadata (label from _constant)
     |    - level_1 options (country/zone)  -- cascade via /locations
     | ========================================================= */
    public function formData(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) $user->company_id;

        // ---- Outlet types
        $outletTypes = DB::select(
            "SELECT id, outlet_type
               FROM _retailer_outlet_type
              WHERE company_id = ? AND status = 1
              ORDER BY sequence, outlet_type",
            [$companyId]
        );

        // ---- _constant row (fall back to company_id = 1)
        $cfg = DB::selectOne(
            "SELECT * FROM _constant
              WHERE company_id = ? AND constant_status = '1'
              LIMIT 1",
            [$companyId]
        );
        if (!$cfg) {
            $cfg = DB::selectOne(
                "SELECT * FROM _constant
                  WHERE constant_status = '1'
                  ORDER BY company_id
                  LIMIT 1"
            );
        }

        $retailerLevel = (int) ($cfg->retailer_level ?? 7);
        if ($retailerLevel < 1 || $retailerLevel > 7) {
            $retailerLevel = 7;
        }

        // ---- Location level metadata + level-1 options
        $levels = [];
        for ($i = 1; $i <= $retailerLevel; $i++) {
            $title = $cfg->{"location_title_{$i}"} ?? "Level {$i}";
            $parentField = $i > 1 ? "location_" . ($i - 1) . "_id" : null;
            $levels[] = [
                'level'        => $i,
                'field'        => "location_{$i}_id",
                'label'        => $title,
                'table'        => "location_{$i}",
                'parent_field' => $parentField,
            ];
        }

        // Level 1 rows (top of hierarchy)
        $level1Options = DB::select(
            "SELECT id, name FROM location_1 WHERE company_id = ? ORDER BY name",
            [$companyId]
        );

        $outletCategories = DB::select(
            "SELECT id, outlet_category
               FROM _retailer_outlet_category
              WHERE company_id = ? AND status = 1
              ORDER BY outlet_category",
            [$companyId]
        );

        return response()->json([
            'status'          => true,
            'outlet_types'    => $outletTypes,
            'outlet_categories' => $outletCategories,
            'location_levels' => $levels,
            'level_1_options' => $level1Options,
            'retailer_level'  => $retailerLevel,
            'dealer_id'       => (int) $user->dealer_id,
            'company_id'      => $companyId,
        ]);
    }

    /* =========================================================
     |  LOCATION CASCADE
     |  GET /api/retailers/locations?level=N&parent_id=X
     |  Returns children for a given level.
     |  - level=1: parent_id ignored, all top-level rows
     |  - level>1: requires parent_id
     | ========================================================= */
    // public function locations(Request $request): JsonResponse
    // {
        // $user      = $request->user();
        // $companyId = (int) $user->company_id;

        // $level = (int) $request->input('level', 1);
        // if ($level < 1 || $level > 7) {
            // return response()->json([
                // 'status'  => false,
                // 'message' => 'Invalid level. Use 1-7.',
            // ], 422);
        // }

        // $table = "location_{$level}";

        // if ($level === 1) {
            // $rows = DB::select(
                // "SELECT id, name FROM {$table}
                  // WHERE company_id = ?
                  // ORDER BY name",
                // [$companyId]
            // );
        // } else {
            // $parentId = $request->input('parent_id');
            // if ($parentId === null || $parentId === '') {
                // return response()->json([
                    // 'status'  => false,
                    // 'message' => "parent_id is required for level {$level}.",
                // ], 422);
            // }
            // $parentCol = "location_" . ($level - 1) . "_id";
            // $rows = DB::select(
                // "SELECT id, name FROM {$table}
                  // WHERE {$parentCol} = ? AND company_id = ?
                  // ORDER BY name",
                // [(int) $parentId, $companyId]
            // );
        // }

        // return response()->json([
            // 'status' => true,
            // 'level'  => $level,
            // 'rows'   => $rows,
            // 'count'  => count($rows),
        // ]);
    // }

    /* =========================================================
     |  CREATE
     |  POST /api/retailers
     |  JSON or multipart.
     |
     |  location_id  = deepest location picked by the user
     |  (this is what gets stored — cascade is client-side)
     | ========================================================= */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $v = Validator::make($request->all(), [
            'name'              => ['required', 'string', 'max:70'],
            'address'           => ['nullable', 'string', 'max:200'],
            'email'             => ['nullable', 'email', 'max:50'],
            'landline'          => ['nullable', 'string', 'max:50'],
            'other_numbers'     => ['nullable', 'string', 'max:200'],
            'contact_per_name'  => ['nullable', 'string', 'max:80'],
            'pin_no'            => ['nullable', 'string', 'max:20'],
            'tin_no'            => ['nullable', 'string', 'max:20'],
            'outlet_type_id'    => ['nullable', 'integer', Rule::exists('_retailer_outlet_type', 'id')->where('company_id', (int) $request->user()->company_id)],
            'class'             => ['nullable', 'integer'],
            'avg_per_month_pur' => ['nullable', 'numeric'],
            'location_id'       => ['nullable', 'integer', Rule::exists('location_7', 'id')->where('company_id', (int) $request->user()->company_id)],
            'state_id'          => ['nullable', 'integer'],
            'lat_long'          => ['nullable', 'string', 'max:30'],
            'track_address'     => ['nullable', 'string'],
            'image'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'image_name'        => ['nullable', 'string', 'max:350'],
        ]);
        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        // Duplicate name per dealer
        $dup = DB::selectOne(
            "SELECT id FROM retailer
              WHERE name = ? AND dealer_id = ?
              LIMIT 1",
            [$request->input('name'), $dealerId]
        );
        if ($dup) {
            return response()->json([
                'status'  => false,
                'message' => "A retailer named '{$request->input('name')}' already exists for this dealer.",
            ], 409);
        }

        $imageName = $this->handleImageUpload($request);

        // Required NOT NULL columns — provide safe defaults
        $trackAddress = (string) ($request->input('track_address') ?? '');
        if ($trackAddress === '') {
            $trackAddress = (string) ($request->input('address') ?? '');
        }

        $locationId = $request->input('location_id');
        $locationId = ($locationId === null || $locationId === '') ? null : (int) $locationId;

        DB::beginTransaction();
        try {
            DB::insert(
                "INSERT INTO retailer
                    (username, password, retailer_code, name, class,
                     image_name, dealer_id, location_id, company_id,
                     address, email, contact_per_name,
                     landline, other_numbers,
                     tin_no, pin_no, outlet_type_id,
                     avg_per_month_pur, lat_long, mncmcclatcellid,
                     track_address,
                     state_id_retailer,
                     created_on, created_by_person_id,
                     status, sync_status, retailer_status,
                     deactivated_by_user, deactivated_date_time,
                     verfiy_retailer_status, phone_update_by,
                     sequence_id, retailer_order_status)
                 VALUES
                    (?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     ?,
                     ?,
                     NOW(), ?,
                     1, 1, 1,
                     0, '1970-01-01 00:00:00',
                     0, 0,
                     0, 0)",
                [
                    $request->input('username'),                         // nullable
                    $request->input('password') ?: 'NA',                 // NOT NULL default NA
                    $request->input('retailer_code'),                    // nullable
                    $request->input('name'),
                    (int) ($request->input('class') ?? 0),
                    $imageName,
                    $dealerId,
                    $locationId,
                    $companyId,
                    $request->input('address'),
                    $request->input('email'),
                    $request->input('contact_per_name'),
                    $request->input('landline'),
                    $request->input('other_numbers'),
                    (string) ($request->input('tin_no') ?? '0'),         // NOT NULL default 0
                    $request->input('pin_no'),
                    $request->input('outlet_type_id'),
                    $request->input('avg_per_month_pur', 0),
                    $request->input('lat_long', '0'),
                    $request->input('mncmcclatcellid', '0'),
                    $trackAddress,
                    (int) ($request->input('state_id') ?? 0),
                    (int) $user->getKey(),
                ]
            );

            $newId = DB::selectOne("SELECT LAST_INSERT_ID() AS id")->id;

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Retailer created.',
                'id'      => (int) $newId,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Retailer could not be created: ' . safe_error_message($e),
            ], 500);
        }
    }

    /* =========================================================
     |  UPDATE
     |  PUT /api/retailers/{id}
     | ========================================================= */
    public function update(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT id, image_name, location_id, outlet_type_id FROM retailer
              WHERE id = ? AND dealer_id = ?
              LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json([
                'status'  => false,
                'message' => 'Retailer not found.',
            ], 404);
        }

        $v = Validator::make($request->all(), [
            'name'              => ['required', 'string', 'max:70'],
            'address'           => ['nullable', 'string', 'max:200'],
            'email'             => ['nullable', 'email', 'max:50'],
            'landline'          => ['nullable', 'string', 'max:50'],
            'other_numbers'     => ['nullable', 'string', 'max:200'],
            'contact_per_name'  => ['nullable', 'string', 'max:80'],
            'pin_no'            => ['nullable', 'string', 'max:20'],
            'tin_no'            => ['nullable', 'string', 'max:20'],
            'outlet_type_id'    => ['nullable', 'integer', ...((string) $request->input('outlet_type_id') === (string) $existing->outlet_type_id ? [] : [Rule::exists('_retailer_outlet_type', 'id')->where('company_id', (int) $request->user()->company_id)])],
            'class'             => ['nullable', 'integer'],
            'avg_per_month_pur' => ['nullable', 'numeric'],
            'location_id'       => ['nullable', 'integer', ...((string) $request->input('location_id') === (string) $existing->location_id ? [] : [Rule::exists('location_7', 'id')->where('company_id', (int) $request->user()->company_id)])],
            'state_id'          => ['nullable', 'integer'],
            'lat_long'          => ['nullable', 'string', 'max:30'],
            'track_address'     => ['nullable', 'string'],
            'image'             => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'image_name'        => ['nullable', 'string', 'max:350'],
        ]);
        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        // Duplicate name check (exclude self)
        $dup = DB::selectOne(
            "SELECT id FROM retailer
              WHERE name = ? AND dealer_id = ? AND id != ?
              LIMIT 1",
            [$request->input('name'), $dealerId, $id]
        );
        if ($dup) {
            return response()->json([
                'status'  => false,
                'message' => "Another retailer named '{$request->input('name')}' already exists for this dealer.",
            ], 409);
        }

        $imageName = $this->handleImageUpload($request) ?? $existing->image_name;

        $locationId = $request->input('location_id');
        $locationId = ($locationId === null || $locationId === '') ? null : (int) $locationId;

        DB::beginTransaction();
        try {
            DB::update(
                "UPDATE retailer SET
                    name              = ?,
                    class             = ?,
                    image_name        = ?,
                    location_id       = ?,
                    address           = ?,
                    email             = ?,
                    contact_per_name  = ?,
                    landline          = ?,
                    other_numbers     = ?,
                    tin_no            = ?,
                    pin_no            = ?,
                    outlet_type_id    = ?,
                    avg_per_month_pur = ?,
                    lat_long          = ?,
                    track_address     = ?,
                    state_id_retailer = ?,
                    updated_at        = NOW()
                  WHERE id = ? AND dealer_id = ?",
                [
                    $request->input('name'),
                    (int) ($request->input('class') ?? 0),
                    $imageName,
                    $locationId,
                    $request->input('address'),
                    $request->input('email'),
                    $request->input('contact_per_name'),
                    $request->input('landline'),
                    $request->input('other_numbers'),
                    (string) ($request->input('tin_no') ?? '0'),
                    $request->input('pin_no'),
                    $request->input('outlet_type_id'),
                    $request->input('avg_per_month_pur', 0),
                    $request->input('lat_long', '0'),
                    $request->input('track_address', ''),
                    (int) ($request->input('state_id') ?? 0),
                    $id,
                    $dealerId,
                ]
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Retailer updated.',
                'id'      => (int) $id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Retailer could not be updated: ' . safe_error_message($e),
            ], 500);
        }
    }

    /* =========================================================
     |  DELETE  (soft — retailer_status = 0)
     |  DELETE /api/retailers/{id}
     | ========================================================= */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT id, name FROM retailer WHERE id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json([
                'status'  => false,
                'message' => 'Retailer not found.',
            ], 404);
        }

        // Refuse to delete if outstanding challans exist
        $challans = DB::selectOne(
            "SELECT COUNT(*) AS c FROM challan_order WHERE ch_retailer_id = ?",
            [$id]
        );
        if ((int) $challans->c > 0) {
            return response()->json([
                'status'  => false,
                'message' => "Retailer has {$challans->c} challan(s) on record. Cannot deactivate.",
            ], 409);
        }

        DB::update(
            "UPDATE retailer
                SET retailer_status         = 0,
                    deactivated_by_user     = ?,
                    deactivated_date_time   = NOW(),
                    updated_at              = NOW()
              WHERE id = ? AND dealer_id = ?",
            [(int) $user->getKey(), $id, $dealerId]
        );

        return response()->json([
            'status'  => true,
            'message' => 'Retailer deactivated.',
            'id'      => (int) $id,
        ]);
    }

    /* =========================================================
     |  HELPERS
     | ========================================================= */

    /**
     * Save uploaded image to storage/app/public/retailers/{dealerId}/
     * and return the filename, or null if no image present.
     * Falls back to `image_name` string if the client pre-uploaded.
     */
    private function handleImageUpload(Request $request): ?string
    {
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $name = 'ret_' . date('YmdHis') . '_' . random_int(1000, 9999) . '.' . $ext;

            // Handle both JSON (base64) and multipart uploads
            $file->storeAs('retailers', $name, 'public');
            return $name;
        }

        $name = $request->input('image_name');
        return ($name !== null && $name !== '') ? (string) $name : null;
    }

    // public function query(Request $request): JsonResponse
// {
    // $user      = $request->user();
    // $dealerId  = (int) $user->dealer_id;
    // $companyId = (int) $user->company_id;

    // $fb = $this->filterRequest('retailer', $request)
        // ->hardScope('r.dealer_id = ?', [$dealerId]);

    // if ($companyId > 0) {
        // $fb->hardScope('r.company_id = ?', [$companyId]);
    // }

    // if ($fb->errors()) {
        // return response()->json([
            // 'status'  => false,
            // 'message' => 'Invalid filter.',
            // 'errors'  => $fb->errors(),
        // ], 422);
    // }

    // $result = $fb->run();

    // return response()->json([
        // 'status' => true,
        // 'data'   => $result['rows'],
        // 'meta'   => $result['meta'],
    // ]);
// }
/* =========================================================
 |  CLAIM RETAILERS
 |  GET /api/retailers/claim-retailers
 | ========================================================= */
public function claimRetailers(Request $request): JsonResponse
{
    $user      = $request->user();
    $dealerId  = (int) ($user->dealer_id ?? 0);
    $companyId = (int) ($user->company_id ?? 0);

    if ($dealerId <= 0) {
        return response()->json([
            'status'  => false,
            'message' => 'Dealer context missing from token.',
        ], 403);
    }

    $rows = DB::table('retailer AS r')
        ->leftJoin('challan_order AS co',
            'co.ch_retailer_id', '=', 'r.id')
        ->leftJoin('challan_order_details AS cod',
            'cod.ch_id', '=', 'co.id')
        ->where('r.dealer_id', $dealerId)
        ->when($companyId > 0, fn ($q) => $q->where('r.company_id', $companyId))
        ->groupBy('r.id', 'r.name')
        ->select([
            'r.id',
            'r.name',
            DB::raw('COALESCE(SUM(cod.taxable_amt), 0) AS achieved_amount'),
            DB::raw('MAX(co.ch_dealer_id) AS ch_dealer_id'),
        ])
        ->orderBy('r.name')
        ->get();

    $data = $rows->map(fn ($r) => [
        'retailer_id'     => (string) $r->id,
        'retailer_name'   => $r->name,
        'scheme_date'     => null,
        'scheme'          => null,
        'achieved_amount' => (float) $r->achieved_amount,
        'claim_gift'      => null,
        'ch_dealer_id'    => $r->ch_dealer_id,
    ])->values();

    return response()->json([
        'status' => true,
        'data'   => $data,
    ]);
}


}