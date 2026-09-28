<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerPersonLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'uname' => ['required', 'string', 'max:100'],
            'pass'  => ['required', 'string', 'max:100'],
        ]);

        $uname = $request->input('uname');
        $pass  = $request->input('pass');
        $host  = $request->getHost();
        $ip    = $request->ip();
        $salt  = config('app.edsalt');

        $site = DB::selectOne(
            "SELECT domain_url, company_image, title, id
               FROM company
              WHERE domain_url = ?
              LIMIT 1",
            [$host]
        );

        $user = DB::selectOne(
            "SELECT
                dealer_person_login.regis_status,
                d.dealer_code,
                d.edit_stock AS edit_stock,
                dpId AS id,
                d.csa_id AS csa_id,
                role_id AS urole,
                rolename,
                d.name AS dealer_name,
                person_name AS name,
                reason,
                bitactive,
                ipaddress,
                dealer_status AS activestatus,
                uname,
                role_group_id,
                dealer_id,
                DATE_FORMAT(lastvisit, '%e %M %Y at %r IST') AS lastlogin,
                d.state_id,
                dealer_person_login.company_id,
                dealer_person_login.quantity_type,
                d.ownership_type_id,
                d.template_id,
                dealer_person_login.state_id AS dpr_state
             FROM dealer_person_login
             INNER JOIN _role USING(role_id)
             INNER JOIN dealer d ON dealer_person_login.dealer_id = d.id
             WHERE uname = ?
               AND AES_DECRYPT(dealer_person_login.pass, ?) = ?
               AND bitactive = 1
             LIMIT 1",
            [$uname, $salt, $pass]
        );

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Sorry username or password do not match.',
            ], 401);
        }

        if ((string) $user->activestatus === '0') {
            return response()->json([
                'status'  => false,
                'message' => 'Your code is blocked. Please contact your respective depot.',
            ], 403);
        }

        $stid        = $user->state_id != 0 ? $user->state_id : $user->dpr_state;
        $sessionYear = DB::selectOne("SELECT * FROM session_year ORDER BY sesId DESC LIMIT 1");
        $constant    = DB::selectOne("SELECT * FROM _constant LIMIT 1");

        $comp = DB::selectOne(
            "SELECT domain_url, company_image, title, id
               FROM company
              WHERE id = ?
              LIMIT 1",
            [$user->company_id]
        );

        DB::update(
            "UPDATE dealer_person_login
                SET lastvisit = NOW(), ipaddress = ?
              WHERE uname = ?
              LIMIT 1",
            [$ip, $uname]
        );

        DB::insert(
            "INSERT INTO `dms_login_log` (`dealer_id`, `login_at`, `ip_address`)
             VALUES (?, NOW(), ?)",
            [$user->dealer_id, $ip]
        );

        $model = DealerPersonLogin::find($user->id);
        $model->tokens()->delete();
        $token = $model->createToken('api-token')->plainTextToken;

        $redirect = $this->resolveRedirectUrl($user, $comp);

        $companyName  = DB::table('company')->where('id', $user->company_id)->value('title');
        $companyImage = DB::table('company')->where('id', $user->company_id)->value('company_image');

        return response()->json([
            'status'  => true,
            'message' => 'Login successful.',
            'token'   => $token,
            'user'    => [
                'id'                => $user->id,
                'uname'             => $user->uname,
                'name'              => $user->name,
                'urole'             => $user->urole,
                'rolename'          => $user->rolename,
                'role_group_id'     => $user->role_group_id,
                'dealer_id'         => $user->dealer_id,
                'dealer_code'       => $user->dealer_code,
                'dealer_name'       => $user->dealer_name,
                'csa_id'            => $user->csa_id,
                'company_id'        => $user->company_id,
                'company_name'      => $companyName,
                'company_image'     => $companyImage,
                'domain_url'        => $comp->domain_url ?? null,
                'state_id'          => $stid,
                'quantity_type'     => $user->quantity_type,
                'ownership_type_id' => $user->ownership_type_id,
                'template_id'       => $user->template_id,
                'edit_stock'        => $user->edit_stock,
                'signup_status'     => 1,
                'lastlogin'         => $user->lastlogin,
            ],
            'session'  => $sessionYear,
            'constant' => $constant,
            'site'     => $site,
            'redirect' => $redirect,
        ]);
    }
// dsdshj
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => true,
            'user'   => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Logged out.',
        ]);
    }

    private function resolveRedirectUrl(object $user, ?object $comp): string
    {
        $domain = $comp->domain_url ?? '';

        if ($user->urole == 1) {
            return "https://{$domain}/public/welcome";
        }
        if ($user->urole == 41) {
            return "https://{$domain}/public/report_welcome";
        }
        if ($user->regis_status == 1 || in_array($user->urole, [38, 283])) {
            return url('/index.php');
        }
        return "https://{$domain}/public/Order-details";
    }
}