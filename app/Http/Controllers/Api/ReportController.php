<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location2;
use App\Models\Location3;
use App\Models\Location4;
use App\Models\Location5;
use App\Models\Location6;
use App\Models\Dealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private function ctx(Request $request): array
    {
        $u = $request->user();
        $dealer = DB::table('dealer')->where('id', $u->dealer_id ?? 0)->first();
        return [
            'dealer_id'   => (int) ($u->dealer_id ?? 0),
            'company_id'  => (int) ($dealer->company_id ?? ($u->company_id ?? 0)),
            'dealer_code' => $dealer->dealer_code ?? '',
            'role_id'     => (int) ($u->role_id ?? ($dealer->role_id ?? 0)),
        ];
    }

    /* =========================================================
     | SALE STATEMENT (was saleStatement + saleStatementEthical)
     * ========================================================= */
    public function saleStatementMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);

        $divisions = DB::table('MKTG_CATG_MAST')
            ->where('flag', 'Y')
            ->pluck('MKTG_CATG_NAME', 'MKTG_CATG');

        $locQ = Location2::where('status', 1);
        if ($c['role_id'] !== 1) {
            $locQ->join('dealer_report_section_data', 'dealer_report_section_data.location_2', '=', 'location_2.id')
                ->where('dealer_id', $c['dealer_id']);
        }
        $locations = $locQ->groupBy('location_2.id')->pluck('location_2.name as name', 'location_2.id as id');

        return response()->json([
            'status'  => true,
            'message' => 'Sale statement meta',
            'data'    => [
                'dms_division'     => $divisions,
                'location_2_arr'   => $locations,
                'variant'          => 'standard',   // or 'ethical' via separate route
            ],
        ]);
    }

    /* =========================================================
     | TALLY (was dms_to_tally_report + dms_to_tally)
     * ========================================================= */
    public function tallyMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);

        $retailers = DB::table('dealer_location_rate_list')
            ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            ->select('retailer.id as retailer_id', 'retailer.name as retailer_name')
            ->where('dealer_location_rate_list.dealer_id', $c['dealer_id'])
            ->where('dealer_location_rate_list.company_id', $c['company_id'])
            ->where('retailer.company_id', $c['company_id'])
            ->pluck('retailer_name', 'retailer_id');

        return response()->json([
            'status'  => true,
            'message' => 'Tally report meta',
            'data'    => ['retailer' => $retailers],
        ]);
    }

    public function tallyReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        [$from, $to] = $this->parseDateRange($request);

        $q = DB::table('challan_order')
            ->join('challan_order_details', 'challan_order_details.ch_id', '=', 'challan_order.id')
            ->join('retailer', 'retailer.id', '=', 'challan_order.ch_retailer_id')
            ->join('dealer', 'dealer.id', '=', 'challan_order.ch_dealer_id')
            ->select(
                'ch_retailer_id as retailer_id',
                'ch_date as challan_date',
                'state_id_retailer',
                'product_id',
                'hsn_code',
                'retailer.name as retailer_name',
                'challan_order.ch_no as challan_id',
                'dealer.state_id as state_id_dealer',
                'product_rate',
                'qty',
                'free_qty',
                'retailer.tin_no as gst_no',
                'taxable_amt'
            )
            ->whereDate('challan_order.ch_date', '>=', $from)
            ->whereDate('challan_order.ch_date', '<=', $to)
            ->where('retailer.company_id', $c['company_id'])
            ->where('dealer.company_id', $c['company_id'])
            ->where('challan_order.ch_dealer_id', $c['dealer_id'])
            ->where('dealer.id', $c['dealer_id']);

        if (! empty($request->retailer)) {
            $q->whereIn('retailer.id', (array) $request->retailer);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Tally report',
            'data'    => [
                'rows'         => $q->get(),
                'state'        => DB::table('location_3')->where('company_id', $c['company_id'])->where('status', 1)->pluck('name', 'id'),
                'product_name' => DB::table('catalog_product')->where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'product_gst'  => DB::table('catalog_product')->where('status', 1)->where('company_id', $c['company_id'])->pluck('gst_percent', 'id'),
            ],
        ]);
    }

    /* =========================================================
     | INVOICE SUMMARY (was dms_invoice_summary_report + _summary)
     * ========================================================= */
    public function invoiceSummaryMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);

        $retailers = DB::table('retailer')
            ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'retailer.location_id')
            ->where('dealer_location_rate_list.dealer_id', $c['dealer_id'])
            ->where('dealer_location_rate_list.company_id', $c['company_id'])
            ->where('retailer.company_id', $c['company_id'])
            ->where('retailer.retailer_status', 1)
            ->pluck('retailer.name', 'retailer.id');

        return response()->json([
            'status'  => true,
            'message' => 'Invoice summary meta',
            'data'    => [
                'location3' => Location3::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location4' => Location4::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location5' => Location5::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location6' => Location6::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'retailer'  => $retailers,
            ],
        ]);
    }

    public function invoiceSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        [$from, $to] = $this->parseDateRange($request);

        $q = DB::table('challan_order')
            ->join('challan_order_details', 'challan_order_details.ch_id', '=', 'challan_order.id')
            ->join('retailer', 'retailer.id', '=', 'challan_order.ch_retailer_id')
            ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'retailer.location_id')
            ->join('location_7', 'location_7.id', '=', 'dealer_location_rate_list.location_id')
            ->join('location_6', 'location_7.location_6_id', '=', 'location_6.id')
            ->join('location_5', 'location_6.location_5_id', '=', 'location_5.id')
            ->join('location_4', 'location_5.location_4_id', '=', 'location_4.id')
            ->select(
                'ch_retailer_id as retailer_id',
                'ch_date as challan_date',
                'product_id',
                'challan_order.ch_no as challan_number',
                'challan_order.id as challan_id',
                'retailer.name as retailer_name',
                'ch_dealer_id as dealer_id',
                'qty',
                'taxable_amt'
            )
            ->whereDate('challan_order.ch_date', '>=', $from)
            ->whereDate('challan_order.ch_date', '<=', $to)
            ->where('retailer.company_id', $c['company_id'])
            ->where('location_4.company_id', $c['company_id'])
            ->where('location_5.company_id', $c['company_id'])
            ->where('location_6.company_id', $c['company_id'])
            ->where('location_7.company_id', $c['company_id'])
            ->where('dealer_location_rate_list.company_id', $c['company_id'])
            ->where('challan_order.ch_dealer_id', $c['dealer_id']);

        foreach (['location_3' => 'location_4.location_3_id', 'location_4' => 'location_4.id',
                  'location_5' => 'location_5.id', 'location_6' => 'location_6.id'] as $in => $col) {
            if (! empty($request->$in)) {
                $q->whereIn($col, (array) $request->$in);
            }
        }

        $main = $q->groupBy('challan_id')->get();

        $product_ids = $main->pluck('product_id')->unique()->values()->all();
        $category = [];
        if ($product_ids) {
            $rows = DB::table('catalog_0')
                ->join('catalog_1', 'catalog_1.catalog_0_id', '=', 'catalog_0.id')
                ->join('catalog_2', 'catalog_2.catalog_1_id', '=', 'catalog_1.id')
                ->join('catalog_product', 'catalog_product.catalog_id', '=', 'catalog_2.id')
                ->select('catalog_0.name as classification', 'catalog_1.name as category',
                         'catalog_product.id as sku_id', 'catalog_product.gst_percent')
                ->whereIn('catalog_product.id', $product_ids)
                ->where('catalog_0.company_id', $c['company_id'])
                ->get();
            foreach ($rows as $r) {
                $category[$r->sku_id] = [
                    'classification' => $r->classification,
                    'category'       => $r->category,
                    'gst_percent'    => $r->gst_percent,
                ];
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Invoice summary',
            'data'    => [
                'rows'           => $main,
                'category_array' => $category,
                'dealer_name'    => DB::table('dealer')->where('company_id', $c['company_id'])->where('dealer_status', 1)->pluck('name', 'id'),
                'dealer_code'    => $c['dealer_code'],
            ],
        ]);
    }

    /* =========================================================
     | PURCHASE (was dms_purchase_report + dms_purchase)
     * ========================================================= */
    public function purchaseReportMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        $retailers = DB::table('dealer_location_rate_list')
            ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            ->select('retailer.id as retailer_id', 'retailer.name as retailer_name')
            ->where('dealer_location_rate_list.dealer_id', $c['dealer_id'])
            ->where('dealer_location_rate_list.company_id', $c['company_id'])
            ->where('retailer.company_id', $c['company_id'])
            ->pluck('retailer_name', 'retailer_id');

        return response()->json(['status' => true, 'message' => 'Purchase meta', 'data' => ['retailer' => $retailers]]);
    }

    public function purchaseReport(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        [$from, $to] = $this->parseDateRange($request);

        $q = DB::table('demand_order')
            ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            ->join('dealer_location_rate_list', 'dealer_location_rate_list.dealer_id', '=', 'demand_order.dealer_id')
            ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            ->select(
                'demand_order.order_id',
                'demand_order_details.product_id',
                'challan_no',
                'order_date',
                'retailer.name as retailer_name',
                'retailer.tin_no as gst_no',
                'retailer.location_id as beat_id',
                'quantity',
                'free_qty',
                'pr_rate',
                'total_rs'
            )
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to)
            ->where('demand_order.dealer_id', $c['dealer_id'])
            ->where('dealer_location_rate_list.dealer_id', $c['dealer_id'])
            ->where('dealer_location_rate_list.company_id', $c['company_id'])
            ->where('retailer.company_id', $c['company_id'])
            ->where('demand_order.company_id', $c['company_id']);

        if (! empty($request->retailer)) {
            $q->whereIn('retailer.id', (array) $request->retailer);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Purchase report',
            'data'    => [
                'rows'           => $q->get(),
                'retailer_state' => DB::table('location_7')
                    ->join('location_6', 'location_7.location_6_id', '=', 'location_6.id')
                    ->join('location_5', 'location_6.location_5_id', '=', 'location_5.id')
                    ->join('location_4', 'location_5.location_4_id', '=', 'location_4.id')
                    ->join('location_3', 'location_4.location_3_id', '=', 'location_3.id')
                    ->where('location_7.company_id', $c['company_id'])
                    ->where('location_3.company_id', $c['company_id'])
                    ->pluck('location_3.name as state', 'location_7.id as beat_id'),
                'product_name'   => DB::table('catalog_product')->where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'product_gst'    => DB::table('catalog_product')->where('status', 1)->where('company_id', $c['company_id'])->pluck('gst_percent', 'id'),
            ],
        ]);
    }

    /* =========================================================
     | DAMAGE REPLACE RETAILER (was Damagereplaceretailer*)
     * ========================================================= */
    public function damageReplaceMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        return response()->json([
            'status'  => true,
            'message' => 'Damage replace meta',
            'data'    => [
                'location3' => Location3::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location4' => Location4::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location5' => Location5::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'location6' => Location6::where('status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
                'users'     => DB::table('person')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('company_id', $c['company_id'])
                    ->pluck('name', 'uid'),
                'dealer'    => Dealer::where('dealer_status', 1)->where('company_id', $c['company_id'])->pluck('name', 'id'),
            ],
        ]);
    }

    public function damageReplaceList(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        [$from, $to] = $this->parseDateRange($request);

        $q = DB::table('damage_replace_retailer')
            ->join('person',  'person.id', '=', 'damage_replace_retailer.user_id')
            ->join('dealer',  'dealer.id', '=', 'damage_replace_retailer.dis_code')
            ->join('retailer','retailer.id', '=', 'damage_replace_retailer.ret_code')
            ->join('location_3', 'location_3.id', '=', 'dealer.state_id')
            ->join('location_6', 'location_6.id', '=', 'dealer.town_id')
            ->join('location_5', 'location_5.id', '=', 'location_6.location_5_id')
            ->join('location_4', 'location_4.id', '=', 'location_5.location_4_id')
            ->select(
                'dealer.name as dealer_name',
                'retailer.name as retailer_name',
                DB::raw("CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name) as user_name"),
                'prod_qty', 'prod_value', 'mrp',
                'image_name1', 'image_name2', 'image_name3', 'image_name4', 'image_name5',
                'batch_no', 'mfg_date',
                'retailer.retailer_code as rcode',
                'location_3.name as l3_name',
                'location_6.name as l6_name',
                'dealer.dealer_code',
                'location_5.name as l5_name',
                'replaceid'
            )
            ->where('dealer.company_id', $c['company_id'])
            ->where('person.company_id', $c['company_id'])
            ->where('damage_replace_retailer.company_id', $c['company_id'])
            ->whereRaw("DATE_FORMAT(date_time,'%Y-%m-%d') >= ? AND DATE_FORMAT(date_time,'%Y-%m-%d') <= ?", [$from, $to]);

        foreach (['retailer' => 'retailer.id', 'user' => 'person.id',
                  'location_3' => 'location_3.id', 'location_4' => 'location_4.id',
                  'location_6' => 'location_6.id', 'dealer' => 'dealer.id',
                  'role' => 'person.role_id'] as $req => $col) {
            if (! empty($request->$req)) {
                $q->whereIn($col, (array) $request->$req);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Damage replace list',
            'data'    => ['rows' => $q->groupBy('replaceid')->get()],
        ]);
    }

    public function damageReplaceDetail(Request $request): \Illuminate\Http\JsonResponse
    {
        if (empty($request->returnid)) {
            return response()->json(['status' => false, 'message' => 'returnid required'], 422);
        }
        $c = $this->ctx($request);

        $rows = DB::table('damage_replace_retailer')
            ->join('catalog_product', 'catalog_product.id', '=', 'damage_replace_retailer.prod_code')
            ->select('catalog_product.name as sku_name', 'prod_qty', 'prod_value',
                     DB::raw("DATE_FORMAT(mfg_date,'%d-%m-%y') as mfg_date"),
                     'mrp', 'damage_replace_retailer.batch_no')
            ->where('replaceid', $request->returnid)
            ->where('damage_replace_retailer.company_id', $c['company_id'])
            ->groupBy('catalog_product.id', 'damage_replace_retailer.mrp',
                      'damage_replace_retailer.batch_no', 'damage_replace_retailer.mfg_date')
            ->get()
            ->map(function ($r) {
                $r->prod_qty   = $r->prod_qty ?: 0;
                $r->prod_value = $r->prod_value ?: 0;
                $r->mrp        = $r->mrp ?: 0;
                if (empty($r->mfg_date) || $r->mfg_date === '00-00-00') $r->mfg_date = '';
                return $r;
            });

        return response()->json(['status' => true, 'message' => 'Damage details', 'data' => ['rows' => $rows]]);
    }

    /* ---------------------------------------------------------
     | Shared helpers
     * --------------------------------------------------------- */
    private function parseDateRange(Request $request): array
    {
        if (empty($request->date_range_picker)) {
            return [date('Y-m-d'), date('Y-m-d')];
        }
        $parts = explode(' -', $request->date_range_picker);
        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }
}