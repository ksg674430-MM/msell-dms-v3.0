<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DmsReportService
{
    /* =========================================================
     *  DMS -> TALLY REPORT
     * ========================================================= */
    public function toTallyFilters($user): array
    {
        $companyId = $this->companyId($user);
        $dealerId  = $this->dealerId($user);

        $retailer = DB::table('dealer_location_rate_list')
            ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            ->select('retailer.id as retailer_id', 'retailer.name as retailer_name')
            ->where('dealer_location_rate_list.dealer_id', $dealerId)
            ->where('dealer_location_rate_list.company_id', $companyId)
            ->where('retailer.company_id', $companyId)
            ->pluck('retailer_name', 'retailer_id');

        return [
            'retailer' => $retailer,
        ];
    }

    public function toTallyData(Request $request, $user): array
    {
        [$fromDate, $toDate] = $this->resolveDateRange($request->input('date_range_picker'));
        $companyId = $this->companyId($user);
        $dealerId  = $this->dealerId($user);

        $mainQuery = DB::table('challan_order')
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
            ->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') >= '$fromDate' AND DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') <= '$toDate'")
            ->where('retailer.company_id', $companyId)
            ->where('dealer.company_id', $companyId)
            ->where('challan_order.ch_dealer_id', $dealerId)
            ->where('dealer.id', $dealerId);

        if (!empty($request->input('retailer'))) {
            $mainQuery->whereIn('retailer.id', $request->input('retailer'));
        }

        $mainData = $mainQuery->get();

        $state = DB::table('location_3')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->pluck('name', 'id');

        $productName = DB::table('catalog_product')
            ->where('status', 1)
            ->where('company_id', $companyId)
            ->pluck('name', 'id');

        $productGst = DB::table('catalog_product')
            ->where('status', 1)
            ->where('company_id', $companyId)
            ->pluck('gst_percent', 'id');

        return [
            'from_date'    => $fromDate,
            'to_date'      => $toDate,
            'main_data'    => $mainData,
            'state'        => $state,
            'product_name' => $productName,
            'product_gst'  => $productGst,
        ];
    }

    /* =========================================================
     *  DMS INVOICE SUMMARY REPORT
     * ========================================================= */
    public function invoiceSummaryFilters($user): array
    {
        $companyId = $this->companyId($user);
        $dealerId  = $this->dealerId($user);

        $state     = DB::table('location_3')->where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');
        $area      = DB::table('location_4')->where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');
        $headQuater = DB::table('location_5')->where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');
        $town      = DB::table('location_6')->where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');

        $retailer = DB::table('retailer')
            ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'retailer.location_id')
            ->where('dealer_location_rate_list.dealer_id', $dealerId)
            ->where('dealer_location_rate_list.company_id', $companyId)
            ->where('retailer.company_id', $companyId)
            ->where('retailer.retailer_status', 1)
            ->pluck('retailer.name', 'retailer.id');

        return [
            'location3'  => $state,
            'location4'  => $area,
            'location5'  => $headQuater,
            'location6'  => $town,
            'retailer'   => $retailer,
            'company_id' => $companyId,
        ];
    }

    public function invoiceSummaryData(Request $request, $user): array
    {
        [$fromDate, $toDate] = $this->resolveDateRange($request->input('date_range_picker'));
        $companyId  = $this->companyId($user);
        $dealerId   = $this->dealerId($user);
        $dealerCode = $this->dealerCode($user);

        $mainQuery = DB::table('challan_order')
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
            ->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') >= '$fromDate' AND DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') <= '$toDate'")
            ->where('retailer.company_id', $companyId)
            ->where('location_4.company_id', $companyId)
            ->where('location_5.company_id', $companyId)
            ->where('location_6.company_id', $companyId)
            ->where('location_7.company_id', $companyId)
            ->where('dealer_location_rate_list.company_id', $companyId)
            ->where('challan_order.ch_dealer_id', $dealerId);

        if ($request->filled('location_3')) {
            $mainQuery->whereIn('location_4.location_3_id', $request->input('location_3'));
        }
        if ($request->filled('location_4')) {
            $mainQuery->whereIn('location_4.id', $request->input('location_4'));
        }
        if ($request->filled('location_5')) {
            $mainQuery->whereIn('location_5.id', $request->input('location_5'));
        }
        if ($request->filled('location_6')) {
            $mainQuery->whereIn('location_6.id', $request->input('location_6'));
        }

        $mainData = $mainQuery->groupBy('challan_id')->get();

        $productIds = [];
        foreach ($mainData as $row) {
            $productIds[] = $row->product_id;
        }

        $catalogData = DB::table('catalog_0')
            ->join('catalog_1', 'catalog_1.catalog_0_id', '=', 'catalog_0.id')
            ->join('catalog_2', 'catalog_2.catalog_1_id', '=', 'catalog_1.id')
            ->join('catalog_product', 'catalog_product.catalog_id', '=', 'catalog_2.id')
            ->select(
                'catalog_0.name as classification',
                'catalog_1.name as category',
                'catalog_product.id as sku_id',
                'catalog_product.gst_percent'
            )
            ->whereIn('catalog_product.id', $productIds)
            ->where('catalog_0.company_id', $companyId)
            ->where('catalog_1.company_id', $companyId)
            ->where('catalog_2.company_id', $companyId)
            ->where('catalog_product.company_id', $companyId)
            ->get();

        $categoryArray = [];
        foreach ($catalogData as $row) {
            $categoryArray[$row->sku_id] = [
                'classification' => $row->classification,
                'category'       => $row->category,
                'gst_percent'    => $row->gst_percent,
            ];
        }

        $dealerName = DB::table('dealer')
            ->where('company_id', $companyId)
            ->where('dealer_status', '=', '1')
            ->pluck('name', 'id');

        return [
            'from_date'      => $fromDate,
            'to_date'        => $toDate,
            'company_id'     => $companyId,
            'main_data'      => $mainData,
            'category_array' => $categoryArray,
            'dealer_name'    => $dealerName,
            'dealer_code'    => $dealerCode,
        ];
    }

    /* =========================================================
     *  DMS PURCHASE REPORT
     * ========================================================= */
    public function purchaseFilters($user): array
    {
        $companyId = $this->companyId($user);
        $dealerId  = $this->dealerId($user);

        $retailer = DB::table('dealer_location_rate_list')
            ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            ->select('retailer.id as retailer_id', 'retailer.name as retailer_name')
            ->where('dealer_location_rate_list.dealer_id', $dealerId)
            ->where('dealer_location_rate_list.company_id', $companyId)
            ->where('retailer.company_id', $companyId)
            ->pluck('retailer_name', 'retailer_id');

        return [
            'retailer' => $retailer,
        ];
    }

    public function purchaseData(Request $request, $user): array
    {
        [$fromDate, $toDate] = $this->resolveDateRange($request->input('date_range_picker'));
        $companyId = $this->companyId($user);
        $dealerId  = $this->dealerId($user);

        $mainQuery = DB::table('demand_order')
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
            ->whereRaw("DATE_FORMAT(order_date, '%Y-%m-%d') >= '$fromDate' AND DATE_FORMAT(order_date, '%Y-%m-%d') <= '$toDate'")
            ->where('demand_order.dealer_id', $dealerId)
            ->where('dealer_location_rate_list.dealer_id', $dealerId)
            ->where('dealer_location_rate_list.company_id', $companyId)
            ->where('retailer.company_id', $companyId)
            ->where('demand_order.company_id', $companyId);

        if (!empty($request->input('retailer'))) {
            $mainQuery->whereIn('retailer.id', $request->input('retailer'));
        }

        $mainData = $mainQuery->get();

        $retailerState = DB::table('location_7')
            ->join('location_6', 'location_7.location_6_id', '=', 'location_6.id')
            ->join('location_5', 'location_6.location_5_id', '=', 'location_5.id')
            ->join('location_4', 'location_5.location_4_id', '=', 'location_4.id')
            ->join('location_3', 'location_4.location_3_id', '=', 'location_3.id')
            ->where('location_7.company_id', $companyId)
            ->where('location_6.company_id', $companyId)
            ->where('location_5.company_id', $companyId)
            ->where('location_4.company_id', $companyId)
            ->where('location_3.company_id', $companyId)
            ->pluck('location_3.name as state', 'location_7.id as beat_id');

        $productName = DB::table('catalog_product')
            ->where('status', 1)
            ->where('company_id', $companyId)
            ->pluck('name', 'id');

        $productGst = DB::table('catalog_product')
            ->where('status', 1)
            ->where('company_id', $companyId)
            ->pluck('gst_percent', 'id');

        return [
            'from_date'      => $fromDate,
            'to_date'        => $toDate,
            'main_data'      => $mainData,
            'retailer_state' => $retailerState,
            'product_name'   => $productName,
            'product_gst'    => $productGst,
        ];
    }

    /* =========================================================
     *  COMMON REPORT SECTION (view resolver → API response)
     * ========================================================= */
    public function commonReportSection(Request $request, $user): array
    {
        $reportName = $request->input('report_name');

        $ajaxReportName = match ($reportName) {
            'ss_van_sales_report'      => 'ss_van_sales_report_new',
            'retailer_ledger_index'    => 'retailer_ledger_ajax',
            'retailer_wise_sales_index'=> 'retailer_wise_sales_ajax',
            'van_sale_index'           => 'van_sale_ajax',
            'van_settlement_index'     => 'van_settlement_ajax',
            'db_sales_index'           => 'db_sales_ajax',
            'db_van_summary_index'     => 'db_van_summary_ajax',
            'retailer-wise-index-new'  => 'retailer-wise-new-ajax',
            'DisplayClaimReport'       => 'DgrClaimAjax',
            default                    => null,
        };

        return [
            'ajax_report_name' => $ajaxReportName,
            'report_name'      => $reportName,
            'dealer_id'        => $this->dealerId($user),
            'company_id'       => $this->companyId($user),
        ];
    }

    /* =========================================================
     *  HELPERS
     * ========================================================= */
    protected function resolveDateRange(?string $range): array
    {
        if (empty($range)) {
            return [date('Y-m') . '-01', date('Y-m-d')];
        }

        $parts = explode(' -', $range);

        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    protected function companyId($user): int
    {
        return (int) ($user->company_id ?? 0);
    }

    protected function dealerId($user): int
    {
        return (int) ($user->dealer_id ?? 0);
    }

    protected function dealerCode($user): string
    {
        return (string) ($user->dealer_code ?? '0');
    }
}