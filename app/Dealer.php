<?php

namespace App;
use DB;

use Illuminate\Database\Eloquent\Model;

class Dealer extends Model
{
    protected $table='dealer';
    protected $guarded = array();
    public $timestamps=false;
    public static function getDealerLedger($company_id,$dealer_id_arr){
        $sale_details_data = DB::table('dealer_ledger_busy');
                        if(!empty($dealer_id_arr)){
                           $sale_details_data->whereIn('dealer_ledger_busy.dealer_id',$dealer_id_arr);
                        }
        $sale_details = $sale_details_data->groupBy('dealer_ledger_busy.dealer_id')->get();
        return $sale_details;
    }
    public static function getActiveDealerDetails($company_id,$dealer_id_arr,$user_id){
        $details = DB::table('dealer_location_rate_list')
						->select('dealer.id as dealer_id','dealer.name as dealer_name','location_3.name as state_name')	
						->join('dealer','dealer.id','=','dealer_location_rate_list.dealer_id')
						->Join('location_3','location_3.id','=','dealer.state_id')
                        ->where('dealer.dealer_status','1')
						//->where('dealer.priniti_status','!=','1')
                        ->where('dealer_location_rate_list.company_id',$company_id);
                        if(!empty($dealer_id_arr)){
                           $details->whereIn('dealer.id',$dealer_id_arr);
                        }
                        if(!empty($user_id)){
                           $details->whereIn('dealer_location_rate_list.user_id',$user_id);
                        }
        $data = $details->groupBy('dealer.id')->get();
        return $data;
    }
    public static function getDealerCountSsWise($company_id,$ss_id_arr){
        $data = DB::table('dealer')
                ->whereIn('dealer.csa_id',$ss_id_arr)
                ->groupBy('csa_id')->where('dealer_status',1)
                ->pluck(DB::raw("COUNT(dealer.id) as count"),'csa_id');
        return $data;
    }

    public static function getDealerDetails($dealer_id)
    {
        return Self::find($dealer_id);
    }
}
