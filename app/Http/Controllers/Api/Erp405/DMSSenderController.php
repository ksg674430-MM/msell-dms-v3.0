<?php

namespace App\Http\Controllers\Api\Erp405;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DMSSenderController extends Controller
{
    private const COMPANY_ID = 405;

    public function send_order_details_erp(Request $request)
    {
        $div_code = $request->div_code;
        $entity_code = $request->entity_code;
        if(empty($div_code))
        {
            return response()->json([ 'response' =>False,'message'=>'Check Your Div Code','response_order_head'=> array(),'Sync_date_time'=>date('Y-m-d H:i:s')]);
        }

        $order_body_details_step1 = DB::table('demand_order')
                            ->join('demand_order_details','demand_order_details.order_id','=','demand_order.order_id')
                            ->join('dealer','dealer.id','=','demand_order.dealer_id')
                            ->join('ACC_MAST','ACC_MAST.ACC_CODE','=','dealer.dealer_code')
                            ->select('demand_order_details.*','order_date','ACC_MAST.DIV_CODE','dealer.dealer_code as dealer_code','demand_order.entity_code as entity_code')

                            ->where('dealer.company_id', self::COMPANY_ID)
                            ->where('demand_order.order_date', '>=', '2023-04-01')
                            ->where('sale_div_code',$div_code)
                            ->where('demand_order.entity_code',$entity_code)
                            ->where('erp_order_no',0)
                            ->where('send_order_erp_status','=','1')
                            ->groupBy('demand_order_details.id')
                            ->get();

        $order_head_details_step2 = DB::table('demand_order')
                            ->join('demand_order_details','demand_order_details.order_id','=','demand_order.order_id')
                            ->join('dealer','dealer.id','=','demand_order.dealer_id')
                            ->join('ACC_MAST','ACC_MAST.ACC_CODE','=','dealer.dealer_code')
                            ->select('demand_order.*','ACC_MAST.DIV_CODE','dealer.dealer_code as dealer_code','ACC_NAME','demand_order.entity_code as entity_code')
                            ->where('dealer.company_id', self::COMPANY_ID)
                            ->where('demand_order.order_date', '>=', '2023-04-01')

                            ->where('erp_order_no',0)
                            ->where('sale_div_code',$div_code)
                            ->where('demand_order.entity_code',$entity_code)

                            ->where('send_order_erp_status','=','1')
                            ->groupBy('demand_order.order_id')

                            ->get();

        $data_item_plant_code  = DB::table('ITEM_MAST')

                                ->pluck('PLANT_CODE','ITEM_CODE');

        $um_to_aum = DB::table('ITEM_AUM_MAST')
                    ->where('AUM','BOX')
                    ->groupBy('ITEM_CODE')
                    ->pluck('UMTOAUM','ITEM_CODE');

        $f_out = [];
        $out = [];

        $final_tr_rate = '0';
        $sl_no = 0;
        foreach ($order_body_details_step1 as $key => $value) {

            if(!empty($div_code))
            {

                $x_order_id = !empty($order_body_details_step1[$key-1])?$order_body_details_step1[$key-1]->order_id:'0';
                if($x_order_id == $value->order_id)
                {
                    $sl_no = $sl_no+1;
                }
                else
                {
                    $sl_no = 1;
                }

                $td_discount = (($value->t1_rate)*-1);
                $atd_discount = (($value->atd_rate)*-1);
                $final_tr_rate = $value->t1_rate_perc;
                $first_dramt = $value->total_rs+$td_discount+$atd_discount;
                $out['order_id'] = $value->id;
                $out['entity_code'] = !empty($value->entity_code)?$value->entity_code:'JH';
                $out['div_code'] = $div_code;
                $out['t_code'] = 'O';
                $out['sl_no'] = $sl_no;
                $out['item_code'] = $value->product_id;
                $out['um'] = $value->order_unit;
                $out['plant_code'] = $data_item_plant_code[$value->product_id] ?? '';
                $out['rate'] = $value->rate;
                $out['dramt'] = round($first_dramt,2);
                if($value->order_unit == 'BOX')
                {
                    $out['aparty_qty'] = !empty($um_to_aum[$value->product_id]) ? $value->quantity/$um_to_aum[$value->product_id] : $value->quantity;
                }
                else
                {
                    $out['aparty_qty'] = $value->quantity;
                }

                $out['party_qty'] = $value->quantity;

                $out['qty_order'] = $value->quantity-$value->free_qty;
                $out['free_qty'] = $value->free_qty;
                $out['afield1']  = $value->total_rs;
                $out['afield2'] = round($td_discount,2);
                $out['afield4'] = $atd_discount;
                $out['vrdate_change'] = date('d-M-y',strtotime($value->order_date));
                $out['t1rate'] = $final_tr_rate;
                $out['remarks'] = !empty($value->remarks)?$value->remarks:'';
                $f_out[$value->order_id][] = $out;
            }

        }

        $head_f_out = [];
        $out = [];
        foreach ($order_head_details_step2 as $key => $value) {

            if(!empty($div_code))
            {

                $ref_no = $value->ACC_NAME;
                $ref_no_1 = substr($ref_no, 0,10);
                $prefix = !empty($value->prefix)?$value->prefix:'B-';

                $out['entity_code'] = !empty($value->entity_code)?$value->entity_code:'JH';
                $out['div_code'] = $div_code;
                $out['vr_no_define'] = $value->vr_no_define;
                $out['t_code'] = 'O';
                $out['vrdate'] = date('d-M-y',strtotime($value->order_date));
                $out['acc_code'] = $value->dealer_code;
                $out['party_ref_no'] = $value->dealer_code.'/'.$ref_no_1.'/'.'WEB-'.$value->vr_no_define.'-'.$prefix.$value->id;
                $out['party_pref_date'] = date('d-M-y',strtotime($value->order_date));
                $out['user_code'] = 'WEB';
                $out['file_code'] = $prefix.$value->id;
                $out['erp_order_no'] = $value->erp_order_no;
                $out['server_date_time'] = !empty($value->created_date)?$value->created_date:'';
                $out['scheme_flag'] = '1';
                $out['order_body_details'] = !empty($f_out[$value->order_id])?$f_out[$value->order_id]:'0';
                $head_f_out[] = $out;
            }

        }

        return response()->json([ 'response' =>True,'response_order_head'=> $head_f_out,'Sync_date_time'=>date('Y-m-d H:i:s')]);

    }

    public function recieve_order_status_erp(Request $request)
    {
        $json_decode_data = json_decode($request->response);
        if(empty($json_decode_data->response->order_status_details) || COUNT($json_decode_data->response->order_status_details)==0)
        {
            return response()->json([ 'response' =>False,'Sync_status'=>'Erp Order No Not Updated Check the internet or recieve the order again !!','Hit_date_time'=>date('Y-m-d H:i:s')]);
        }

        $found = 0;
        DB::transaction(function () use ($json_decode_data, &$found) {
            foreach ($json_decode_data->response->order_status_details as $value) {
                $order_id = str_replace('B-', '', $value->order_id);
                $scope = DB::table('demand_order')
                    ->where('id', $order_id)
                    ->whereIn('dealer_id', DB::table('dealer')->where('company_id', self::COMPANY_ID)->select('id'));
                if (!(clone $scope)->exists()) {
                    continue;
                }
                $scope->update([
                    'erp_order_no'=>$value->erp_order_no,
                    'send_order_erp_status'=>3,
                    'status_recieve_date_time'=>date('Y-m-d H:i:s')
                ]);
                $found++;
            }
        });

        if($found > 0)
        {
            return response()->json([ 'response' =>True,'Sync_status'=>'Succesfully Erp Order No Updated !!','Hit_date_time'=>date('Y-m-d H:i:s')]);
        }
        return response()->json([ 'response' =>False,'Sync_status'=>'Erp Order No Not Updated Check the internet or recieve the order again !!','Hit_date_time'=>date('Y-m-d H:i:s')]);
    }
}
