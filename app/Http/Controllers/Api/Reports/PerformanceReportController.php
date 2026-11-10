<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceReportController extends Controller
{
    use ReportFilterTrait;

    // Legacy: report::get_user_sales_performance_list()
    // public function userSales(Request $request)
    // {
        // [$from, $to] = $this->dateRange($request);
        // ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope();

        // $rows = DB::table('user_sales_order as uso')
            // ->join('user_sales_order_details as usod', 'usod.order_id', '=', 'uso.order_id')
            // ->join('person as p', 'p.id', '=', 'uso.user_id')
            // ->where('uso.dealer_id', $dealer)
            // ->where('uso.company_id', $company)
            // ->whereBetween(DB::raw('DATE(uso.date)'), [$from, $to])
            // ->groupBy('uso.user_id', 'uso.dealer_id',
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)"))
            // ->select([
                // DB::raw('COUNT(DISTINCT uso.retailer_id) as tc'),
                // DB::raw('COUNT(DISTINCT usod.product_id) as total_sku'),
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as fullname"),
                // 'uso.dealer_id',
                // 'uso.user_id',
                // DB::raw('SUM(usod.rate * usod.quantity) as sale_value'),
                // DB::raw("(SELECT COUNT(DISTINCT uso1.retailer_id)
                          // FROM user_sales_order uso1
                          // WHERE uso1.dealer_id = uso.dealer_id
                            // AND uso1.user_id = uso.user_id
                            // AND uso1.call_status = 1
                            // AND DATE(uso1.date) BETWEEN '$from' AND '$to') as pc"),
            // ])
            // ->orderByDesc('sale_value')
            // ->get();

        // return $this->ok($rows, ['from' => $from, 'to' => $to]);
    // }

    // Legacy: report::get_user_bill_performance_list()
    // public function userBill(Request $request)
    // {
        // [$from, $to] = $this->dateRange($request);
        // ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope();

        // $rows = DB::table('challan_order as co')
            // ->join('person as p', 'p.id', '=', 'co.ch_user_id')
            // ->where('co.ch_dealer_id', $dealer)
            // ->where('co.company_id', $company)
            // ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to])
            // ->groupBy('co.ch_user_id', 'co.ch_dealer_id',
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)"))
            // ->select([
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as fullname"),
                // 'co.ch_dealer_id',
                // 'co.ch_user_id as user_id',
                // DB::raw('ROUND(SUM(co.amount),2) as sale_value'),
            // ])
            // ->orderByDesc('sale_value')
            // ->get();

        // return $this->ok($rows, ['from' => $from, 'to' => $to]);
    // }

    // Legacy: report::get_retailer_sales_performance_list()
    public function retailerSales(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope();

        $rows = DB::table('user_sales_order as uso')
            ->join('user_sales_order_details as usod', 'usod.order_id', '=', 'uso.order_id')
            ->join('retailer as r', 'r.id', '=', 'uso.retailer_id')
            ->leftJoin('location_7 as l7', 'l7.id', '=', 'r.location_id')
            ->leftJoin('person as p', 'p.id', '=', 'uso.user_id')
            ->where('uso.dealer_id', $dealer)
            ->where('uso.company_id', $company)
            ->whereBetween(DB::raw('DATE(uso.date)'), [$from, $to])
            ->groupBy('uso.retailer_id', 'r.name', 'uso.dealer_id',
                      'r.contact_per_name', 'r.landline', 'r.other_numbers',
                      'r.track_address', 'l7.name')
            ->select([
                DB::raw('COUNT(DISTINCT usod.product_id) as total_sku'),
                'r.name as retailer_name',
                'uso.dealer_id',
                'uso.retailer_id',
                DB::raw('ROUND(SUM((usod.rate * usod.quantity) + (usod.case_rate * usod.case_quantity)),2) as sale_value'),
                DB::raw('SUM(usod.quantity) as order_quantity'),
                DB::raw('COUNT(DISTINCT uso.order_id) as orders'),
                'r.contact_per_name',
                'r.landline',
                'r.other_numbers',
                'r.track_address',
                'l7.name as beat_name',
                DB::raw("GROUP_CONCAT(DISTINCT CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name)) as user_name"),
                DB::raw('GROUP_CONCAT(DISTINCT uso.date) as order_date'),
            ])
            ->orderByDesc('sale_value')
            ->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    // Legacy: dispatch_report::get_secondary_sales_book_list()
  public function secondarySalesBook(Request $request)
{
    [$from, $to] = $this->dateRange($request, 'start', 'end');
    ['dealer_id' => $dealer] = $this->scope($request);

    if (!$request->filled('user_id')) {
        return $this->fail('user_id is required');
    }

    $rows = DB::table('user_sales_order as uso')
        ->join('retailer as r', 'r.id', '=', 'uso.retailer_id')
        ->join('dealer as d', 'd.id', '=', 'uso.dealer_id')
        ->join('user_sales_order_details as usod', 'usod.order_id', '=', 'uso.order_id')
        ->join('catalog_product as cp', 'cp.id', '=', 'usod.product_id')
        ->join('person as p', 'p.id', '=', 'uso.user_id')
        ->join('_role as ro', 'ro.role_id', '=', 'p.role_id')
        ->leftJoin('location_7 as l7', 'l7.id', '=', 'uso.location_id')
        ->where('uso.dealer_id', $dealer)
        ->where('uso.user_id', $request->user_id)
        ->whereBetween(DB::raw('DATE(uso.date)'), [$from, $to])
        ->groupBy('uso.retailer_id', 'r.name', 'l7.name',
                  'usod.product_id', 'cp.name',
                  'p.first_name', 'p.middle_name', 'p.last_name',
                  'ro.rolename', 'd.name', 'd.address')
        ->select([
            'uso.retailer_id',
            'r.name as retailer_name',
            'cp.id as product_id',
            'cp.name as product_name',
            DB::raw('SUM(usod.quantity) as quantity'),
            DB::raw('SUM(usod.case_quantity) as case_quantity'),
            DB::raw('SUM((usod.rate * usod.quantity) + (usod.case_rate * usod.case_quantity)) as sale_value'),
            DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
            'ro.rolename',
            'l7.name as beat_name',
            'd.name as dealer_name',
            'd.address as dealer_address',
        ])
        ->orderBy('retailer_name')
        ->get();

    return $this->ok($rows, ['from' => $from, 'to' => $to]);
}
}