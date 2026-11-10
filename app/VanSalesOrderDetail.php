<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VanSalesOrderDetail extends Model
{
    protected $table = 'ss_van_sales_order_details';
    protected $guarded = [];

    public static function getVanSalesOrderDetails($orderIds, $companyId)
    {
        return DB::table('ss_van_sales_order_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'ss_van_sales_order_details.product_id')
            ->join('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            ->join('catalog_1', 'catalog_1.id', '=', 'catalog_2.catalog_1_id')
            ->join('catalog_0', 'catalog_0.id', '=', 'catalog_1.catalog_0_id')
            ->whereIn('ss_van_sales_order_details.order_id', $orderIds)
            ->where('ss_van_sales_order_details.company_id', $companyId)
            ->select(
                'ss_van_sales_order_details.order_id',
                'ss_van_sales_order_details.product_id',
                'catalog_0.name as classification_name',
                'catalog_1.name as category_name',
                'catalog_2.name as product_name',
                'catalog_product.name as sku_name',
                'catalog_product.quantity_per_case',
                'ss_van_sales_order_details.cases',
                'ss_van_sales_order_details.pcs',
                'ss_van_sales_order_details.rate',
                'ss_van_sales_order_details.pr_rate',
                'ss_van_sales_order_details.batch_no'
            )
            ->get();
    }
}
