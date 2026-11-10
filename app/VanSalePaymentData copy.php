<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VanSalePaymentData extends Model
{
    public static function getVanSalePaymentData($orderIds, $companyId)
    {
        return DB::table('user_sales_order_extra_details')
            ->join('van_sale_payment_details', 'van_sale_payment_details.order_id', '=', 'user_sales_order_extra_details.order_id')
            ->whereIn('user_sales_order_extra_details.order_id', $orderIds)
            ->where('user_sales_order_extra_details.company_id', $companyId)
            ->select(
                'user_sales_order_extra_details.order_id',
                DB::raw('ROUND(SUM(van_sale_payment_details.online_amount),2) as upi'),
                DB::raw('ROUND(SUM(van_sale_payment_details.cash_amount),2) as cash'),
                DB::raw('ROUND(SUM(van_sale_payment_details.credit_amount),2) as credit')
            )
            ->groupBy('user_sales_order_extra_details.order_id')
            ->get();
    }
}
