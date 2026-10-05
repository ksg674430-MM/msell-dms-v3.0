<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SsVanSalesReportController extends Controller
{
    public function index()
    {
        $company_id = Auth::user()->company_id;

        $dealer = DB::table('dealer')
            ->where('company_id', $company_id)
            ->pluck('name', 'id');

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => [
                'company_id' => $company_id,
                'dealer'     => $dealer,
            ],
        ]);
    }

    public function report(Request $request)
    {
        $company_id = Auth::user()->company_id;

        $explodeDate = explode(" -", $request->date_range_picker);
        $from_date   = date('Y-m-d', strtotime(trim($explodeDate[0])));
        $to_date     = date('Y-m-d', strtotime(trim($explodeDate[1])));

        $person_data = DB::table('person')
            ->join('dealer_person_login', function ($join) use ($company_id) {
                $join->on('dealer_person_login.csa_id', '=', 'person.id')
                     ->where('dealer_person_login.company_id', '=', $company_id)
                     ->where('dealer_person_login.csa_id', '>', 0); // exclude dealer-only logins
            })
            ->join('location_6', 'location_6.id', '=', 'person.town_id')
            ->join('location_5', 'location_5.id', '=', 'location_6.location_5_id')
            ->join('location_4', 'location_4.id', '=', 'location_5.location_4_id')
            ->join('location_3', 'location_3.id', '=', 'location_4.location_3_id')
            ->select(DB::raw(
                'CONCAT_WS(" ", person.first_name, person.middle_name, person.last_name) as user_name,
                 location_5.name as hq,
                 location_4.name as area,
                 person.role_id,
                 person.person_id_senior,
                 person.mobile,
                 person.id as user_id,
                 person.emp_code as emp_code,
                 location_3.name as state,
                 location_6.name as town,
                 dealer_person_login.uname as login_uname,
                 dealer_person_login.dealer_id as dealer_id'
            ))
            ->where('person.company_id', $company_id);

        if (!empty($request->location_3)) {
            $person_data->whereIn('location_3.id', $request->location_3);
        }
        if (!empty($request->location_4)) {
            $person_data->whereIn('location_4.id', $request->location_4);
        }
        if (!empty($request->location_5)) {
            $person_data->whereIn('location_5.id', $request->location_5);
        }
        if (!empty($request->location_6)) {
            $person_data->whereIn('location_6.id', $request->location_6);
        }
        if (!empty($request->user)) {
            $person_data->whereIn('person.id', $request->user);
        }
        if (!empty($request->role)) {
            $person_data->whereIn('person.role_id', $request->role);
        }

        $user_record = $person_data->groupBy('person.id')->get();

        return response()->json([
            'status'  => true,
            'message' => 'Success',
            'data'    => [
                'company_id'  => $company_id,
                'from_date'   => $from_date,
                'to_date'     => $to_date,
                'total'       => $user_record->count(),
                'user_record' => $user_record,
            ],
        ]);
    }
}