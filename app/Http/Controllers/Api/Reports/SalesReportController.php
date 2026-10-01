<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    use ReportFilterTrait;

    public function itemWise(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'co.id', '=', 'cod.ch_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->leftJoin('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

        if ($request->filled('retailer_id')) $q->where('co.ch_retailer_id', $request->retailer_id);
        if ($request->filled('product_id'))  $q->where('cod.product_id', $request->product_id);
        if ($request->filled('category_id')) $q->where('c2.id', $request->category_id);

        $q->select([
                'co.id as invoiceId',
                'co.ch_no as invnum',
                DB::raw("DATE_FORMAT(co.ch_date,'%d-%m-%Y') as invdatef"),
                'co.ch_retailer_id as partyId',
                'r.name as party_name',
                'cod.product_id as itemId',
                'cp.name as itemname',
                'cp.itemcode',
                'c2.name as category',
                'cod.batch_no',
                'cod.qty',
                'cod.free_qty',
                'cod.product_rate as rate',
                'cod.mrp',
                'cod.taxable_amt',
                'cod.vat_amt',
                DB::raw('(cod.qty * cod.product_rate) as basicval'),
            ])
            ->orderBy('co.ch_date')
            ->orderBy('co.ch_no');

        [$per, $page] = $this->paging($request);
        $rows = $q->paginate($per, ['*'], 'page', $page);

        return $this->ok($rows->items(), [
            'from'     => $from,
            'to'       => $to,
            'page'     => $rows->currentPage(),
            'per_page' => $rows->perPage(),
            'total'    => $rows->total(),
        ]);
    }

    public function itemSummary(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'co.id', '=', 'cod.ch_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

        if ($request->filled('category_id')) $q->where('c2.id', $request->category_id);
        if ($request->filled('hsn_code'))    $q->where('cod.hsn_code', $request->hsn_code);

        $rows = $q->groupBy('cod.product_id', 'cp.name', 'cp.itemcode', 'c2.name', 'cp.gst_percent')
            ->select([
                'cod.product_id as itemId',
                'cp.name as itemname',
                'cp.itemcode',
                'c2.name as category',
                'cp.gst_percent as gst_percent',
                DB::raw('ROUND(SUM(cod.qty),2) as qtysum'),
                DB::raw('ROUND(SUM(cod.free_qty),2) as free_qty_sum'),
                DB::raw('ROUND(SUM(cod.qty * cod.product_rate),2) as ratesum'),
                DB::raw('ROUND(SUM(cod.taxable_amt),2) as taxable_amt'),
                DB::raw('ROUND(SUM(cod.vat_amt),2) as tax_amt'),
            ])
            ->orderBy('itemname')
            ->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    public function productSale(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'co.id', '=', 'cod.ch_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

        if ($request->filled('retailer_id')) $q->where('co.ch_retailer_id', $request->retailer_id);
        if ($request->filled('category_id')) $q->where('c2.id', $request->category_id);

        $rows = $q->groupBy('co.ch_retailer_id', 'r.name', 'cod.product_id', 'cp.name', 'cp.unit', 'cp.quantity_per_case')
            ->select([
                'co.ch_retailer_id as retailer_id',
                'r.name as retailer_name',
                'cod.product_id',
                'cp.name as product_name',
                'cp.unit',
                'cp.quantity_per_case as qty_per_case',
                DB::raw('ROUND(SUM(cod.qty + cod.free_qty),2) as qty'),
                DB::raw('ROUND(SUM(cod.taxable_amt),2) as taxable_amt'),
                DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as net_amount'),
            ])
            ->orderBy('retailer_name')
            ->orderBy('product_name')
            ->get()
            ->map(function ($r) {
                $r->box = ($r->qty_per_case > 0) ? round($r->qty / $r->qty_per_case, 2) : 0;
                return $r;
            });

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    public function itemSale(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope($request);

        $q = DB::table('user_sales_order as uso')
            ->join('user_sales_order_details as usod', 'usod.order_id', '=', 'uso.order_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'usod.product_id')
            ->join('person as p', 'p.id', '=', 'uso.user_id')
            ->leftJoin('location_7 as l7', 'l7.id', '=', 'uso.location_id')
            ->where('uso.dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(uso.date)'), [$from, $to]);

        if ($request->filled('user_id')) $q->where('uso.user_id', $request->user_id);

        $rows = $q->groupBy('cp.id', 'cp.name', 'uso.user_id', 'uso.date')
            ->select([
                'cp.id as product_id',
                'cp.name as product_name',
                DB::raw('SUM(usod.case_quantity) as case_quantity'),
                DB::raw('SUM(usod.quantity) as quantity'),
                DB::raw('SUM((usod.rate * usod.quantity) + (usod.case_rate * usod.case_quantity)) as sale_value'),
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                DB::raw('GROUP_CONCAT(DISTINCT l7.name) as beat_name'),
                'uso.user_id',
                'uso.date',
            ])
            ->orderBy('product_name')
            ->get();

        $mrps = DB::table('product_rate_list')->where('company_id', $company)->pluck('mrp_pcs', 'product_id');

        return $this->ok($rows, ['from' => $from, 'to' => $to, 'mrp' => $mrps]);
    }

    public function itemChallan(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'co.id', '=', 'cod.ch_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->join('person as p', 'p.id', '=', 'co.ch_user_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

        if ($request->filled('user_id')) $q->where('co.ch_user_id', $request->user_id);

        $rows = $q->groupBy('cp.id', 'cp.name', 'co.ch_user_id')
            ->select([
                'cp.id as product_id',
                'cp.name as product_name',
                DB::raw('SUM(cod.qty) as quantity'),
                DB::raw('SUM(cod.product_rate * cod.qty) as sale_value'),
                DB::raw('SUM(cod.vat_amt) as vat_amt'),
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                'co.ch_user_id as user_id',
            ])
            ->orderBy('product_name')
            ->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

  public function retailerBillSummary(Request $request)
{
    [$from, $to] = $this->dateRange($request, 'start', 'end');
    ['dealer_id' => $dealer] = $this->scope($request);

    $q = DB::table('challan_order as co')
        ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
        ->where('co.ch_dealer_id', $dealer)
        ->where('r.retailer_status', 1)
        ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

    if ($request->filled('retailer_id')) $q->where('co.ch_retailer_id', $request->retailer_id);

    $rows = $q->groupBy('co.ch_retailer_id', 'r.name', 'co.ch_no', DB::raw('DATE(co.ch_date)'))
        ->select([
            'co.id as id',
            'co.ch_retailer_id',
            'co.ch_no as invoice_no',
            DB::raw("DATE_FORMAT(co.ch_date,'%d-%m-%Y') as date"),
            'r.name as retailer_name',
            'co.amount as total_amt',
            'co.amount_round',
            'co.remaining',
        ])
        ->orderBy('date')->orderBy('invoice_no')->get();

    return $this->ok($rows, ['from' => $from, 'to' => $to]);
}



    public function retailerDuesAgeing(Request $request)
    {
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope($request);
        $today = now()->toDateString();

        $rows = DB::table('challan_order as co')
            ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->where('co.company_id', $company)
            ->groupBy('co.ch_retailer_id', 'r.name')
            ->select([
                'co.ch_retailer_id',
                'r.name as retailer_name',
                DB::raw('ROUND(SUM(co.remaining),2) as total_dues'),
                DB::raw("ROUND(SUM(CASE WHEN DATE(co.ch_date) >= DATE_SUB('$today', INTERVAL 30 DAY) THEN co.remaining ELSE 0 END),2) as lt30"),
                DB::raw("ROUND(SUM(CASE WHEN DATE(co.ch_date) >= DATE_SUB('$today', INTERVAL 60 DAY) AND DATE(co.ch_date) < DATE_SUB('$today', INTERVAL 30 DAY) THEN co.remaining ELSE 0 END),2) as lt60"),
                DB::raw("ROUND(SUM(CASE WHEN DATE(co.ch_date) >= DATE_SUB('$today', INTERVAL 90 DAY) AND DATE(co.ch_date) < DATE_SUB('$today', INTERVAL 60 DAY) THEN co.remaining ELSE 0 END),2) as lt90"),
                DB::raw("ROUND(SUM(CASE WHEN DATE(co.ch_date) < DATE_SUB('$today', INTERVAL 90 DAY) THEN co.remaining ELSE 0 END),2) as lt90plus"),
            ])
            ->havingRaw('total_dues > 0')
            ->orderByDesc('total_dues')->get();

        return $this->ok($rows, ['as_on' => $today]);
    }

  public function salesPurchasePerformance(Request $request)
{
    [$from, $to] = $this->dateRange($request);
    ['dealer_id' => $dealer] = $this->scope($request);

    $startMonth = \Carbon\Carbon::parse($from)->startOfMonth();
    $endMonth   = \Carbon\Carbon::parse($to)->endOfMonth();
    $period     = new \DatePeriod($startMonth, new \DateInterval('P1M'), $endMonth->copy()->addDay());

    $out = [];
    foreach ($period as $dt) {
        $ym    = $dt->format('Y-m');
        $label = $dt->format('M-Y');

        $sale = DB::table('challan_order')
            ->where('ch_dealer_id', $dealer)
            ->whereRaw("DATE_FORMAT(ch_date,'%Y-%m') = ?", [$ym])
            ->sum('amount_round');

        $purchase = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('po.order_id', '=', 'pod.order_id')
                  ->on('po.challan_no', '=', 'pod.purchase_inv');
            })
            ->where('po.dealer_id', $dealer)
            ->whereRaw("DATE_FORMAT(po.created_date,'%Y-%m') = ?", [$ym])
            ->sum('pod.total_amount');

        $out[] = ['month' => $ym, 'label' => $label, 'sale' => (float) $sale, 'purchase' => (float) $purchase];
    }

    return $this->ok($out);
}

    public function salesPurchaseList(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);

        $rows = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('co.ch_dealer_id', $dealer)
            ->groupBy('c2.id', 'c2.name')
            ->select(['c2.id as pid', 'c2.name as cat'])
            ->orderBy('cat')->get();

        return $this->ok($rows);
    }
}