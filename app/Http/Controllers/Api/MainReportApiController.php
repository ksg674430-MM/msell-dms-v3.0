<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\JuniorData;
use App\TableReturn;
use App\SalaryManagementSummary;
use App\Location1;
use App\Location2;
use App\Location3;
use App\Location4;
use App\Location5;
use App\Location6;
use App\Location7;
use App\UserDetail;
use App\Person;
use App\CommonEmployeeDetails;
use App\Dealer;
use App\DealerLocation;
use App\DealerRetailer;
use App\VanSalesOrder;
use App\VanSalesOrderDetail;
use App\Secondary_Sale;
use App\VanSalePaymentData;
use App\FulfillmentData;
use App\Services\DealerBalanceStockService;
use DateTime;

class MainReportApiController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Parse the "YYYY-MM-DD - YYYY-MM-DD" or "YYYY-MM-DD-YYYY-MM-DD" date
     * range that the front-end sends, into a [from, to] array.
     */
    private function parseDateRange(?string $range, bool $defaultToday = false): array
    {
        if (empty($range)) {
            if ($defaultToday) {
                $today = now()->format('Y-m-d');
                return [$today, $today];
            }
            abort(422, 'date_range_picker is required');
        }

        // Normalise both " - " and "-" separators
        $parts = preg_split('/\s*-\s*/', trim($range));

        if (count($parts) < 2) {
            abort(422, 'Invalid date_range_picker format. Expected "from - to".');
        }

        $from = date('Y-m-d', strtotime(trim($parts[0])));
        $to   = date('Y-m-d', strtotime(trim($parts[1])));

        if (!$from || !$to) {
            abort(422, 'Invalid dates in date_range_picker.');
        }

        return [$from, $to];
    }

    /**
     * Populate the junior-user list for the currently authenticated user
     * and return it (never returns empty — always includes the caller).
     */
    private function getJuniorUserIds(Request $request): array
    {
        $user       = $request->user();
        $company_id = $user->company_id;
        $roleId     = $user->is_admin;

        // Super admin / HO roles see everyone
        if (in_array($roleId, [1, 50], true)) {
            return [];
        }

        $cacheKey = "juniordata_{$user->id}";
        Cache::forget($cacheKey);

        JuniorData::getJuniorUser($user->id, $company_id);

        $ids = Cache::get($cacheKey, []);
        $ids[] = $user->id;

        return $ids;
    }

    /* ------------------------------------------------------------------ */
    /*  Dispatch Order                                                     */
    /* ------------------------------------------------------------------ */

    public function dispatchOrderIndex(Request $request)
    {
        $company_id  = $request->user()->company_id;
        $distributor = DB::table('dealer')
            ->where('dealer_status', 1)
            ->where('company_id', $company_id)
            ->pluck('name', 'id');

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'distributor' => $distributor,
            ],
        ]);
    }

    public function dispatchOrderAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $distributorIds = $request->input('distributor', []);

        $query = DB::table('purchase_order')
            ->join('dealer', 'dealer.id', '=', 'purchase_order.dealer_id')
            ->join('purchase_order_details', 'purchase_order_details.order_id', '=', 'purchase_order.id')
            ->join('catalog_product', 'catalog_product.id', '=', 'purchase_order_details.product_id')
            ->select(
                'dealer.id as dealer_id',
                'dealer.name as distributor_name',
                'purchase_order.id as order_id',
                'catalog_product.name as sku_name',
                DB::raw('purchase_order_details.total_value as msell_order_amount'),
                DB::raw('purchase_order_details.inv_busy_amt as billing_amount'),
                DB::raw('DATE(purchase_order.dispatch_date) as dispatch_date')
            )
            ->where('purchase_order.invoice_no_p', '!=', '0')
            ->where('dealer.company_id', $company_id)
            ->where('purchase_order.company_id', $company_id)
            ->where('purchase_order_details.company_id', $company_id)
            ->whereBetween(DB::raw('DATE(purchase_order.dispatch_date)'), [$from_date, $to_date]);

        if (!empty($distributorIds)) {
            $query->whereIn('purchase_order.dealer_id', (array) $distributorIds);
        }

        $reportData = $query
            ->orderBy('dealer.name')
            ->orderBy('purchase_order.id')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $reportData,
            'meta'   => [
                'company_id' => $company_id,
                'from_date'  => $from_date,
                'to_date'    => $to_date,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Dispatch Amount                                                    */
    /* ------------------------------------------------------------------ */

    public function dispatchAmountIndex(Request $request)
    {
        $company_id  = $request->user()->company_id;
        $distributor = DB::table('dealer')
            ->where('dealer_status', 1)
            ->where('company_id', $company_id)
            ->pluck('name', 'id');

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'distributor' => $distributor,
            ],
        ]);
    }

    public function dispatchAmountAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $distributorIds = $request->input('distributor', []);

        $query = DB::table('purchase_order')
            ->join('dealer', 'dealer.id', '=', 'purchase_order.dealer_id')
            ->join('purchase_order_details', 'purchase_order_details.order_id', '=', 'purchase_order.id')
            ->select(
                'dealer.id as dealer_id',
                'dealer.name as distributor_name',
                'purchase_order.id as order_id',
                DB::raw('SUM(purchase_order_details.total_value) as msell_order_amount'),
                DB::raw('SUM(purchase_order_details.inv_busy_amt) as billing_amount'),
                DB::raw('DATE(purchase_order.dispatch_date) as dispatch_date')
            )
            ->where('purchase_order.invoice_no_p', '!=', '0')
            ->where('dealer.company_id', $company_id)
            ->where('purchase_order.company_id', $company_id)
            ->where('purchase_order_details.company_id', $company_id)
            ->whereBetween(DB::raw('DATE(purchase_order.dispatch_date)'), [$from_date, $to_date]);

        if (!empty($distributorIds)) {
            $query->whereIn('purchase_order.dealer_id', (array) $distributorIds);
        }

        $reportData = $query
            ->groupBy('dealer.id', 'dealer.name', 'purchase_order.id')
            ->orderBy('dealer.name')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $reportData,
            'meta'   => [
                'company_id' => $company_id,
                'from_date'  => $from_date,
                'to_date'    => $to_date,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Zero Order Dashboard                                               */
    /* ------------------------------------------------------------------ */

    public function dashboardZeroOrder(Request $request)
    {
        $company_id = $request->user()->company_id;

        $brand = DB::table('catalog_0')
            ->orderBy('sequence')
            ->where('status', 1)
            ->where('company_id', $company_id)
            ->pluck('catalog_0.name', 'catalog_0.id');

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id' => $company_id,
                'brand'      => $brand,
            ],
        ]);
    }

    public function dashboardZeroOrderChart(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange(
            $request->input('date_range_picker'),
            true
        );

        $query = DB::table('user_sales_order')
            ->join('user_sales_order_smart_force', 'user_sales_order.order_id', '=', 'user_sales_order_smart_force.order_id')
            ->join('_no_sale_reason', '_no_sale_reason.id', '=', 'user_sales_order.reason')
            ->join('catalog_0', 'catalog_0.id', '=', 'user_sales_order_smart_force.brand_id')
            ->select(
                'catalog_0.name as brand_name',
                '_no_sale_reason.name as reason_name',
                DB::raw('COUNT(user_sales_order.id) as total_count')
            )
            ->where('user_sales_order.call_status', 0)
            ->whereBetween('user_sales_order.date', [$from_date, $to_date])
            ->where('user_sales_order.company_id', $company_id);

        if ($request->filled('brand_id')) {
            $query->where('user_sales_order_smart_force.brand_id', $request->brand_id);
        }

        $noSaleReasons = $query
            ->groupBy('catalog_0.name', '_no_sale_reason.name')
            ->orderByDesc('total_count')
            ->get();

        return response()->json([
            'status' => true,
            'labels' => $noSaleReasons->pluck('reason_name'),
            'counts' => $noSaleReasons->pluck('total_count'),
            'table'  => $noSaleReasons,
        ]);
    }

    public function dashboardUserZeroOrder(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange(
            $request->input('date_range_picker'),
            true
        );

        $query = DB::table('user_sales_order')
            ->join('person', 'user_sales_order.user_id', '=', 'person.id')
            ->join('retailer', 'user_sales_order.retailer_id', '=', 'retailer.id')
            ->join('user_sales_order_smart_force', 'user_sales_order.order_id', '=', 'user_sales_order_smart_force.order_id')
            ->join('_no_sale_reason', '_no_sale_reason.id', '=', 'user_sales_order.reason')
            ->join('catalog_0', 'catalog_0.id', '=', 'user_sales_order_smart_force.brand_id')
            ->select(
                DB::raw('CONCAT(person.first_name," ",person.last_name) as user_name'),
                'retailer.name as retailer_name',
                'catalog_0.name as brand_name',
                '_no_sale_reason.name as reason_name',
                'user_sales_order.user_id',
                'user_sales_order.retailer_id',
                'user_sales_order_smart_force.brand_id',
                'user_sales_order.reason',
                DB::raw('COUNT(user_sales_order.id) as total_count')
            )
            ->where('user_sales_order.company_id', $company_id)
            ->where('user_sales_order.call_status', 0)
            ->whereBetween('user_sales_order.date', [$from_date, $to_date]);

        if ($request->filled('brand_id')) {
            $query->where('user_sales_order_smart_force.brand_id', $request->brand_id);
        }

        $userZeroOrders = $query
            ->groupBy(
                'user_sales_order.user_id',
                'user_sales_order.retailer_id',
                'user_sales_order_smart_force.brand_id',
                'user_sales_order.reason'
            )
            ->orderBy('user_sales_order.user_id')
            ->get();

        return response()->json([
            'status'     => true,
            'user_table' => $userZeroOrders,
        ]);
    }

    public function dashboardUserZeroOrderReasonBar(Request $request)
    {
        $company_id = $request->user()->company_id;
        $user_id    = $request->input('user_id');
        $brand_id   = $request->input('brand_id');
        $dateRange  = $request->input('date_range_picker');

        if (!empty($dateRange)) {
            [$from_date, $to_date] = $this->parseDateRange($dateRange);
        } else {
            $from_date = now()->format('Y-m-d');
            $to_date   = now()->format('Y-m-d');
        }

        $query = DB::table('user_sales_order')
            ->join('user_sales_order_smart_force', 'user_sales_order.order_id', '=', 'user_sales_order_smart_force.order_id')
            ->join('_no_sale_reason', '_no_sale_reason.id', '=', 'user_sales_order.reason')
            ->join('catalog_0', 'catalog_0.id', '=', 'user_sales_order_smart_force.brand_id')
            ->where('user_sales_order.company_id', $company_id)
            ->where('user_sales_order.call_status', 0)
            ->whereBetween('user_sales_order.date', [$from_date, $to_date]);

        if (!empty($user_id) && $user_id !== 'all') {
            $query->where('user_sales_order.user_id', $user_id);
        }

        if (!empty($brand_id)) {
            $query->where('catalog_0.id', $brand_id);
        }

        $data = $query
            ->select('_no_sale_reason.name as reason_name', DB::raw('COUNT(user_sales_order.id) as total_count'))
            ->groupBy('_no_sale_reason.id', '_no_sale_reason.name')
            ->get();

        $allReasons = DB::table('_no_sale_reason')
            ->where('company_id', $company_id)
            ->where('status', 1)
            ->pluck('name');

        $finalData = $allReasons->map(function ($reason) use ($data) {
            $found = $data->firstWhere('reason_name', $reason);
            return [
                'reason_name' => $reason,
                'total_count' => $found ? $found->total_count : 0,
            ];
        });

        $users = DB::table('person')
            ->join('user_sales_order', 'person.id', '=', 'user_sales_order.user_id')
            ->where('person.company_id', $company_id)
            ->where('user_sales_order.call_status', 0)
            ->distinct()
            ->select('person.id', DB::raw('CONCAT(person.first_name," ",person.last_name) as fullname'))
            ->get();

        return response()->json([
            'status'     => true,
            'chart_data' => $finalData,
            'users'      => $users,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Competitor Analysis                                                */
    /* ------------------------------------------------------------------ */

    public function competitorAnalysisReport(Request $request)
    {
        $formatJson = function ($value) {

            if (!$value || $value === 'NULL' || $value === 'null') {
                return '-';
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {

                if (array_values($decoded) === $decoded) {
                    return implode(", ", $decoded);
                }

                $result = [];
                foreach ($decoded as $key => $item) {
                    if (!empty($item['checked'])) {
                        $text = $key;
                        if (!empty($item['details'])) {
                            $text .= " (" . $item['details'] . ")";
                        }
                        $result[] = $text;
                    }
                }

                return $result ? implode(", ", $result) : "-";
            }

            return $value;
        };

        $data = DB::table('competitor_analysis')
            ->orderBy('id', 'DESC')
            ->get()
            ->map(function ($row) use ($formatJson) {

                $row->channel_type          = $formatJson($row->channel_type);
                $row->market_category       = $formatJson($row->market_category);
                $row->schemes               = $formatJson($row->schemes);
                $row->competitor_preference = $formatJson($row->competitor_preference);
                $row->pop_material          = $formatJson($row->pop_material);
                $row->issues                = $formatJson($row->issues);

                $stock_status = [1 => 'High', 2 => 'Medium', 3 => 'Low', 4 => 'Out of Stock'];
                $row->stock_status = $stock_status[$row->stock_status] ?? '-';

                $push = [1 => 'Strong Competitor', 2 => 'Moderate Competitor', 3 => 'Neutral', 4 => 'Prefers Our Brand'];
                $row->distributor_push = $push[$row->distributor_push] ?? '-';

                return $row;
            });

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }

    public function getProducts(Request $request, $visitId)
    {
        $products = DB::table('competitor_products')
            ->where('visit_id', $visitId)
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $products,
        ]);
    }

    public function getImages(Request $request, $visitId)
    {
        $images = DB::table('competitor_images')
            ->where('visit_id', $visitId)
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $images,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Simple Export (Attendance Summary — Excel download)                */
    /* ------------------------------------------------------------------ */

    public function simpleExport(Request $request)
    {
        $company_id = $request->user()->company_id;

        if (!empty($request->date_range_picker)) {
            [$from_date, $to_date] = $this->parseDateRange($request->date_range_picker);
        } else {
            $from_date = $to_date = date('Y-m-d');
        }

        $att_view_name = TableReturn::attendance_table_return_view($from_date, $to_date);

        // User filter (seniors see juniors only)
        $role_id  = $request->user()->is_admin;
        $datasenior = null;

        if (!in_array($role_id, [1, 50], true)) {
            $cacheKey = "juniordata_{$request->user()->id}";
            Cache::forget($cacheKey);
            JuniorData::getJuniorUser($request->user()->id, $company_id);
            $datasenior = Cache::get($cacheKey, []);
            if (!in_array($request->user()->id, $datasenior)) {
                $datasenior[] = $request->user()->id;
            }
        }

        $maxIds = DB::table('user_daily_attendance')
            ->select(
                'user_id',
                DB::raw("DATE_FORMAT(work_date, '%Y-%m-%d') as work_date"),
                DB::raw("MAX(id) as max_id")
            )
            ->where('company_id', $company_id)
            ->whereBetween(DB::raw("DATE_FORMAT(work_date,'%Y-%m-%d')"), [$from_date, $to_date])
            ->groupBy('user_id', DB::raw("DATE_FORMAT(work_date, '%Y-%m-%d')"));

        $query = DB::table('person')
            ->join('person_login', 'person.id', '=', 'person_login.person_id')
            ->leftJoin($att_view_name, function ($join) use ($from_date, $to_date, $att_view_name) {
                $join->on($att_view_name . '.user_id', '=', 'person.id')
                    ->whereBetween(DB::raw("DATE({$att_view_name}.work_date)"), [$from_date, $to_date]);
            })
            ->leftJoin('_role', '_role.role_id', '=', 'person.role_id')
            ->leftJoin('location_5', 'location_5.id', '=', 'person.head_quater_id')
            ->where('person.company_id', $company_id)
            ->where('person_login.person_status', 1);

        if (!empty($datasenior)) {
            $query->whereIn('person.id', $datasenior);
        }

        $query = $query
            ->leftJoinSub($maxIds, 'max_att', function ($join) {
                $join->on('person.id', '=', 'max_att.user_id');
            })
            ->leftJoin('user_daily_attendance AS uda', 'uda.id', '=', 'max_att.max_id');

        $rows = $query->select(
                'location_5.name as headquarter',
                DB::raw("CONCAT_WS(' ', person.first_name, NULLIF(person.middle_name,''), person.last_name) as full_name"),
                '_role.rolename as role',
                DB::raw("CASE WHEN {$att_view_name}.work_date IS NOT NULL THEN 'Marked' ELSE 'Not Marked' END as attendance_status"),
                'uda.remarks as remarks'
            )
            ->orderBy('full_name', 'ASC')
            ->get();

        $html = '
        <table border="1" style="border-collapse: collapse; width: 100%;">
            <tr>
                <td colspan="6" style="text-align:center; font-weight:bold; font-size:18px;">
                    Sales Staff Attendance Report
                </td>
            </tr>
            <tr>
                <td colspan="6" style="text-align:center; font-size:14px;">
                    Date: ' . date('d-m-Y') . '
                </td>
            </tr>
            <tr></tr>
            <tr style="font-weight:bold; background:#d9d9d9; text-align:center;">
                <td>Sr.no</td>
                <td>Head Quarter</td>
                <td>User Name</td>
                <td>Role</td>
                <td>Attendance Status</td>
                <td>Attendance Remarks</td>
            </tr>';

        $i = 1;
        foreach ($rows as $r) {
            $html .= '
            <tr>
                <td>' . $i++ . '</td>
                <td>' . $r->headquarter . '</td>
                <td>' . $r->full_name . '</td>
                <td>' . $r->role . '</td>
                <td>' . $r->attendance_status . '</td>
                <td>' . (
                    $r->attendance_status == "Not Marked"
                        ? "N/A"
                        : htmlspecialchars($r->remarks ?? '')
                ) . '</td>
            </tr>';
        }
        $html .= '</table>';

        $fileName = "AttendanceSummary-" . date('d-m-Y') . ".xls";

        return response($html)
            ->header('Content-Type', 'application/vnd.ms-excel')
            ->header('Content-Disposition', "attachment; filename=\"$fileName\"");
    }

    /* ------------------------------------------------------------------ */
    /*  Retailer Ledger                                                    */
    /* ------------------------------------------------------------------ */

    public function retailerLedgerIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function retailerLedgerAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $orders = DB::table('user_sales_order as o')
            ->join('person', 'o.user_id', '=', 'person.id')
            ->join('retailer as r', 'o.retailer_id', '=', 'r.id')
            ->join('dealer as d', 'o.dealer_id', '=', 'd.id')
            ->join('location_7 as l', 'r.location_id', '=', 'l.id')
            ->join('location_6', 'location_6.id', '=', 'l.location_6_id')
            ->join('location_5', 'location_5.id', '=', 'location_6.location_5_id')
            ->join('location_4', 'location_4.id', '=', 'location_5.location_4_id')
            ->join('location_3', 'location_3.id', '=', 'location_4.location_3_id')
            ->join('location_2', 'location_2.id', '=', 'location_3.location_2_id')
            ->join('location_1', 'location_1.id', '=', 'location_2.location_1_id')
            ->select(
                'o.retailer_id',
                'o.order_id',
                'r.name as retailer_name',
                'r.contact_per_name',
                'r.landline',
                'r.other_numbers',
                'l.name as beat_name',
                'o.discount',
                'o.date as created_date',
                'r.created_by_person_id as created_by',
                'd.name as distributor_name',
                'o.user_id as order_by',
            )
            ->where('o.company_id', $company_id)
            ->whereBetween('o.date', [$from_date, $to_date]);

        if (!empty($request->zone))         $orders->whereIn('location_1.id', $request->zone);
        if (!empty($request->region))       $orders->whereIn('location_2.id', $request->region);
        if (!empty($request->location_3))   $orders->whereIn('location_3.id', $request->location_3);
        if (!empty($request->location_4))   $orders->whereIn('location_4.id', $request->location_4);
        if (!empty($request->location_5))   $orders->whereIn('location_5.id', $request->location_5);
        if (!empty($request->location_6))   $orders->whereIn('location_6.id', $request->location_6);
        if (!empty($request->location_7))   $orders->whereIn('l.id', $request->location_7);
        if (!empty($request->user))         $orders->whereIn('user_id', $request->user);
        if (!empty($request->role))         $orders->whereIn('person.role_id', $request->role);

        $orders = $orders->get();

        if ($orders->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $orderIds = $orders->pluck('order_id')->toArray();

        $salesData = DB::table('user_sales_order_details as d')
            ->join('user_sales_order as o', 'o.order_id', '=', 'd.order_id')
            ->whereIn('o.order_id', $orderIds)
            ->where('o.company_id', $company_id)
            ->select(
                'o.retailer_id',
                DB::raw('SUM(d.case_quantity) as total_case_qty'),
                DB::raw('SUM((d.rate*d.quantity)+(d.case_rate*d.case_quantity)) as total_sale_value')
            )
            ->groupBy('o.retailer_id')
            ->get()
            ->keyBy('retailer_id');

        $paymentData = DB::table('van_sale_payment_details as p')
            ->join('user_sales_order as o', 'o.order_id', '=', 'p.order_id')
            ->whereIn('p.order_id', $orderIds)
            ->where('p.company_id', $company_id)
            ->select(
                'o.retailer_id',
                DB::raw('SUM(p.amount) as amount'),
                DB::raw('SUM(p.cash_amount) as cash'),
                DB::raw('SUM(p.online_amount) as online'),
                DB::raw('SUM(p.credit_amount) as credit'),
                DB::raw('SUM(p.cheque_amount) as cheque'),
                DB::raw('SUM(p.adujment_amount) as adjustment')
            )
            ->groupBy('o.retailer_id')
            ->get()
            ->keyBy('retailer_id');

        $user_name = DB::table('person')
            ->select(DB::raw("CONCAT_WS(' ',first_name,NULLIF(middle_name,''),last_name) as name"), 'id')
            ->where('company_id', $company_id)
            ->pluck('name', 'id')
            ->toArray();

        $channel = DB::table('retailer')
            ->join('location_7', 'location_7.id', '=', 'retailer.location_id')
            ->join('location_6', 'location_6.id', '=', 'location_7.location_6_id')
            ->select('retailer.id as retailer_id', 'location_6.name as channel')
            ->where('retailer.company_id', $company_id)
            ->pluck('channel', 'retailer_id')
            ->toArray();

        $reportData = [];
        $sr = 1;

        foreach ($orders as $order) {

            $user_id = $order->order_by;

            $cacheKey = "seniorData_{$user_id}";
            Cache::forget($cacheKey);
            JuniorData::getSeniorUser($user_id, $company_id);
            $senior_ids = Cache::get($cacheKey, []);

            $se_name = [];
            $so_name = [];

            if (!empty($senior_ids)) {
                $se_name = DB::table('person')
                    ->whereIn('id', $senior_ids)
                    ->where('role_id', 2958)
                    ->where('company_id', $company_id)
                    ->pluck(DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name)"), 'id')
                    ->toArray();

                $so_name = DB::table('person')
                    ->whereIn('id', $senior_ids)
                    ->where('role_id', 2957)
                    ->where('company_id', $company_id)
                    ->pluck(DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name)"), 'id')
                    ->toArray();
            }

            $sales   = $salesData[$order->retailer_id] ?? null;
            $payment = $paymentData[$order->retailer_id] ?? null;

            $totalCollection = ($payment->cash ?? 0) + ($payment->online ?? 0) + ($payment->credit ?? 0) + ($payment->cheque ?? 0);
            $outstanding     = $payment->credit ?? 0;

            $reportData[] = [
                'sr_no'           => $sr++,
                'retailer_id'     => $order->retailer_id,
                'retailer_name'   => $order->retailer_name,
                'contact_person'  => $order->contact_per_name,
                'contact_no'      => $order->landline ?? $order->other_numbers ?? '',
                'beat_name'       => $order->beat_name,
                'db_name'         => $order->distributor_name ?? '',
                'sale_qty'        => $sales->total_case_qty ?? 0,
                'sale_value'      => $sales->total_sale_value ?? 0,
                'discount'        => $order->discount ?? 0,
                'cash'            => $payment->cash ?? 0,
                'online'          => $payment->online ?? 0,
                'cheque'          => $payment->cheque ?? 0,
                'credit'          => $payment->credit ?? 0,
                'total_collection'=> $totalCollection,
                'outstanding'     => $outstanding,
                'assigned_se'     => implode(', ', $se_name),
                'assigned_so'     => implode(', ', $so_name),
                'channel'         => $channel[$order->retailer_id] ?? '',
                'created_by'      => $user_name[$order->created_by ?? ''] ?? '',
                'created_date'    => $order->created_date ?? '',
            ];
        }

        return response()->json([
            'status' => true,
            'data'   => $reportData,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Retailer-wise Sales                                                */
    /* ------------------------------------------------------------------ */

    public function retailerWiseSalesIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function retailerWiseSalesAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $table_name = TableReturn::table_return($from_date, $to_date);

        $orders = DB::table($table_name)
            ->join('person', $table_name . '.user_id', '=', 'person.id')
            ->join('retailer', $table_name . '.retailer_id', '=', 'retailer.id')
            ->join('dealer', $table_name . '.dealer_id', '=', 'dealer.id')
            ->join('location_7', 'location_7.id', '=', 'retailer.location_id')
            ->join('location_6', 'location_6.id', '=', 'location_7.location_6_id')
            ->join('location_5', 'location_5.id', '=', 'location_6.location_5_id')
            ->join('location_4', 'location_4.id', '=', 'location_5.location_4_id')
            ->join('location_3', 'location_3.id', '=', 'location_4.location_3_id')
            ->join('location_2', 'location_2.id', '=', 'location_3.location_2_id')
            ->join('location_1', 'location_1.id', '=', 'location_2.location_1_id')
            ->select(
                $table_name . '.date',
                $table_name . '.user_id as order_by',
                $table_name . '.order_id',
                $table_name . '.image_name',
                'retailer.name as retailer_name',
                'retailer.id as retailer_id',
                'dealer.name as distributor_name',
                'dealer.id as distributor_id',
                'location_7.name as beat_name',
                'retailer.created_by_person_id',
                'retailer.retailer_status',
                'retailer.contact_per_name',
                'retailer.landline',
                'retailer.other_numbers',
            )
            ->where($table_name . '.company_id', $company_id)
            ->whereBetween($table_name . '.date', [$from_date, $to_date]);

        if (!empty($request->zone))         $orders->whereIn('location_1.id', $request->zone);
        if (!empty($request->region))       $orders->whereIn('location_2.id', $request->region);
        if (!empty($request->location_3))   $orders->whereIn('location_3.id', $request->location_3);
        if (!empty($request->location_4))   $orders->whereIn('location_4.id', $request->location_4);
        if (!empty($request->location_5))   $orders->whereIn('location_5.id', $request->location_5);
        if (!empty($request->location_6))   $orders->whereIn('location_6.id', $request->location_6);
        if (!empty($request->location_7))   $orders->whereIn('location_7.id', $request->location_7);
        if (!empty($request->user))         $orders->whereIn($table_name . '.user_id', $request->user);
        if (!empty($request->role))         $orders->whereIn('person.role_id', $request->role);

        $orders = $orders->get();

        if ($orders->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $orderIds = $orders->pluck('order_id')->toArray();

        $details = DB::table('user_sales_order_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'user_sales_order_details.product_id')
            ->join('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            ->join('catalog_1', 'catalog_1.id', '=', 'catalog_2.catalog_1_id')
            ->join('catalog_0', 'catalog_0.id', '=', 'catalog_1.catalog_0_id')
            ->select(
                'user_sales_order_details.order_id',
                'user_sales_order_details.product_id',
                'catalog_0.name as classification_name',
                'catalog_1.name as category_name',
                'catalog_2.name as product_name',
                'catalog_product.name as sku_name',
                'catalog_product.quantity_per_case',
                'user_sales_order_details.case_quantity',
                'user_sales_order_details.case_rate',
                'user_sales_order_details.rate',
                'user_sales_order_details.quantity',
                'user_sales_order_details.scheme_qty',
                'user_sales_order_details.product_discount as discount',
            )
            ->whereIn('order_id', $orderIds)
            ->get()
            ->groupBy('order_id', 'product_id');

        $payments = DB::table('van_sale_payment_details')
            ->whereIn('order_id', $orderIds)
            ->select(
                'order_id',
                DB::raw('SUM(amount) as amount'),
                DB::raw('SUM(cash_amount) as cash'),
                DB::raw('SUM(online_amount) as online'),
                DB::raw('SUM(credit_amount) as credit'),
                DB::raw('SUM(cheque_amount) as cheque'),
                DB::raw('SUM(adujment_amount) as adjustment')
            )
            ->groupBy('order_id')
            ->get()
            ->keyBy('order_id');

        $fulfillment = DB::table('fullfillment_order_details as fd')
            ->join('fullfillment_order as fo', 'fo.order_id', '=', 'fd.order_id')
            ->whereIn('fd.order_id', $orderIds)
            ->select(
                'fd.order_id',
                'fd.product_id',
                DB::raw('SUM(fd.product_fullfiment_cases) as fulfilled_cases'),
                DB::raw('SUM(fd.product_fullfiment_qty) as fulfilled_pcs'),
                DB::raw('SUM(fd.product_fullfiment_scheme_cases) as fulfilled_scheme_cases'),
                DB::raw('SUM(fd.product_value) as fulfilled_value')
            )
            ->groupBy('fd.order_id', 'fd.product_id')
            ->get()
            ->groupBy('order_id');

        $user_name = DB::table('person')
            ->select(DB::raw("CONCAT_WS(' ',first_name,NULLIF(middle_name,''),last_name) as name"), 'id')
            ->where('company_id', $company_id)
            ->pluck('name', 'id')
            ->toArray();

        $channel = DB::table('retailer')
            ->join('location_7', 'location_7.id', '=', 'retailer.location_id')
            ->join('location_6', 'location_6.id', '=', 'location_7.location_6_id')
            ->select('retailer.id as retailer_id', 'location_6.name as channel')
            ->where('retailer.company_id', $company_id)
            ->pluck('channel', 'retailer_id')
            ->toArray();

        $reportData = [];
        $sr = 1;

        foreach ($orders as $order) {

            $user_id  = $order->order_by;
            $cacheKey = "seniorData_{$user_id}";
            Cache::forget($cacheKey);
            JuniorData::getSeniorUser($user_id, $company_id);
            $senior_ids = Cache::get($cacheKey, []);

            $se_name = [];
            $so_name = [];
            if (!empty($senior_ids)) {
                $se_name = DB::table('person')
                    ->whereIn('id', $senior_ids)
                    ->where('role_id', 2958)
                    ->where('company_id', $company_id)
                    ->pluck(DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name)"), 'id')
                    ->toArray();

                $so_name = DB::table('person')
                    ->whereIn('id', $senior_ids)
                    ->where('role_id', 2957)
                    ->where('company_id', $company_id)
                    ->pluck(DB::raw("CONCAT_WS(' ', first_name, NULLIF(middle_name,''), last_name)"), 'id')
                    ->toArray();
            }

            $payment = $payments[$order->order_id] ?? null;

            $amount  = $payment->amount ?? 0;
            $cash    = $payment->cash ?? 0;
            $online  = $payment->online ?? 0;
            $credit  = $payment->credit ?? 0;
            $cheque  = $payment->cheque ?? 0;
            $adjust  = $payment->adjustment ?? 0;

            $collection  = $cash + $online + $cheque + $credit;
            $outstanding = $credit;

            if (!isset($details[$order->order_id])) continue;

            foreach ($details[$order->order_id] as $item) {

                if ($order->retailer_status == 1) {
                    $status = 'Active';
                } elseif ($order->retailer_status == 0) {
                    $status = 'Inactive';
                } else {
                    $status = 'Deleted';
                }

                $orderQty = $item->case_quantity ?? 0;
                $freeQty  = $item->scheme_qty ?? 0;
                $totalQty = $orderQty + $freeQty;

                $orderValue = ($item->rate * ($item->quantity ?? 0)) + ($item->case_rate * $orderQty);

                $fulData = $fulfillment[$order->order_id] ?? collect([]);
                $fulData = $fulData->firstWhere('product_id', $item->product_id) ?? null;

                $reportData[] = [
                    'sr_no'                  => $sr++,
                    'date'                   => $order->date,
                    'order_id'               => $order->order_id,
                    'retailer_id'            => $order->retailer_id,
                    'retailer_name'          => $order->retailer_name,
                    'contact_person'         => $order->contact_per_name,
                    'contact_no'             => !empty($order->landline) ? $order->landline : ($order->other_numbers ?? ''),
                    'beat_name'              => $order->beat_name ?? '',
                    'db_name'                => $order->distributor_name ?? '',

                    'classification'         => $item->classification_name ?? '',
                    'category'               => $item->category_name ?? '',
                    'product_name'           => $item->product_name ?? '',
                    'sku_name'               => $item->sku_name ?? '',

                    'rate_per_case'          => $item->case_rate ?? 0,
                    'order_qty'              => $orderQty,
                    'free_qty'               => $freeQty,
                    'total_order_qty'        => $totalQty,
                    'order_value'            => $orderValue,

                    'fulfilled_qty_cases'    => $fulData->fulfilled_cases ?? 0,
                    'fulfilled_qty_pcs'      => $fulData->fulfilled_pcs ?? 0,
                    'fulfilled_scheme_cases' => $fulData->fulfilled_scheme_cases ?? 0,
                    'fulfilled_value'        => $fulData->fulfilled_value ?? 0,

                    'sale_value'             => $amount,
                    'discount'               => $item->discount ?? 0,
                    'cash'                   => $cash,
                    'online'                 => $online,
                    'credit'                 => $credit,
                    'cheque'                 => $cheque,
                    'adjustment'             => $adjust,
                    'collection'             => $collection,
                    'outstanding'            => $outstanding,

                    'image'                  => $order->image_name ?? '',
                    'order_by'               => $user_name[$order->order_by ?? ''] ?? '',
                    'assigned_se'            => implode(', ', $se_name),
                    'assigned_so'            => implode(', ', $so_name),
                    'channel'                => $channel[$order->retailer_id] ?? '',
                    'created_by'             => $user_name[$order->created_by_person_id ?? ''] ?? '',
                    'status'                 => $status,
                ];
            }
        }

        return response()->json([
            'status' => true,
            'data'   => $reportData,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Van Sale                                                           */
    /* ------------------------------------------------------------------ */

    public function vanSaleIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function vanSaleAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $datasenior = $this->getJuniorUserIds($request);

        $orders = VanSalesOrder::getVanSalesOrder($company_id, $from_date, $to_date);
        if (!empty($datasenior)) {
            $orders = $orders->join('dealer_location_rate_list', 'ss_van_sales_order.dealer_id', '=', 'dealer_location_rate_list.dealer_id')
                ->whereIn('dealer_location_rate_list.user_id', $datasenior);
        }
        $orders = $orders->get();

        if ($orders->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $orderIds = $orders->pluck('order_id')->toArray();

        $details     = VanSalesOrderDetail::getVanSalesOrderDetails($orderIds, $company_id);
        $secondary   = Secondary_Sale::getSecondarySale($orderIds, $company_id);
        $fulfillment = FulfillmentData::getFullfillmentData($orderIds);

        $reportData  = [];
        $orderHeader = $orders->keyBy('order_id');

        foreach ($details as $detail) {
            $key = $detail->order_id . '_' . $detail->product_id;

            if (!isset($reportData[$key])) {
                $header = $orderHeader[$detail->order_id];

                $reportData[$key] = [
                    'date'               => $header->sale_date ?? '',
                    'dealer_name'        => $header->dealer_name ?? '',
                    'vehicle_number'     => $header->vehicle_number ?? '',
                    'driver_name'        => $header->driver_name ?? '',
                    'beat_name'          => $header->beat_name ?? '',
                    'csa_name'           => $header->csa_name ?? '',
                    'classification_name'=> $detail->classification_name ?? '',
                    'category_name'      => $detail->category_name ?? '',
                    'product_name'       => $detail->product_name ?? '',
                    'sku_name'           => $detail->sku_name ?? '',
                    'quantity_per_case'  => $detail->quantity_per_case ?? '',
                    'load_qty'           => 0,
                    'fulfilment_qty'     => 0,
                    'ready_qty'          => 0,
                    'free_qty'           => 0,
                    'sale_value'         => 0,
                    'rate'               => $detail->rate ?? '',
                ];
            }

            $reportData[$key]['load_qty'] += ($detail->cases) + ($detail->pcs / $detail->quantity_per_case);
        }

        foreach ($fulfillment as $full) {
            $key = $full->order_id . '_' . $full->product_id;
            if (isset($reportData[$key])) {
                $qpc = $reportData[$key]['quantity_per_case'];
                $reportData[$key]['fulfilment_qty'] += ($full->total_cases) + ($full->total_pieces / $qpc);
            }
        }

        foreach ($secondary as $sec) {
            $key = $sec->order_id . '_' . $sec->product_id;
            if (isset($reportData[$key])) {
                $qpc = $reportData[$key]['quantity_per_case'];
                $reportData[$key]['ready_qty']  += ($sec->case_quantity) + ($sec->quantity / $qpc);
                $reportData[$key]['free_qty']   += ($sec->scheme_qty / $qpc);
                $reportData[$key]['sale_value'] += $sec->total_sale;
            }
        }

        return response()->json([
            'status' => true,
            'data'   => array_values($reportData),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Van Settlement                                                     */
    /* ------------------------------------------------------------------ */

    public function vanSettlementIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function vanSettlementAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $datasenior = $this->getJuniorUserIds($request);

        $orders = VanSalesOrder::getVanSalesOrder($company_id, $from_date, $to_date);
        if (!empty($datasenior)) {
            $orders = $orders->join('dealer_location_rate_list', 'ss_van_sales_order.dealer_id', '=', 'dealer_location_rate_list.dealer_id')
                ->whereIn('dealer_location_rate_list.user_id', $datasenior);
        }
        $orders = $orders->get();

        $orderIds = $orders->pluck('order_id')->toArray();

        if (empty($orderIds)) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $details     = VanSalesOrderDetail::getVanSalesOrderDetails($orderIds, $company_id);
        $secondary   = Secondary_Sale::getSecondarySale($orderIds, $company_id);
        $fulfillment = FulfillmentData::getFullfillmentData($orderIds);
        $payments    = VanSalePaymentData::getVanSalePaymentData($orderIds, $company_id)->keyBy('van_sale_order_id');

        $orderHeader = $orders->keyBy('order_id');

        $reportData = [];

        foreach ($details as $detail) {
            $key = $detail->order_id . '_' . $detail->product_id;

            if (!isset($reportData[$key])) {
                $header = $orderHeader[$detail->order_id];

                $reportData[$key] = [
                    'date'               => $header->sale_date ?? '',
                    'dealer_name'        => $header->dealer_name ?? '',
                    'vehicle_number'     => $header->vehicle_number ?? '',
                    'driver_name'        => $header->driver_name ?? '',
                    'beat_name'          => $header->beat_name ?? '',
                    'csa_name'           => $header->csa_name ?? '',
                    'load_in_time'       => $header->date_time ?? '',
                    'load_out_time'      => $header->checkout_date_time ?? '',
                    'start_kms'          => $header->meter_reading ?? 0,
                    'end_kms'            => $header->running_meter_end ?? 0,
                    'total_kms'          => ($header->running_meter_end ?? 0) - ($header->meter_reading ?? 0),
                    'classification_name'=> $detail->classification_name ?? '',
                    'category_name'      => $detail->category_name ?? '',
                    'product_name'       => $detail->product_name ?? '',
                    'sku_name'           => $detail->sku_name ?? '',
                    'quantity_per_case'  => $detail->quantity_per_case ?? 0,
                    'load_qty'           => 0,
                    'fulfilment_qty'     => 0,
                    'ready_qty'          => 0,
                    'free_qty'           => 0,
                    'sale_value'         => 0,
                    'rate'               => $detail->rate ?? 0,
                    'cash'               => 0,
                    'online'             => 0,
                    'total_collection'   => 0,
                    'outstanding'        => 0,
                ];
            }

            $reportData[$key]['load_qty'] += ($detail->cases) + ($detail->pcs / $detail->quantity_per_case);
        }

        foreach ($fulfillment as $full) {
            $key = $full->order_id . '_' . $full->product_id;
            if (isset($reportData[$key])) {
                $qpc = $reportData[$key]['quantity_per_case'];
                $reportData[$key]['fulfilment_qty'] += ($full->total_cases) + ($full->total_pieces / $qpc);
            }
        }

        foreach ($secondary as $sec) {
            $key = $sec->order_id . '_' . $sec->product_id;
            if (isset($reportData[$key])) {
                $qpc = $reportData[$key]['quantity_per_case'];
                $reportData[$key]['ready_qty']  += ($sec->case_quantity) + ($sec->quantity / $qpc);
                $reportData[$key]['free_qty']   += ($sec->scheme_qty / $qpc);
                $reportData[$key]['sale_value'] += $sec->total_sale;
            }
        }

        foreach ($reportData as $key => $row) {
            $order_id = explode('_', $key)[0];
            if (isset($payments[$order_id])) {
                $reportData[$key]['cash']             = $payments[$order_id]->cash ?? 0;
                $reportData[$key]['online']           = $payments[$order_id]->upi ?? 0;
                $reportData[$key]['total_collection'] = ($payments[$order_id]->cash ?? 0) + ($payments[$order_id]->upi ?? 0) + ($payments[$order_id]->credit ?? 0);
                $reportData[$key]['outstanding']      = $reportData[$key]['sale_value'] - $reportData[$key]['total_collection'];
            }
        }

        return response()->json([
            'status' => true,
            'data'   => array_values($reportData),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  DB Sales                                                           */
    /* ------------------------------------------------------------------ */

    public function dbSalesIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function dbSalesAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $datasenior = $this->getJuniorUserIds($request);

        $ordersQuery = VanSalesOrder::getVanSalesOrder($company_id, $from_date, $to_date);

        if (!empty($datasenior)) {
            $ordersQuery->join('dealer_location_rate_list',
                    'ss_van_sales_order.dealer_id', '=', 'dealer_location_rate_list.dealer_id')
                ->whereIn('dealer_location_rate_list.user_id', $datasenior);
        }

        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $orderIds = $orders->pluck('order_id')->toArray();

        $orderDetails = VanSalesOrderDetail::getVanSalesOrderDetails($orderIds, $company_id)
            ->groupBy(['order_id', 'product_id']);

        $payments = VanSalePaymentData::getVanSalePaymentData($orderIds, $company_id)
            ->keyBy('order_id');

        $orderHeader = $orders->keyBy('order_id');

        $finalReport = [];

        // NOTE: The original controller contained a bug here — it referenced
        // `$order` and `$reportData` instead of `$header` and `$finalReport`.
        // Fixed below.
        foreach ($orderDetails as $detail) {
            $key = $detail->order_id . '_' . $detail->product_id;

            if (!isset($finalReport[$key])) {
                $header = $orderHeader[$detail->order_id];

                $finalReport[$key] = [
                    'date'               => $header->sale_date ?? '',
                    'dealer'             => $header->dealer_name ?? '',
                    'classification'     => $detail->classification_name ?? '',
                    'category'           => $detail->category_name ?? '',
                    'product'            => $detail->product_name ?? '',
                    'sku'                => $detail->sku_name ?? '',

                    'opening_stock_case' => 0,
                    'purchase_case'      => 0,
                    'fulfillment_case'   => 0,
                    'total_sale_case'    => 0,
                    'closing_stock_case' => 0,

                    'opening_value'      => 0,
                    'purchase_value'     => 0,
                    'fulfillment_value'  => 0,
                    'total_sale_value'   => 0,
                    'closing_value'      => 0,

                    'cash'               => 0,
                    'upi'                => 0,
                    'collection'         => 0,
                    'outstanding'        => 0,
                    'profit_loss'        => 0,
                ];
            }

            $finalReport[$key]['opening_stock_case'] += $detail->opening_stock_case ?? 0;
            $finalReport[$key]['purchase_case']      += $detail->purchase_case ?? 0;
            $finalReport[$key]['fulfillment_case']   += $detail->fulfillment_case ?? 0;
            $finalReport[$key]['total_sale_case']    += $detail->total_sale_case ?? 0;
            $finalReport[$key]['closing_stock_case'] += $detail->closing_stock_case ?? 0;

            $finalReport[$key]['opening_value']      += $detail->opening_value ?? 0;
            $finalReport[$key]['purchase_value']     += $detail->purchase_value ?? 0;
            $finalReport[$key]['fulfillment_value']  += $detail->fulfillment_value ?? 0;
            $finalReport[$key]['total_sale_value']   += $detail->total_sale_value ?? 0;
            $finalReport[$key]['closing_value']      += $detail->closing_value ?? 0;

            $finalReport[$key]['cash']        += $payments[$detail->order_id]['cash'] ?? 0;
            $finalReport[$key]['upi']         += $payments[$detail->order_id]['upi'] ?? 0;
            $finalReport[$key]['collection']  += $payments[$detail->order_id]['collection'] ?? 0;
            $finalReport[$key]['outstanding'] += $payments[$detail->order_id]['outstanding'] ?? 0;
            $finalReport[$key]['profit_loss'] += $payments[$detail->order_id]['profit_loss'] ?? 0;
        }

        return response()->json([
            'status' => true,
            'data'   => array_values($finalReport),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  DGR Claim                                                          */
    /* ------------------------------------------------------------------ */

    public function dgrClaimReport(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function dgrClaimAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $main_data = DB::table('dms_retailer_images_amount_claim as claim')
            ->join('challan_order as co', 'claim.order_id', '=', 'co.id')
            ->join('dealer as d', 'claim.dealer_id', '=', 'd.id')
            ->leftJoin('retailer as r', 'claim.retailer_id', '=', 'r.id')
            ->leftJoin('_retailer_outlet_category as roc', 'r.class', '=', 'roc.id')
            ->leftJoin('person as p', 'co.ch_user_id', '=', 'p.id')
            ->leftJoin('location_7 as l7', 'r.location_id', '=', 'l7.id')
            ->leftJoin('location_6 as l6', 'd.town_id', '=', 'l6.id')
            ->where('claim.company_id', $company_id)
            ->whereBetween(DB::raw('DATE(claim.date_time)'), [$from_date, $to_date])
            ->select(
                'claim.*',
                'co.ch_no as invoice_no',
                DB::raw("DATE_FORMAT(co.ch_date, '%d-%m-%Y') as invoice_date"),
                'co.amount as invoice_amount',
                'co.discount_amt',
                'd.name as dealer_name',
                'd.address as dealer_address',
                'l6.name as town_name',
                'r.name as retailer_name',
                'r.address as retailer_address',
                'r.contact_per_name',
                'roc.outlet_category as retailer_category',
                'l7.name as beat_name',
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as tsm_name"),
                'co.ch_user_id'
            )
            ->orderBy('claim.date_time', 'DESC')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $main_data,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  DB Van Summary                                                     */
    /* ------------------------------------------------------------------ */

    public function dbVanSummaryIndex(Request $request)
    {
        $company_id = $request->user()->company_id;

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'  => $company_id,
                'region'      => Location1::where('status', 1)->where('company_id', $company_id)->select('name', 'id')->get(),
                'state'       => Location3::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'area'        => Location4::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'head_quater' => Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'town'        => Location6::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'beat'        => Location7::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'distributor' => Dealer::where('dealer_status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'division'    => DB::table('department')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                'users'       => DB::table('person')
                    ->join('person_login', 'person_login.person_id', '=', 'person.id')
                    ->select(DB::raw('CONCAT(person.first_name," ",person.last_name) as name'), 'person.id as uid')
                    ->where('person_login.person_status', '=', '1')
                    ->where('person.company_id', $company_id)
                    ->pluck('name', 'uid'),
            ],
        ]);
    }

    public function dbVanSummaryAjax(Request $request)
    {
        $company_id = $request->user()->company_id;

        [$from_date, $to_date] = $this->parseDateRange($request->input('date_range_picker'));

        $datasenior = $this->getJuniorUserIds($request);

        $orders = VanSalesOrder::getVanSalesOrder($company_id, $from_date, $to_date);
        if (!empty($datasenior)) {
            $orders = $orders->join('dealer_location_rate_list', 'ss_van_sales_order.dealer_id', '=', 'dealer_location_rate_list.dealer_id')
                ->whereIn('dealer_location_rate_list.user_id', $datasenior);
        }
        $orders = $orders->get();

        if ($orders->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $orderIds  = $orders->pluck('order_id')->toArray();
        $details   = VanSalesOrderDetail::getVanSalesOrderDetails($orderIds, $company_id);
        $secondary = Secondary_Sale::getSecondarySale($orderIds, $company_id);

        $reportData  = [];
        $orderHeader = $orders->keyBy('order_id');

        foreach ($details as $detail) {
            $key = $detail->order_id . '_' . $detail->product_id;

            if (!isset($reportData[$key])) {
                $header = $orderHeader[$detail->order_id];

                $reportData[$key] = [
                    'date'               => $header->sale_date ?? '',
                    'vehicle_number'     => $header->vehicle_number ?? '',
                    'classification_name'=> $detail->classification_name ?? '',
                    'category_name'      => $detail->category_name ?? '',
                    'product_name'       => $detail->product_name ?? '',
                    'sku_name'           => $detail->sku_name ?? '',
                    'quantity_per_case'  => $detail->quantity_per_case ?? '',
                    'ready_qty'          => 0,
                ];
            }
        }

        foreach ($secondary as $sec) {
            $key = $sec->order_id . '_' . $sec->product_id;
            if (isset($reportData[$key])) {
                $qpc = $reportData[$key]['quantity_per_case'];
                $reportData[$key]['ready_qty'] += ($sec->case_quantity) + ($sec->quantity / $qpc);
            }
        }

        return response()->json([
            'status' => true,
            'data'   => array_values($reportData),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Sales Score Card                                                   */
    /* ------------------------------------------------------------------ */

    public function salesScoreCard(Request $request)
    {
        $company_id = $request->user()->company_id;

        $userss = DB::table('person as p')
            ->join('person_login', 'p.id', '=', 'person_login.person_id')
            ->leftJoin('person as senior', 'p.person_id_senior', '=', 'senior.id')
            ->where('p.company_id', $company_id)
            ->where('person_login.person_status', 1)
            ->select(
                DB::raw("CONCAT(p.first_name, ' ', IFNULL(p.last_name,'')) as first_name"),
                'p.id',
                'p.emp_code',
                DB::raw("CONCAT(senior.first_name, ' ', IFNULL(senior.last_name,'')) as senior_name")
            )
            ->orderBy('p.first_name')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $userss,
        ]);
    }

    public function salesScoreCardData(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'from'    => 'required|date_format:Y-m',
            'to'      => 'required|date_format:Y-m|after_or_equal:from',
        ]);

        $userId = $validated['user_id'];
        $from   = DateTime::createFromFormat('Y-m-d', $validated['from'] . '-01');
        $to     = DateTime::createFromFormat('Y-m-d', $validated['to'] . '-01');

        $months = [];
        $cursor = clone $from;
        while ($cursor <= $to) {
            $months[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
        }

        $sectionDefs = [
            'sales_tgt_vs_ach' => [
                'label'      => 'Sales TGT V/S ACH (IN LAC)',
                'weightage'  => 45,
                'ytd_score'  => 0.0,
                'month_score_dummy' => 0.0,
                'rows'       => [
                    'TGT'   => ['ytd' => 33,     'month_dummy' => 4],
                    'ACH'   => ['ytd' => 2,      'month_dummy' => 1],
                    '% ACH' => ['ytd' => '4.9%', 'month_dummy' => '32.9%'],
                ],
            ],
            'forecast_deviation' => [
                'label'      => 'Forecast Deviation (IN SQM)',
                'weightage'  => 16,
                'ytd_score'  => 3.2,
                'month_score_dummy' => 1.6,
                'rows'       => [
                    'Forecast (In Lac)'  => ['ytd' => 4,       'month_dummy' => 0.5],
                    'Sales (In Lac)'     => ['ytd' => 3,       'month_dummy' => 0.2],
                    'Deviation (In Lac)' => ['ytd' => 3,       'month_dummy' => 0.4],
                    'Deviation %'        => ['ytd' => '93.9%', 'month_dummy' => '187.7%'],
                ],
            ],
            'active_customer' => [
                'label'      => 'Active Customer (>6 Lac Annually)',
                'weightage'  => 15,
                'ytd_score'  => 12.0,
                'month_score_dummy' => 12.0,
                'rows'       => [
                    'TGT'   => ['ytd' => 20,      'month_dummy' => 20],
                    'ACH'   => ['ytd' => 16,      'month_dummy' => 16],
                    '% ACH' => ['ytd' => '80.0%', 'month_dummy' => '80.0%'],
                ],
            ],
            'sale_from_new_customers' => [
                'label'      => 'Sale From New Customers',
                'weightage'  => 25,
                'ytd_score'  => 25.0,
                'month_score_dummy' => 25.0,
                'rows'       => [
                    'Total Sale'                  => ['ytd' => 2,          'month_dummy' => 1],
                    'New Customer Addition'       => ['ytd' => 13,         'month_dummy' => 0],
                    'Count of New Customers Sale' => ['ytd' => 38,         'month_dummy' => 5],
                    'Sale From New & Existing'    => ['ytd' => 109,        'month_dummy' => 13.2],
                    '%Sale From New & Existing'   => ['ytd' => '6637.8%',  'month_dummy' => '966.5%'],
                    'TGT% Sale From New'          => ['ytd' => '20%',      'month_dummy' => '20%'],
                    '% Sale From New Customers'   => ['ytd' => '33188.8%', 'month_dummy' => '4832.6%'],
                ],
            ],
            'punched_order_not_picked' => [
                'label'      => 'The punched Order was not picked up',
                'weightage'  => null,
                'ytd_score'  => 0.0,
                'month_score_dummy' => 0.0,
                'rows'       => [
                    'TGT'   => ['ytd' => 1,      'month_dummy' => 1],
                    'ACH'   => ['ytd' => 0.04,   'month_dummy' => 0.04],
                    '% ACH' => ['ytd' => '2.9%', 'month_dummy' => '2.9%'],
                ],
            ],
            'collection' => [
                'label'      => 'Collection',
                'weightage'  => null,
                'ytd_score'  => 0.0,
                'month_score_dummy' => 0.0,
                'rows'       => [
                    'TGT'   => ['ytd' => 44,     'month_dummy' => 44],
                    'ACH'   => ['ytd' => 35,     'month_dummy' => 35],
                    '% ACH' => ['ytd' => '79.5%', 'month_dummy' => '79.5%'],
                ],
            ],
        ];

        $sections = [];

        foreach ($sectionDefs as $sectionKey => $def) {
            $rows = [];
            foreach ($def['rows'] as $rowLabel => $rowDef) {
                $rows[$rowLabel] = [
                    'ytd'    => $rowDef['ytd'],
                    'months' => $this->buildMonthlyRow($userId, $months, $sectionKey, $rowLabel, $rowDef['month_dummy']),
                ];
            }

            $sections[$sectionKey] = [
                'label'        => $def['label'],
                'weightage'    => $def['weightage'],
                'rows'         => $rows,
                'ytd_score'    => $def['ytd_score'],
                'month_scores' => $this->buildMonthlyScores($userId, $months, $sectionKey, $def['month_score_dummy']),
            ];
        }

        $totalScore = [
            'weightage'    => 100,
            'ytd_score'    => 40,
            'month_scores' => $this->buildMonthlyScores($userId, $months, 'total', 39),
        ];

        return response()->json([
            'status' => true,
            'data'   => [
                'user' => [
                    'id'          => $userId,
                    'name'        => 'Arun Kumar Bhatt',
                    'senior_name' => 'Ajit Gupta Ji',
                    'emp_code'    => 'E000002',
                ],
                'months'      => $months,
                'sections'    => $sections,
                'total_score' => $totalScore,
                'note'        => "As per management's decision, a minimum threshold of 70% has been established for all evaluation parameters.",
            ],
        ]);
    }

    private function buildMonthlyRow(int $userId, array $months, string $sectionKey, string $rowLabel, $dummyValue): array
    {
        $values = [];
        foreach ($months as $month) {
            $start = DateTime::createFromFormat('Y-m-d', $month . '-01');
            $end   = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);
            // TODO: replace with a real query
            $values[$month] = $dummyValue;
        }
        return $values;
    }

    private function buildMonthlyScores(int $userId, array $months, string $sectionKey, $dummyScore): array
    {
        $scores = [];
        foreach ($months as $month) {
            $start = DateTime::createFromFormat('Y-m-d', $month . '-01');
            $end   = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);
            // TODO: replace with a real query
            $scores[$month] = $dummyScore;
        }
        return $scores;
    }

    /* ------------------------------------------------------------------ */
    /*  Complaint                                                          */
    /* ------------------------------------------------------------------ */

    public function complaintForm(Request $request)
    {
        $company_id = $request->user()->company_id;

        $user = DB::table('person')
            ->join('person_login', 'person.id', '=', 'person_login.person_id')
            ->where('person.company_id', $company_id)
            ->where('person_login.person_status', 1)
            ->select('person.id', DB::raw("CONCAT(person.first_name, ' ', person.last_name) AS person_name"))
            ->get();

        $user_data = DB::table('person')
            ->join('person_login', 'person.id', '=', 'person_login.person_id')
            ->where('person.company_id', $company_id)
            ->where('person_login.person_status', 1)
            ->select('person.id', DB::raw("CONCAT(person.first_name, ' ', person.last_name) AS person_name"))
            ->pluck('person_name', 'id')
            ->toArray();

        $status = DB::table('_complaint_status')
            ->where('status', 1)
            ->pluck('name', 'id')
            ->toArray();

        $case_no = DB::table('complain_details')
            ->where('company_id', $company_id)
            ->orderBy('id', 'desc')
            ->pluck('case_no')
            ->toArray();

        $sub_department = DB::table('sub_department')
            ->where('company_id', $company_id)
            ->where('status', 1)
            ->pluck('name', 'id');

        $location_5 = Location5::where('status', 1)->where('company_id', $company_id)->pluck('name', 'id');

        return response()->json([
            'status' => true,
            'data'   => [
                'company_id'     => $company_id,
                'user'           => $user,
                'user_data'      => $user_data,
                'case_no'        => $case_no,
                'location_5'     => $location_5,
                'status'         => $status,
                'sub_department' => $sub_department,
            ],
        ]);
    }

    public function storeComplaint(Request $request)
    {
        $request->validate([
            'case_no'           => 'required|max:50',
            'contact_no'        => 'required|max:11',
            'customer_name'     => 'required|max:100',
            'address'           => 'required',
            'machine_serial_no' => 'required|max:100',
            'machine_type'      => 'required|max:100',
            'assigned_to'       => 'required|integer',
            'fault_description' => 'required',
        ]);

        $company_id = $request->user()->company_id;
        $today      = now()->toDateString();

        $latestCase = DB::table('complain_details')
            ->where('company_id', $company_id)
            ->where('case_no', $request->case_no)
            ->orderBy('id', 'desc')
            ->first();

        if ($latestCase && $latestCase->visit_count == 0) {
            return response()->json([
                'status'  => false,
                'type'    => 'running_case',
                'message' => 'This case number is already running. Showing existing details.',
                'data'    => $latestCase,
            ], 409);
        }

        DB::table('complain_details')->insert([
            'company_id'        => $company_id,
            'case_no'           => $request->case_no,
            'case_status'       => 0,
            'contact_no'        => $request->contact_no,
            'customer_name'     => $request->customer_name,
            'address'           => $request->address,
            'machine_serial_no' => $request->machine_serial_no,
            'machine_type'      => $request->machine_type,
            'assigned_date'     => $today,
            'assigned_to'       => $request->assigned_to,
            'fault_description' => $request->fault_description,
            'lro'               => $request->lro,
            'visit_count'       => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Complaint saved successfully',
        ], 201);
    }

    public function getRunningCase(Request $request)
    {
        $request->validate(['case_no' => 'required']);

        $company_id = $request->user()->company_id;

        $latestCase = DB::table('complain_details')
            ->where('company_id', $company_id)
            ->where('case_no', $request->case_no)
            ->orderBy('id', 'desc')
            ->first();

        if ($latestCase && $latestCase->visit_count == 0) {
            return response()->json(['status' => true, 'data' => $latestCase]);
        }

        return response()->json(['status' => false]);
    }

    public function complaintList(Request $request)
    {
        $sub_department = $request->sub_department;
        $assigned_to    = $request->user_data;
        $case_no        = $request->case_no;
        $location_5     = $request->location_5;
        $status         = $request->status;

        $company_id = $request->user()->company_id;

        $datasenior = $this->getJuniorUserIds($request);

        $data = DB::table('complain_details')
            ->leftJoin('person', 'person.id', '=', 'complain_details.assigned_to')
            ->leftJoin('department', 'person.department_id', '=', 'department.id')
            ->leftJoin('sub_department', 'department.id', '=', 'sub_department.super_id')
            ->leftJoin('_complaint_status', '_complaint_status.id', '=', 'complain_details.case_status_pre')
            ->leftJoin('travelling_expense_bill', 'travelling_expense_bill.complaint_id', '=', 'complain_details.id')
            ->where('complain_details.company_id', $company_id)
            ->select(
                'complain_details.case_no',
                'complain_details.visit_count',
                'complain_details.customer_name',
                'complain_details.contact_no',
                'complain_details.machine_type',
                'complain_details.assigned_date',
                '_complaint_status.name as case_status',
                'complain_details.machine_serial_no',
                DB::raw("CONCAT(person.first_name,' ',person.last_name) as assigned_to_name"),
                'travelling_expense_bill.complaint_id as expense_status',
                'complain_details.id as complaint_id',
            );

        if (!empty($sub_department)) $data->where('sub_department.id', $sub_department);
        if (!empty($assigned_to))    $data->where('complain_details.assigned_to', $assigned_to);
        if (!empty($case_no))        $data->where('complain_details.case_no', $case_no);
        if (!empty($location_5))     $data->where('person.head_quater_id', $location_5);
        if (!empty($datasenior))     $data->whereIn('person.id', $datasenior);

        if (!empty($status)) {
            if ($status == 1) {
                $data->where('complain_details.case_status_pre', 0);
            } else {
                $data->where('complain_details.case_status_pre', $status);
            }
        }

        $data = $data
            ->groupBy('complain_details.id')
            ->orderBy('complain_details.assigned_date', 'desc')
            ->get();

        return response()->json(['status' => true, 'data' => $data]);
    }

    public function deleteComplaint(Request $request)
    {
        $request->validate(['case_no' => 'required']);

        $case_no    = $request->case_no;
        $company_id = $request->user()->company_id;

        $case = DB::table('complain_details')
            ->where('case_no', $case_no)
            ->where('company_id', $company_id)
            ->first();

        if (empty($case->id)) {
            return response()->json(['status' => false, 'message' => 'Case not found'], 404);
        }

        DB::table('complain_details')->where('case_no', $case_no)->delete();

        return response()->json(['status' => true, 'message' => 'Case deleted successfully']);
    }

    public function showComplaint(Request $request)
    {
        $company_id = $request->user()->company_id;
        $case_no    = $request->case_no;

        $case_details = DB::table('complain_details')
            ->where('case_no', $case_no)
            ->where('company_id', $company_id)
            ->first();

        if (!$case_details) {
            return response()->json(['status' => false, 'message' => 'Case not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $case_details]);
    }

    public function updateComplaint(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'case_no'           => 'required|max:50',
            'contact_no'        => 'required|digits:10',
            'customer_name'     => 'required|max:100',
            'address'           => 'required',
            'machine_serial_no' => 'required|max:100',
            'machine_type'      => 'required|max:100',
            'assigned_date'     => 'required|date',
            'assigned_to'       => 'required|integer',
            'fault_description' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $company_id = $request->user()->company_id;
        $case_no    = $request->case_no;

        $update = DB::table('complain_details')
            ->where('case_no', $case_no)
            ->where('company_id', $company_id)
            ->update([
                'contact_no'        => $request->contact_no,
                'customer_name'     => $request->customer_name,
                'address'           => $request->address,
                'machine_serial_no' => $request->machine_serial_no,
                'machine_type'      => $request->machine_type,
                'assigned_date'     => $request->assigned_date,
                'assigned_to'       => $request->assigned_to,
                'fault_description' => $request->fault_description,
                'lro'               => $request->lro,
                'updated_at'        => now(),
            ]);

        if (!$update) {
            return response()->json([
                'status'  => false,
                'message' => 'No complaint found or nothing to update.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Complaint updated successfully',
        ]);
    }
}