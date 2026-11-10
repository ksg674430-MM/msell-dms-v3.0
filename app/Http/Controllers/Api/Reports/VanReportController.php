<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use App\Secondary_Sale;
use App\VanSalesOrder;
use App\VanSalesOrderDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VanReportController extends Controller
{
    use ReportFilterTrait;

    public function ssVanSales(Request $request)
    {
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $orders = VanSalesOrder::getVanSalesOrder($company, $from, $to)
            ->leftJoin('_vehicle_details as vd', 'vd.id', '=', 'ss_van_sales_order.vehicle_id')
            ->where('ss_van_sales_order.dealer_id', $dealer)
            ->orderByDesc('ss_van_sales_order.sale_date')->orderByDesc('ss_van_sales_order.date_time')
            ->addSelect('ss_van_sales_order.address', 'ss_van_sales_order.flag', 'ss_van_sales_order.driver_number',
                'ss_van_sales_order.vehicle_number', 'ss_van_sales_order.meter_reading', 'ss_van_sales_order.total_volume', 'vd.name as vehicle')
            ->get();

        $items = DB::table('ss_van_sales_order_details as sd')
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'sd.product_id')
            ->whereIn('sd.order_id', $orders->pluck('order_id'))
            ->select('sd.order_id', 'cp.name as product', 'sd.pr_rate as rate', 'sd.cases', 'sd.weight',
                DB::raw('ROUND(sd.pr_rate * sd.cases, 2) as amount'))
            ->get()->groupBy('order_id');

        foreach ($orders as $o) {
            $o->items = $items[$o->order_id] ?? [];
        }

        return $this->ok($orders);
    }

    public function vanSales(Request $request)
    {
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $orders = VanSalesOrder::getVanSalesOrder($company, $from, $to)
            ->where('ss_van_sales_order.dealer_id', $dealer)
            ->addSelect('ss_van_sales_order.vehicle_number', 'ss_van_sales_order.meter_reading')
            ->get()->keyBy('order_id');
        if ($orders->isEmpty()) return $this->ok([]);
        $ids = $orders->keys()->map(fn ($id) => (string) $id)->all();

        $details = VanSalesOrderDetail::getVanSalesOrderDetails($ids, $company);

        $fulfilment = DB::table('fullfillment_order as fo')
            ->join('fullfillment_order_details as fd', 'fd.order_id', '=', 'fo.order_id')
            ->whereIn('fo.van_order_id', $ids)
            ->groupBy('fo.van_order_id', 'fd.product_id')
            ->select('fo.van_order_id as order_id', 'fd.product_id',
                DB::raw('SUM(fd.product_fullfiment_cases) as cases'),
                DB::raw('SUM(fd.product_fullfiment_qty) as pcs'),
                DB::raw('SUM(fd.product_rate * fd.product_fullfiment_qty + fd.product_case_rate * fd.product_fullfiment_cases) as value'))
            ->get();

        $secondary = Secondary_Sale::getSecondarySale($ids, $company);

        $vanOf = DB::table('user_sales_order_extra_details')->whereIn('van_sale_order_id', $ids)->pluck('van_sale_order_id', 'order_id');
        $payments = [];
        foreach ($this->payments($vanOf->keys()->all()) as $orderId => $p) {
            $van = $vanOf[$orderId];
            foreach (['cash', 'online', 'credit'] as $f) {
                $payments[$van][$f] = ($payments[$van][$f] ?? 0) + (float) $p->$f;
            }
        }

        $rows = [];
        foreach ($details as $d) {
            $key = $d->order_id.'_'.$d->product_id;
            $qpc = (float) $d->quantity_per_case;
            if (!isset($rows[$key])) {
                $h = $orders[$d->order_id];
                $rows[$key] = [
                    'order_id' => (string) $d->order_id,
                    'product_id' => (int) $d->product_id,
                    'date' => $h->sale_date,
                    'dealer_name' => $h->dealer_name,
                    'vehicle_number' => $h->vehicle_number,
                    'driver_name' => $h->driver_name,
                    'beat_name' => $h->beat_name,
                    'csa_name' => $h->csa_name,
                    'load_in_time' => $h->date_time,
                    'load_out_time' => $h->checkout_date_time,
                    'start_kms' => $h->meter_reading,
                    'classification' => $d->classification_name,
                    'category' => $d->category_name,
                    'product' => $d->product_name,
                    'sku' => $d->sku_name,
                    'rate' => (float) $d->pr_rate,
                    'qpc' => $qpc,
                    'load_qty' => 0, 'fulfilment_qty' => 0, 'fulfilment_value' => 0,
                    'ready_qty' => 0, 'ready_value' => 0, 'free_qty' => 0,
                    'cash' => 0, 'online' => 0, 'credit' => 0,
                ];
            }
            $rows[$key]['load_qty'] += $d->cases + ($qpc > 0 ? $d->pcs / $qpc : 0);
        }

        foreach ($fulfilment as $f) {
            $key = $f->order_id.'_'.$f->product_id;
            if (!isset($rows[$key])) continue;
            $qpc = $rows[$key]['qpc'];
            $rows[$key]['fulfilment_qty'] += $f->cases + ($qpc > 0 ? $f->pcs / $qpc : 0);
            $rows[$key]['fulfilment_value'] += (float) $f->value;
        }

        foreach ($secondary as $s) {
            $key = $s->order_id.'_'.$s->product_id;
            if (!isset($rows[$key])) continue;
            $qpc = $rows[$key]['qpc'];
            $rows[$key]['ready_qty'] += $s->case_quantity + ($qpc > 0 ? $s->quantity / $qpc : 0);
            $rows[$key]['free_qty'] += $qpc > 0 ? $s->scheme_qty / $qpc : 0;
            $rows[$key]['ready_value'] += (float) $s->total_sale;
        }

        $orderValue = [];
        foreach ($rows as &$row) {
            $row['sale_qty'] = $row['fulfilment_qty'] + $row['ready_qty'];
            $row['sale_value'] = $row['fulfilment_value'] + $row['ready_value'];
            $orderValue[$row['order_id']] = ($orderValue[$row['order_id']] ?? 0) + $row['sale_value'];
        }
        unset($row);

        foreach ($rows as &$row) {
            $pay = $payments[$row['order_id']] ?? null;
            $total = $orderValue[$row['order_id']];
            if ($pay && $total > 0) {
                $ratio = $row['sale_value'] / $total;
                foreach (['cash', 'online', 'credit'] as $f) {
                    $row[$f] = round($pay[$f] * $ratio, 2);
                }
            }
            foreach (['load_qty', 'fulfilment_qty', 'fulfilment_value', 'ready_qty', 'ready_value', 'free_qty', 'sale_qty', 'sale_value'] as $f) {
                $row[$f] = round($row[$f], 2);
            }
        }
        unset($row);

        return $this->ok(array_values($rows));
    }

    public function retailerLedger(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $orders = $this->retailerOrders($dealer, $from, $to);
        if ($orders->isEmpty()) return $this->ok([]);
        $ids = $orders->pluck('order_id')->all();

        $sales = DB::table('user_sales_order as o')
            ->join('user_sales_order_details as d', 'd.order_id', '=', 'o.order_id')
            ->whereIn('o.order_id', $ids)
            ->groupBy('o.retailer_id')
            ->select(DB::raw('CAST(o.retailer_id AS CHAR) as retailer_id'),
                DB::raw('SUM(d.case_quantity) as qty'),
                DB::raw('SUM(d.rate * d.quantity + d.case_rate * d.case_quantity) as value'))
            ->get()->keyBy('retailer_id');

        $discounts = DB::table('fullfillment_order')
            ->whereIn('order_id', $ids)
            ->groupBy('retailer_id')
            ->select(DB::raw('CAST(retailer_id AS CHAR) as retailer_id'), DB::raw('SUM(discount_amount) as discount'))
            ->pluck('discount', 'retailer_id');

        $payments = $this->payments($ids);
        $seniors = $this->seniors($orders->pluck('user_id')->all());
        $names = $this->names($orders->pluck('created_by_person_id')->all());

        $rows = [];
        foreach ($orders as $o) {
            $rid = $o->retailer_id;
            if (!isset($rows[$rid])) {
                $rows[$rid] = [
                    'retailer_id' => $rid,
                    'retailer_name' => $o->retailer_name,
                    'contact_person' => $o->contact_per_name,
                    'contact_no' => $o->contact_no,
                    'beat_name' => $o->beat_name,
                    'db_name' => $o->db_name,
                    'sale_qty' => (float) ($sales[$rid]->qty ?? 0),
                    'sale_value' => round((float) ($sales[$rid]->value ?? 0), 2),
                    'discount' => (float) ($discounts[$rid] ?? 0),
                    'cash' => 0, 'online' => 0, 'cheque' => 0, 'credit' => 0,
                    'se' => [], 'so' => [],
                    'channel' => $o->channel,
                    'created_by' => $names[$o->created_by_person_id] ?? '',
                    'created_date' => $o->created_on,
                ];
            }
            if ($pay = $payments[$o->order_id] ?? null) {
                foreach (['cash', 'online', 'cheque', 'credit'] as $f) {
                    $rows[$rid][$f] += (float) $pay->$f;
                }
            }
            $rows[$rid]['se'] += $seniors[$o->user_id]['se'];
            $rows[$rid]['so'] += $seniors[$o->user_id]['so'];
        }

        return $this->ok(array_values(array_map(fn ($r) => array_merge($r, [
            'total_collection' => $r['cash'] + $r['online'] + $r['cheque'] + $r['credit'],
            'outstanding' => $r['credit'],
            'se' => implode(', ', $r['se']),
            'so' => implode(', ', $r['so']),
        ]), $rows)));
    }

    public function retailerWiseSales(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $orders = $this->retailerOrders($dealer, $from, $to);
        if ($orders->isEmpty()) return $this->ok([]);
        $ids = $orders->pluck('order_id')->all();

        $details = $this->orderDetails($ids);
        $payments = $this->payments($ids);
        $fulfilment = DB::table('fullfillment_order_details')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id', 'product_id')
            ->select('order_id', 'product_id',
                DB::raw('SUM(product_fullfiment_cases) as cases'),
                DB::raw('SUM(product_fullfiment_scheme_cases) as scheme'),
                DB::raw('SUM(product_value) as value'))
            ->get()->keyBy(fn ($f) => $f->order_id.'_'.$f->product_id);
        $seniors = $this->seniors($orders->pluck('user_id')->all());
        $names = $this->names(array_merge($orders->pluck('user_id')->all(), $orders->pluck('created_by_person_id')->all()));

        $rows = [];
        foreach ($orders as $o) {
            $pay = $payments[$o->order_id] ?? null;
            foreach ($details[$o->order_id] ?? [] as $i => $d) {
                $ful = $fulfilment[$o->order_id.'_'.$d->product_id] ?? null;
                $rows[] = [
                    'first' => $i === 0,
                    'date' => $o->date,
                    'order_id' => $o->order_id,
                    'retailer_id' => $o->retailer_id,
                    'retailer_name' => $o->retailer_name,
                    'contact_person' => $o->contact_per_name,
                    'contact_no' => $o->contact_no,
                    'beat_name' => $o->beat_name,
                    'db_name' => $o->db_name,
                    'classification' => $d->classification,
                    'category' => $d->category,
                    'product' => $d->product,
                    'sku' => $d->sku,
                    'product_id' => (int) $d->product_id,
                    'rate_per_case' => (float) $d->case_rate,
                    'order_qty' => (float) $d->case_quantity,
                    'free_qty' => (float) $d->scheme_qty,
                    'total_order_qty' => (float) $d->case_quantity + (float) $d->scheme_qty,
                    'order_value' => round($d->rate * $d->quantity + $d->case_rate * $d->case_quantity, 2),
                    'fulfilled_cases' => (float) ($ful->cases ?? 0),
                    'fulfilled_scheme' => (float) ($ful->scheme ?? 0),
                    'fulfilled_value' => (float) ($ful->value ?? 0),
                    'discount' => (float) $d->discount,
                    'sale_value' => (float) ($pay->amount ?? 0),
                    'cash' => (float) ($pay->cash ?? 0),
                    'online' => (float) ($pay->online ?? 0),
                    'credit' => (float) ($pay->credit ?? 0),
                    'cheque' => (float) ($pay->cheque ?? 0),
                    'has_payment' => $pay !== null,
                    'image' => $o->image_name,
                    'order_by' => $names[$o->user_id] ?? '',
                    'order_type' => $o->van_sale_order_id ? 'Ready Order' : 'SFA Order',
                    'se' => implode(', ', $seniors[$o->user_id]['se']),
                    'so' => implode(', ', $seniors[$o->user_id]['so']),
                    'channel' => $o->channel,
                    'created_by' => $names[$o->created_by_person_id] ?? '',
                    'status' => ['0' => 'Inactive', '1' => 'Active'][(string) $o->retailer_status] ?? 'Deleted',
                ];
            }
        }

        return $this->ok($rows);
    }

    public function updateRetailerWiseSale(Request $request, string $orderId)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'sale_value' => 'required|numeric|min:0',
            'discount' => 'required|numeric|min:0',
            'cash' => 'required|numeric|min:0',
            'online' => 'required|numeric|min:0',
            'credit' => 'required|numeric|min:0',
            'cheque' => 'required|numeric|min:0',
        ]);
        ['dealer_id' => $dealer] = $this->scope($request);

        abort_unless(DB::table('user_sales_order')->where('order_id', $orderId)->where('dealer_id', $dealer)->exists(), 404, 'Order not found.');
        abort_unless(DB::table('van_sale_payment_details')->where('order_id', $orderId)->exists(), 422, 'No van sale payment exists for this order.');

        DB::transaction(function () use ($orderId, $data) {
            DB::table('van_sale_payment_details')->where('order_id', $orderId)->update([
                'amount' => (int) round($data['sale_value']),
                'cash_amount' => $data['cash'],
                'online_amount' => $data['online'],
                'credit_amount' => $data['credit'],
                'cheque_amount' => $data['cheque'],
            ]);
            DB::table('user_sales_order_details')->where('order_id', $orderId)->where('product_id', $data['product_id'])
                ->update(['product_discount' => $data['discount']]);
        });

        return response()->json(['status' => true, 'message' => 'Sale details updated successfully.']);
    }

    public function retailerWise(Request $request)
    {
        ['dealer_id' => $dealer] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $orders = $this->retailerOrders($dealer, $from, $to);
        if ($orders->isEmpty()) return $this->ok(['products' => [], 'rows' => []]);
        $ids = $orders->pluck('order_id')->all();

        $details = $this->orderDetails($ids);
        $payments = $this->payments($ids);
        $fulfilment = DB::table('fullfillment_order_details')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id')
            ->select('order_id',
                DB::raw('SUM(product_fullfiment_cases) as cases'),
                DB::raw('SUM(product_fullfiment_scheme_cases) as scheme'),
                DB::raw('SUM(product_value) as value'))
            ->get()->keyBy('order_id');
        $seniors = $this->seniors($orders->pluck('user_id')->all());

        $products = [];
        $rows = [];
        foreach ($orders as $o) {
            if (!isset($details[$o->order_id])) continue;
            $rid = $o->retailer_id;
            $rows[$rid] ??= [
                'retailer_id' => $rid,
                'retailer_name' => $o->retailer_name,
                'contact_person' => $o->contact_per_name,
                'contact_no' => $o->contact_no,
                'beat_name' => $o->beat_name,
                'db_name' => $o->db_name,
                'qty' => [],
                'fulfilled_cases' => 0, 'fulfilled_scheme' => 0, 'fulfilled_value' => 0, 'discount' => 0,
                'cash' => 0, 'online' => 0, 'cheque' => 0, 'credit' => 0, 'adjustment' => 0,
                'image' => $o->image_name,
                'se' => [], 'so' => [],
                'channel' => $o->channel,
            ];
            foreach ($details[$o->order_id] as $d) {
                $qty = (float) $d->case_quantity + ($d->qpc > 0 ? $d->quantity / $d->qpc : 0);
                $rows[$rid]['qty'][$d->product_id] = ($rows[$rid]['qty'][$d->product_id] ?? 0) + $qty;
                $rows[$rid]['discount'] += (float) $d->discount;
                $products[$d->product_id] = $d->sku;
            }
            if ($ful = $fulfilment[$o->order_id] ?? null) {
                $rows[$rid]['fulfilled_cases'] += (float) $ful->cases;
                $rows[$rid]['fulfilled_scheme'] += (float) $ful->scheme;
                $rows[$rid]['fulfilled_value'] += (float) $ful->value;
            }
            if ($pay = $payments[$o->order_id] ?? null) {
                foreach (['cash', 'online', 'cheque', 'credit', 'adjustment'] as $f) {
                    $rows[$rid][$f] += (float) $pay->$f;
                }
            }
            $rows[$rid]['se'] += $seniors[$o->user_id]['se'];
            $rows[$rid]['so'] += $seniors[$o->user_id]['so'];
        }

        ksort($products);
        return $this->ok([
            'products' => collect($products)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values(),
            'rows' => array_values(array_map(fn ($r) => array_merge($r, [
                'qty' => array_map(fn ($q) => round($q, 2), $r['qty']),
                'collection' => $r['cash'] + $r['online'] + $r['cheque'] + $r['adjustment'],
                'outstanding' => $r['credit'],
                'se' => implode(', ', $r['se']),
                'so' => implode(', ', $r['so']),
            ]), $rows)),
        ]);
    }

    private function retailerOrders(int $dealer, string $from, string $to)
    {
        return DB::table('user_sales_order as o')
            ->join('retailer as r', 'r.id', '=', 'o.retailer_id')
            ->leftJoin('dealer as d', 'd.id', '=', 'o.dealer_id')
            ->leftJoin('location_7 as l', 'l.id', '=', 'r.location_id')
            ->leftJoin('location_6 as l6', 'l6.id', '=', 'l.location_6_id')
            ->leftJoin('user_sales_order_extra_details as e', 'e.order_id', '=', 'o.order_id')
            ->where('o.dealer_id', $dealer)
            ->whereBetween('o.date', [$from, $to])
            ->orderBy('o.date')->orderBy('o.time')
            ->select('o.order_id', 'o.date', 'o.user_id', 'o.image_name',
                DB::raw('CAST(o.retailer_id AS CHAR) as retailer_id'),
                'r.name as retailer_name', 'r.contact_per_name',
                DB::raw("COALESCE(NULLIF(r.landline, ''), r.other_numbers) as contact_no"),
                'r.created_by_person_id', 'r.created_on', 'r.retailer_status',
                'l.name as beat_name', 'l6.name as channel', 'd.name as db_name', 'e.van_sale_order_id')
            ->get();
    }

    private function orderDetails(array $ids)
    {
        return DB::table('user_sales_order_details as sod')
            ->join('catalog_product as cp', 'cp.id', '=', 'sod.product_id')
            ->leftJoin('catalog_2 as c2', 'c2.id', '=', 'cp.catalog_id')
            ->leftJoin('catalog_1 as c1', 'c1.id', '=', 'c2.catalog_1_id')
            ->leftJoin('catalog_0 as c0', 'c0.id', '=', 'c1.catalog_0_id')
            ->whereIn('sod.order_id', $ids)
            ->orderBy('sod.id')
            ->select('sod.order_id', 'sod.product_id', 'c0.name as classification', 'c1.name as category', 'c2.name as product',
                'cp.name as sku', 'cp.quantity_per_case as qpc', 'sod.case_quantity', 'sod.case_rate', 'sod.rate', 'sod.quantity',
                'sod.scheme_qty', 'sod.product_discount as discount')
            ->get()->groupBy('order_id')->map->values();
    }

    private function payments(array $ids)
    {
        return DB::table('van_sale_payment_details')
            ->whereIn('order_id', $ids)
            ->groupBy('order_id')
            ->select('order_id',
                DB::raw('SUM(amount) as amount'),
                DB::raw('SUM(cash_amount) as cash'),
                DB::raw('SUM(online_amount) as online'),
                DB::raw('SUM(credit_amount) as credit'),
                DB::raw('SUM(cheque_amount) as cheque'),
                DB::raw('SUM(adujment_amount) as adjustment'))
            ->get()->keyBy('order_id');
    }

    private function names(array $ids): array
    {
        return DB::table('person')
            ->whereIn('id', array_unique(array_filter($ids)))
            ->pluck(DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name)"), 'id')
            ->all();
    }

    private function seniors(array $userIds): array
    {
        $people = [];
        $find = function ($id) use (&$people) {
            return $people[$id] ??= DB::table('person')->where('id', $id)
                ->select('person_id_senior', 'role_id', DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name) as name"))
                ->first();
        };

        $out = [];
        foreach (array_unique($userIds) as $uid) {
            $out[$uid] = ['se' => [], 'so' => []];
            $seen = [$uid => true];
            $id = $find($uid)->person_id_senior ?? 0;
            while ($id && !isset($seen[$id]) && ($p = $find($id))) {
                $seen[$id] = true;
                if ((int) $p->role_id === 2958) $out[$uid]['se'][$id] = $p->name;
                if ((int) $p->role_id === 2957) $out[$uid]['so'][$id] = $p->name;
                $id = $p->person_id_senior;
            }
        }
        return $out;
    }
}
