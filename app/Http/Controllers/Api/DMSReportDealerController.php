<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Dealer;
use App\DealerLocation;
use App\Location2;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DMSReportDealerController extends Controller
{
    protected $auth_id;
    protected $dealer_id;
    protected $csa_id;
    protected $dealer_code;
    protected $role_id;
    protected $current_menu;
    protected $date;

    public function __construct()
    {
        // $this->middleware('auth:sanctum');  // or 'auth:api'
        $this->current_menu = 'Order-details';
        $this->date         = date('Y-m-d');
    }

    /**
     * Hydrate auth properties from the token's user.
     */
    private function initAuth(Request $request): void
    {
        $user = $request->user();

        $this->auth_id     = $user->id          ?? 0;
        $this->dealer_id   = $user->dealer_id   ?? 0;
        $this->csa_id      = $user->csa_id      ?? 0;
        $this->dealer_code = $user->dealer_code ?? '0';
        $this->role_id     = $user->urole       ?? '0';

        if (!$this->auth_id) {
            abort(401, 'Unauthenticated.');
        }
    }

    /**
     * Resolve a "from - to" date range string into [Y-m-d, Y-m-d].
     */
    private function resolveDateRange(?string $range, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        if (empty($range)) {
            return [
                $defaultFrom ?? date('Y-m') . '-01',
                $defaultTo   ?? date('Y-m-d'),
            ];
        }

        $parts = explode(' -', $range);

        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    /* =========================================================
     *  SHORT ITEM LIST REPORT  (filters for the front-end)
     * ========================================================= */
    public function shortItemListReport(Request $request)
    {
        $this->initAuth($request);

        $depoFilter = DB::table('dealer')
            ->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')
            ->where('dealer_status', 1)
            ->where('role_id', 37)
            ->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as dealer_name"), 'div_code_main');

        $dealerCode = null;

        if ($this->role_id == '41') {
            $terrId        = '';
            $dealerIdCus   = '';

            $dealerReportSectionDataQuery = DB::table('dealer_report_section_data')
                ->where('dealer_id', $this->dealer_id)
                ->where('location_2', '!=', '0')
                ->groupBy('location_2');

            if (!empty($terrId)) {
                $dealerReportSectionDataQuery->whereIn('location_2', $terrId);
            }

            $dealerReportSectionData = $dealerReportSectionDataQuery->take(1)->pluck('location_2')->toArray();

            $dealerIdData = DealerLocation::join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id');

            if (!empty($dealerIdCus)) {
                $dealerIdData->whereIn('dealer_id', $dealerIdCus);
            }
            if (!empty($terrId)) {
                $dealerIdData->whereIn('l2_id', $terrId);
            } else {
                $dealerIdData->whereIn('l2_id', $dealerReportSectionData);
            }

            $dealerId = $dealerIdData->groupBy('dealer_id')->pluck('dealer_id')->toArray();

            $dealerCode = DB::table('dealer')->whereIn('id', $dealerId)->pluck('dealer_code')->toArray();
        }

        $dealerNameQuery = DB::table('dealer')->where('dealer_status', 1);
        if (!empty($dealerCode)) {
            $dealerNameQuery->whereIn('dealer_code', $dealerCode);
        }
        $dealerName = $dealerNameQuery->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'dealer_code');

        return response()->json([
            'success' => true,
            'data'    => [
                'dealer_name'  => $dealerName,
                'depo_filter'  => $depoFilter,
                'role_id'      => $this->role_id,
                'current_menu' => $this->current_menu,
            ],
        ]);
    }

    /* =========================================================
     *  SHORT ITEM LIST REPORT – AJAX (data table)
     * ========================================================= */
    public function shortItemListReportAjax(Request $request)
    {
        $this->initAuth($request);

        [$fromDate, $toDate] = $this->resolveDateRange($request->input('date_range_picker'));

        if ($this->role_id == 1) {
            $dealerCode = $request->input('dealer_code');
            $dealerId   = $request->input('dealer_id');
        } else {
            if ($this->role_id == '41') {
                $terrId      = '';
                $dealerIdCus = '';
                $dealerCode  = $request->input('dealer_code');

                $dealerReportSectionDataQuery = DB::table('dealer_report_section_data')
                    ->where('dealer_id', $this->dealer_id)
                    ->where('location_2', '!=', '0')
                    ->groupBy('location_2');

                if (!empty($terrId)) {
                    $dealerReportSectionDataQuery->whereIn('location_2', $terrId);
                }

                $dealerReportSectionData = $dealerReportSectionDataQuery->take(1)->pluck('location_2')->toArray();

                $dealerIdData = DealerLocation::join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                    ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id');

                if (!empty($dealerIdCus)) {
                    $dealerIdData->whereIn('dealer_id', $dealerIdCus);
                }
                if (!empty($dealerCode)) {
                    $dealerIdData->whereIn('dealer_code', $dealerCode);
                }
                if (!empty($terrId)) {
                    $dealerIdData->whereIn('l2_id', $terrId);
                } else {
                    $dealerIdData->whereIn('l2_id', $dealerReportSectionData);
                }

                $dealerId = $dealerIdData->groupBy('dealer_id')->pluck('dealer_id')->toArray();

                $dealerCodeQuery = DB::table('dealer');
                if (!empty($dealerCode)) {
                    $dealerCodeQuery->whereIn('dealer_code', $dealerCode);
                } else {
                    $dealerCodeQuery->whereIn('id', $dealerId);
                }
                $dealerCode = $dealerCodeQuery->pluck('dealer_code')->toArray();
            } else {
                $dealerCode = [$this->dealer_code];
                $dealerId   = $this->dealer_id;
            }
        }

        $dataDivCodeQuery = DB::table('dealer_person_login')
            ->join('dealer', 'dealer.id', '=', 'dealer_person_login.dealer_id')
            ->where('dealer_status', 1)
            ->groupBy('dealer.id');

        if (!empty($dealerCode)) {
            $dataDivCodeQuery->whereIn('dealer_code', $dealerCode);
        }

        $dataDivCode = $dataDivCodeQuery->pluck('div_code_main', 'dealer_code');

        // The original controller returned placeholders here because the
        // summary queries were commented out. Preserve that behaviour.
        $dealerOrderSummary = [];
        $invoiceSummary     = [];

        return response()->json([
            'success' => true,
            'data'    => [
                'dealer_order_summary' => $dealerOrderSummary,
                'data_div_code'        => $dataDivCode,
                'invoice_summary'      => $invoiceSummary,
                'role_id'              => $this->role_id,
                'current_menu'         => $this->current_menu,
                'from_date'            => $fromDate,
                'to_date'              => $toDate,
            ],
        ]);
    }

    /* =========================================================
     *  ITEM DETAIL FOR A SHORT ITEM
     * ========================================================= */
    public function itemDetailerShortItem(Request $request)
    {
        $this->initAuth($request);

        $validated = $request->validate([
            'order_no'   => 'required',
            'sl_no'      => 'required',
            'order_date' => 'required|date',
        ]);

        $dataReturn = DB::table('ORDER_HEAD')
            ->join('ORDER_BODY', function ($join) {
                $join->on('ORDER_BODY.VRNO', '=', 'ORDER_HEAD.VRNO');
                $join->on('ORDER_BODY.DIV_CODE', '=', 'ORDER_HEAD.DIV_CODE');
            })
            ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ORDER_BODY.ITEM_CODE')
            ->select(
                'ITEM_MAST.ITEM_NAME',
                DB::raw("SUM(QTYCANCELLED) as qty_cancel"),
                DB::raw("SUM(ORDER_BODY.AFIELD1) as order_amt"),
                'REBOOK_VRNO as r_vrno',
                'ITEM_MAST.ITEM_CODE',
                'QTYCANCELLED_FLAG'
            )
            ->whereRaw("date_format(ORDER_HEAD.VRDATE_FILTER,'%Y-%m-%d')='{$validated['order_date']}'")
            ->where('ORDER_BODY.VRNO', $validated['order_no'])
            ->where('ORDER_BODY.DO_VRNO', $validated['sl_no'])
            ->groupBy('ORDER_BODY.ITEM_CODE')
            ->get();

        return response()->json([
            'code'        => 200,
            'data_return' => $dataReturn,
        ]);
    }

    /* =========================================================
     *  DATE NORMALIZATION MAINTENANCE ENDPOINT
     * ========================================================= */
    public function changeDate(Request $request)
    {
        $this->initAuth($request);

        $rows = DB::table('DEALER_TARGET_MAST')->where('id', '>', '0')->get();

        foreach ($rows as $row) {
            DB::table('DEALER_TARGET_MAST')
                ->where('id', $row->id)
                ->update([
                    'FROM_DATE_FILTER' => date('Y-m-d', strtotime($row->FROM_DATE)),
                    'TO_DATE_FILTER'   => date('Y-m-d', strtotime($row->TO_DATE)),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Dates normalized.',
            'count'   => $rows->count(),
        ]);
    }

    /* =========================================================
     *  DEALER SALE DETAILS – FILTERS
     * ========================================================= */
    public function dealerSaleDetailsReport(Request $request)
    {
        $this->initAuth($request);

        $depoFilter = DB::table('dealer')
            ->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')
            ->where('dealer_status', 1)
            ->where('role_id', 37)
            ->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as dealer_name"), 'div_code_main');

        $dealerCode = null;

        if ($this->role_id == '41') {
            $terrId      = '';
            $dealerIdCus = '';

            $dealerReportSectionDataQuery = DB::table('dealer_report_section_data')
                ->where('dealer_id', $this->dealer_id)
                ->where('location_2', '!=', '0')
                ->groupBy('location_2');

            if (!empty($terrId)) {
                $dealerReportSectionDataQuery->whereIn('location_2', $terrId);
            }

            $dealerReportSectionData = $dealerReportSectionDataQuery->take(1)->pluck('location_2')->toArray();

            $dealerIdData = DealerLocation::join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id');

            if (!empty($dealerIdCus)) {
                $dealerIdData->whereIn('dealer_id', $dealerIdCus);
            }
            if (!empty($terrId)) {
                $dealerIdData->whereIn('l2_id', $terrId);
            } else {
                $dealerIdData->whereIn('l2_id', $dealerReportSectionData);
            }

            $dealerId   = $dealerIdData->groupBy('dealer_id')->pluck('dealer_id')->toArray();
            $dealerCode = DB::table('dealer')->whereIn('id', $dealerId)->pluck('dealer_code')->toArray();
        }

        $dealerNameQuery = DB::table('dealer')->where('dealer_status', 1);
        if (!empty($dealerCode)) {
            $dealerNameQuery->whereIn('dealer_code', $dealerCode);
        }
        $dealerName = $dealerNameQuery->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'dealer_code');

        return response()->json([
            'success' => true,
            'data'    => [
                'dealer_name'  => $dealerName,
                'depo_filter'  => $depoFilter,
                'role_id'      => $this->role_id,
                'current_menu' => $this->current_menu,
            ],
        ]);
    }

    /* =========================================================
     *  DEALER SALE DETAILS – AJAX
     * ========================================================= */
    public function dealerSaleDetailsReportAjax(Request $request)
    {
        $this->initAuth($request);

        [$fromDate, $toDate] = $this->resolveDateRange($request->input('date_range_picker'));

        if ($this->role_id == 1) {
            $dealerCode = $request->input('dealer_code');
            $dealerId   = $request->input('dealer_id');
        } else {
            if ($this->role_id == '41') {
                $terrId      = '';
                $dealerIdCus = '';
                $dealerCode  = $request->input('dealer_code');

                $dealerReportSectionDataQuery = DB::table('dealer_report_section_data')
                    ->where('dealer_id', $this->dealer_id)
                    ->where('location_2', '!=', '0')
                    ->groupBy('location_2');

                if (!empty($terrId)) {
                    $dealerReportSectionDataQuery->whereIn('location_2', $terrId);
                }

                $dealerReportSectionData = $dealerReportSectionDataQuery->take(1)->pluck('location_2')->toArray();

                $dealerIdData = DealerLocation::join('location_view', 'location_view.l5_id', '=', 'dealer_location_rate_list.location_id')
                    ->join('dealer', 'dealer_location_rate_list.dealer_id', '=', 'dealer.id');

                if (!empty($dealerIdCus)) {
                    $dealerIdData->whereIn('dealer_id', $dealerIdCus);
                }
                if (!empty($dealerCode)) {
                    $dealerIdData->whereIn('dealer_code', $dealerCode);
                }
                if (!empty($terrId)) {
                    $dealerIdData->whereIn('l2_id', $terrId);
                } else {
                    $dealerIdData->whereIn('l2_id', $dealerReportSectionData);
                }

                $dealerId = $dealerIdData->groupBy('dealer_id')->pluck('dealer_id')->toArray();

                $dealerCodeQuery = DB::table('dealer');
                if (!empty($dealerCode)) {
                    $dealerCodeQuery->whereIn('dealer_code', $dealerCode);
                } else {
                    $dealerCodeQuery->whereIn('id', $dealerId);
                }
                $dealerCode = $dealerCodeQuery->pluck('dealer_code')->toArray();
            } else {
                $dealerCode = [$this->dealer_code];
                $dealerId   = $this->dealer_id;
            }
        }

        $mktgCatg = DB::table('MKTG_CATG_MAST')
            ->join('PROD_CATG_MAST', 'PROD_CATG_MAST.MKTG_CATG', '=', 'MKTG_CATG_MAST.MKTG_CATG')
            ->select('MKTG_CATG_NAME as name', DB::raw("COUNT(PROD_CATG_MAST.id) as count_prod"))
            ->groupBy('MKTG_CATG_MAST.MKTG_CATG')
            ->orderBy('MKTG_CATG_MAST.id', 'ASC')
            ->get();

        $prodCatg = DB::table('PROD_CATG_MAST')
            ->join('MKTG_CATG_MAST', 'PROD_CATG_MAST.MKTG_CATG', '=', 'MKTG_CATG_MAST.MKTG_CATG')
            ->groupBy('PROD_CATG_MAST.PROD_CATG')
            ->orderBy('PROD_CATG_MAST.USER_SEQ', 'ASC')
            ->pluck('PROD_CATG_MAST.PROD_CATG_NAME', 'PROD_CATG_MAST.PROD_CATG');

        return response()->json([
            'success' => true,
            'data'    => [
                'mktg_catg'    => $mktgCatg,
                'prod_catg'    => $prodCatg,
                'current_menu' => $this->current_menu,
                'from_date'    => $fromDate,
                'to_date'      => $toDate,
            ],
        ]);
    }
}