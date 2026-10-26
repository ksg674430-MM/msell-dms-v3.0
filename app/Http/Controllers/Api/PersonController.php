<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $auth = $request->user();

        abort_if(
            ! $auth || empty($auth->company_id),
            403,
            'Company context missing from auth token.'
        );

        $companyId = (int) $auth->company_id;
        $dealerId  = ! empty($auth->dealer_id) ? (int) $auth->dealer_id : null;

        $persons = DB::table('person')
            ->join('person_login', 'person_login.person_id', '=', 'person.id')
            ->join('person_details', 'person_details.person_id', '=', 'person.id')
            ->join('_role', '_role.role_id', '=', 'person.role_id')
            ->join('person_finance_details', function ($join) use ($companyId) {
                $join->on('person_finance_details.person_id', '=', 'person.id')
                     ->where('person_finance_details.company_id', '=', $companyId);
            })

            // Persons that have at least one UDR mapping (replaces the INNER JOIN
            // which used to fan out one row per dealer/retailer combination).
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_dealer_retailer')
                  ->whereColumn('user_dealer_retailer.user_id', 'person.id');
            })

            // Dealer scope from the auth token. Only applied when the token
            // actually carries a dealer_id (company-level tokens won't).
            ->when($dealerId !== null, fn ($q) =>
                $q->whereExists(function ($sub) use ($dealerId) {
                    $sub->select(DB::raw(1))
                        ->from('dealer_person')
                        ->whereColumn('dealer_person.person_id', 'person.id')
                        ->where('dealer_person.dealer_id', '=', $dealerId);
                })
            )

            ->select([
                'person.id',
                'person.role_id',
                'person.company_id',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.mobile',
                'person.email',
                'person.status',
                '_role.rolename',                            // ← correct column name
                'person_details.gender',
                'person_details.address',
                'person_login.person_username',
                'person_login.person_status',
                'person_login.last_web_access_on',
                'person_login.last_mobile_access_on',
                // Never select person_password, even as an alias.
                DB::raw("CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name) AS name"),
                DB::raw("DATE_FORMAT(person_details.dob, '%d/%m/%Y') AS dob"),
                DB::raw("DATE_FORMAT(person_login.last_web_access_on, '%e/%b/%Y AT %r') AS lastvisit"),
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $persons,
        ], 200);
    }
}