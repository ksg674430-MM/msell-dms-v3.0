<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminDistributorController extends Controller
{
    use RequiresAdmin;

    public function options(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        return response()->json([
            'status'       => true,
            'depots'       => DB::select(
                "SELECT l.div_code_main AS value, MIN(CONCAT(d.dealer_code, ' - ', d.name)) AS label
                   FROM dealer d
                   JOIN dealer_person_login l ON l.dealer_id = d.id
                   JOIN _role r ON r.role_id = l.role_id
                  WHERE d.company_id = ? AND d.dealer_status = 1 AND r.rolename IN ('DEPO', 'Depo')
                    AND COALESCE(l.div_code_main, '') <> ''
                  GROUP BY l.div_code_main
                  ORDER BY label",
                [$ctx->company_id]
            ),
            'territories'  => DB::select(
                "SELECT id AS value, name AS label FROM location_2 WHERE company_id = ? AND status = 1 ORDER BY name",
                [$ctx->company_id]
            ),
            'distributors' => DB::select(
                "SELECT id AS value, CONCAT(dealer_code, ' - ', name) AS label FROM dealer WHERE company_id = ? AND dealer_status <> 9 ORDER BY name",
                [$ctx->company_id]
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $perPage = min(max((int) $request->query('per_page', 25), 10), 500);
        $page = max((int) $request->query('page', 1), 1);
        $ids = $this->intList($request->query('distributor_ids'));
        $territories = $this->intList($request->query('territory_ids'));
        $depots = array_values(array_filter(array_map('strval', (array) $request->query('depots', [])), fn ($v) => $v !== ''));
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $where = ['d.company_id = ?', 'd.dealer_status <> 9'];
        $bind = [$ctx->company_id];
        if ($ids) {
            $where[] = 'd.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($bind, ...$ids);
        }
        if ($status === '1' || $status === '0') {
            $where[] = 'd.dealer_status = ?';
            $bind[] = (int) $status;
        }
        if ($depots) {
            $where[] = 'EXISTS (SELECT 1 FROM dealer_person_login l WHERE l.dealer_id = d.id AND l.div_code_main IN (' . implode(',', array_fill(0, count($depots), '?')) . '))';
            array_push($bind, ...$depots);
        }
        if ($territories) {
            $where[] = 'EXISTS (SELECT 1 FROM dealer_location_rate_list r JOIN location_view v ON v.l5_id = r.location_id
                                 WHERE r.dealer_id = d.id AND v.l2_id IN (' . implode(',', array_fill(0, count($territories), '?')) . '))';
            array_push($bind, ...$territories);
        }
        if ($search !== '') {
            $where[] = '(d.dealer_code LIKE ? OR d.name LIKE ? OR d.email LIKE ? OR d.other_numbers LIKE ?)';
            $like = '%' . addcslashes($search, '%_\\') . '%';
            array_push($bind, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);

        $total = (int) DB::scalar("SELECT COUNT(*) FROM dealer d WHERE $sqlWhere", $bind);
        $rows = DB::select(
            "SELECT d.id, d.dealer_code, d.name, d.email, d.other_numbers AS mobile, d.dealer_status
               FROM dealer d
              WHERE $sqlWhere
              ORDER BY d.id DESC
              LIMIT ? OFFSET ?",
            [...$bind, $perPage, ($page - 1) * $perPage]
        );

        $pageIds = array_map(fn ($r) => (int) $r->id, $rows);
        $logins = $territoryNames = [];
        if ($pageIds) {
            $in = implode(',', array_fill(0, count($pageIds), '?'));
            foreach (DB::select(
                "SELECT dealer_id, uname, div_code_main, lastvisit FROM dealer_person_login WHERE dealer_id IN ($in) ORDER BY dpId",
                $pageIds
            ) as $l) {
                $logins[$l->dealer_id] ??= $l;
            }
            foreach (DB::select(
                "SELECT r.dealer_id, GROUP_CONCAT(DISTINCT v.l2_name ORDER BY v.l2_name SEPARATOR ', ') AS names
                   FROM dealer_location_rate_list r JOIN location_view v ON v.l5_id = r.location_id
                  WHERE r.dealer_id IN ($in)
                  GROUP BY r.dealer_id",
                $pageIds
            ) as $t) {
                $territoryNames[$t->dealer_id] = $t->names;
            }
        }

        foreach ($rows as $r) {
            $l = $logins[$r->id] ?? null;
            $r->div_code = $l->div_code_main ?? '';
            $r->username = $l->uname ?? '';
            $r->last_login = $l->lastvisit ?? null;
            $r->territory = $territoryNames[$r->id] ?? '';
            $r->dealer_status = (int) $r->dealer_status;
        }

        return response()->json([
            'status' => true,
            'rows'   => $rows,
            'total'  => $total,
            'page'   => $page,
            'per_page' => $perPage,
        ]);
    }

    public function setStatus(Request $request, int $dealerId): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $action = $request->input('action');
        $map = ['active' => 1, 'inactive' => 0, 'delete' => 9];
        if (!isset($map[$action])) {
            return response()->json(['status' => false, 'message' => 'Unknown action.'], 422);
        }
        if ($dealerId === $ctx->dealer_id) {
            return response()->json(['status' => false, 'message' => 'You cannot change your own account here.'], 422);
        }

        $updated = DB::table('dealer')
            ->where('id', $dealerId)
            ->where('company_id', $ctx->company_id)
            ->where('dealer_status', '<>', 9)
            ->update(['dealer_status' => $map[$action], 'deleted_deactivated_on' => now()]);

        if (!$updated) {
            return response()->json(['status' => false, 'message' => 'Distributor not found.'], 404);
        }

        $messages = ['active' => 'Distributor activated.', 'inactive' => 'Distributor deactivated.', 'delete' => 'Distributor deleted.'];
        return response()->json(['status' => true, 'message' => $messages[$action]]);
    }

    public function formOptions(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $levels = [];
        foreach ([1 => null, 2 => 'location_1_id', 3 => 'location_2_id', 4 => 'location_3_id', 5 => 'location_4_id'] as $level => $parent) {
            $levels[$level] = DB::select(
                "SELECT id AS value, name AS label" . ($parent ? ", $parent AS parent" : '') . " FROM location_$level WHERE company_id = ? AND status = 1 ORDER BY name",
                [$ctx->company_id]
            );
        }

        return response()->json([
            'status'     => true,
            'csa'        => DB::select("SELECT c_id AS value, csa_name AS label FROM csa WHERE company_id = ? AND active_status = 1 ORDER BY csa_name", [$ctx->company_id]),
            'div_codes'  => $ctx->erp ? DB::select("SELECT name AS value, name AS label FROM _div_code_master WHERE status = 1 ORDER BY name") : [],
            'roles'      => $this->roles($ctx),
            'divisions'  => DB::select("SELECT id AS value, type AS label FROM _product_division WHERE company_id IN (0, ?) ORDER BY type", [$ctx->company_id]),
            'locations'  => $levels,
        ]);
    }

    public function show(Request $request, int $dealerId): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $dealer = DB::selectOne(
            "SELECT id, dealer_code, name, contact_person, address, email, landline, other_numbers AS mobile, tin_no, pin_no, csa_id, state_id, town_id
               FROM dealer WHERE id = ? AND company_id = ? AND dealer_status <> 9",
            [$dealerId, $ctx->company_id]
        );
        if (!$dealer) return response()->json(['status' => false, 'message' => 'Distributor not found.'], 404);

        $login = DB::selectOne(
            "SELECT uname, role_id, div_code_main, regis_status, AES_DECRYPT(pass, ?) AS password FROM dealer_person_login WHERE dealer_id = ? ORDER BY dpId LIMIT 1",
            [config('app.edsalt'), $dealerId]
        );
        $beats = DB::table('dealer_location_rate_list')->where('dealer_id', $dealerId)->distinct()->pluck('location_id')->map(fn ($v) => (int) $v);
        $report = DB::table('dealer_report_section_data')->where('dealer_id', $dealerId)->get(['location_2', 'product_divison_id']);
        $path = $this->locationPath((int) $dealer->town_id, $ctx->company_id);

        return response()->json([
            'status' => true,
            'dealer' => $dealer,
            'login'  => $login ? ['username' => $login->uname, 'password' => (string) $login->password, 'role_id' => (int) $login->role_id, 'div_code' => (string) $login->div_code_main, 'regis_status' => (string) $login->regis_status] : null,
            'location' => $path,
            'beats'  => $beats,
            'report_territories' => $report->where('location_2', '>', 0)->pluck('location_2')->map(fn ($v) => (int) $v)->values(),
            'report_divisions'   => $report->where('product_divison_id', '>', 0)->pluck('product_divison_id')->map(fn ($v) => (int) $v)->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        return $this->save($request, $ctx, null);
    }

    public function update(Request $request, int $dealerId): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        $exists = DB::table('dealer')->where('id', $dealerId)->where('company_id', $ctx->company_id)->where('dealer_status', '<>', 9)->exists();
        if (!$exists) return response()->json(['status' => false, 'message' => 'Distributor not found.'], 404);
        return $this->save($request, $ctx, $dealerId);
    }

    public function sendCredentials(Request $request, int $dealerId): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $v = Validator::make($request->all(), [
            'to'   => ['required', 'email', 'max:150'],
            'cc'   => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ], ['to.email' => 'Enter a valid To email address.', 'cc.*.email' => 'Each CC must be a valid email address.']);
        if ($v->fails()) return $this->invalid($v);

        $exists = DB::table('dealer')->where('id', $dealerId)->where('company_id', $ctx->company_id)->where('dealer_status', '<>', 9)->exists();
        if (!$exists) return response()->json(['status' => false, 'message' => 'Distributor not found.'], 404);

        $to = $request->input('to');
        $cc = array_values(array_filter((array) $request->input('cc', [])));
        try {
            Mail::raw($request->input('body'), function ($m) use ($to, $cc) {
                $m->to($to)->subject('Credentials');
                if ($cc) $m->cc($cc);
            });
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => false, 'message' => 'The mail could not be sent. Please try again.'], 502);
        }

        return response()->json(['status' => true, 'message' => 'Mail sent to ' . $to . '.']);
    }

    public function blockOrderDate(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        if ($request->isMethod('get')) {
            return response()->json(['status' => true, 'block_date' => DB::table('dealer')->where('company_id', $ctx->company_id)->where('dealer_status', '<>', 9)->max('block_order_date')]);
        }

        $v = Validator::make($request->all(), ['block_date' => ['nullable', 'date_format:Y-m-d']]);
        if ($v->fails()) return $this->invalid($v);
        $date = $request->input('block_date') ?: null;

        $count = DB::table('dealer')->where('company_id', $ctx->company_id)->where('dealer_status', '<>', 9)->update(['block_order_date' => $date]);

        return response()->json([
            'status'  => true,
            'message' => $date ? "Ordering is blocked until $date for $count distributors." : "Order block removed for $count distributors.",
        ]);
    }

    public function message(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        if ($request->isMethod('get')) {
            return response()->json(['status' => true, 'message' => $this->activeMessage($ctx->company_id)]);
        }

        $v = Validator::make($request->all(), [
            'show_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . now('Asia/Kolkata')->toDateString()],
            'color'      => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'message'    => ['required', 'string', 'max:500'],
        ], ['show_until.after_or_equal' => 'The show-until date cannot be in the past.']);
        if ($v->fails()) return $this->invalid($v);

        DB::table('message_dms_setting')->insert([
            'company_id' => $ctx->company_id,
            'message'    => trim($request->input('message')),
            'block_date' => $request->input('show_until'),
            'color'      => $request->input('color') ?: '#dc2626',
            'created_at' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'Message saved. Distributors will see it until ' . $request->input('show_until') . '.']);
    }

    public function banner(Request $request): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $this->activeMessage((int) $request->user()->company_id)]);
    }

    private function activeMessage(int $companyId): ?object
    {
        if (!Schema::hasTable('message_dms_setting')) return null;
        return DB::selectOne(
            "SELECT message, block_date AS show_until, color FROM message_dms_setting WHERE company_id = ? AND block_date >= ? ORDER BY id DESC LIMIT 1",
            [$companyId, now('Asia/Kolkata')->toDateString()]
        );
    }

    private function save(Request $request, object $ctx, ?int $dealerId): JsonResponse
    {
        $creating = $dealerId === null;
        $roleIds = array_column($this->roles($ctx), 'value');
        $divRequired = $ctx->erp;
        $v = Validator::make($request->all(), [
            'code'           => ['required', 'string', 'min:2', 'max:40'],
            'firm_name'      => ['required', 'string', 'min:2', 'max:90'],
            'contact_person' => ['nullable', 'string', 'max:80'],
            'address'        => ['required', 'string', 'max:500'],
            'landline'       => ['nullable', 'string', 'max:20'],
            'email'          => ['required', 'email', 'max:100'],
            'pin_no'         => ['required', 'digits:6'],
            'tin_no'         => ['required', 'string', 'max:20'],
            'mobile'         => ['required', 'digits:10'],
            'csa_id'         => ['required', 'integer', Rule::exists('csa', 'c_id')->where('company_id', $ctx->company_id)],
            'regis_status'   => ['required', Rule::in(['0', '1'])],
            'div_code'       => [$divRequired ? 'required' : 'nullable', 'string', 'max:200'],
            'role_id'        => ['required', 'integer', Rule::in($roleIds)],
            'username'       => ['required', 'string', 'max:20', Rule::unique('dealer_person_login', 'uname')->where(fn ($q) => $dealerId ? $q->where('dealer_id', '<>', $dealerId) : $q)],
            'password'       => [$creating ? 'required' : 'nullable', 'string', 'min:4', 'max:50'],
            'location_1'     => ['nullable', 'integer'],
            'location_2'     => ['nullable', 'integer'],
            'location_3'     => ['nullable', 'integer'],
            'location_4'     => ['nullable', 'integer'],
            'beats'          => ['nullable', 'array'],
            'beats.*'        => ['integer'],
            'report_territories'   => ['nullable', 'array'],
            'report_territories.*' => ['integer'],
            'report_divisions'     => ['nullable', 'array'],
            'report_divisions.*'   => ['integer'],
        ], [
            'username.unique' => 'This user name is already taken.',
            'mobile.digits'   => 'Mobile must be 10 digits.',
            'pin_no.digits'   => 'Pin code must be 6 digits.',
            'csa_id.required' => 'Select the SS Name/Depot.',
            'div_code.required' => 'Select the Div Code.',
        ]);
        if ($v->fails()) return $this->invalid($v);

        $code = trim($request->input('code'));
        $dup = DB::table('dealer')->where('company_id', $ctx->company_id)->where('dealer_code', $code)->where('dealer_status', '<>', 9)
            ->when($dealerId, fn ($q) => $q->where('id', '<>', $dealerId))->exists();
        if ($dup) return response()->json(['status' => false, 'message' => 'A distributor with this code already exists.'], 422);

        $loc = [];
        foreach ([1, 2, 3, 4] as $level) {
            $id = (int) $request->input("location_$level");
            if ($id && !DB::table("location_$level")->where('id', $id)->where('company_id', $ctx->company_id)->exists()) {
                return response()->json(['status' => false, 'message' => 'Select a valid location.'], 422);
            }
            $loc[$level] = $id;
        }
        $beats = $this->validIds('location_5', $request->input('beats', []), $ctx->company_id);
        $territories = $this->validIds('location_2', $request->input('report_territories', []), $ctx->company_id);
        $divisions = array_map('intval', DB::table('_product_division')->whereIn('company_id', [0, $ctx->company_id])->whereIn('id', $this->intList($request->input('report_divisions', [])))->pluck('id')->all());

        $dealerData = [
            'name'           => trim($request->input('firm_name')),
            'contact_person' => trim((string) $request->input('contact_person')),
            'dealer_code'    => $code,
            'address'        => trim($request->input('address')),
            'email'          => trim($request->input('email')),
            'landline'       => trim((string) $request->input('landline')),
            'other_numbers'  => $request->input('mobile'),
            'tin_no'         => strtoupper(trim($request->input('tin_no'))),
            'pin_no'         => $request->input('pin_no'),
            'csa_id'         => (int) $request->input('csa_id'),
            'state_id'       => $loc[3],
            'town_id'        => $loc[4],
        ];
        $loginData = [
            'person_name'   => $dealerData['name'],
            'uname'         => trim($request->input('username')),
            'state_id'      => $loc[3],
            'role_id'       => (int) $request->input('role_id'),
            'div_code_main' => (string) $request->input('div_code', ''),
            'regis_status'  => $request->input('regis_status'),
            'csa_id'        => $dealerData['csa_id'],
        ];
        $password = (string) $request->input('password', '');

        DB::transaction(function () use (&$dealerId, $creating, $ctx, $dealerData, $loginData, $password, $beats, $territories, $divisions) {
            if ($creating) {
                $dealerId = DB::table('dealer')->insertGetId($dealerData + [
                    'company_id' => $ctx->company_id,
                    'created_at' => now(),
                    'created_by' => $ctx->dp_id,
                    'dealer_status' => 1,
                    'dms_status' => 0,
                    'edit_stock' => 0,
                ]);
            } else {
                DB::table('dealer')->where('id', $dealerId)->update($dealerData + ['updated_by' => $ctx->dp_id, 'updated_at' => now()]);
            }

            $loginId = DB::table('dealer_person_login')->where('dealer_id', $dealerId)->orderBy('dpId')->value('dpId');
            $pass = $password !== '' ? ['pass' => DB::raw('AES_ENCRYPT(' . DB::getPdo()->quote($password) . ', ' . DB::getPdo()->quote((string) config('app.edsalt')) . ')')] : [];
            if ($loginId) {
                DB::table('dealer_person_login')->where('dpId', $loginId)->update($loginData + $pass);
            } else {
                DB::table('dealer_person_login')->insert($loginData + $pass + [
                    'company_id' => $ctx->company_id,
                    'dealer_id'  => $dealerId,
                    'activestatus' => 1,
                    'bitactive'  => 1,
                    'created_date' => now(),
                ]);
            }

            $current = DB::table('dealer_location_rate_list')->where('dealer_id', $dealerId)->pluck('location_id')->map(fn ($v) => (int) $v)->all();
            DB::table('dealer_location_rate_list')->where('dealer_id', $dealerId)->whereNotIn('location_id', $beats ?: [0])->delete();
            $add = array_diff($beats, $current);
            if ($add) {
                DB::table('dealer_location_rate_list')->insert(array_map(fn ($b) => [
                    'dealer_id' => $dealerId, 'location_id' => $b, 'user_id' => 0, 'company_id' => $ctx->company_id, 'server_date' => now(),
                ], array_values($add)));
            }

            DB::table('dealer_report_section_data')->where('dealer_id', $dealerId)->delete();
            $rows = array_merge(
                array_map(fn ($t) => ['dealer_id' => $dealerId, 'location_2' => $t, 'product_divison_id' => 0, 'server_date_time' => now()], $territories),
                array_map(fn ($d) => ['dealer_id' => $dealerId, 'location_2' => 0, 'product_divison_id' => $d, 'server_date_time' => now()], $divisions),
            );
            if ($rows) DB::table('dealer_report_section_data')->insert($rows);
        });

        return response()->json([
            'status'    => true,
            'dealer_id' => $dealerId,
            'message'   => $creating ? 'Distributor created successfully.' : 'Distributor updated successfully.',
        ], $creating ? 201 : 200);
    }

    private function roles(object $ctx): array
    {
        return DB::select(
            "SELECT role_id AS value, rolename AS label FROM _role
              WHERE company_id = ? AND status = 1 AND UPPER(TRIM(rolename)) IN ('DISTRIBUTOR', 'DEALER', 'DEPO', 'SUPER ADMIN', 'REPORT')
              ORDER BY role_id",
            [$ctx->company_id]
        );
    }

    private function locationPath(int $townId, int $companyId): array
    {
        $path = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        if (!$townId) return $path;
        $row = DB::selectOne(
            "SELECT l4.id l4, l3.id l3, l2.id l2, l1.id l1
               FROM location_4 l4
               LEFT JOIN location_3 l3 ON l3.id = l4.location_3_id
               LEFT JOIN location_2 l2 ON l2.id = l3.location_2_id
               LEFT JOIN location_1 l1 ON l1.id = l2.location_1_id
              WHERE l4.id = ? AND l4.company_id = ?",
            [$townId, $companyId]
        );
        return $row ? [1 => (int) $row->l1, 2 => (int) $row->l2, 3 => (int) $row->l3, 4 => (int) $row->l4] : $path;
    }

    private function validIds(string $table, $ids, int $companyId): array
    {
        $ids = $this->intList($ids);
        if (!$ids) return [];
        return array_map('intval', DB::table($table)->where('company_id', $companyId)->whereIn('id', $ids)->pluck('id')->all());
    }

    private function invalid($v): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
    }

    private function intList($value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $value), fn ($v) => $v > 0)));
    }
}
