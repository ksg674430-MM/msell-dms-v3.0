<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PersonController extends Controller
{
    /* =========================================================
     |  LIST — GET /api/persons
     |  Query: q, role_id, active (yes|no|all)
     ========================================================= */
    public function index(Request $request): JsonResponse
    {
        $auth = $request->user();
        abort_if(! $auth || empty($auth->company_id), 403, 'Company context missing from auth token.');

        $companyId = (int) $auth->company_id;
        $dealerId  = ! empty($auth->dealer_id) ? (int) $auth->dealer_id : null;

        $q = DB::table('person')
            ->join('person_login', 'person_login.person_id', '=', 'person.id')
            ->join('person_details', 'person_details.person_id', '=', 'person.id')
            ->join('_role', '_role.role_id', '=', 'person.role_id')
            ->leftJoin('person_finance_details', function ($join) use ($companyId) {
                $join->on('person_finance_details.person_id', '=', 'person.id')
                     ->where('person_finance_details.company_id', '=', $companyId);
            })
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('user_dealer_retailer')
                    ->whereColumn('user_dealer_retailer.user_id', 'person.id');
            })
            // Hide soft-deleted (legacy person_status = 9)
            ->where(function ($sub) {
                $sub->whereNull('person_login.person_status')
                    ->orWhere('person_login.person_status', '!=', 9);
            });

        // Dealer scope — matches legacy: user_dealer_retailer.dealer_id = X
        //                             OR dealer_person.dealer_id = X
        if ($dealerId !== null) {
            $q->where(function ($sub) use ($dealerId) {
                $sub->whereExists(function ($s) use ($dealerId) {
                    $s->select(DB::raw(1))
                      ->from('user_dealer_retailer')
                      ->whereColumn('user_dealer_retailer.user_id', 'person.id')
                      ->where('user_dealer_retailer.dealer_id', '=', $dealerId);
                })->orWhereExists(function ($s) use ($dealerId) {
                    $s->select(DB::raw(1))
                      ->from('dealer_person')
                      ->whereColumn('dealer_person.person_id', 'person.id')
                      ->where('dealer_person.dealer_id', '=', $dealerId);
                });
            });
        }

        if ($request->filled('role_id')) {
            $q->where('person.role_id', (int) $request->input('role_id'));
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $like = "%{$search}%";
            $q->where(function ($sub) use ($like) {
                $sub->where('person.first_name', 'LIKE', $like)
                    ->orWhere('person.last_name', 'LIKE', $like)
                    ->orWhere('person.mobile', 'LIKE', $like)
                    ->orWhere('person.email', 'LIKE', $like);
            });
        }

        $active = (string) $request->input('active', 'all');
        if ($active !== 'all') {
            $q->where('person_login.person_status', $active === 'no' ? 0 : 1);
        }

        $persons = $q->select([
            'person.id',
            'person.role_id',
            'person.company_id',
            'person.first_name',
            'person.middle_name',
            'person.last_name',
            'person.mobile',
            'person.email',
            'person.status',
(Schema::hasColumn('person', 'division')
    ? 'person.division'
    : (Schema::hasColumn('person_details', 'division')
        ? 'person_details.division'
        : DB::raw('NULL AS division'))),
'_role.rolename',
'person_details.gender',
            'person_details.address',
            'person_login.person_username',
            'person_login.person_status',
            'person_login.last_web_access_on',
            'person_login.last_mobile_access_on',
            DB::raw("CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name) AS name"),
            DB::raw("DATE_FORMAT(person_details.dob, '%d/%m/%Y') AS dob"),
            DB::raw("DATE_FORMAT(person_login.last_web_access_on, '%e/%b/%Y AT %r') AS lastvisit"),
        ])->orderByDesc('person.id')->get();

        return response()->json(['success' => true, 'data' => $persons], 200);
    }

    /* =========================================================
     |  SHOW — GET /api/persons/{id}
     ========================================================= */
    public function show(Request $request, $id): JsonResponse
    {
        $auth = $request->user();
        $row = $this->findPersonOrNull((int) $id, (int) $auth->company_id, $auth->dealer_id ?? null);

        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Person not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $row]);
    }

    /* =========================================================
     |  FORM DATA — GET /api/persons/form-data
     |  Dropdowns for the create/edit form
     |  → companies (admin only), dealer_user roles, statuses
     ========================================================= */
    public function formData(Request $request): JsonResponse
    {
        $auth = $request->user();
        $companyId = (int) ($auth->company_id ?? 0);

        $roles = DB::table('_role')
            ->where('role_group_id', 22)   // dealer user group — from legacy form
            ->orderBy('rolename')
            ->get(['role_id', 'rolename']);

        $companies = DB::table('company')->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'success'   => true,
            'roles'     => $roles,
            'companies' => $companies,
            'statuses'  => [
                ['value' => 1, 'label' => 'Active'],
                ['value' => 0, 'label' => 'Deactive'],
            ],
            'genders' => [
                ['value' => 'M', 'label' => 'Male'],
                ['value' => 'F', 'label' => 'Female'],
            ],
            'company_id' => $companyId,
        ]);
    }

    /* =========================================================
     |  STORE — POST /api/persons
     |  Transaction across 5 tables (mirrors legacy dsp_save)
     ========================================================= */
    public function store(Request $request): JsonResponse
    {
        $auth = $request->user();
        abort_if(! $auth || empty($auth->company_id), 403, 'Company context missing.');

        $companyId = (int) $auth->company_id;
        $dealerId  = (int) ($auth->dealer_id ?? 0);

        $v = Validator::make($request->all(), [
            'first_name'       => ['required', 'string', 'max:60'],
            'middle_name'      => ['nullable', 'string', 'max:60'],
            'last_name'        => ['required', 'string', 'max:60'],
            'mobile'           => ['required', 'string', 'regex:/^\d{10}$/'],
            'email'            => ['nullable', 'email', 'max:60'],
            'role_id'          => ['required', 'integer'],
            'company_id'       => ['nullable', 'integer'],
            'person_status'    => ['required', 'integer', 'in:0,1'],
            'address'          => ['nullable', 'string', 'max:500'],
            'gender'           => ['nullable', 'in:M,F'],
            'dob'              => ['nullable', 'date'],
            'alternate_number' => ['nullable', 'string', 'max:10'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        // Mobile uniqueness — legacy checks across ALL persons
        if (DB::table('person')->where('mobile', $request->input('mobile'))->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Mobile already exists, please provide a different value.',
            ], 409);
        }

        $effectiveCompanyId = (int) ($request->input('company_id') ?? $companyId);

        DB::beginTransaction();
        try {
            $personId = DB::table('person')->insertGetId([
                'first_name'        => $request->input('first_name'),
                'middle_name'       => $request->input('middle_name', ''),
                'last_name'         => $request->input('last_name'),
                'company_id'        => $effectiveCompanyId,
                'role_id'           => (int) $request->input('role_id'),
                'person_id_senior'  => $dealerId,
                'mobile'            => $request->input('mobile'),
                'email'             => $request->input('email', ''),
            ]);

            DB::table('person_login')->insert([
                'person_id'       => $personId,
                'person_username' => '',
                'person_password' => '',
                'person_status'   => (int) $request->input('person_status'),
            ]);

            DB::table('person_details')->insert([
                'person_id'        => $personId,
                'address'          => $request->input('address', ''),
                'gender'           => $request->input('gender', 'M'),
                'dob'              => $request->input('dob'),
                'alternate_number' => $request->input('alternate_number', ''),
                'created_on'       => now(),
            ]);

            DB::table('person_finance_details')->insert([
                'person_id'      => $personId,
                'bank_branch_id' => '',
                'account_number' => '',
                'pan_no'         => '',
                'tin_no'         => '0',
            ]);

            DB::table('user_dealer_retailer')->insert([
                'user_id'     => $personId,
                'dealer_id'   => $dealerId,
                'retailer_id' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dealer Person successfully saved.',
                'id'      => $personId,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Person store failed', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Person could not be created: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  UPDATE — PUT /api/persons/{id}
     |  Transaction across 4 tables (mirrors legacy dsp_edit)
     |  Note: user_dealer_retailer mapping is NOT touched (matching legacy)
     ========================================================= */
    public function update(Request $request, $id): JsonResponse
    {
        $auth = $request->user();
        abort_if(! $auth || empty($auth->company_id), 403);

        $dealerId = $auth->dealer_id ?? null;
        $existing = $this->findPersonOrNull((int) $id, (int) $auth->company_id, $dealerId);
        if (! $existing) {
            return response()->json(['success' => false, 'message' => 'Person not found.'], 404);
        }

        $v = Validator::make($request->all(), [
            'first_name'       => ['required', 'string', 'max:60'],
            'middle_name'      => ['nullable', 'string', 'max:60'],
            'last_name'        => ['required', 'string', 'max:60'],
            'mobile'           => ['required', 'string', 'regex:/^\d{10}$/'],
            'email'            => ['nullable', 'email', 'max:60'],
            'role_id'          => ['required', 'integer'],
            'company_id'       => ['nullable', 'integer'],
            'person_status'    => ['required', 'integer', 'in:0,1,9'],
            'address'          => ['nullable', 'string', 'max:500'],
            'gender'           => ['nullable', 'in:M,F'],
            'dob'              => ['nullable', 'date'],
            'alternate_number' => ['nullable', 'string', 'max:10'],
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $v->errors()], 422);
        }

        // Mobile uniqueness — exclude self
        if (DB::table('person')
                ->where('mobile', $request->input('mobile'))
                ->where('id', '!=', (int) $id)
                ->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Mobile already exists, please provide a different value.',
            ], 409);
        }

        DB::beginTransaction();
        try {
            DB::table('person')->where('id', (int) $id)->update([
                'first_name'  => $request->input('first_name'),
                'middle_name' => $request->input('middle_name', ''),
                'last_name'   => $request->input('last_name'),
                'company_id'  => (int) ($request->input('company_id') ?? $auth->company_id),
                'role_id'     => (int) $request->input('role_id'),
                'mobile'      => $request->input('mobile'),
                'email'       => $request->input('email', ''),
            ]);

            DB::table('person_login')->where('person_id', (int) $id)->update([
                'person_status' => (int) $request->input('person_status'),
            ]);

            DB::table('person_details')->where('person_id', (int) $id)->update([
                'address'          => $request->input('address', ''),
                'gender'           => $request->input('gender', 'M'),
                'dob'              => $request->input('dob'),
                'alternate_number' => $request->input('alternate_number', ''),
            ]);

            // Legacy updates finance_details to blank — keep that behaviour
            DB::table('person_finance_details')->where('person_id', (int) $id)->update([
                'bank_branch_id' => '',
                'account_number' => '',
                'pan_no'         => '',
                'tin_no'         => '0',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dealer Person successfully updated.',
                'id'      => (int) $id,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Person update failed', ['id' => $id, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /* =========================================================
     |  SOFT DELETE — DELETE /api/persons/{id}
     |  Legacy: person_login.person_status = 9 (not a real delete)
     ========================================================= */
    public function destroy(Request $request, $id): JsonResponse
    {
        $auth = $request->user();
        $dealerId = $auth->dealer_id ?? null;

        $existing = $this->findPersonOrNull((int) $id, (int) $auth->company_id, $dealerId);
        if (! $existing) {
            return response()->json(['success' => false, 'message' => 'Person not found.'], 404);
        }

        $affected = DB::table('person_login')
            ->where('person_id', (int) $id)
            ->update(['person_status' => 9]);

        if (! $affected) {
            return response()->json(['success' => false, 'message' => 'Person could not be deleted.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Person successfully deleted.']);
    }

    /* =========================================================
     |  TOGGLE ACTIVE — POST /api/persons/{id}/toggle-status
     |  Flips person_login.person_status between 0 (Deactive) and 1 (Active)
     ========================================================= */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        $auth = $request->user();
        $dealerId = $auth->dealer_id ?? null;

        $existing = $this->findPersonOrNull((int) $id, (int) $auth->company_id, $dealerId);
        if (! $existing) {
            return response()->json(['success' => false, 'message' => 'Person not found.'], 404);
        }

        $current = (int) (DB::table('person_login')->where('person_id', (int) $id)->value('person_status') ?? 0);
        // If currently deleted (9), restore to Active
        $next = $current === 9 ? 1 : ($current === 1 ? 0 : 1);

        DB::table('person_login')->where('person_id', (int) $id)->update(['person_status' => $next]);

        return response()->json([
            'success'       => true,
            'message'       => $next === 1 ? 'Person activated.' : 'Person deactivated.',
            'person_status' => $next,
        ]);
    }

    /* =========================================================
     |  Helper — fetch a single person, honouring dealer scope
     ========================================================= */
    private function findPersonOrNull(int $id, int $companyId, $dealerId): ?object
    {
        $q = DB::table('person')
            ->join('person_login', 'person_login.person_id', '=', 'person.id')
            ->join('person_details', 'person_details.person_id', '=', 'person.id')
            ->join('_role', '_role.role_id', '=', 'person.role_id')
            ->where('person.id', $id)
            ->where('person.company_id', $companyId);

        if (! empty($dealerId)) {
            $q->where(function ($sub) use ($dealerId) {
                $sub->whereExists(function ($s) use ($dealerId) {
                    $s->select(DB::raw(1))
                      ->from('user_dealer_retailer')
                      ->whereColumn('user_dealer_retailer.user_id', 'person.id')
                      ->where('user_dealer_retailer.dealer_id', '=', (int) $dealerId);
                })->orWhereExists(function ($s) use ($dealerId) {
                    $s->select(DB::raw(1))
                      ->from('dealer_person')
                      ->whereColumn('dealer_person.person_id', 'person.id')
                      ->where('dealer_person.dealer_id', '=', (int) $dealerId);
                });
            });
        }

        return $q->select([
            'person.id',
            'person.role_id',
            'person.company_id',
            'person.first_name',
            'person.middle_name',
            'person.last_name',
            'person.mobile',
            'person.email',
            'person.status',
            '_role.rolename',
            'person_details.gender',
            'person_details.address',
            'person_login.person_username',
            'person_login.person_status',
            'person_login.last_web_access_on',
            'person_login.last_mobile_access_on',
            DB::raw("CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name) AS name"),
            DB::raw("DATE_FORMAT(person_details.dob, '%d/%m/%Y') AS dob"),
            DB::raw("DATE_FORMAT(person_login.last_web_access_on, '%e/%b/%Y AT %r') AS lastvisit"),
        ])->first();
    }
}