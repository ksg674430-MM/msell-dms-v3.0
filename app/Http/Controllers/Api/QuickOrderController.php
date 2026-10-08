<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use PDF;

class QuickOrderController extends Controller
{
    /* ============================================================
     |  CONTEXT HELPERS
     * ============================================================ */

    /**
     * Resolve the logged in dealer / user context.
     * Assumes the Sanctum user has a `dealer_id` column.
     */
    private function context(Request $request): array
    {
        $user = $request->user();

        $dealer_id = $user->dealer_id ?? 0;

        $dealer = DB::table('dealer')->where('id', $dealer_id)->first();

        return [
            'user'        => $user,
            'dealer'      => $dealer,
            'dealer_id'   => $dealer_id,
            'company_id'  => $dealer->company_id  ?? ($user->company_id  ?? 0),
            'dealer_code' => $dealer->dealer_code ?? ($user->dealer_code ?? ''),
            'csa_id'      => $dealer->csa_id      ?? ($user->csa_id      ?? 0),
            'role_id'     => $user->role_id       ?? ($dealer->role_id   ?? 0),
            'domain_url'  => $user->domain_url    ?? '',
            'quantity_type' => $dealer->quantity_type ?? 0,
            'date'        => date('Y-m-d'),
        ];
    }

    private function allowedDealerId(array $ctx, $requested)
    {
        $own = (int) $ctx['dealer_id'];
        $requested = (int) $requested;
        if (!$requested || $requested === $own) {
            return $own;
        }
        if ((int) ($ctx['dealer']->is_depo ?? 0) !== 1) {
            return null;
        }
        $login = DB::table('dealer_person_login')
            ->where('dealer_id', $own)
            ->where('company_id', $ctx['company_id'])
            ->first();
        $allowed = $login && DB::table('dealer')
            ->where('id', $requested)
            ->where('company_id', $ctx['company_id'])
            ->where('csa_id', $login->csa_id)
            ->where('dealer_status', 1)
            ->exists();
        return $allowed ? $requested : null;
    }

    private function canAccessOrder(array $ctx, $order): bool
    {
        if (!$order || (int) $order->company_id !== (int) $ctx['company_id']) {
            return false;
        }
        $role_id = (int) $ctx['role_id'];
        if ($role_id === 1 || (int) $order->dealer_id === (int) $ctx['dealer_id']) {
            return true;
        }
        if (in_array($role_id, [37, 823], true)) {
            return (string) $order->sale_div_code === (string) $this->resolveDivCode($ctx['dealer_id'], $ctx['dealer_code']);
        }
        return false;
    }

    private function dealerRate(array $ctx, $itemCode, $mode = 0)
    {
        $company_id = $ctx['company_id'];
        $state_id   = $ctx['dealer']->state_id ?? 0;
        if (empty($state_id)) {
            $csa_data = DB::table('csa')->where('c_id', $ctx['dealer']->csa_id ?? 0)->first();
            $state_id = $csa_data->state_id ?? 0;
        }

        $rate = DB::table('product_rate_list')
            ->where('product_id', $itemCode)
            ->where('state_id', $state_id)
            ->where('company_id', $company_id)
            ->first()
            ?: DB::table('product_rate_list')
                ->where('product_id', $itemCode)
                ->where('company_id', $company_id)
                ->first();

        if (!$rate) {
            return null;
        }

        $login = DB::table('dealer_person_login')
            ->where('company_id', $company_id)
            ->where('dealer_id', $ctx['dealer_id'])
            ->first();
        $qtyType = $login->order_quantity_type ?? 0;

        if ($company_id == 44) {
            $result = $rate->dealer_rate;
            $perCase = true;
        } elseif ($company_id == 206) {
            $result = $rate->dealer_pcs_rate;
            $perCase = false;
        } elseif ($qtyType == 1) {
            $result = ((int) $mode === 1) ? $rate->ss_case_rate : $rate->dealer_rate;
            $perCase = true;
        } else {
            $result = ((int) $mode === 1) ? $rate->ss_pcs_rate : $rate->dealer_pcs_rate;
            $perCase = false;
        }
        $mrp = $perCase ? $rate->mrp : ((float) ($rate->mrp_pcs ?? 0) > 0 ? $rate->mrp_pcs : $rate->mrp);

        $um = 'PCS';
        if (in_array($company_id, [243, 213, 331], true)) {
            $um = $qtyType == 1 ? 'BOX' : 'PCS';
        }

        return ['rate' => (float) $result, 'um' => $um, 'mrp' => (float) ($mrp ?? 0)];
    }

    private function dealerScheme(array $ctx, $productId, float $qty, float $rate): array
    {
        $none   = ['rate' => $rate, 'percent' => 0.0];
        $dealer = $ctx['dealer'];
        if (empty($dealer->state_id) || $qty <= 0 || $rate <= 0) {
            return $none;
        }

        $today = date('Y-m-d');
        $q = DB::table('product_wise_scheme_plan_details')
            ->where('product_id', $productId)
            ->where('state_id', $dealer->state_id)
            ->where('company_id', $ctx['company_id'])
            ->whereDate('valid_from_date', '<=', $today)
            ->whereDate('valid_to_date', '>=', $today);
        if (!empty($dealer->ownership_type_id)) {
            $q->where('ownership_type', $dealer->ownership_type_id);
        }
        $s = $q->first();
        if (!$s) {
            return $none;
        }

        $last = trim((string) $s->sale_value_range_last);
        if ($qty < (float) $s->sale_value_range_first || ($last !== '~' && $qty > (float) $last)) {
            return $none;
        }

        $value = (float) $s->value_amount_percentage;
        if ((int) $s->sale_unit === 5) {
            if ((int) $s->incentive_type === 1) {
                return ['rate' => round($rate - ($rate * $value / 100), 2), 'percent' => 0.0];
            }
            if ((int) $s->incentive_type === 2 && $value > 0) {
                return ['rate' => $value, 'percent' => 0.0];
            }
            return $none;
        }

        return (int) $s->incentive_type === 1 ? ['rate' => $rate, 'percent' => $value] : $none;
    }

    private function lineTotal(float $rate, float $qty, float $percent): float
    {
        $gross = $rate * $qty;
        return round($gross - ($gross * $percent / 100), 2);
    }

    /** Resolve division code (used across listing + store). */
    private function resolveDivCode($dealer_id, $dealer_code)
    {
        $div_code_entry  = DB::table('dealer')->where('id', $dealer_id)->first();
        $deiv_code_login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first();

        if ($dealer_code === '00001') {
            return $div_code_entry->DIV_CODE ?? 'JH';
        }

        $else = $deiv_code_login->div_code_main ?? '0';
        return $div_code_entry->DIV_CODE ?? $else;
    }

    /* ============================================================
     |  1) ORDER LISTING
     * ============================================================ */

    public function index(Request $request)
    {
        $ctx         = $this->context($request);
        $dealer_id   = $ctx['dealer_id'];
        $company_id  = $ctx['company_id'];
        $role_id     = $ctx['role_id'];
        $dealer_code = $ctx['dealer_code'];

        // ---- Date range ----
        if (empty($request->date_range_picker)) {
            $from_date = date('Y-m') . '-01';
            $to_date   = date('Y-m-d');
        } else {
            $parts     = explode(" -", $request->date_range_picker);
            $from_date = date('Y-m-d', strtotime(trim($parts[0])));
            $to_date   = date('Y-m-d', strtotime(trim($parts[1])));
        }

        $order_no = $request->order_no;
        $div_code = $this->resolveDivCode($dealer_id, $dealer_code);

        // ---- Base demand_order query ----
        $baseQ = DB::table('demand_order')
            ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            ->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")
            ->groupBy('demand_order.id');

        if (!empty($order_no)) {
            $baseQ->where(function ($q) use ($order_no) {
                $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")
                  ->orWhere('erp_order_no', 'LIKE', "%{$order_no}%");
            });
        }

        // ---- Role based build ----
        if (in_array($role_id, [37, 823], true)) {
            if ($dealer_id == 1170) {
                $records = (clone $baseQ)
                    ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id',
                        DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))
                    ->where('demand_order.dealer_id', $dealer_id)
                    ->orderBy('demand_order.order_date', 'DESC')
                    ->get()->toArray();
            } else {
                $records = (clone $baseQ)
                    ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*',
                        DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))
                    ->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')
                    ->where('sale_div_code', $div_code)
                    ->orderBy('demand_order.order_date', 'DESC')
                    ->get()->toArray();
            }
        } else {
            $records = (clone $baseQ)
                ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id',
                    DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))
                ->where('demand_order.dealer_id', $dealer_id)
                ->orderBy('demand_order.order_date', 'DESC')
                ->get()->toArray();
        }

        // ---- Depot variant (role 37 non-1170) ----
        if ($dealer_id != 1170 && $role_id == 37) {
            $depoQ = DB::table('demand_order')
                ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
                ->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")
                ->groupBy('demand_order.id');

            if (!empty($order_no)) {
                $depoQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")
                      ->orWhere('erp_order_no', 'LIKE', "%{$order_no}%");
                });
            }

            $records_depo = $depoQ
                ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*',
                    DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))
                ->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')
                ->where('dealer_id', $dealer_id)
                ->orderBy('demand_order.order_date', 'DESC')
                ->get()->toArray();

            $records = array_merge($records_depo, $records);
        }

        // ---- ERP refs ----
        $erp_order_no = DB::table('purchase_order')
            ->where('dealer_id', $dealer_id)
            ->get()
            ->groupBy('order_id');

        // ---- Admin (role 1) ----
        $depo_filter = [];
        $dealer_name = [];

        if ($role_id == 1) {
            $adminQ = DB::table('demand_order')
                ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
                ->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")
                ->groupBy('demand_order.id');

            $adminQ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*',
                    DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))
                ->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id');

            if (!empty($order_no)) {
                $adminQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")
                      ->orWhere('erp_order_no', 'LIKE', "%{$order_no}%");
                });
            }
            if (!empty($request->depo_filter)) {
                $adminQ->whereIn('demand_order.sale_div_code', (array) $request->depo_filter);
            }
            if (!empty($request->distributor)) {
                $adminQ->whereIn('demand_order.dealer_id', (array) $request->distributor);
            }

            $records = $adminQ->get()->toArray();

            $depo_filter = DB::table('dealer')
                ->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')
                ->where('dealer_status', 1)
                ->where('role_id', 37)
                ->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as dealer_name"), 'div_code_main');

            $dealer_name = DB::table('dealer')
                ->where('dealer_status', 1)
                ->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'id');
        }

        return response()->json([
            'status'  => true,
            'message' => 'Order list fetched',
            'data'    => compact('records', 'erp_order_no', 'depo_filter', 'dealer_name') + [
                'role_id'     => $role_id,
                'dealer_id'   => $dealer_id,
                'company_id'  => $company_id,
                'dealer_code' => $dealer_code,
                'from_date'   => $from_date,
                'to_date'     => $to_date,
            ],
        ]);
    }

    /* ============================================================
     |  2) CREATE-DATA  (screen render data)
     * ============================================================ */

    public function createData(Request $request)
    {
        $ctx         = $this->context($request);
        $dealer_id   = $ctx['dealer_id'];
        $company_id  = $ctx['company_id'];
        $role_id     = $ctx['role_id'];
        $dealer_code = $ctx['dealer_code'];
        $csa_id      = $ctx['csa_id'];

        // ---------- Div code ----------
        $div_code_entry  = DB::table('acc_mast')->where('ACC_CODE', $dealer_code)->first();
        $deiv_code_login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first();
        $div_code        = $div_code_entry->DIV_CODE ?? ($deiv_code_login->div_code_main ?? '0');

        // ---------- Images / div master ----------
        $image_dyn = DB::table('image_mast')
            ->where('img_locn', 'Order')
            ->where('company_id', $company_id)
            ->get();

        $div_code_master = DB::table('_div_code_master')
            ->where('status', '1')
            ->pluck('name', 'id');

        // ---------- Already-in-cart products ----------
        $record_product_id = DB::table('demand_order_cart')
            ->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
            ->where('demand_order_cart.dealer_id', $dealer_id)
            ->groupBy('demand_order_details_cart.product_id')
            ->pluck('product_id')->toArray();

        // ---------- Cart summary ----------
        $cart_details = DB::table('demand_order_details_cart')
            ->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')
            ->select(
                DB::raw("COUNT(demand_order_details_cart.product_id) as count"),
                DB::raw("SUM(total_rs-t1_rate-atd_rate+atd_rate) as sale_vale")
            )
            ->where('demand_order_cart.dealer_id', $dealer_id)
            ->first();

        // ---------- Conversions ----------
        $converstion_unit_item_code = DB::table('item_aum_mast')->pluck('UMTOAUM', 'ITEM_CODE');

        // ---------- Available catalog products ----------
        $catalog_product_details = DB::table('catalog_product')
            ->select('id', 'itemcode', 'name', 'catalog_id', 'quantity_per_case', 'gst_percent',
                DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"))
            ->whereNotIn('id', $record_product_id)
            ->where('status', '=', '1')
            ->where('catalog_product.company_id', $company_id)
            ->orderBy('itemcode', 'ASC')
            ->get();

        // ---------- Dealer array (CSA-linked) ----------
        $selectCsa = DB::table('dealer_person_login')
            ->where('dealer_id', $dealer_id)
            ->where('company_id', $company_id)
            ->first();

        $dealer_array = [];
        if ($selectCsa) {
            $dealer_array = DB::table('dealer')
                ->where('company_id', $company_id)
                ->where('csa_id', $selectCsa->csa_id)
                ->where('dealer_status', 1)
                ->orderBy('id', 'ASC')
                ->get()->toArray();
        }

        $checkSuper = DB::table('dealer')->where('id', $dealer_id)->first();

        // ---------- Current month order ----------
        $currMonth = date('Y-m');
        $currMonthOrder = DB::table('demand_order')
            ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            ->select(DB::raw("SUM(rate*quantity) as demand_value"))
            ->where('dealer_id', $dealer_id)
            ->whereRaw("DATE_FORMAT(demand_order.order_date ,'%Y-%m')='$currMonth'")
            ->first();

        return response()->json([
            'status'  => true,
            'message' => 'Create data fetched',
            'data'    => [
                'dealer_id'                  => $dealer_id,
                'company_id'                 => $company_id,
                'role_id'                    => $role_id,
                'csa_id'                     => $csa_id,
                'dealer_code'                => $dealer_code,
                'div_code'                   => $div_code,
                'div_code_master'            => $div_code_master,
                'image_dyn'                  => $image_dyn,
                'catalog_product_details'    => $catalog_product_details,
                'converstion_unit_item_code' => $converstion_unit_item_code,
                'sale_value_cart'            => $cart_details->sale_vale ?? '0',
                'count_prod_cart'            => $cart_details->count     ?? '0',
                'dealer_array'               => $dealer_array,
                'checkSuper'                 => $checkSuper,
                'currMonthOrder'             => $currMonthOrder,
                'quantity_type'              => $selectCsa->order_quantity_type ?? 0,
            ],
        ]);
    }

    /* ============================================================
     |  3) STORE  (insert into demand_order_cart + details)
     * ============================================================ */

    public function store(Request $request)
    {
        $ctx        = $this->context($request);
        $auth_id    = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];
        $role_id    = $ctx['role_id'];
        $dealer_id  = $ctx['dealer_id'];

        DB::beginTransaction();

        try {
            $orderDate = $request->order_date ?: date('Y-m-d');

            // ---- Resolve dealer ----
            $dealer_id_cus = $this->allowedDealerId(
                $ctx,
                $request->dealer_id_cus_select == '1' ? $dealer_id : $request->dealer_id_cus
            );
            if (!$dealer_id_cus) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'You cannot place an order for this distributor.'], 403);
            }

            // ---- Excel import path ----
            if ($request->hasFile('uploadExcelData')) {
                return $this->importFromCsv($request, $orderDate, $dealer_id_cus, $ctx);
            }

            $item_code = array_values(array_filter((array) ($request->item_code ?? []), fn ($v) => $v !== null && $v !== ''));
            $valid_ids = DB::table('catalog_product')
                ->whereIn('id', $item_code)
                ->where('company_id', $company_id)
                ->where('status', 1)
                ->pluck('id')
                ->map(fn ($v) => (string) $v)
                ->toArray();
            $invalid = array_values(array_diff(array_map('strval', $item_code), $valid_ids));
            if (!empty($invalid)) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid product id(s): ' . implode(', ', $invalid),
                    'data'    => ['invalid_ids' => $invalid],
                ], 422);
            }

            // ---- Determine sale_div_code / status ----
            if ($role_id == 37) {
                $depo_dealer_status = 'DO';
                $sale_div_code      = $request->sale_div_code ?: 'JH';
            } elseif ($role_id == 1) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Admin cannot place order here'], 403);
            } else {
                $depo_dealer_status = 'SO';
                $row = DB::table('dealer_person_login')->select('div_code_main as DIV_CODE')->where('dealer_id', $dealer_id_cus)->first();
                if (empty($row->DIV_CODE)) {
                    $row = DB::table('dealer')->where('id', $dealer_id_cus)->first();
                }
                $sale_div_code = $row->DIV_CODE ?? 'JH';
            }

            $order_id = date('YmdHis') . $auth_id;

            // ---- Check existing cart ----
            $check = DB::table('demand_order_cart')->where('dealer_id', $dealer_id_cus)->first();

            if ($check) {
                $order_id   = $check->order_id;
                $uid        = $check->id;
                $order_date = $check->order_date;
                if (filled($request->final_remarks)) {
                    DB::table('demand_order_cart')->where('id', $uid)->update(['order_remark' => mb_substr((string) $request->final_remarks, 0, 150)]);
                }
            } else {
                $insert = DB::table('demand_order_cart')->insertGetId([
                    'order_id'          => $order_id,
                    'dealer_id'         => $dealer_id_cus,
                    'created_date'      => $orderDate . ' ' . date('H:i:s'),
                    'order_date'        => $orderDate,
                    'created_person_id' => $auth_id,
                    'date_time'         => $orderDate . ' ' . date('H:i:s'),
                    'company_id'        => $company_id,
                    'sale_div_code'     => $sale_div_code,
                    'csa_id'            => $ctx['csa_id'],
                    'order_remark'      => mb_substr((string) ($request->final_remarks ?? ''), 0, 150),
                    'total_value'       => 0,
                    'vr_no_define'      => $depo_dealer_status,
                ]);
                $uid = $insert;
            }

            // ---- Update-in-place of a single row ----
            if ($request->update_qty_status == '1') {
                $update_qty = (float) $request->update_qty;
                $line = DB::table('demand_order_details_cart')
                    ->where('product_id', $request->update_product_id)
                    ->where('order_id', $order_id)
                    ->first();
                if (!$line || $update_qty <= 0) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => $line ? 'Quantity must be greater than 0.' : 'Item not found in your cart.'], 422);
                }
                $base = $this->dealerRate($ctx, $line->product_id, $request->for_self_for_db);
                $baseRate = $base['rate'] ?? (float) $line->rate;
                $scheme = $this->dealerScheme($ctx, $line->product_id, $update_qty, $baseRate);
                $total = $this->lineTotal($scheme['rate'], $update_qty, $scheme['percent']);
                $discount = $this->commonDiscountFunction($dealer_id, $line->product_id, $total);
                DB::table('demand_order_details_cart')
                    ->where('id', $line->id)
                    ->update([
                        'rate'     => $scheme['rate'],
                        'quantity' => $update_qty,
                        'free_qty' => 0,
                        'total_rs' => $total,
                        'order_converstion_unit' => $line->order_unit === 'PCS' ? $update_qty : $line->order_converstion_unit,
                        't1_rate'  => $discount[1] ?? '0',
                        'atd_rate' => $discount[0] ?? '0',
                        't1_rate_perc' => $discount[2] ?? '0',
                    ]);
            }

            // ---- Insert new rows ----
            $raw_items = (array) ($request->item_code ?? []);
            $qty       = array_values((array) ($request->qty ?? []));
            $remarks   = array_values((array) ($request->remarks ?? []));
            $raw_items = array_values($raw_items);

            $in_cart = DB::table('demand_order_details_cart')
                ->where('order_id', $order_id)
                ->pluck('product_id')
                ->map(fn ($v) => (string) $v)
                ->toArray();

            $rows = [];
            foreach ($raw_items as $k => $value) {
                $line_qty = (float) ($qty[$k] ?? 0);
                if ($value === null || $value === '' || $line_qty <= 0) {
                    continue;
                }
                if (in_array((string) $value, $in_cart, true)) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => "Product {$value} is already in your cart."], 422);
                }
                $in_cart[] = (string) $value;

                $base = $this->dealerRate($ctx, $value, $request->for_self_for_db);
                if (!$base || $base['rate'] <= 0) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => "Rate not found for product {$value}."], 422);
                }
                $scheme   = $this->dealerScheme($ctx, $value, $line_qty, $base['rate']);
                $line_total = $this->lineTotal($scheme['rate'], $line_qty, $scheme['percent']);

                if ($base['um'] === 'PCS') {
                    $order_conv = $line_qty;
                } else {
                    $cd         = DB::table('item_aum_mast')->where('ITEM_CODE', $value)->first();
                    $order_conv = ($cd->UMTOAUM ?? 0) * $line_qty;
                }

                $discount = $this->commonDiscountFunction($dealer_id, $value, $line_total);

                $rows[] = [
                    'order_id'                  => $order_id,
                    'product_id'                => $value,
                    'rate'                      => $scheme['rate'],
                    'mrp'                       => $base['mrp'],
                    'quantity'                  => $line_qty,
                    'scheme_qty'                => 0,
                    'free_qty'                  => 0,
                    'order_unit'                => $base['um'],
                    'order_converstion_unit'    => $order_conv,
                    'purchase_inv'              => '',
                    'mfg_date'                  => '0000-00-00',
                    'expiry_date'               => '0000-00-00',
                    'receive_date'              => '0000-00-00',
                    'batch_no'                  => '',
                    'pr_rate'                   => '',
                    'gst'                       => '',
                    'cases'                     => '',
                    'total_rs'                  => $line_total,
                    'remarks'                   => mb_substr((string) ($remarks[$k] ?? ''), 0, 25),
                    'scheme_qty_with_free_qty'  => '0',
                    't1_rate'                   => $discount[1] ?? '0',
                    'atd_rate'                  => $discount[0] ?? '0',
                    't1_rate_perc'              => $discount[2] ?? '0',
                ];
            }

            if ($rows) {
                DB::table('demand_order_details_cart')->insert($rows);
                DB::table('demand_order_cart')->where('id', $uid)->update([
                    'total_value' => DB::table('demand_order_details_cart')->where('order_id', $order_id)->sum('total_rs'),
                ]);
            }

            DB::commit();

            // ---------- Return grouped cart payload ----------
            $final_out = $this->buildCartPayload($dealer_id_cus, $uid, $company_id);

            return response()->json([
                'status'  => true,
                'message' => 'Cart updated',
                'data'    => [
                    'uid'             => $uid,
                    'order_id'        => 'B-' . $uid,
                    'order_date'      => $order_date ?? $orderDate,
                    'dealer_id_cus'   => $dealer_id_cus,
                    'final_out'       => $final_out,
                ],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Cart update failed',
                'error'   => safe_error_message($e),
            ], 500);
        }
    }

    /** CSV variant of store() */
    private function importFromCsv(Request $request, $orderDate, $dealer_id_cus, array $ctx)
    {
        $file = $request->file('uploadExcelData');
        if (!$file || !($fh = fopen($file->getRealPath(), 'r'))) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Invalid CSV file'], 422);
        }

        $company_id = $ctx['company_id'];
        $order_id   = date('YmdHis') . $ctx['dealer_id'];
        $lines      = [];
        $skipped    = [];
        $seen       = [];
        $line_no    = 1;

        fgetcsv($fh, 1000, ",");
        while (($row = fgetcsv($fh, 1000, ",")) !== false) {
            $line_no++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($row) !== 3) {
                $skipped[] = "Row {$line_no}: expected 3 columns (SNO, Product Id, Quantity).";
                continue;
            }
            $product_id = trim((string) $row[1]);
            $quantity   = (float) str_replace(',', '', trim((string) $row[2]));

            $product = DB::table('catalog_product')
                ->where('id', $product_id)
                ->first(['name', 'company_id', 'status']);
            $problem = match (true) {
                !$product || (int) $product->company_id !== (int) $company_id => "product {$product_id} not found.",
                (int) $product->status !== 1 => "product {$product_id} ({$product->name}) is not active.",
                $quantity <= 0 => "quantity for product {$product_id} must be greater than 0.",
                isset($seen[$product_id]) => "product {$product_id} is already in row {$seen[$product_id]}.",
                default => null,
            };
            if (!$problem) {
                $base = $this->dealerRate($ctx, $product_id);
                if (!$base || $base['rate'] <= 0) {
                    $problem = "rate not found for product {$product_id} ({$product->name}).";
                }
            }
            if ($problem) {
                $skipped[] = "Row {$line_no}: {$problem}";
                continue;
            }
            $seen[$product_id] = $line_no;
            $scheme = $this->dealerScheme($ctx, $product_id, $quantity, $base['rate']);
            $total  = $this->lineTotal($scheme['rate'], $quantity, $scheme['percent']);

            $lines[] = [
                'order_id'                  => $order_id,
                'product_id'                => $product_id,
                'rate'                      => $scheme['rate'],
                'mrp'                       => $base['mrp'],
                'quantity'                  => $quantity,
                'scheme_qty'                => 0,
                'free_qty'                  => '0',
                'order_unit'                => $base['um'],
                'order_converstion_unit'    => $base['um'] === 'PCS' ? $quantity : 0,
                'purchase_inv'              => '',
                'mfg_date'                  => '0000-00-00',
                'expiry_date'               => '0000-00-00',
                'receive_date'              => '0000-00-00',
                'batch_no'                  => '',
                'pr_rate'                   => '0',
                'gst'                       => '0',
                'cases'                     => '0',
                'total_rs'                  => $total,
                'remarks'                   => '',
                'scheme_qty_with_free_qty'  => '0',
                't1_rate'                   => '0',
                'atd_rate'                  => '0',
                't1_rate_perc'              => '0',
            ];
        }
        fclose($fh);

        if (!$lines) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => $skipped ? 'No item was ordered. ' . implode(' ', $skipped) : 'The file has no order rows.',
                'skipped' => $skipped,
            ], 422);
        }

        DB::table('demand_order_details')->insert($lines);
        DB::table('purchase_order_details')->insert(array_map(fn ($l) => [
            'id'         => $order_id,
            'order_id'   => $order_id,
            'product_id' => $l['product_id'],
            'mrp'        => $l['mrp'],
            'rate'       => $l['rate'],
            'quantity'   => $l['quantity'],
            'scheme_qty' => 0,
            'cases'      => 0,
            'pcs'        => 0,
            'pr_rate'    => 0,
            'company_id' => $company_id,
        ], $lines));

        DB::table('demand_order')->insert([
            'order_id'          => $order_id,
            'dealer_id'         => $dealer_id_cus,
            'company_id'        => $company_id,
            'created_date'      => $orderDate . ' ' . date('H:i:s'),
            'order_date'        => $orderDate,
            'created_person_id' => $ctx['dealer_id'],
            'date_time'         => $orderDate . ' ' . date('H:i:s'),
            'sale_div_code'     => 'JH',
            'csa_id'            => $ctx['csa_id'],
            'order_remark'      => '',
            'total_value'       => array_sum(array_column($lines, 'total_rs')),
            'vr_no_define'      => '',
        ]);

        DB::table('purchase_order')->insert([
            'id'                       => $order_id,
            'order_id'                 => $order_id,
            'dealer_id'                => $dealer_id_cus,
            'created_date'             => $orderDate . ' ' . date('H:i:s'),
            'created_person_id'        => $ctx['dealer_id'],
            'sale_date'                => $orderDate,
            'order_date'               => $orderDate,
            'receive_date'             => null,
            'date_time'                => $orderDate . ' ' . date('H:i:s'),
            'company_id'               => $company_id,
            'vehicle_id'               => 11,
            'dms_manual_order_status'  => 0,
            'dms_order_reason_id'      => 2,
            'd_palnt_id'               => 0,
            'dms_order_confirm_remaks' => 'NA',
        ]);

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Order uploaded successfully (' . count($lines) . ' items).' . ($skipped ? ' Not ordered: ' . implode(' ', $skipped) : ''),
            'skipped' => $skipped,
            'data'    => ['order_id' => $order_id],
        ]);
    }

    /* ============================================================
     |  4) STORE-FINAL  (cart → demand_order + purchase_order)
     * ============================================================ */

    public function storeFinal(Request $request)
    {
        $ctx        = $this->context($request);
        $auth_id    = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];
        $role_id    = $ctx['role_id'];
        $csa_id     = $ctx['csa_id'];

        $order_id_for_use = $request->order_id_for_use;

        $head = DB::table('demand_order_cart')->where('id', $order_id_for_use)->first();
        if (!$head || $this->allowedDealerId($ctx, $head->dealer_id) !== (int) $head->dealer_id) {
            return response()->json(['status' => false, 'message' => 'Cart not found'], 404);
        }
        $order_date = date('Y-m-d', strtotime($request->order_date ?: $head->order_date));

        $lines = DB::table('demand_order_details_cart')
            ->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')
            ->select('demand_order_details_cart.*')
            ->where('demand_order_cart.id', $order_id_for_use)
            ->groupBy('demand_order_details_cart.id')
            ->get();

        $dealer_id = $head->dealer_id;
        $sale_div  = $head->sale_div_code ?? '';

        if ($role_id == 37) {
            $vr_status = 'DO';
        } elseif ($role_id == 1) {
            return response()->json(['status' => false, 'message' => 'Admin cannot finalize'], 403);
        } else {
            $vr_status = 'SO';
        }

        $order_id = date('YmdHis') . $auth_id;

        $detailRows = [];
        $poRows     = [];
        $total_rs   = [];

        foreach ($lines as $v) {
            $total_rs[] = $v->total_rs;
            $detailRows[] = [
                'order_id'                  => $order_id,
                'product_id'                => $v->product_id,
                'rate'                      => $v->rate,
                'mrp'                       => $v->mrp,
                'quantity'                  => $v->quantity,
                'scheme_qty'                => $v->scheme_qty,
                'free_qty'                  => $v->free_qty,
                'order_unit'                => $v->order_unit,
                'order_converstion_unit'    => $v->order_converstion_unit,
                'purchase_inv'              => $v->purchase_inv,
                'mfg_date'                  => $v->mfg_date,
                'expiry_date'               => $v->expiry_date,
                'receive_date'              => $v->receive_date,
                'batch_no'                  => $v->batch_no,
                'pr_rate'                   => $v->pr_rate,
                'gst'                       => $v->gst,
                'cases'                     => $v->cases,
                'total_rs'                  => $v->total_rs,
                'remarks'                   => $v->remarks,
                'scheme_qty_with_free_qty'  => $v->scheme_qty_with_free_qty,
                't1_rate'                   => $v->t1_rate,
                'atd_rate'                  => $v->atd_rate,
                't1_rate_perc'              => $v->t1_rate_perc,
            ];
            $poRows[] = [
                'id'         => $order_id,
                'order_id'   => $order_id,
                'product_id' => $v->product_id,
                'mrp'        => $v->mrp,
                'rate'       => $v->rate,
                'quantity'   => $v->quantity,
                'scheme_qty' => $v->scheme_qty,
                'cases'      => $v->cases,
                'pcs'        => $v->pcs ?? 0,
                'pr_rate'    => $v->pr_rate,
                'company_id' => $company_id,
            ];
        }

        if (!$detailRows) {
            return response()->json(['status' => false, 'message' => 'Empty cart'], 422);
        }

        $available = DB::table('catalog_product')
            ->whereIn('id', $lines->pluck('product_id')->all())
            ->where('company_id', $company_id)
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($v) => (string) $v)
            ->all();
        $unavailable = $lines->reject(fn ($v) => in_array((string) $v->product_id, $available, true))->pluck('product_id')->all();
        if ($unavailable) {
            $names = DB::table('catalog_product')->whereIn('id', $unavailable)->pluck('name')->all() ?: $unavailable;
            return response()->json([
                'status'  => false,
                'message' => 'Remove items that are not available for your company before processing: ' . implode(', ', $names),
            ], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('demand_order_details')->insert($detailRows);
            DB::table('purchase_order_details')->insert($poRows);

            DB::table('demand_order')->insert([
                'order_id'          => $order_id,
                'dealer_id'         => $dealer_id,
                'created_date'      => $order_date . ' ' . date('H:i:s'),
                'order_date'        => $order_date,
                'created_person_id' => $auth_id,
                'date_time'         => $order_date . ' ' . date('H:i:s'),
                'company_id'        => $company_id,
                'sale_div_code'     => $sale_div,
                'csa_id'            => $csa_id,
                'order_remark'      => $head->order_remark ?? '',
                'total_value'       => array_sum($total_rs),
                'vr_no_define'      => $vr_status,
            ]);

            DB::table('purchase_order')->insert([
                'id'                       => $order_id,
                'order_id'                 => $order_id,
                'dealer_id'                => $dealer_id,
                'created_date'             => $order_date . ' ' . date('H:i:s'),
                'created_person_id'        => $auth_id,
                'sale_date'                => $order_date,
                'order_date'               => $order_date,
                'receive_date'             => null,
                'date_time'                => $order_date . ' ' . date('H:i:s'),
                'company_id'               => $company_id,
                'vehicle_id'               => 11,
                'dms_manual_order_status'  => 0,
                'dms_order_reason_id'      => 2,
                'd_palnt_id'               => 0,
                'dms_order_confirm_remaks' => 'NA',
            ]);

            // wipe cart
            DB::table('demand_order_details_cart')
                ->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')
                ->where('demand_order_cart.id', $order_id_for_use)
                ->delete();

            DB::table('demand_order_cart')->where('id', $order_id_for_use)->delete();

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Order finalized',
                'data'    => ['order_id' => $order_id],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Finalize failed',
                'error'   => safe_error_message($e),
            ], 500);
        }
    }

    /* ============================================================
     |  5) CART MODAL DATA
     * ============================================================ */

    public function cartDataForModal(Request $request)
    {
        $ctx           = $this->context($request);
        $company_id    = $ctx['company_id'];
        $dealer_id_cus = $this->allowedDealerId($ctx, $request->dealer_id_cus);
        if (!$dealer_id_cus) {
            return response()->json(['status' => false, 'message' => 'You cannot view this distributor\'s cart.'], 403);
        }

        $head = DB::table('demand_order_cart')
            ->join('dealer', 'dealer.id', '=', 'demand_order_cart.dealer_id')
            ->select('dealer.*', 'demand_order_cart.id as ppid', 'demand_order_cart.order_remark as cart_remark')
            ->where('demand_order_cart.dealer_id', $dealer_id_cus)
            ->first();

        $uid        = $head->ppid ?? '';
        $order_date = '';
        $final_out  = [];

        if ($uid) {
            $dealer = DB::table('dealer')->where('id', $head->dealer_code ?? 0)->first();
            $final_out = $this->buildCartPayload($dealer_id_cus, $uid, $company_id, $order_date);
        }

        return response()->json([
            'status' => true,
            'message'=> 'Cart data',
            'data'   => [
                'uid'         => $uid,
                'order_id'    => 'B-' . $uid,
                'order_date'  => $order_date,
                'party_name'  => $head->name ?? '',
                'order_remark' => $head->cart_remark ?? '',
                'final_out'   => $final_out,
                'dealer_id_cus' => $dealer_id_cus,
            ],
        ]);
    }

    /**
     * Build the nested cart payload (grouped by catalog_2).
     */
    private function buildCartPayload($dealer_id_cus, $uid, $company_id, &$order_date = null)
{
    // 1) All rows query in one go (with category)
    $rows = DB::table('demand_order_cart')
        ->join('demand_order_details_cart',
               'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
        ->leftJoin('catalog_product',
               'catalog_product.id', '=', 'demand_order_details_cart.product_id')
        ->leftJoin('catalog_2',
               'catalog_2.id', '=', 'catalog_product.catalog_id')
        ->select(
            'demand_order_cart.order_id     as cart_order_id',
            'demand_order_cart.order_date   as cart_order_date',
            'demand_order_details_cart.*',
            'demand_order_details_cart.id   as pid',
            ...$this->orderLineColumns()
        )
        ->where('demand_order_cart.dealer_id', $dealer_id_cus)
        ->where('demand_order_cart.id', $uid)
        ->orderBy('catalog_2.id')
        ->orderBy('demand_order_details_cart.id')
        ->get();

    // 2) Group on the basis of catalog_2 
    $order_date = $rows->first()->cart_order_date ?? $order_date;

    return $this->groupOrderLines($rows);
}

    private function orderLineColumns(): array
    {
        return [
            'catalog_product.id                as cp_id',
            'catalog_product.name              as cp_name',
            'catalog_product.quantity_per_case as cp_qty_per_case',
            'catalog_product.gst_percent       as cp_gst',
            'catalog_2.id                      as cat2_id',
            'catalog_2.name                    as cat2_name',
        ];
    }

    private function groupOrderLines($rows): array
{
    $final_out = [];
    foreach ($rows as $v) {
        $catId   = $v->cat2_id   ?: 'uncategorized';
        $catName = $v->cat2_name ?: 'Uncategorized';

        if (!isset($final_out[$catId])) {
            $final_out[$catId] = ['name' => $catName, 'rows' => []];
        }

        $final_out[$catId]['rows'][] = [
            'total_rs'               => $v->total_rs,
            'pid'                    => $v->pid,
            'order_id'               => $v->cart_order_id,
            'order_converstion_unit' => $v->order_converstion_unit,
            'ITEM_CODE'              => $v->cp_id ?? $v->product_id,
            'ITEM_NAME'              => $v->cp_name ?? ('Product #' . $v->product_id),
            'rate'                   => $v->rate,
            'order_unit'             => $v->order_unit,
            'quantity'               => $v->quantity,
            'quantity_per_case'      => $v->cp_qty_per_case,
            'gst_percent'            => $v->cp_gst ?? '0',
            'free_qty'               => $v->free_qty,
            'remarks'                => $v->remarks,
            't1_rate'                => $v->t1_rate,
            'atd_rate'               => $v->atd_rate,
        ];
    }

    return $final_out;
}

    /* ============================================================
     |  6) DELETE CART ITEM
     * ============================================================ */

    public function deleteCartItem(Request $request)
    {
        $request->validate([
            'id'         => 'required|integer',
            'order_id'   => 'required|integer',
            'product_id' => 'required|integer',
        ]);

        $ctx = $this->context($request);

        $cart = DB::table('demand_order_cart')->where('id', $request->order_id)->first();
        if (!$cart || $this->allowedDealerId($ctx, $cart->dealer_id) !== (int) $cart->dealer_id) {
            return response()->json(['status' => false, 'message' => 'Cart not found'], 404);
        }

        DB::table('demand_order_details_cart')
            ->where('product_id', $request->product_id)
            ->where('id', $request->id)
            ->where('order_id', $cart->order_id)
            ->delete();
        DB::table('demand_order_cart')->where('id', $cart->id)->update([
            'total_value' => DB::table('demand_order_details_cart')->where('order_id', $cart->order_id)->sum('total_rs'),
        ]);

        $final_out  = $this->buildCartPayload($cart->dealer_id, $request->order_id, $ctx['company_id'], $order_date);
        $head       = DB::table('demand_order_cart')->where('id', $request->order_id)->first();

        return response()->json([
            'status'  => true,
            'message' => 'Item removed',
            'data'    => [
                'uid'           => $request->order_id,
                'order_id'      => 'B-' . $request->order_id,
                'order_date'    => $head->order_date ?? '',
                'dealer_id_cus' => $head->dealer_id  ?? $ctx['dealer_id'],
                'final_out'     => $final_out,
            ],
        ]);
    }

    /* ============================================================
     |  7) EDIT (perfoma view of a demand_order)
     * ============================================================ */

    public function edit(Request $request, $id)
    {
        $ctx        = $this->context($request);
        $company_id = $ctx['company_id'];

        // encrypted id from UI → decrypt
        try { $uid = Crypt::decryptString($id); } catch (\Throwable $e) { $uid = (int) $id; }

        if (!$this->canAccessOrder($ctx, DB::table('demand_order')->where('id', $uid)->first())) {
            return response()->json(['status' => false, 'message' => 'Order not found'], 404);
        }

        $converstion_unit_item_code = DB::table('catalog_product')
            ->where('company_id', $company_id)
            ->groupBy('id')
            ->pluck('quantity_per_case', 'id');

        $order = DB::table('demand_order')->where('id', $uid)->first();
        $head  = DB::table('dealer')->where('id', $order->dealer_id)->first();

        $rows = DB::table('demand_order_details')
            ->leftJoin('catalog_product', 'catalog_product.id', '=', 'demand_order_details.product_id')
            ->leftJoin('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            ->select('demand_order_details.*', 'demand_order_details.id as pid', 'demand_order_details.order_id as cart_order_id', ...$this->orderLineColumns())
            ->where('demand_order_details.order_id', (string) $order->order_id)
            ->orderBy('catalog_2.id')
            ->orderBy('demand_order_details.id')
            ->get();

        $final_out      = $this->groupOrderLines($rows);
        $mktg_cat_array = array_map(fn ($g) => $g['name'], $final_out);
        $order_date     = $order->order_date ?? '';

        $currMonth      = date('Y-m');
        $checkSuper     = DB::table('dealer')->where('dealer.company_id', $company_id)->where('id', $ctx['dealer_id'])->first();
        $currMonthOrder = DB::table('demand_order')
            ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            ->select(DB::raw("SUM(rate*quantity) as demand_value"))
            ->where('dealer_id', $ctx['dealer_id'])
            ->whereRaw("DATE_FORMAT(demand_order.order_date ,'%Y-%m')='$currMonth'")
            ->first();

        return response()->json([
            'status'  => true,
            'message' => 'Order detail',
            'data'    => [
                'order_id'       => 'B-' . $uid,
                'party_name'     => $head->name ?? '',
                'order_remark'   => $order->order_remark ?? '',
                'order_date'     => $order_date,
                'mktg_cat_array' => $mktg_cat_array,
                'final_out'      => $final_out,
                'converstion_unit_item_code' => $converstion_unit_item_code,
                'checkSuper'     => $checkSuper,
                'currMonthOrder' => $currMonthOrder,
            ],
        ]);
    }

    public function invoiceDetails(Request $request)
    {
        $ctx = $this->context($request);

        $from_date = $request->filled('from_date') ? date('Y-m-d', strtotime($request->from_date)) : date('Y-m-d');
        $to_date   = $request->filled('to_date') ? date('Y-m-d', strtotime($request->to_date)) : date('Y-m-d');
        if ($from_date < '2021-04-01') {
            $from_date = '2021-04-01';
        }

        $q = DB::table('purchase_order')
            ->join('purchase_order_details', 'purchase_order_details.order_id', '=', 'purchase_order.order_id')
            ->select(
                'purchase_order.invoice_no_p',
                DB::raw('MAX(purchase_order.sale_date) as sale_date'),
                DB::raw("GROUP_CONCAT(DISTINCT purchase_order_details.order_id ORDER BY purchase_order_details.order_id SEPARATOR ', ') as order_ids"),
                DB::raw("MAX(NULLIF(purchase_order.remarks, '')) as remarks"),
                DB::raw("MAX(NULLIF(NULLIF(purchase_order.pdf_name2, ''), '0')) as pdf"),
                DB::raw('SUM(purchase_order_details.inv_busy_amt) as amount')
            )
            ->where('purchase_order.dealer_id', $ctx['dealer_id'])
            ->whereNotIn('purchase_order.invoice_no_p', ['0', ''])
            ->whereDate('purchase_order.sale_date', '>=', $from_date)
            ->whereDate('purchase_order.sale_date', '<=', $to_date);

        if ($request->filled('ref_no')) {
            $ref = '%' . trim((string) $request->ref_no) . '%';
            $q->where(function ($w) use ($ref) {
                $w->where('purchase_order.invoice_no_p', 'LIKE', $ref)
                  ->orWhere('purchase_order_details.order_id', 'LIKE', $ref);
            });
        }

        $rows = $q->groupBy('purchase_order.invoice_no_p')
            ->orderBy('sale_date', 'DESC')
            ->orderBy('purchase_order.invoice_no_p', 'ASC')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => [
                'from_date' => $from_date,
                'to_date'   => $to_date,
                'rows'      => $rows,
            ],
        ]);
    }

    /* ============================================================
     |  8) PDF (perfoma) + EMAIL
     * ============================================================ */

    // public function downloadPdf(Request $request, $id)
    // {
        // $ctx        = $this->context($request);
        // $company_id = $ctx['company_id'];

        // try { $uid = Crypt::decryptString($id); } catch (\Throwable $e) { $uid = $id; }

        // $head = DB::table('demand_order')
            // ->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')
            // ->select('dealer.*', 'demand_order.order_remark as order_remark',
                // 'demand_order.id as pid', 'demand_order.order_date as order_date')
            // ->where('demand_order.order_id', $uid)
            // ->first();

        // if (!$head || !$this->canAccessOrder($ctx, DB::table('demand_order')->where('order_id', $uid)->first())) {
            // return response()->json(['status' => false, 'message' => 'Order not found'], 404);
        // }

        // $mktg_cat_array = DB::table('catalog_2')
            // ->where('company_id', $company_id)
            // ->orderBy('name', 'ASC')
            // ->pluck('name', 'id');

        // $final_out = [];
        // foreach ($mktg_cat_array as $catId => $catName) {
            // $rows = DB::table('demand_order')
                // ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
                // ->join('catalog_product', 'catalog_product.id', '=', 'demand_order_details.product_id')
                // ->where('catalog_product.catalog_id', $catId)
                // ->where('catalog_product.company_id', $company_id)
                // ->where('demand_order.order_id', $uid)
                // ->groupBy('demand_order_details.id')
                // ->get();

            // $out_2 = [];
            // foreach ($rows as $v) {
                // $out_2[] = [
                    // 'total_rs'                => $v->total_rs,
                    // 'order_converstion_unit'  => $v->order_converstion_unit,
                    // 'ITEM_CODE'               => $v->itemcode,
                    // 'ITEM_NAME'               => $v->name,
                    // 'rate'                    => $v->rate,
                    // 'order_unit'              => $v->order_unit,
                    // 'quantity'                => $v->quantity,
                    // 'free_qty'                => $v->free_qty,
                    // 'remarks'                 => $v->remarks,
                    // 't1_rate'                 => $v->t1_rate,
                    // 'atd_rate'                => $v->atd_rate,
                // ];
            // }
            // $final_out[$catId] = ['name' => $catName, 'rows' => $out_2];
        // }

        // $pdf = PDF::loadView('DMS.QuickOrder.perfoma_pdf', [
            // 'party_name'    => $head->name ?? '',
            // 'order_remark'  => $head->order_remark ?? '',
            // 'order_id'      => 'B-' . $head->pid,
            // 'order_date'    => $head->order_date,
            // 'mktg_cat_array'=> $mktg_cat_array,
            // 'final_out'     => $final_out,
        // ]);

        // // email to configured recipient
        // if ($request->boolean('email', false)) {
            // $to = $company_id == 206 ? 'financesupport@nutraj.com' : 'rohit.k@manacleindia.com';
            // $mail = DB::table('mail_master')->where('company_id', $company_id)->first();
            // if ($mail && !empty($mail->mail)) {
                // config([
                    // 'mail.username' => $mail->mail,
                    // 'mail.password' => $mail->password_key,
                    // 'mail.from.address' => $mail->mail,
                // ]);
                // Mail::send('DMS.QuickOrder.perfoma_pdf', [
                    // 'party_name'    => $head->name ?? '',
                    // 'order_remark'  => $head->order_remark ?? '',
                    // 'order_id'      => 'B-' . $head->pid,
                    // 'order_date'    => $head->order_date,
                    // 'mktg_cat_array'=> $mktg_cat_array,
                    // 'final_out'     => $final_out,
                // ], function ($m) use ($to, $mail) {
                    // $m->from($mail->mail)->to($to)->subject('Report');
                // });
            // }
        // }

        // return $pdf->download('B-' . $head->pid . '.pdf');
    // }

    /* ============================================================
     |  9) SEND TO ERP
     * ============================================================ */

    public function sendToErp(Request $request)
    {
        $ctx = $this->context($request);
        if ((int) $ctx['role_id'] !== 37) {
            return response()->json(['status' => false, 'message' => 'Only a depot can send orders to ERP.'], 403);
        }

        $scope = fn (array $ids) => DB::table('demand_order')
            ->whereIn('id', $ids)
            ->where('company_id', $ctx['company_id'])
            ->where('sale_div_code', $this->resolveDivCode($ctx['dealer_id'], $ctx['dealer_code']))
            ->where('dealer_id', '<>', $ctx['dealer_id'])
            ->pluck('id')
            ->all();

        $ids       = $scope((array) $request->order_no);
        $unchecked = $scope((array) $request->checkbox_uncheck);

        if ($unchecked) {
            DB::table('demand_order')->whereIn('id', $unchecked)->update([
                'send_order_erp_status'    => 2,
                'send_order_erp_date_time' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($ids) {
            DB::table('demand_order')->whereIn('id', $ids)->update([
                'send_order_erp_status'    => 1,
                'send_order_erp_date_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return response()->json(['status' => true, 'message' => count($ids) . ' order(s) sent to ERP.']);
    }

    /* ============================================================
     |  10) PRODUCT RATE LOOKUP
     * ============================================================ */

    public function productRate(Request $request)
    {
        $ctx        = $this->context($request);
        $company_id = $ctx['company_id'];

        $item_code      = $request->item_code;
        $dealer_id_cus  = $this->allowedDealerId($ctx, $request->dealer_id_cus) ?? $ctx['dealer_id'];
        $catg           = $request->catg;
        $item_codes_str = array_filter(explode(',', (string) $request->item_codes_str));

        $blocked = DB::table('demand_order_cart')
            ->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
            ->where('dealer_id', $dealer_id_cus)
            ->groupBy('product_id')
            ->pluck('product_id');

        $productsQ = DB::table('catalog_product')
            ->where('company_id', $company_id)
            ->where('status', '=', '1')
            ->whereNotIn('id', $item_codes_str);

        if (!empty($catg)) $productsQ->where('catalog_id', $catg);
        if ($blocked->isNotEmpty()) $productsQ->whereNotIn('id', $blocked);

        $products = $productsQ->groupBy('id')->orderBy('id', 'ASC')->get(['id', 'itemcode', 'name']);

        $item_mast = DB::table('catalog_product')->where('company_id', $company_id)->where('id', $item_code)->first();
        $base      = $item_mast ? $this->dealerRate($ctx, $item_code, $request->for_self_for_db) : null;

        if (!$base) {
            return response()->json([
                'status'  => false,
                'code'    => 401,
                'message' => 'Rate not found',
                'data'    => ['products' => $products],
            ]);
        }

        $qty    = (float) $request->input('qty', 0);
        $scheme = $this->dealerScheme($ctx, $item_code, $qty, $base['rate']);

        return response()->json([
            'status'  => true,
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'result'              => $base['rate'],
                'item_mast_name'      => $item_mast->name ?? '',
                'item_aum_mast_array' => $products,
                'selection_size_um'   => $base['um'],
                'case_size'           => $item_mast->quantity_per_case ?? null,
                'scheme_rate'         => $scheme['rate'],
                'scheme_percent'      => $scheme['percent'],
                'line_total'          => $this->lineTotal($scheme['rate'], $qty, $scheme['percent']),
            ],
        ]);
    }

    /* ============================================================
     |  11) CHECK SCHEME
     * ============================================================ */

    // public function checkScheme(Request $request)
    // {
        // $ctx        = $this->context($request);
        // $date       = $ctx['date'];
        // $dealer_code = $request->dealer_code ?: $ctx['dealer_code'];
        // $qty         = $request->get('term', '0');
        // $unit_conf   = $request->unit_conf ?: '';
        // $item_code   = $request->item_code ?: '0';

        // // Active circulars for this dealer
        // $scheme_details = DB::table('circular_mast')
            // ->join('scheme_mast',  'scheme_mast.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
            // ->join('scheme_slab',  'scheme_slab.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
            // ->join('scheme_domain','scheme_domain.SCHEME_NO','=', 'circular_mast.SCHEME_NO')
            // ->whereRaw("DATE_FORMAT(circular_mast.from_date_condition ,'%Y-%m-%d')<='$date' AND DATE_FORMAT(circular_mast.to_date_condition,'%Y-%m-%d') >= '$date'")
            // ->where('scheme_domain.ACC_CODE', $dealer_code)
            // ->groupBy('scheme_domain.SCHEME_NO')
            // ->get();

        // $scheme_no_step1 = [];
        // foreach ($scheme_details as $v) {
            // if (empty($v->CANCELLEDBY)) $scheme_no_step1[] = $v->SCHEME_NO;
        // }

        // $scheme_step1 = DB::table('circular_mast')
            // ->join('scheme_mast',  'scheme_mast.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
            // ->join('scheme_slab',  'scheme_slab.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
            // ->join('scheme_domain','scheme_domain.SCHEME_NO','=', 'circular_mast.SCHEME_NO')
            // ->whereRaw("DATE_FORMAT(circular_mast.from_date_condition ,'%Y-%m-%d')<='$date' AND DATE_FORMAT(circular_mast.to_date_condition,'%Y-%m-%d') >= '$date'")
            // ->whereIn('scheme_domain.SCHEME_NO', $scheme_no_step1)
            // ->where('scheme_domain.ITEM_CODE', $item_code)
            // ->groupBy('scheme_domain.SCHEME_NO')
            // ->orderBy('scheme_domain.id', 'DESC')
            // ->first();

        // if ($scheme_step1) {
            // $slabs = DB::table('scheme_slab')->where('SCHEME_NO', $scheme_step1->SCHEME_NO)->get();
        // } else {
            // $prodCodes = DB::table('item_mast')->where('ITEM_CODE', $item_code)->pluck('PROD_CODE');
            // $item_row  = DB::table('item_mast')->where('ITEM_CODE', $item_code)->first();

            // $scheme_step1 = DB::table('circular_mast')
                // ->join('scheme_mast',  'scheme_mast.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
                // ->join('scheme_slab',  'scheme_slab.SCHEME_NO',  '=', 'circular_mast.SCHEME_NO')
                // ->join('scheme_domain','scheme_domain.SCHEME_NO','=', 'circular_mast.SCHEME_NO')
                // ->join('item_mast',    'item_mast.PROD_CODE',    '=', 'scheme_domain.PROD_CODE')
                // ->select('item_mast.ITEM_SIZE as ITEM_SIZE', 'scheme_domain.SCHEME_NO as SCHEME_NO')
                // ->whereRaw("DATE_FORMAT(circular_mast.from_date_condition ,'%Y-%m-%d')<='$date' AND DATE_FORMAT(circular_mast.to_date_condition,'%Y-%m-%d') >= '$date'")
                // ->whereIn('scheme_domain.SCHEME_NO', $scheme_no_step1)
                // ->whereIn('scheme_domain.PROD_CODE', $prodCodes)
                // ->groupBy('scheme_domain.SCHEME_NO')
                // ->orderBy('scheme_domain.id', 'DESC')
                // ->first();

            // $slabs = collect();
            // if ($scheme_step1) {
                // $slabs = DB::table('scheme_slab')
                    // ->where('SCHEME_NO', $scheme_step1->SCHEME_NO)
                    // ->where('ITEM_SIZE', $item_row->ITEM_SIZE ?? '')
                    // ->orderBy('id', 'DESC')->get();
                // if ($slabs->isEmpty()) {
                    // $slabs = DB::table('scheme_slab')
                        // ->where('SCHEME_NO', $scheme_step1->SCHEME_NO)
                        // ->orderBy('id', 'DESC')->get();
                // }
            // }
        // }

        // $conv = DB::table('item_aum_mast')->where('ITEM_CODE', $item_code)->first();
        // $converstion_unit = $conv->UMTOAUM ?? 0;

        // // Build out[] — same logic, condensed
        // $out = [];
        // $slabs_txt = [];
        // if ($slabs->isNotEmpty()) {
            // foreach ($slabs as $s) {
                // $FREE = (float) ($s->FREE_ITEM1_QTY1 ?? 0);
                // $BASE = (float) ($s->BASE_FROM ?? 0);
                // $sum  = $BASE + $FREE;
                // if ($unit_conf === 'BOX1') {
                    // $sum_inner  = $BASE * $converstion_unit;
                    // $free_inner = $FREE * $converstion_unit;
                    // $out_value  = $sum . '(' . $sum_inner . '+' . $free_inner . ')';
                // } else {
                    // $free_inner = $FREE;
                    // $out_value  = $sum . '(' . $BASE . '+' . $FREE . ')';
                // }
                // $slabs_txt[] = ' ' . $sum . ' (' . $BASE . '+' . $FREE . '= ' . $sum . ')';
                // $out[] = [
                    // 'value'         => $out_value,
                    // 'title'         => $sum,
                    // 'free_qty'      => $free_inner,
                    // 'slabs_testing' => $sum . '|' . $BASE . '|' . $FREE,
                // ];
            // }
        // } else {
            // $out_value = $unit_conf === 'BOX'
                // ? $qty . '(' . $qty * $converstion_unit . '+ 0 = ' . $qty * $converstion_unit . ')'
                // : $qty . '(' . $qty . '+ 0 = ' . $qty . ')';
            // $out[] = ['value' => $out_value, 'title' => $qty, 'free_qty' => '0', 'slabs_testing' => $qty . '|' . $qty . '|0'];
        // }

        // // Simple projected slab series — kept as in original
        // $result = [];
        // foreach ($out as $k => $v) {
            // $result[] = [
                // 'value'    => $v['value'],
                // 'title'    => $v['title'],
                // 'free_qty' => $v['free_qty'],
            // ];
        // }

        // return response()->json([
            // 'status'  => true,
            // 'code'    => 200,
            // 'message' => 'Found',
            // 'data'    => [
                // 'result' => $result,
                // 'slabs'  => implode(',', $slabs_txt),
            // ],
        // ]);
    // }

    /* ============================================================
     |  12) SCHEME AUTOCOMPLETE (product_wise_scheme_plan based)
     * ============================================================ */

    // public function schemeAutocomplete(Request $request)
    // {
        // $ctx        = $this->context($request);
        // $date       = $ctx['date'];
        // $company_id = $ctx['company_id'];
        // $dealer_id  = $ctx['dealer_id'];
        // $item_code  = $request->item_code ?: '0';
        // $qty        = (float) $request->get('term', '0');
        // $unit_conf  = $request->unit_conf ?: '';

        // $dealer = DB::table('dealer')->where('id', $dealer_id)->first();

        // $plans = DB::table('product_wise_scheme_plan_details')
            // ->join('product_wise_scheme_plan', 'product_wise_scheme_plan.id', '=', 'product_wise_scheme_plan_details.scheme_id')
            // ->join('catalog_product', 'catalog_product.id', '=', 'product_wise_scheme_plan_details.product_id')
            // ->join('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            // ->join('catalog_1', 'catalog_1.id', '=', 'catalog_2.catalog_1_id')
            // ->select(
                // 'sale_value_range_first as BASE_FROM',
                // 'sale_value_range_last  as BASE_TO',
                // 'value_amount_percentage as FREE_ITEM1_QTY1',
                // 'scheme_id as SCHEME_NO'
            // )
            // ->where('product_wise_scheme_plan_details.state_id', $dealer->state_id ?? 0)
            // ->where('catalog_product.id', $item_code)
            // ->where('incentive_type', 3)
            // ->where('product_wise_scheme_plan_details.company_id', $company_id)
            // ->whereRaw("DATE_FORMAT(valid_from_date,'%Y-%m-%d')<='$date' AND DATE_FORMAT(valid_to_date,'%Y-%m-%d')>='$date'")
            // ->groupBy('product_wise_scheme_plan_details.state_id', 'product_wise_scheme_plan_details.product_id')
            // ->get();

        // $conv = DB::table('catalog_product')->where('id', $item_code)->first();
        // $converstion_unit = $conv->quantity_per_case ?? 1;

        // if ($unit_conf === 'BOX') $qty *= $converstion_unit;

        // $result = [];
        // if ($plans->isNotEmpty()) {
            // foreach ($plans as $p) {
                // $base = (float) ($p->BASE_FROM ?? 0);
                // $free = (float) ($p->FREE_ITEM1_QTY1 ?? 0);
                // $sum  = $base + $free;

                // if ($unit_conf === 'BOX1') {
                    // $sum_inner  = $base * $converstion_unit;
                    // $free_inner = $free * $converstion_unit;
                    // $val        = $sum . '(' . $sum_inner . '+' . $free_inner . ')';
                // } else {
                    // $free_inner = $free;
                    // $val        = $sum . '(' . $base . '+' . $free . ')';
                // }

                // if ($qty <= $sum) {
                    // $result[$sum] = [
                        // 'value'    => $val,
                        // 'title'    => $sum,
                        // 'free_qty' => $free_inner,
                    // ];
                // }
            // }
            // $result = array_values($result);
        // } else {
            // // No scheme — use PUDA size progression
            // $puda = DB::table('www_web_item_display')->where('ITEM_CODE', $item_code)->first();
            // $step = (float) ($puda->PUDA_SIZE ?? 1);
            // if ($step <= 0) $step = 1;

            // $t = $qty;
            // for ($i = 0; $i < 10; $i++) {
                // $result[] = [
                    // 'value'    => $t . '(' . $t . '+0)',
                    // 'title'    => $t,
                    // 'free_qty' => 0,
                // ];
                // $t += $step;
            // }
        // }

        // return response()->json([
            // 'status'  => true,
            // 'code'    => 200,
            // 'message' => 'Found',
            // 'data'    => ['result' => $result],
        // ]);
    // }

    /* ============================================================
     |  13) COMMON DISCOUNT (private)
     * ============================================================ */

    private function commonDiscountFunction($dealer_id, $product_id, $total_rs)
    {
        $dealer      = DB::table('dealer')->where('id', $dealer_id)->first();
        $dealer_code = $dealer->dealer_code ?? '0';
        $dealer_dt   = DB::table('acc_mast')->where('ACC_CODE', $dealer_code)->first();
        if (!$dealer_dt || empty($dealer_code)) return [0, 0, 0];

        // STAX discount
        $final_stax_discount = 0;
        $stax_list = DB::table('prod_tax_mast')
            ->where('STATE_CODE', $dealer_dt->STATE_CODE)
            ->orderBy('id', 'DESC')->get();

        if ($stax_list->isNotEmpty()) {
            $first = $stax_list->first();
            $final_stax_discount = $first->STAX_RATE ?? 0;
        }

        // Dealer bill tax rate
        $final_tr_rate = 0;
        $bill_list = DB::table('dealer_billtax_mast')
            ->where('ACC_CODE', $dealer_code)
            ->orderBy('id', 'DESC')->get();

        if ($bill_list->isNotEmpty()) {
            $final_tr_rate = $bill_list->first()->TI_RATE ?? 0;
        }

        // Compute
        $gst_rate  = (float) $final_stax_discount;
        $atd_rate  = $gst_rate > 0 ? (($gst_rate * 100) / ($gst_rate + 100)) : 0;
        $tr_rate   = (float) $final_tr_rate;

        $step1        = $total_rs * $tr_rate;
        $td_discount  = $step1 / 100;
        $atd_discount = round((($total_rs - $td_discount) * $atd_rate) / 100, 2);

        return [$atd_discount, $td_discount, $tr_rate];
    }

    /* ============================================================
     |  14) REPORTS — shared dealer/div resolution
     * ============================================================ */

    private function resolveReportScope(Request $request, array $ctx): array
    {
        if ($request->check_status == '1') {
            $dealer_id_cus = $request->filled('dealer_id_cus') ? explode(',', $request->dealer_id_cus) : [];
            $terr_id       = $request->filled('terr_id')       ? explode(',', $request->terr_id)       : [];
            $div_code_cus  = $request->filled('div_code_cus')  ? explode(',', $request->div_code_cus)  : [];

            $sec = DB::table('dealer_report_section_data')
                ->where('dealer_id', $ctx['dealer_id'])
                ->where('location_2', '!=', '0')
                ->groupBy('location_2');
            if ($terr_id) $sec->whereIn('location_2', $terr_id);
            $section = $sec->take(1)->pluck('location_2')->toArray();

            $dealerQ = DB::table('dealer_location_rate_list')
                ->join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id');
            if ($dealer_id_cus) $dealerQ->whereIn('dealer_id', $dealer_id_cus);
            if ($terr_id)       $dealerQ->whereIn('l2_id', $terr_id);
            else                $dealerQ->whereIn('l2_id', $section);

            $dealer_ids = $dealerQ->groupBy('dealer_id')->pluck('dealer_id')->toArray();

            $divQ = DB::table('dealer_person_login')->whereIn('dealer_id', $dealer_ids);
            if ($div_code_cus) $divQ->whereIn('div_code_main', $div_code_cus);
            $div_code = $divQ->pluck('div_code_main')->toArray();

            $codeQ = DB::table('dealer')->whereIn('dealer.id', $dealer_ids);
            if ($div_code_cus) {
                $codeQ->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')
                      ->whereIn('div_code_main', $div_code_cus);
            }
            $dealer_code = $codeQ->pluck('dealer_code')->toArray();
        } else {
            $dealer_code = [$ctx['dealer_code']];
            $acc = DB::table('acc_mast')->where('ACC_CODE', $ctx['dealer_code'])->first();
            $login = DB::table('dealer_person_login')->where('dealer_id', $ctx['dealer_id'])->first();
            $div_code = [$acc->DIV_CODE ?? ($login->div_code_main ?? '0')];
        }

        return [$dealer_code, $div_code];
    }

    /* ---------- OTC ---------- */
    // public function otc(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'OTC report',
            // 'data'    => $this->buildMarketReport('OTC', $dealer_code, $div_code),
        // ]);
    // }

    /* ---------- OT2 ---------- */
    // public function ot2(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'OT2 report',
            // 'data'    => $this->buildMarketReport('OT2', $dealer_code, $div_code),
        // ]);
    // }

    /* ---------- Ethical (JPS) ---------- */
    // public function ethical(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Ethical report',
            // 'data'    => $this->buildMarketReport('JPS', $dealer_code, $div_code),
        // ]);
    // }

    /* ---------- FMCG ---------- */
    // public function fmcg(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'FMCG report',
            // 'data'    => $this->buildMarketReport('FMC', $dealer_code, $div_code),
        // ]);
    // }

    /* ---------- JPS ---------- */
    // public function jps(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'JPS report',
            // 'data'    => $this->buildMarketReport('JPS', $dealer_code, $div_code),
        // ]);
    // }

    /* ---------- JP2 ---------- */
    // public function jp2(Request $request)
    // {
        // $ctx = $this->context($request);
        // [$dealer_code, $div_code] = $this->resolveReportScope($request, $ctx);
        // return response()->json([
            // 'status'  => true,
            // 'message' => 'JP2 report',
            // 'data'    => $this->buildMarketReport('JP2', $dealer_code, $div_code),
        // ]);
    // }

    /**
     * Common market/category-wise target vs achievement data builder.
     */
    private function buildMarketReport(string $mktg_catg, array $dealer_code, array $div_code): array
    {
        $from_target_date = "01-APR-" . date('y');
        $to_target_date   = "31-MAR-" . (date('y') + 1);
        $from_sale_date   = date('Y') . "-04-01";
        $to_sale_date     = (date('Y') + 1) . "-03-31";
        $month_from       = date('Y-m') . '-01';
        $month_to         = date('Y-m-t');

        $quarter = DB::table('_quater_master')->where('details_month', date('Y-m'))->first();
        $q_from  = $quarter->quater_from_date ?? $month_from;
        $q_to    = $quarter->quater_to_date   ?? $month_to;

        $prod_catg_mast_array = DB::table('prod_catg_mast')
            ->where('MKTG_CATG', $mktg_catg)
            ->orderBy('USER_SEQ', 'ASC')
            ->get();

        $um_array = DB::table('prod_catg_mast')
            ->join('item_mast',     'item_mast.PROD_CATG',     '=', 'prod_catg_mast.PROD_CATG')
            ->join('item_aum_mast', 'item_aum_mast.ITEM_CODE', '=', 'item_mast.ITEM_CODE')
            ->pluck('item_aum_mast.AUM', 'prod_catg_mast.PROD_CATG');

        $targets = DB::table('dealer_target_mast')
            ->join('prod_catg_mast', 'prod_catg_mast.PROD_CATG', '=', 'dealer_target_mast.PROD_CATG')
            ->where('dealer_target_mast.FROM_DATE', $from_target_date)
            ->where('dealer_target_mast.TO_DATE',   $to_target_date)
            ->whereIn('dealer_target_mast.ACC_CODE', $dealer_code)
            ->groupBy('dealer_target_mast.PROD_CATG')
            ->pluck(DB::raw('SUM(dealer_target_mast.TARGET_QTY) as TARGET_QTY'), 'dealer_target_mast.PROD_CATG');

        $salesQtyYtd = DB::table('itemtran_body')
            ->join('itemtran_head','itemtran_head.VRNO','=','itemtran_body.VRNO')
            ->join('item_mast','item_mast.ITEM_CODE','=','itemtran_body.ITEM_CODE')
            ->join('item_aum_mast','item_aum_mast.ITEM_CODE','=','item_mast.ITEM_CODE')
            ->join('prod_catg_mast','prod_catg_mast.PROD_CATG','=','item_mast.PROD_CATG')
            ->whereRaw("date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'")
            ->whereIn('itemtran_head.DIV_CODE', $div_code)
            ->whereIn('itemtran_body.DIV_CODE', $div_code)
            ->whereIn('itemtran_head.ACC_CODE', $dealer_code)
            ->groupBy('prod_catg_mast.PROD_CATG')
            ->pluck(DB::raw('SUM(itemtran_body.QTYISSUED/item_aum_mast.UMTOAUM) as QTYISSUED'), 'prod_catg_mast.PROD_CATG');

        $salesValYtd = DB::table('itemtran_body')
            ->join('itemtran_head','itemtran_head.VRNO','=','itemtran_body.VRNO')
            ->join('item_mast','item_mast.ITEM_CODE','=','itemtran_body.ITEM_CODE')
            ->join('prod_catg_mast','prod_catg_mast.PROD_CATG','=','item_mast.PROD_CATG')
            ->whereRaw("date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'")
            ->whereIn('itemtran_head.DIV_CODE', $div_code)
            ->whereIn('itemtran_body.DIV_CODE', $div_code)
            ->whereIn('itemtran_head.ACC_CODE', $dealer_code)
            ->groupBy('prod_catg_mast.PROD_CATG')
            ->pluck(DB::raw('SUM(itemtran_body.VALISSUED) as VALISSUED'), 'prod_catg_mast.PROD_CATG');

        $salesQtyMonth = DB::table('itemtran_body')
            ->join('itemtran_head','itemtran_head.VRNO','=','itemtran_body.VRNO')
            ->join('item_mast','item_mast.ITEM_CODE','=','itemtran_body.ITEM_CODE')
            ->join('item_aum_mast','item_aum_mast.ITEM_CODE','=','item_mast.ITEM_CODE')
            ->join('prod_catg_mast','prod_catg_mast.PROD_CATG','=','item_mast.PROD_CATG')
            ->whereRaw("date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')>='$month_from' and date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')<='$month_to'")
            ->whereIn('itemtran_head.DIV_CODE', $div_code)
            ->whereIn('itemtran_body.DIV_CODE', $div_code)
            ->whereIn('itemtran_head.ACC_CODE', $dealer_code)
            ->groupBy('prod_catg_mast.PROD_CATG')
            ->pluck(DB::raw('SUM(itemtran_body.QTYISSUED/item_aum_mast.UMTOAUM) as QTYISSUED'), 'prod_catg_mast.PROD_CATG');

        $salesQtyQuat = DB::table('itemtran_body')
            ->join('itemtran_head','itemtran_head.VRNO','=','itemtran_body.VRNO')
            ->join('item_mast','item_mast.ITEM_CODE','=','itemtran_body.ITEM_CODE')
            ->join('item_aum_mast','item_aum_mast.ITEM_CODE','=','item_mast.ITEM_CODE')
            ->join('prod_catg_mast','prod_catg_mast.PROD_CATG','=','item_mast.PROD_CATG')
            ->whereRaw("date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')>='$q_from' and date_format(itemtran_body.VRDATE_FILTER,'%Y-%m-%d')<='$q_to'")
            ->whereIn('itemtran_head.DIV_CODE', $div_code)
            ->whereIn('itemtran_body.DIV_CODE', $div_code)
            ->whereIn('itemtran_head.ACC_CODE', $dealer_code)
            ->groupBy('prod_catg_mast.PROD_CATG')
            ->pluck(DB::raw('SUM(itemtran_body.QTYISSUED/item_aum_mast.UMTOAUM) as QTYISSUED'), 'prod_catg_mast.PROD_CATG');

        return [
            'prod_catg_mast_array'              => $prod_catg_mast_array,
            'um_array'                          => $um_array,
            'prod_wise_target_mast'             => $targets,
            'prod_wise_sales_mast_data_qty'     => $salesQtyYtd,
            'prod_wise_sales_mast_data_val'     => $salesValYtd,
            'month_prod_wise_sales_mast_data_qty' => $salesQtyMonth,
            'quad_prod_wise_sales_mast_data_qty'  => $salesQtyQuat,
        ];
    }

    /* ---------- Sale Statement ---------- */
    // public function saleStatement(Request $request)
    // {
        // $ctx = $this->context($request);

        // $dms_division = DB::table('mktg_catg_mast')
            // ->where('flag', '=', 'Y')
            // ->pluck('MKTG_CATG_NAME', 'MKTG_CATG');

        // $locQ = DB::table('location_2')->where('status', 1);
        // if ($ctx['role_id'] != 1) {
            // $locQ->join('dealer_report_section_data', 'dealer_report_section_data.location_2', '=', 'location_2.id')
                 // ->where('dealer_id', $ctx['dealer_id']);
        // }
        // $locations = $locQ->groupBy('location_2.id')->pluck('location_2.name', 'location_2.id');

        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Sale statement meta',
            // 'data'    => [
                // 'dms_division' => $dms_division,
                // 'locations'    => $locations,
            // ],
        // ]);
    // }

    // public function saleStatementEthical(Request $request)
    // {
        // return $this->saleStatement($request);
    // }

    /* ---------- DMS to Tally report ---------- */
    // public function dmsToTally(Request $request)
    // {
        // $ctx        = $this->context($request);
        // $company_id = $ctx['company_id'];
        // $dealer_id  = $ctx['dealer_id'];

        // if (!$request->filled('date_range_picker')) {
            // return response()->json(['status' => false, 'message' => 'date_range_picker required'], 422);
        // }
        // $parts     = explode(" -", $request->date_range_picker);
        // $from_date = date('Y-m-d', strtotime(trim($parts[0])));
        // $to_date   = date('Y-m-d', strtotime(trim($parts[1])));

        // $q = DB::table('challan_order')
            // ->join('challan_order_details', 'challan_order_details.ch_id', '=', 'challan_order.id')
            // ->join('retailer', 'retailer.id', '=', 'challan_order.ch_retailer_id')
            // ->join('dealer',   'dealer.id',   '=', 'challan_order.ch_dealer_id')
            // ->select(
                // 'ch_retailer_id as retailer_id',
                // 'ch_date as challan_date',
                // 'state_id_retailer',
                // 'product_id',
                // 'hsn_code',
                // 'retailer.name as retailer_name',
                // 'challan_order.ch_no as challan_id',
                // 'dealer.state_id as state_id_dealer',
                // 'product_rate', 'qty', 'free_qty',
                // 'retailer.tin_no as gst_no',
                // 'taxable_amt'
            // )
            // ->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') >= '$from_date' AND DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') <= '$to_date'")
            // ->where('retailer.company_id', $company_id)
            // ->where('dealer.company_id',   $company_id)
            // ->where('challan_order.ch_dealer_id', $dealer_id);

        // if ($request->filled('retailer')) $q->whereIn('retailer.id', (array) $request->retailer);

        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Tally data',
            // 'data'    => [
                // 'main_data'    => $q->get(),
                // 'states'       => DB::table('location_3')->where('company_id', $company_id)->where('status', 1)->pluck('name', 'id'),
                // 'product_name' => DB::table('catalog_product')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                // 'product_gst'  => DB::table('catalog_product')->where('status', 1)->where('company_id', $company_id)->pluck('gst_percent', 'id'),
            // ],
        // ]);
    // }

    /* ---------- Invoice Summary ---------- */
    // public function invoiceSummary(Request $request)
    // {
        // $ctx        = $this->context($request);
        // $company_id = $ctx['company_id'];
        // $dealer_id  = $ctx['dealer_id'];

        // if (!$request->filled('date_range_picker')) {
            // return response()->json(['status' => false, 'message' => 'date_range_picker required'], 422);
        // }
        // $parts     = explode(" -", $request->date_range_picker);
        // $from_date = date('Y-m-d', strtotime(trim($parts[0])));
        // $to_date   = date('Y-m-d', strtotime(trim($parts[1])));

        // $q = DB::table('challan_order')
            // ->join('challan_order_details', 'challan_order_details.ch_id', '=', 'challan_order.id')
            // ->join('retailer', 'retailer.id', '=', 'challan_order.ch_retailer_id')
            // ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'retailer.location_id')
            // ->join('location_7', 'location_7.id', '=', 'dealer_location_rate_list.location_id')
            // ->join('location_6', 'location_7.location_6_id', '=', 'location_6.id')
            // ->join('location_5', 'location_6.location_5_id', '=', 'location_5.id')
            // ->join('location_4', 'location_5.location_4_id', '=', 'location_4.id')
            // ->select(
                // 'ch_retailer_id as retailer_id',
                // 'ch_date as challan_date',
                // 'product_id',
                // 'challan_order.ch_no as challan_number',
                // 'challan_order.id as challan_id',
                // 'retailer.name as retailer_name',
                // 'ch_dealer_id as dealer_id',
                // 'qty', 'taxable_amt'
            // )
            // ->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') >= '$from_date' AND DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') <= '$to_date'")
            // ->where('retailer.company_id', $company_id)
            // ->where('challan_order.ch_dealer_id', $dealer_id);

        // if ($request->filled('location_4')) $q->whereIn('location_4.id', (array) $request->location_4);
        // if ($request->filled('location_5')) $q->whereIn('location_5.id', (array) $request->location_5);
        // if ($request->filled('location_6')) $q->whereIn('location_6.id', (array) $request->location_6);

        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Invoice summary',
            // 'data'    => ['main_data' => $q->groupBy('challan_id')->get()],
        // ]);
    // }

    /* ---------- Purchase Report ---------- */
    // public function purchaseReport(Request $request)
    // {
        // $ctx        = $this->context($request);
        // $company_id = $ctx['company_id'];
        // $dealer_id  = $ctx['dealer_id'];

        // if (!$request->filled('date_range_picker')) {
            // return response()->json(['status' => false, 'message' => 'date_range_picker required'], 422);
        // }
        // $parts     = explode(" -", $request->date_range_picker);
        // $from_date = date('Y-m-d', strtotime(trim($parts[0])));
        // $to_date   = date('Y-m-d', strtotime(trim($parts[1])));

        // $q = DB::table('demand_order')
            // ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            // ->join('dealer_location_rate_list', 'dealer_location_rate_list.dealer_id', '=', 'demand_order.dealer_id')
            // ->join('retailer', 'retailer.location_id', '=', 'dealer_location_rate_list.location_id')
            // ->select(
                // 'demand_order.order_id',
                // 'demand_order_details.product_id',
                // 'challan_no', 'order_date',
                // 'retailer.name as retailer_name',
                // 'retailer.tin_no as gst_no',
                // 'retailer.location_id as beat_id',
                // 'quantity', 'free_qty', 'pr_rate', 'total_rs'
            // )
            // ->whereRaw("DATE_FORMAT(order_date, '%Y-%m-%d') >= '$from_date' AND DATE_FORMAT(order_date, '%Y-%m-%d') <= '$to_date'")
            // ->where('demand_order.dealer_id', $dealer_id)
            // ->where('dealer_location_rate_list.dealer_id', $dealer_id)
            // ->where('dealer_location_rate_list.company_id', $company_id)
            // ->where('retailer.company_id', $company_id)
            // ->where('demand_order.company_id', $company_id);

        // if ($request->filled('retailer')) $q->whereIn('retailer.id', (array) $request->retailer);

        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Purchase report',
            // 'data'    => [
                // 'main_data'    => $q->get(),
                // 'product_name' => DB::table('catalog_product')->where('status', 1)->where('company_id', $company_id)->pluck('name', 'id'),
                // 'product_gst'  => DB::table('catalog_product')->where('status', 1)->where('company_id', $company_id)->pluck('gst_percent', 'id'),
            // ],
        // ]);
    // }
//---Dealer Dashboard
public function dealerDashboard(Request $request)
{
    $ctx       = $this->context($request);
    $dealerId  = (int) $ctx['dealer_id'];
    $domainUrl = (string) DB::table('company')->where('id', $ctx['company_id'])->value('domain_url');

    $banners = DB::table('image_mast')
        ->where('img_locn', 'Dashboard')
        ->where('company_id', $ctx['company_id'])
        ->orderBy('id', 'DESC')
        ->pluck('img')
        ->map(fn ($img) => [
            'url'   => "https://{$domainUrl}/public/baidyanath_images/{$img}",
            'video' => strtolower(pathinfo($img, PATHINFO_EXTENSION)) === 'mp4',
        ]);

    $entries = DB::table('dealer_ledger_busy_details')
        ->where('dealer_id', $dealerId)
        ->orderBy('trans_date')
        ->orderBy('id')
        ->get(['trans_date', 'vocuher_number', 'vocuher_type', 'narration', 'amount', 'op_bal']);

    $opening = round((float) $entries->whereNull('trans_date')->sum('op_bal'), 2);
    $rows    = $entries->whereNotNull('trans_date')->values()->map(fn ($r) => [
        'date'       => $r->trans_date,
        'voucher_no' => $r->vocuher_number,
        'vch_type'   => $r->vocuher_type,
        'account'    => $r->narration,
        'amount'     => (float) $r->amount,
    ]);
    $summary = DB::table('dealer_ledger_busy')->where('dealer_id', $dealerId)->orderByDesc('end_date')->first(['closing_balance', 'server_date_time']);

    return response()->json([
        'status'  => true,
        'message' => 'Dealer dashboard',
        'data'    => [
            'banners' => $banners,
            'account' => ['name' => $ctx['dealer']->name ?? '', 'erp_code' => $ctx['dealer_code']],
            'ledger'  => [
                'opening_balance' => $opening,
                'closing_balance' => round($summary ? (float) $summary->closing_balance : $opening + $rows->sum('amount'), 2),
                'fetched_at'      => $summary->server_date_time ?? null,
                'rows'            => $rows,
            ],
        ],
    ]);
}

    /* ---------- Common Report dispatcher ---------- */
    // public function commonReportSection(Request $request)
    // {
        // $request->validate(['report_name' => 'required|string']);

        // $map = [
            // 'ss_van_sales_report'        => 'ss_van_sales_report_new',
            // 'retailer_ledger_index'      => 'retailer_ledger_ajax',
            // 'retailer_wise_sales_index'  => 'retailer_wise_sales_ajax',
            // 'van_sale_index'             => 'van_sale_ajax',
            // 'van_settlement_index'       => 'van_settlement_ajax',
            // 'db_sales_index'             => 'db_sales_ajax',
            // 'db_van_summary_index'       => 'db_van_summary_ajax',
            // 'retailer-wise-index-new'    => 'retailer-wise-new-ajax',
            // 'DisplayClaimReport'         => 'DgrClaimAjax',
        // ];

        // return response()->json([
            // 'status'  => true,
            // 'message' => 'Common report',
            // 'data'    => [
                // 'ajax_report_name' => $map[$request->report_name] ?? null,
                // 'report_name'      => $request->report_name,
            // ],
        // ]);
    // }

    /* ============================================================
     |  15) CSV FORMAT DOWNLOADS
     * ============================================================ */

    public function orderFormat()
    {
        return response()->streamDownload(function () {
            echo "SNO,Product Id,Quantity\n";
        }, 'orderFormat.csv', ['Content-Type' => 'text/csv']);
    }

    // public function dmsChallanFormat()
    // {
        // return response()->streamDownload(function () {
            // echo "Date,Order Id,Retailer Id,Retailer Name,User Id,User Name,Product Id,Product Name,Quantity,Rate,Direct Invoice(1)/Through SFA(0)\n";
        // }, 'DMSchallanFormat.csv', ['Content-Type' => 'text/csv']);
    // }
}