<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReportController extends Controller
{
    use ReportFilterTrait;

    public function balanceStock(Request $request)
    {
        ['dealer_id' => $dealer, 'state_id' => $state] = $this->scope($request);

        $q = DB::table('catalog_product as c')
            ->join('catalog_2 as c2', 'c2.id', '=', 'c.catalog_id')
            ->leftJoin('stock as s', function ($j) use ($dealer) {
                $j->on('s.product_id', '=', 'c.id')->where('s.dealer_id', $dealer);
            })
            ->leftJoin('other_company as oc', 'oc.id', '=', 'c.company_id')
            ->where('c.status', 1);

        if ($request->filled('product_id'))      $q->where('c.id', $request->product_id);
        if ($request->filled('category_id'))     $q->where('c.catalog_id', $request->category_id);
        if ($request->filled('manufacturer_id')) $q->where('c.company_id', $request->manufacturer_id);
        if ($request->filled('batch_no'))        $q->where('s.batch_no', $request->batch_no);
        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->where(fn($w) => $w->where('c.name', 'like', "%$s%")->orWhere('c.itemcode', 'like', "%$s%"));
        }

        $q->select([
            'c.id as pid', 'c.name as product_name', 'c.itemcode', 'c.division',
            'c.gst_percent as product_gst', 'c.quantity_per_case', 'c.weight',
            'c2.id as category_id', 'c2.name as cat',
            'oc.id as manufacturer_id', 'oc.cp_name as company_name', 'oc.cp_short_name',
            's.id as stock_id', 's.batch_no', 's.rate', 's.dealer_rate', 's.mrp',
            's.qty', 's.nonsalable_damage as nonsale', 's.salable_damage',
            DB::raw("DATE_FORMAT(s.mfg,'%b-%Y')    as mfg"),
            DB::raw("DATE_FORMAT(s.expire,'%b-%Y') as expire"),
        ])->orderBy('c.name')->orderBy('s.batch_no');

        [$per, $page] = $this->paging($request, 200);
        $p = $q->paginate($per, ['*'], 'page', $page);

        $pids = collect($p->items())->pluck('pid')->unique()->values()->all();

        $mrpMap = $state
            ? DB::table('product_rate_list')->whereIn('product_id', $pids)
                ->where('state_id', $state)->get(['product_id','mrp','dealer_rate'])
                ->keyBy('product_id')
            : collect();

        $inTransit = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', 'pod.order_id', '=', 'po.order_id')
            ->where('po.dealer_id', $dealer)->whereDate('po.ch_date', '1970-01-01')
            ->whereIn('pod.product_id', $pids)->groupBy('pod.product_id')
            ->select('pod.product_id', DB::raw('SUM(pod.quantity + pod.scheme_qty) as qty'))
            ->pluck('qty', 'product_id');

        $sumMap = fn(string $table) => DB::table($table)
            ->where('dealer_id', $dealer)->whereIn('product_id', $pids)
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(qty) as qty'))
            ->pluck('qty', 'product_id');

        $thresholdMap = $sumMap('threshold');
        $manualMap    = $sumMap('stock_manual');
        $nonsaleMap   = $sumMap('stock_nonsale');

        $rows = collect($p->items())->map(function ($r) use ($mrpMap, $inTransit, $thresholdMap, $manualMap, $nonsaleMap) {
            $r->product_mrp         = (float) ($mrpMap[$r->pid]->mrp ?? 0);
            $r->default_dealer_rate = (float) ($mrpMap[$r->pid]->dealer_rate ?? 0);
            $r->intransit           = (float) ($inTransit[$r->pid] ?? 0);
            $r->thresh              = (float) ($thresholdMap[$r->pid] ?? 0);
            $r->manual              = (float) ($manualMap[$r->pid] ?? 0);
            $r->manual_nonsale      = (float) ($nonsaleMap[$r->pid] ?? 0);
            return $r;
        });

        return $this->ok($rows, [
            'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(),
        ]);
    }

    public function balanceStockEdit(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('stock as s')
            ->join('catalog_product as cp', 'cp.id', '=', 's.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('s.dealer_id', $dealer);

        if ($request->filled('product_id'))  $q->where('s.product_id', $request->product_id);
        if ($request->filled('batch_no'))    $q->where('s.batch_no', $request->batch_no);
        if ($request->filled('category_id')) $q->where('c2.id', $request->category_id);

        $rows = $q->select([
            's.id as pid', 's.product_id', 'cp.name as product_name', 'cp.itemcode',
            'c2.id as category_id', 'c2.name as cat',
            's.batch_no', 's.rate', 's.dealer_rate', 's.mrp',
            's.qty', 's.salable_damage', 's.nonsalable_damage',
            DB::raw("DATE_FORMAT(s.mfg,'%b-%Y')    as mfg"),
            DB::raw("DATE_FORMAT(s.expire,'%b-%Y') as expire"),
        ])->orderBy('cp.name')->get();

        return $this->ok($rows);
    }

  public function primaryStock(Request $request)
{
    $q = DB::table('catalog_product_details as cpd')
        ->join('catalog_product as cp', 'cp.id', '=', 'cpd.product_id');

    if ($request->filled('product_id')) $q->where('cpd.product_id', $request->product_id);
    if ($request->filled('batch_no'))   $q->where('cpd.batch_no', $request->batch_no);

    $rows = $q->select([
        'cpd.auto', 'cpd.id', 'cpd.product_id', 'cp.name as product_name',
        'cp.itemcode', 'cpd.batch_no', 'cpd.ostock', 'cpd.rate',
        DB::raw("DATE_FORMAT(cpd.mfg_date,'%d/%m/%Y')    as mfg_date"),
        DB::raw("DATE_FORMAT(cpd.expiry_date,'%d/%m/%Y') as expiry_date"),
        'cpd.created',
    ])->orderBy('cp.name')->orderBy('cpd.batch_no')->limit(500)->get();

    return $this->ok($rows);
}


   public function itemOpeningStock(Request $request)
{
    $q = DB::table('catalog_product_details as cpd')
        ->join('catalog_product as cp', 'cp.id', '=', 'cpd.product_id');

    if ($request->filled('product_id')) $q->where('cpd.product_id', $request->product_id);

    $rows = $q->groupBy('cpd.product_id', 'cp.name', 'cpd.batch_no')
        ->select([
            'cpd.product_id',
            DB::raw("CONCAT_WS(' ', cp.name, cpd.batch_no) as product_name"),
            'cpd.batch_no',
            DB::raw('SUM(cpd.ostock) as open_stock'),
        ])->limit(500)->get();

    return $this->ok($rows);
}


    public function stockSummary(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('stock as s')
            ->join('catalog_product as cp', 'cp.id', '=', 's.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('s.dealer_id', $dealer);

        if ($request->filled('category_id')) $q->where('c2.id', $request->category_id);

        $rows = $q->groupBy('c2.id', 'c2.name')
            ->select([
                'c2.id as pdid',
                'c2.name as type',
                DB::raw('ROUND(SUM(s.qty),2) as qty'),
                DB::raw('ROUND(SUM(s.dealer_rate * s.qty),2) as total_amt'),
                DB::raw('ROUND(SUM(s.rate * s.qty),2) as total_rate_amt'),
            ])->orderBy('type')->get();

        return $this->ok($rows);
    }

    public function openingClosingStock(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $fyStart = now()->year . '-04-01';
        if (now()->month < 4) $fyStart = (now()->year - 1) . '-04-01';
        $prevDay = date('Y-m-d', strtotime($from . ' -1 day'));

        $productIds = DB::table('stock')->where('dealer_id', $dealer)->distinct()->pluck('product_id');

        $rows = DB::table('catalog_product as cp')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->whereIn('cp.id', $productIds)
            ->select('cp.id as product_id', 'cp.name as product_name', 'cp.itemcode',
                     'c2.id as category_id', 'c2.name as cat', 'cp.unit')
            ->orderBy('cp.name')->get();

        $rows->transform(function ($r) use ($dealer, $from, $to, $prevDay, $fyStart) {
            $open = (float) DB::table('opening_stocks')
                ->where('dealer_id', $dealer)->where('product_id', $r->product_id)->sum('qty');

            $openPurchase = (float) DB::table('purchase_order as po')
                ->join('purchase_order_details as pod', function ($j) {
                    $j->on('pod.order_id', '=', 'po.order_id')
                      ->on('pod.purchase_inv', '=', 'po.challan_no');
                })
                ->where('po.dealer_id', $dealer)->where('pod.product_id', $r->product_id)
                ->whereDate('po.created_date', '>=', $fyStart)->whereDate('po.created_date', '<=', $prevDay)
                ->whereDate('po.receive_date', '!=', '0000-00-00')
                ->sum(DB::raw('pod.quantity + pod.scheme_qty'));

            $openBilled = (float) DB::table('challan_order as co')
                ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
                ->where('co.ch_dealer_id', $dealer)->where('cod.product_id', $r->product_id)
                ->whereDate('co.ch_date', '>=', $fyStart)->whereDate('co.ch_date', '<=', $prevDay)
                ->sum(DB::raw('cod.qty + cod.free_qty'));

            $periodPurchase = (float) DB::table('purchase_order as po')
                ->join('purchase_order_details as pod', function ($j) {
                    $j->on('pod.order_id', '=', 'po.order_id')
                      ->on('pod.purchase_inv', '=', 'po.challan_no');
                })
                ->where('po.dealer_id', $dealer)->where('pod.product_id', $r->product_id)
                ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
                ->whereDate('po.receive_date', '!=', '0000-00-00')
                ->sum(DB::raw('pod.quantity + pod.scheme_qty'));

            $periodBilled = (float) DB::table('challan_order as co')
                ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
                ->where('co.ch_dealer_id', $dealer)->where('cod.product_id', $r->product_id)
                ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
                ->sum(DB::raw('cod.qty + cod.free_qty'));

            $r->opening = $open + $openPurchase - $openBilled;
            $r->purchase = $periodPurchase;
            $r->billed   = $periodBilled;
            $r->closing  = $r->opening + $periodPurchase - $periodBilled;
            return $r;
        });

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    public function stockLedger(Request $request)
    {
        $productId = (int) $request->input('product_id');
        if (!$productId) return $this->fail('product_id is required');

        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $openingQty = (float) DB::table('opening_stocks')
            ->where('product_id', $productId)->where('dealer_id', $dealer)->sum('qty');

        $openPurchase = (float) DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')
                  ->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->where('po.dealer_id', $dealer)->where('pod.product_id', $productId)
            ->whereDate('po.receive_date', '!=', '0000-00-00')
            ->whereDate('po.created_date', '<', $from)
            ->sum(DB::raw('pod.quantity + pod.scheme_qty'));

        $openSale = (float) DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->where('co.ch_dealer_id', $dealer)->where('cod.product_id', $productId)
            ->whereDate('co.ch_date', '<', $from)
            ->sum(DB::raw('cod.qty + cod.free_qty'));

        $openingBalance = $openingQty + $openPurchase - $openSale;

        $sales = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->leftJoin('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)->where('cod.product_id', $productId)
            ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
            ->select([
                DB::raw("'sale' as type"), 'co.id as ref_id', 'co.ch_no as ref_no',
                DB::raw("DATE_FORMAT(co.ch_date,'%Y-%m-%d') as ref_date"),
                'r.name as party_name', 'cod.batch_no',
                DB::raw('(cod.qty + cod.free_qty) as out_qty'),
                DB::raw('0 as in_qty'),
                DB::raw('cod.taxable_amt as amount'),
            ])->get();

        $purchases = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')
                  ->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->leftJoin('csa as c', 'c.c_id', '=', 'po.csa_id')
            ->where('po.dealer_id', $dealer)->where('pod.product_id', $productId)
            ->whereDate('po.receive_date', '!=', '0000-00-00')
            ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
            ->select([
                DB::raw("'purchase' as type"), 'po.id as ref_id', 'po.challan_no as ref_no',
                DB::raw("DATE_FORMAT(po.created_date,'%Y-%m-%d') as ref_date"),
                'c.csa_name as party_name', 'pod.batch_no',
                DB::raw('0 as out_qty'),
                DB::raw('(pod.quantity + pod.scheme_qty) as in_qty'),
                DB::raw('pod.total_amount as amount'),
            ])->get();

        $rows = $sales->merge($purchases)->sortBy(fn($r) => $r->ref_date.'_'.$r->type.$r->ref_id)->values();

        return $this->ok([
            'opening_balance' => $openingBalance,
            'transactions'    => $rows,
            'closing_balance' => $openingBalance + $purchases->sum('in_qty') - $sales->sum('out_qty'),
        ], ['from' => $from, 'to' => $to, 'product_id' => $productId]);
    }

    public function categoryQtyValue(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $purchase = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')
                  ->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->join('catalog_product as cp', 'cp.id', '=', 'pod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('po.dealer_id', $dealer)
            ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
            ->whereDate('po.receive_date', '!=', '0000-00-00')
            ->groupBy('c2.id', 'c2.name')
            ->select('c2.id as category_id', 'c2.name as category',
                DB::raw('ROUND(SUM(pod.quantity + pod.scheme_qty),2) as purchase_qty'),
                DB::raw('ROUND(SUM(pod.quantity * pod.rate),2) as purchase_value'))
            ->get()->keyBy('category_id');

        $billed = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('other_company as oc', 'oc.id', '=', 'cp.company_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
            ->groupBy('c2.id', 'c2.name')
            ->select('c2.id as category_id', 'c2.name as category',
                DB::raw('ROUND(SUM(cod.qty + cod.free_qty),2) as billed_qty'),
                DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as billed_value'))
            ->get()->keyBy('category_id');

        $all = collect($purchase->keys())->merge($billed->keys())->unique()->values();

        $rows = $all->map(fn($id) => [
            'category_id'    => $id,
            'category'       => $purchase[$id]->category    ?? $billed[$id]->category    ?? null,
            'purchase_qty'   => (float) ($purchase[$id]->purchase_qty   ?? 0),
            'purchase_value' => (float) ($purchase[$id]->purchase_value ?? 0),
            'billed_qty'     => (float) ($billed[$id]->billed_qty       ?? 0),
            'billed_value'   => (float) ($billed[$id]->billed_value     ?? 0),
        ])->values();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }
}