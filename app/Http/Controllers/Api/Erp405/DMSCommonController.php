<?php

namespace App\Http\Controllers\Api\Erp405;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DMSCommonController extends Controller
{
    public function return_order_date_div_wise(Request $request)
    {
        $div_code = json_decode($request->response);

        $order_date_fetch = DB::table('ORDER_HEAD')
                            ->where('DIV_CODE',$div_code)
                            ->orderBy('VRDATE_FILTER','DESC')
                            ->first();
        $order_body_date_fetch = DB::table('ORDER_BODY')

                            ->where('DIV_CODE',$div_code)
                            ->orderBy('VRDATE_FILTER','DESC')
                            ->first();

        if(empty($order_date_fetch->VRDATE))
        {
            $from_date = date('d-M-Y', strtotime('-3 day'));
            $from_date = '01-APR-2021';
            $to_date = date('d-M-Y');
        }
        else{
            $from_date_1 = date('d-M-Y', strtotime($order_date_fetch->VRDATE));
            $from_date = date('d-M-Y', strtotime('-2 day',strtotime($from_date_1)));
            $to_date = date('d-M-Y');
        }

        if(empty($order_body_date_fetch->VRDATE))
        {
            $b_from_date = date('d-M-Y', strtotime('-3 day'));
            $b_from_date = '01-APR-2021';
            $b_to_date = date('d-M-Y');
        }
        else{
            $b_from_date_1 = date('d-M-Y', strtotime($order_body_date_fetch->VRDATE));
            $b_from_date = date('d-M-Y', strtotime('-2 day',strtotime($b_from_date_1)));
            $b_to_date = date('d-M-Y');
        }
        return response()->json([ 'response' =>True,'from_date'=> $from_date,'b_from_date'=> $b_from_date,'to_date'=> $to_date,'b_to_date'=> $b_to_date]);
    }
}
