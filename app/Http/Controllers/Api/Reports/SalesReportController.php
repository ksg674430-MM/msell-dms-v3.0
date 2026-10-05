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
            ->leftJoin('location_7 as l7', 'l7.id', '=', 'r.location_id')
            ->leftJoin('person as p', 'p.id', '=', 'co.ch_user_id')
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
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                'l7.name as beat_name',
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
            ->orderBy('co.ch_no')
            ->orderBy('cp.name');

        return $this->ok($q->get(), ['from' => $from, 'to' => $to]);
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
        if ($request->filled('product_id'))  $q->where('cod.product_id', $request->product_id);

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
                DB::raw("(SELECT ROUND(COALESCE(SUM(s.qty),0),2) FROM stock s WHERE s.product_id = cod.product_id AND s.dealer_id = {$dealer}) as closing_stock"),
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
        if ($request->filled('product_id'))  $q->where('cod.product_id', $request->product_id);

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
        ['dealer_id' => $dealer] = $this->scope($request);
        $today = now()->toDateString();

        $rows = DB::table('challan_order as co')
            ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->where('co.remaining', '>', 0)
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

    public function retailerCategorySale(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $rows = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->join('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to])
            ->groupBy('co.ch_retailer_id', 'r.name', 'c2.id', 'c2.name')
            ->orderBy('r.name')
            ->get([
                'co.ch_retailer_id as retailer_id',
                'r.name as retailer_name',
                'c2.id as category_id',
                'c2.name as category',
                DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as amount'),
            ]);

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    public function challanVsSale(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $rows = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->join('user_sales_order as uso', 'uso.order_id', '=', 'cod.order_id')
            ->join('retailer as r', 'r.id', '=', 'uso.retailer_id')
            ->leftJoin('person as p', 'p.id', '=', 'uso.user_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to])
            ->groupBy('uso.order_id', 'co.id', 'co.ch_no', 'co.ch_date', 'co.invoice_type', 'co.remark', 'uso.date', 'r.name', 'p.first_name', 'p.middle_name', 'p.last_name')
            ->orderBy('co.ch_date')
            ->get([
                'uso.order_id',
                'co.id as ch_id',
                'co.ch_no',
                DB::raw("DATE_FORMAT(co.ch_date,'%Y-%m-%d') as ch_date"),
                'co.invoice_type',
                'co.remark',
                DB::raw("DATE_FORMAT(uso.date,'%Y-%m-%d') as order_date"),
                'r.name as retailer_name',
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
            ]);

        $orderIds = $rows->pluck('order_id')->unique()->values()->all();
        $challanItems = DB::table('challan_order_details as cod')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->whereIn('cod.ch_id', $rows->pluck('ch_id')->map(fn ($v) => (string) $v)->all())
            ->whereIn('cod.order_id', $orderIds)
            ->get(['cod.ch_id', 'cod.order_id', 'cp.itemcode', 'cp.name', 'cod.qty', DB::raw('cod.qty * cod.product_rate as value')])
            ->groupBy(fn ($i) => $i->ch_id . '|' . $i->order_id);
        $orderItems = DB::table('user_sales_order_details as usod')
            ->join('catalog_product as cp', 'cp.id', '=', 'usod.product_id')
            ->whereIn('usod.order_id', $orderIds)
            ->get(['usod.order_id', 'cp.itemcode', 'cp.name', 'usod.quantity as qty', DB::raw('usod.quantity * usod.rate as value')])
            ->groupBy('order_id');

        foreach ($rows as $r) {
            $r->order_via     = (int) $r->invoice_type === 1 ? 'Direct Invoice' : ($r->remark === 'Order Submitted by whatsapp' ? 'Secondary Sale Whatsapp Order Invoice' : ((int) $r->invoice_type === 2 ? 'Secondary Sale Invoice' : ''));
            $r->challan_items = $challanItems[$r->ch_id . '|' . $r->order_id] ?? collect();
            $r->order_items   = $orderItems[$r->order_id] ?? collect();
            $r->challan_value = round($r->challan_items->sum('value'), 2);
            $r->order_value   = round($r->order_items->sum('value'), 2);
            unset($r->invoice_type, $r->remark);
        }

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    public function dsr(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('user_sales_order as uso')
            ->leftJoin('person as p', 'p.id', '=', 'uso.user_id')
            ->leftJoin('retailer as r', 'r.id', '=', 'uso.retailer_id')
            ->where('uso.dealer_id', $dealer)
            ->where('uso.call_status', '1')
            ->whereBetween(DB::raw('DATE(uso.date)'), [$from, $to]);

        if ($request->filled('order_no')) $q->where('uso.order_id', $request->order_no);

        $orders = $q->groupBy('uso.order_id', 'uso.date', 'uso.order_status', 'p.first_name', 'p.middle_name', 'p.last_name', 'r.name')
            ->orderBy('uso.date')
            ->get([
                'uso.order_id',
                DB::raw("DATE_FORMAT(uso.date,'%Y-%m-%d') as date"),
                'uso.order_status',
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                'r.name as retailer_name',
            ]);

        $items = DB::table('user_sales_order_details as usod')
            ->join('catalog_product as cp', 'cp.id', '=', 'usod.product_id')
            ->whereIn('usod.order_id', $orders->pluck('order_id')->all())
            ->orderBy('usod.id')
            ->get(['usod.order_id', 'cp.itemcode', 'cp.name', 'usod.quantity', 'usod.scheme_qty', DB::raw('usod.quantity * usod.rate as value')])
            ->groupBy('order_id');

        $statuses = ['Fresh Order', 'Challan Generated', 'Challan Cancel', 'Invoice Generated', 'invoice Cancel', 'Order Dispatch', 'challan Closed'];
        foreach ($orders as $o) {
            $status        = (int) $o->order_status;
            $o->status     = $statuses[$status] ?? ($status === 8 ? 'Cancelled' : '');
            $o->dms_status = $status === 1 ? 'Delivered' : ($status === 8 ? 'Cancelled' : 'Undelivered');
            $o->items      = $items[$o->order_id] ?? collect();
            $o->amount     = round($o->items->sum('value'), 2);
            unset($o->order_status);
        }

        return $this->ok($orders, ['from' => $from, 'to' => $to]);
    }

    public function filters(Request $request)
    {
        ['company_id' => $company] = $this->scope($request);

        return $this->ok([
            'categories' => DB::table('catalog_2')->where('company_id', $company)->orderBy('name')->get(['id', 'name']),
            'divisions'  => DB::table('_product_division')->where('company_id', $company)->orderBy('type')->get(['id', 'type as name']),
            'products'   => DB::table('catalog_product')->where('company_id', $company)->where('status', 1)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
