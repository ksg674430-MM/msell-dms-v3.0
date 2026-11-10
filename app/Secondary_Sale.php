<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Secondary_Sale extends Model
{
    public static function getSecondarySale($orderIds, $companyId)
    {
        return DB::table('user_sales_order')
            ->join('user_sales_order_details', 'user_sales_order_details.order_id', '=', 'user_sales_order.order_id')
            ->join('user_sales_order_extra_details','user_sales_order_extra_details.order_id','=','user_sales_order.order_id')
            ->whereIn('user_sales_order_extra_details.van_sale_order_id', $orderIds)
            ->where('user_sales_order.company_id', $companyId)
            ->select(
                'user_sales_order_extra_details.van_sale_order_id as order_id',
                'user_sales_order_details.product_id',
                DB::raw('SUM(user_sales_order_details.case_quantity) as case_quantity'),
                DB::raw('SUM(user_sales_order_details.quantity) as quantity'),
                DB::raw('SUM(user_sales_order_details.scheme_qty) as scheme_qty'),
                DB::raw('ROUND(SUM((user_sales_order_details.rate*user_sales_order_details.quantity)+(user_sales_order_details.case_rate*user_sales_order_details.case_quantity)),2) as total_sale')
            )
            ->groupBy('user_sales_order_extra_details.van_sale_order_id', 'user_sales_order_details.product_id')
            ->get();
    }
}
