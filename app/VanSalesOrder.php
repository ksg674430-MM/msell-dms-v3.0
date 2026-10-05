<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VanSalesOrder extends Model
{
    protected $table = 'ss_van_sales_order';
    protected $guarded = [];

    public static function getVanSalesOrder($companyId, $fromDate, $toDate)
    {
        return DB::table('ss_van_sales_order')
            ->leftJoin('dealer', 'dealer.id', '=', 'ss_van_sales_order.dealer_id')
            ->leftJoin('csa as csa', 'csa.c_id', '=', 'ss_van_sales_order.csa_id')
            ->leftJoin('location_7', 'location_7.id', '=', 'ss_van_sales_order.route_id')
            ->where('ss_van_sales_order.company_id', $companyId)
            ->whereBetween('ss_van_sales_order.sale_date', [$fromDate, $toDate])
            ->select(
                'ss_van_sales_order.order_id',
                'ss_van_sales_order.sale_date',
                'ss_van_sales_order.date_time',
                'ss_van_sales_order.checkout_date_time',
                'ss_van_sales_order.driver_name',
                'dealer.id as dealer_id',
                'dealer.name as dealer_name',
                'csa.c_id as csa_id',
                'csa.csa_name as csa_name',
                'location_7.name as beat_name',
            );
    }
}
