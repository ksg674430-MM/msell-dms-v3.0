<?php

namespace App;
use DB;

use Illuminate\Database\Eloquent\Model;

class Location7 extends Model
{
    protected $table='location_7';

    protected $guarded = array();

    public static function getActiveBeatDealerWiseCount($company_id,$dealer_id_arr,$user_id,$groupby = 'dealer_location_rate_list.dealer_id'){
        $details = DB::table('dealer_location_rate_list')
                        ->join('dealer','dealer.id','=','dealer_location_rate_list.dealer_id')
						->join('location_7','location_7.id','=','dealer_location_rate_list.location_id')
						->select(DB::raw("COUNT(distinct location_7.id) as beat_count"),'dealer_location_rate_list.dealer_id','dealer.csa_id as csa_id',DB::raw('GROUP_CONCAT(DISTINCT location_7.id) as beat_ids'))	
						->where('location_7.status','1')
                        ->where('location_7.name', 'NOT LIKE', '%SELF%')    
                        ->where('dealer_location_rate_list.company_id',$company_id)
                        ->where('dealer_status',1);
                        if(!empty($dealer_id_arr)){
                           $details->whereIn('dealer_location_rate_list.dealer_id',$dealer_id_arr);
                        }
                        if(!empty($user_id)){
                           $details->whereIn('dealer_location_rate_list.user_id',$user_id);
                        }
        $data = $details->groupBy($groupby)->get();
        // dd($data,$user_id,$dealer_id_arr,$company_id);
        return $data;
    }
    public static function getActiveBeatDealerWise($company_id,$dealer_id_arr,$user_id){
        $details = DB::table('dealer_location_rate_list')
                        ->join('dealer','dealer.id','=','dealer_location_rate_list.dealer_id')
                        ->join('location_7','location_7.id','=','dealer_location_rate_list.location_id')
						->select('location_7.name as beat_name','location_7.id as beat_id','dealer.name as dealer_name')	
						->where('location_7.status','1')
                        ->where('location_7.name', 'NOT LIKE', '%SELF%')    
                        ->where('dealer_location_rate_list.company_id',$company_id);
                        if(!empty($dealer_id_arr)){
                           $details->whereIn('dealer_location_rate_list.dealer_id',$dealer_id_arr);
                        }
                        if(!empty($user_id)){
                           $details->whereIn('dealer_location_rate_list.user_id',$user_id);
                        }
        $data = $details->groupBy('dealer_location_rate_list.location_id')->get();
        // dd($data,$user_id,$dealer_id_arr,$company_id);
        return $data;
    }
    public static function getBeatNamePluck($company_id,$beat_id_arr){
        return DB::table('location_7')->whereIn('id',$beat_id_arr)->pluck('name','id');
    }
    public static function getBeatSsWise($ss_id,$company_id){
        $user_id = [];
        $dealer_id_arr = [];
        $get_dealer_data = DB::table('dealer')->where('dealer_status',1)->select('id')->where('dealer.csa_id',$ss_id)->get();
        // dd($get_dealer_data);
        foreach ($get_dealer_data as $key => $value) {
            $dealer_id_arr[] = $value->id;
        }
        if(empty($dealer_id_arr)){
            $get_call = [];
        }else{
            $get_call = self::getActiveBeatDealerWise($company_id,$dealer_id_arr,$user_id);
            // dd($get_call);
        }
        
        return $get_call;
        
    }
    public static function beatWiseSale($company_id,$beat_id){
        $data = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')
                        ->select('user_sales_order.*',DB::raw("SUM((rate*quantity)+(secondary_rate*secondary_quantity)+(case_rate*case_quantity)) as total_sale_value"))
                        ->where('location_id',$beat_id)
                        ->groupBy('order_id')
                        ->orderBy('id','ASC')
                        ->get();
        return $data;
    }
    public static function beatWiseTargetSales($company_id, $beat_id, $from_date = null, $to_date = null)
    {
        if (empty($from_date) || empty($to_date)) {
            $currentMonth = date('n');
            $currentYear  = date('Y');

            if ($currentMonth >= 4) {
                $fyStart = date('Y-m-01', strtotime($currentYear . '-04-01'));
            } else {
                $fyStart = date('Y-m-01', strtotime(($currentYear - 1) . '-04-01'));
            }

            $endDate   = date('Y-m-d', strtotime('last day of previous month'));
            $from_date = $fyStart;
            $to_date   = $endDate;
        }

        if($company_id == 44){
            $sales = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')->select(
                        DB::raw("DATE_FORMAT(date, '%Y-%m') as sale_month"),
                        DB::raw("SUM((rate*quantity)) as achieved")
                    )
                    ->where('location_id', $beat_id)
                    ->whereBetween('date', [$from_date, $to_date])
                    ->groupBy(DB::raw("DATE_FORMAT(date, '%Y-%m')"))
                    ->orderByDesc('achieved')
                    ->first();
        }else{
            $sales = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')->select(
                        DB::raw("DATE_FORMAT(date, '%Y-%m') as sale_month"),
                        DB::raw("SUM((rate*quantity)+(secondary_rate*secondary_quantity)+(case_rate*case_quantity)) as achieved")
                    )
                    ->where('location_id', $beat_id)
                    ->whereBetween('date', [$from_date, $to_date])
                    ->groupBy(DB::raw("DATE_FORMAT(date, '%Y-%m')"))
                    ->orderByDesc('achieved')
                    ->first();
        }
        

        $currentMonthStart = date('Y-m-01'); 
        $currentDate       = date('Y-m-d');  

        if($company_id == 44){
        
            $currentAchievedDetails = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')->where('location_id', $beat_id)
                                ->whereBetween('date', [$currentMonthStart, $currentDate])
                                ->select(DB::raw("SUM((rate*quantity)) as ach"))->first();
        }else{
            $currentAchievedDetails = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')->where('location_id', $beat_id)
                                ->whereBetween('date', [$currentMonthStart, $currentDate])
                                ->select(DB::raw("SUM((rate*quantity)+(secondary_rate*secondary_quantity)+(case_rate*case_quantity)) as ach"))->first();
        }
        $currentAchieved = $currentAchievedDetails->ach??0;
        if ($sales) {
            $target = round($sales->achieved * 1.2, 2);
            $gap    = $target - $currentAchieved;

            return [
                'month'    => $sales->sale_month,   
                'achieved' => $currentAchieved,     
                'target'   => $target,
                'gap'      => $gap,
            ];
        }

        return [
            'month'    => null,
            'achieved' => $currentAchieved,
            'target'   => 0,
            'gap'      => 0 - $currentAchieved,
        ];
    }

    public static function distributorWiseMovingItems($company_id,$beat_id){
        $data = UserSalesOrder::join('user_sales_order_details','user_sales_order_details.order_id','=','user_sales_order.order_id')
                        ->select('user_sales_order.dealer_id','user_sales_order_details.product_id')
                        ->where('location_id',$beat_id)
                        ->groupBy('dealer_id', 'product_id')
                        ->get();
        return $data;
    }


}
