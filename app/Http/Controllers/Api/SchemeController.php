<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemeController extends Controller
{
    private function context(Request $request): array
    {
        $user = $request->user();
        $dealer_id = $user->dealer_id ?? 0;
        $dealer = DB::table('dealer')->where('id', $dealer_id)->first();

        return [
            'user'        => $user,
            'dealer'      => $dealer,
            'dealer_id'   => $dealer_id,
            'company_id'  => $dealer->company_id ?? ($user->company_id ?? 0),
            'dealer_code' => $dealer->dealer_code ?? ($user->dealer_code ?? ''),
            'csa_id'      => $dealer->csa_id ?? ($user->csa_id ?? 0),
            'role_id'     => $user->role_id ?? ($dealer->role_id ?? 0),
            'date'        => date('Y-m-d'),
        ];
    }

    /* ============================================================
     |  SCHEME LISTING  (from DMSSchemeControllers::index)
     * ============================================================ */
    public function index(Request $request)
    {
        $ctx = $this->context($request);

        $schemeData = DB::table('scheme_domain_man')
            ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
            ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
            ->where('scheme_domain_man.status', 1)
            ->where('scheme_domain_man.company_id', $ctx['company_id'] ?? 0)
            ->groupBy('scheme_domain_man.scheme_no')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Scheme list fetched',
            'data'    => [
                'role_id'                 => $ctx['role_id'],
                'scheme_catg_title_data'  => $schemeData,
            ],
        ]);
    }

    /* ============================================================
     |  CREATE DATA  (from DMSSchemeControllers::create)
     * ============================================================ */
    public function createData(Request $request)
    {
        $ctx = $this->context($request);

        $mktg_drop_down_data = DB::table('MKTG_CATG_MAST')->get();
        $prod_drop_down_data = DB::table('PROD_CATG_MAST')->orderBy('PROD_CATG_NAME', 'ASC')->get();

        return response()->json([
            'status'  => true,
            'message' => 'Scheme create data',
            'data'    => [
                'role_id'              => $ctx['role_id'],
                'mktg_drop_down_data'  => $mktg_drop_down_data,
                'prod_drop_down_data'  => $prod_drop_down_data,
            ],
        ]);
    }

    /* ============================================================
     |  STORE SCHEME  (from DMSSchemeControllers::store)
     * ============================================================ */
    public function store(Request $request)
    {
        $ctx = $this->context($request);
        $company_id = $ctx['company_id'];

        DB::beginTransaction();
        try {
            $circular_no   = $request->circular_no;
            $circular_date = $request->circular_date;
            $created_at    = date('Y-m-d H:i:s');
            $status        = 1;

            $exists = DB::table('circular_master_man')
                ->where('circular_no', $circular_no)
                ->where('company_id', $company_id)
                ->exists();

            if (!$exists) {
                DB::table('circular_master_man')->insert([
                    'circular_no'   => $circular_no,
                    'circular_date' => $circular_date,
                    'company_id'    => $company_id,
                    'created_at'    => $created_at,
                    'status'        => $status,
                ]);
            }

            $scheme_no         = $request->scheme_no;
            $scheme_from_date  = $request->scheme_from_date;
            $scheme_to_date    = $request->scheme_to_date;
            $scheme_type       = $request->scheme_type;
            $scheme_benific    = $request->scheme_benefically;
            $scheme_des        = $request->scheme_description;
            $sale_type         = $request->sale_type;

            DB::table('scheme_master_man')->insert([
                'scheme_no'        => $scheme_no,
                'scheme_from_date' => $scheme_from_date,
                'scheme_to_date'   => $scheme_to_date,
                'scheme_type'      => $scheme_type,
                'scheme_benific'   => $scheme_benific,
                'scheme_des'       => $scheme_des,
                'company_id'       => $company_id,
                'created_at'       => $created_at,
                'status'           => $status,
            ]);

            $domainRows = [];

            // mktg_all
            if (!empty($request->mktg_all)) {
                $items = DB::table('MKTG_CATG_MAST')->get();
                foreach ($items as $it) {
                    $domainRows[] = [
                        'item_code' => 0, 'mktg_catg' => $it->MKTG_CATG, 'prod_catg' => 0,
                        'circular_no' => $circular_no, 'scheme_no' => $scheme_no,
                        'company_id' => $company_id, 'created_at' => $created_at,
                        'sale_type' => $sale_type, 'status' => $status,
                    ];
                }
            }

            // prod_catg_all
            if (!empty($request->prod_catg_all)) {
                $items = DB::table('PROD_CATG_MAST')->get();
                foreach ($items as $it) {
                    $domainRows[] = [
                        'item_code' => 0, 'mktg_catg' => 0, 'prod_catg' => $it->PROD_CATG,
                        'circular_no' => $circular_no, 'scheme_no' => $scheme_no,
                        'company_id' => $company_id, 'created_at' => $created_at,
                        'sale_type' => $sale_type, 'status' => $status,
                    ];
                }
            }

            // mktg_selected
            if (!empty($request->mktg_selected) && !empty($request->mktg_catg_dropdown)) {
                foreach ((array) $request->mktg_catg_dropdown as $value) {
                    $domainRows[] = [
                        'item_code' => 0, 'mktg_catg' => $value, 'prod_catg' => 0,
                        'circular_no' => $circular_no, 'scheme_no' => $scheme_no,
                        'company_id' => $company_id, 'created_at' => $created_at,
                        'sale_type' => $sale_type, 'status' => $status,
                    ];
                }
            }

            // prod_selected
            if (!empty($request->prod_selected) && !empty($request->prod_catg_dropdown)) {
                foreach ((array) $request->prod_catg_dropdown as $value) {
                    $domainRows[] = [
                        'item_code' => 0, 'mktg_catg' => 0, 'prod_catg' => $value,
                        'circular_no' => $circular_no, 'scheme_no' => $scheme_no,
                        'company_id' => $company_id, 'created_at' => $created_at,
                        'sale_type' => $sale_type, 'status' => $status,
                    ];
                }
            }

            // mainline_all
            if (!empty($request->mainline_all)) {
                $domainRows[] = [
                    'item_code' => 0, 'mktg_catg' => 'mainline', 'prod_catg' => 0,
                    'circular_no' => $circular_no, 'scheme_no' => $scheme_no,
                    'company_id' => $company_id, 'created_at' => $created_at,
                    'sale_type' => $sale_type, 'status' => $status,
                ];
            }

            // dealer_all
            if (!empty($request->dealer_all)) {
                $dealers = DB::table('ACC_MAST')
                    ->join('dealer', 'dealer.dealer_code', '=', 'ACC_MAST.ACC_CODE')
                    ->where('ACC_STATUS', '!=', 'C')
                    ->where('dealer_status', 1)
                    ->groupBy('dealer_code')
                    ->get();

                foreach ($dealers as $d) {
                    $domainRows[] = [
                        'dealer_code' => $d->ACC_CODE,
                        'circular_no' => $circular_no,
                        'scheme_no'   => $scheme_no,
                        'company_id'  => $company_id,
                        'created_at'  => $created_at,
                        'status'      => $status,
                    ];
                }
            }

            if (!empty($domainRows)) {
                DB::table('scheme_domain_man')->insert($domainRows);
            }

            // SLABS
            $slabRows = [];
            $target_sale_from   = (array) $request->target_sale_from_date;
            $target_sale_to     = (array) $request->target_sale_to_date;
            $incentive_sale_from= (array) $request->incentive_sale_from_date;
            $incentive_sale_to  = (array) $request->incentive_sale_to_date;
            $base_from          = (array) $request->base_from;
            $base_to            = (array) $request->base_to;
            $free_final_rate    = (array) $request->free_final_rate;

            foreach ($target_sale_from as $k => $v) {
                if (empty($target_sale_from[$k]) || empty($target_sale_to[$k]) ||
                    empty($incentive_sale_from[$k]) || empty($incentive_sale_to[$k]) ||
                    empty($base_from[$k]) || empty($free_final_rate[$k])) {
                    continue;
                }
                $slabRows[] = [
                    'target_sale_from_date'    => $v,
                    'target_sale_to_date'      => $target_sale_to[$k],
                    'incentive_sale_from_date' => $incentive_sale_from[$k],
                    'incentive_sale_to_date'   => $incentive_sale_to[$k],
                    'base_from'                => $base_from[$k],
                    'base_to'                  => $base_to[$k] ?? 'Above',
                    'free_final_rate'          => $free_final_rate[$k],
                    'scheme_no'                => $scheme_no,
                    'circular_no'              => $circular_no,
                    'company_id'               => $company_id,
                    'created_at'               => $created_at,
                    'status'                   => $status,
                ];
            }
            if (!empty($slabRows)) {
                DB::table('scheme_slab_man')->insert($slabRows);
            }

            DB::commit();
            return response()->json(['status' => true, 'message' => 'Scheme created']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Scheme creation failed',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /* ============================================================
     |  ADMIN SCHEME DETAILS  (from admin_scheme_details)
     * ============================================================ */
    public function adminSchemeDetails(Request $request)
    {
        $ctx = $this->context($request);
        $dealerFilter = [];

        if ($ctx['role_id'] == 41) {
            $reportL2 = DB::table('dealer_report_section_data')
                ->where('dealer_id', $ctx['dealer_id'])
                ->where('location_2', '!=', 0)
                ->groupBy('location_2')
                ->take(1)->pluck('location_2')->toArray();

            $q = DB::table('dealer_location_rate_list')
                ->join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id')
                ->where('dealer_code', '!=', 0);

            if (!empty($reportL2)) {
                $q->whereIn('l2_id', $reportL2);
            }

            $dealerFilter = $q->where('dealer.dealer_status', 1)
                ->groupBy('dealer.id')
                ->pluck(DB::raw("CONCAT_WS('-', dealer.dealer_code, dealer.name) as dealer_name"), 'dealer_code');
        } else {
            $dealerFilter = DB::table('dealer')
                ->where('dealer_status', 1)
                ->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'dealer_code');
        }

        return response()->json([
            'status'  => true,
            'message' => 'Admin scheme details',
            'data'    => [
                'role_id'       => $ctx['role_id'],
                'dealer_filter' => $dealerFilter,
            ],
        ]);
    }

    /* ============================================================
     |  DEALER SCHEME  (from dealer_scheme) — complex logic ported
     * ============================================================ */
    public function dealerScheme(Request $request)
    {
        $ctx = $this->context($request);
        $curr_date   = date('Y-m-d');
        $admin_status = $request->admin_status;
        $dealer_code  = $admin_status == 1
            ? $request->dealer_code
            : $ctx['dealer_code'];

        if (empty($dealer_code)) {
            return response()->json(['status' => false, 'message' => 'dealer_code required'], 422);
        }

        // Financial year
        $fy = DB::table('financial_year')->orderBy('id', 'DESC')->first();
        $from_year_t = $fy->target_from_year ?? date('Y');
        $to_year_t   = $fy->target_to_year   ?? (date('Y') + 1);
        $from_year_d = $fy->from_year        ?? date('Y');
        $to_year_d   = $fy->to_year          ?? (date('Y') + 1);

        $from_target_date = "01-APR-{$from_year_t}";
        $to_target_date   = "31-MAR-{$to_year_t}";
        $from_sale_date   = "{$from_year_d}-04-01";
        $to_sale_date     = "{$to_year_d}-03-31";

        // div code
        $acc = DB::table('ACC_MAST')->where('ACC_CODE', $dealer_code)->first();
        $login = DB::table('dealer_person_login')
            ->select('div_code_main')
            ->join('dealer', 'dealer.id', '=', 'dealer_person_login.dealer_id')
            ->where('dealer.dealer_code', $dealer_code)->first();
        $div_code = $acc->DIV_CODE ?? ($login->div_code_main ?? '0');

        // Active schemes for this dealer
        $schemeNos = DB::table('scheme_domain_man')
            ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
            ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
            ->where('scheme_domain_man.status', 1)
            ->where('dealer_code', $dealer_code)
            ->whereRaw("DATE(scheme_from_date) <= '{$curr_date}' AND DATE(scheme_to_date) >= '{$curr_date}'")
            ->pluck('scheme_domain_man.scheme_no')->toArray();

        if (empty($schemeNos)) {
            return response()->json([
                'status'  => true,
                'message' => 'No active schemes',
                'data'    => [
                    'mktg_catg_return_array' => DB::table('MKTG_CATG_MAST')->pluck('MKTG_CATG_NAME', 'MKTG_CATG'),
                    'prod_catg_return_array' => DB::table('PROD_CATG_MAST')->pluck('PROD_CATG_NAME', 'PROD_CATG'),
                    'scheme_catg_title_data' => [], 'prod_scheme_catg_title_data' => [],
                    'out' => [], 'prod_out' => [],
                    'mktg_catg_wise_sales' => [], 'prod_catg_wise_sales' => [],
                    'mktg_target_out' => [], 'prod_target_out' => [],
                ],
            ]);
        }

        $mktgGroups    = DB::table('mktg_group_mast')->pluck('mktg_group_code')->toArray();
        $prodGroups    = DB::table('prod_group_mast')->pluck('prod_group_code')->toArray();
        $mktgNameMap   = DB::table('MKTG_CATG_MAST')->pluck('MKTG_CATG_NAME', 'MKTG_CATG');
        $prodNameMap   = DB::table('PROD_CATG_MAST')->pluck('PROD_CATG_NAME', 'PROD_CATG');

        // ================= MKTG SCHEMES =================
        $mktgSchemeTitles = DB::table('scheme_domain_man')
            ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
            ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
            ->where('scheme_domain_man.status', 1)
            ->where('dealer_code', 0)
            ->where('prod_catg', '0')
            ->whereIn('scheme_domain_man.scheme_no', $schemeNos)
            ->whereRaw("DATE(scheme_from_date) <= '{$curr_date}' AND DATE(scheme_to_date) >= '{$curr_date}'")
            ->groupBy('mktg_catg')
            ->get();

        $out = [];
        foreach ($mktgSchemeTitles as $t) {
            $out[$t->mktg_catg] = DB::table('scheme_slab_man')
                ->join('scheme_domain_man', 'scheme_slab_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
                ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
                ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
                ->select('scheme_slab_man.*', 'scheme_domain_man.mktg_catg as mktg_catg', 'scheme_domain_man.sale_type as sale_type')
                ->where('scheme_domain_man.status', 1)
                ->where('dealer_code', 0)
                ->where('prod_catg', 0)
                ->whereIn('scheme_master_man.scheme_no', $schemeNos)
                ->where('mktg_catg', $t->mktg_catg)
                ->whereRaw("DATE(scheme_from_date) <= '{$curr_date}' AND DATE(scheme_to_date) >= '{$curr_date}'")
                ->groupBy('scheme_slab_man.id')
                ->orderBy('scheme_slab_man.id', 'DESC')
                ->get();
        }

        $mktgSales   = [];
        $mktgTargets = [];

        foreach ($mktgSchemeTitles as $scheme) {
            if (empty($out[$scheme->mktg_catg])) continue;

            foreach ($out[$scheme->mktg_catg] as $slab) {
                $saleType = $slab->sale_type;
                $isQty    = in_array($saleType, ['BOX', 'PCS'], true);

                if ($scheme->mktg_catg === 'mainline') {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
                        ->join('MKTG_CATG_MAST', 'MKTG_CATG_MAST.MKTG_CATG', '=', 'PROD_CATG_MAST.MKTG_CATG')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'))
                        ->whereIn('MKTG_CATG_MAST.MKTG_CATG', $mktgGroups)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $mktgSales[$scheme->mktg_catg . $slab->id] = (float) ($res->VALISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->whereIn('MKTG_CATG', $mktgGroups)
                        ->where('PROD_CATG', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $mktgTargets[$scheme->mktg_catg] = (float) ($t->TARGET_AMT ?? 0);
                } elseif ($isQty) {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
                        ->join('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
                        ->join('MKTG_CATG_MAST', 'MKTG_CATG_MAST.MKTG_CATG', '=', 'PROD_CATG_MAST.MKTG_CATG')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / ITEM_AUM_MAST.UMTOAUM) as QTYISSUED'))
                        ->where('MKTG_CATG_MAST.MKTG_CATG', $scheme->mktg_catg)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $mktgSales[$scheme->mktg_catg . $slab->id] = (float) ($res->QTYISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->where('MKTG_CATG', $scheme->mktg_catg)
                        ->where('PROD_CATG', '!=', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $mktgTargets[$scheme->mktg_catg] = (float) ($t->TARGET_QTY ?? 0);
                } else {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('PROD_MAST', 'PROD_MAST.PROD_CODE', '=', 'ITEM_MAST.PROD_CODE')
                        ->join('MKTG_CATG_MAST', 'MKTG_CATG_MAST.MKTG_CATG', '=', 'PROD_MAST.MKTG_CATG')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'))
                        ->where('MKTG_CATG_MAST.MKTG_CATG', $scheme->mktg_catg)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $mktgSales[$scheme->mktg_catg . $slab->id] = (float) ($res->VALISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->where('MKTG_CATG', $scheme->mktg_catg)
                        ->where('PROD_CATG', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $mktgTargets[$scheme->mktg_catg] = (float) ($t->TARGET_AMT ?? 0);
                }
            }
        }

        // ================= PROD SCHEMES =================
        $prodSchemeTitles = DB::table('scheme_domain_man')
            ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
            ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
            ->where('scheme_domain_man.status', 1)
            ->where('dealer_code', 0)
            ->where('mktg_catg', '0')
            ->whereIn('scheme_domain_man.scheme_no', $schemeNos)
            ->whereRaw("DATE(scheme_from_date) <= '{$curr_date}' AND DATE(scheme_to_date) >= '{$curr_date}'")
            ->groupBy('prod_catg')
            ->get();

        $prodOut = [];
        foreach ($prodSchemeTitles as $t) {
            $prodOut[$t->prod_catg] = DB::table('scheme_slab_man')
                ->join('scheme_domain_man', 'scheme_slab_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
                ->join('scheme_master_man', 'scheme_master_man.scheme_no', '=', 'scheme_domain_man.scheme_no')
                ->join('circular_master_man', 'circular_master_man.circular_no', '=', 'scheme_domain_man.circular_no')
                ->select('scheme_slab_man.*', 'scheme_domain_man.prod_catg as prod_catg', 'scheme_domain_man.sale_type as sale_type')
                ->where('scheme_domain_man.status', 1)
                ->where('dealer_code', 0)
                ->where('mktg_catg', 0)
                ->whereIn('scheme_master_man.scheme_no', $schemeNos)
                ->where('prod_catg', $t->prod_catg)
                ->whereRaw("DATE(scheme_from_date) <= '{$curr_date}' AND DATE(scheme_to_date) >= '{$curr_date}'")
                ->groupBy('scheme_slab_man.id')
                ->orderBy('id', 'DESC')
                ->get();
        }

        $prodSales   = [];
        $prodTargets = [];

        foreach ($prodSchemeTitles as $scheme) {
            if (empty($prodOut[$scheme->prod_catg])) continue;

            foreach ($prodOut[$scheme->prod_catg] as $slab) {
                $saleType = $slab->sale_type;
                $isQty    = in_array($saleType, ['BOX', 'PCS'], true);

                if ($scheme->prod_catg === 'prod_group') {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
                        ->join('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / ITEM_AUM_MAST.UMTOAUM) as QTYISSUED'))
                        ->whereIn('PROD_CATG_MAST.PROD_CATG', $prodGroups)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $prodSales[$scheme->prod_catg . $slab->id] = (float) ($res->QTYISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->whereIn('PROD_CATG', $prodGroups)
                        ->where('MKTG_CATG', '!=', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $prodTargets[$scheme->prod_catg] = (float) ($t->TARGET_AMT ?? 0);
                } elseif ($isQty) {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
                        ->join('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / ITEM_AUM_MAST.UMTOAUM) as QTYISSUED'))
                        ->where('PROD_CATG_MAST.PROD_CATG', $scheme->prod_catg)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $prodSales[$scheme->prod_catg . $slab->id] = (float) ($res->QTYISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->where('PROD_CATG', $scheme->prod_catg)
                        ->where('MKTG_CATG', '!=', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $prodTargets[$scheme->prod_catg] = (float) ($t->TARGET_QTY ?? 0);
                } else {
                    $res = DB::table('ITEMTRAN_BODY')
                        ->join('ITEMTRAN_HEAD', 'ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO')
                        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
                        ->join('PROD_MAST', 'PROD_MAST.PROD_CODE', '=', 'ITEM_MAST.PROD_CODE')
                        ->select(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'))
                        ->where('PROD_MAST.PROD_CATG', $scheme->prod_catg)
                        ->where('ITEMTRAN_BODY.DIV_CODE', $div_code)
                        ->where('ITEMTRAN_HEAD.DIV_CODE', $div_code)
                        ->whereRaw("DATE(ITEMTRAN_BODY.VRDATE_FILTER) BETWEEN '{$slab->target_sale_from_date}' AND '{$slab->target_sale_to_date}'")
                        ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                        ->first();

                    $prodSales[$scheme->prod_catg . $slab->id] = (float) ($res->VALISSUED ?? 0);

                    $t = DB::table('DEALER_TARGET_MAST')
                        ->where('PROD_CATG', $scheme->prod_catg)
                        ->where('MKTG_CATG', '!=', '')
                        ->where('FROM_DATE', $from_target_date)
                        ->where('TO_DATE', $to_target_date)
                        ->where('ACC_CODE', $dealer_code)
                        ->first();
                    $prodTargets[$scheme->prod_catg] = (float) ($t->TARGET_AMT ?? 0);
                }
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Dealer scheme fetched',
            'data'    => [
                'admin_status'              => $admin_status,
                'dealer_code'               => $dealer_code,
                'mktg_catg_return_array'    => $mktgNameMap,
                'mktg_target_out'           => $mktgTargets,
                'mktg_catg_wise_sales'      => $mktgSales,
                'scheme_catg_title_data'    => $mktgSchemeTitles,
                'out'                       => $out,

                'prod_catg_return_array'    => $prodNameMap,
                'prod_target_out'           => $prodTargets,
                'prod_catg_wise_sales'      => $prodSales,
                'prod_scheme_catg_title_data'=> $prodSchemeTitles,
                'prod_out'                  => $prodOut,
            ],
        ]);
    }
}