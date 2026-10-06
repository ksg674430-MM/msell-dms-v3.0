<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Location3;
use App\Location6;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DMSClaimReportController extends Controller
{
    protected $dealer_id;
    protected $company_id = 213;

    public function __construct()
    {
        // $this->middleware('auth:sanctum');
    }

    private function initAuth(Request $request): void
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Unauthenticated.');
        }
        $this->dealer_id  = $user->dealer_id ?? 0;
        $this->company_id = $user->company_id ?? 213;
    }

    private function resolveDateRange(Request $request): array
    {
        $range = $request->input('date_range_picker');
        if (empty($range)) {
            abort(422, 'date_range_picker is required (format: "YYYY-MM-DD - YYYY-MM-DD").');
        }

        $parts = explode(' -', $range);
        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    /**
     * Static filter data (states / towns) for the claim report screen.
     */
    public function dmsClaimReport(Request $request)
    {
        $this->initAuth($request);

        return response()->json([
            'success' => true,
            'data'    => [
                'states' => Location3::where('status', 1)
                    ->where('company_id', $this->company_id)
                    ->pluck('name', 'id'),
                'towns'  => Location6::where('status', 1)
                    ->where('company_id', $this->company_id)
                    ->pluck('name', 'id'),
            ],
        ]);
    }

    /**
     * Consolidated DGR + Sampling + Rate Difference claim report.
     */
    public function dmsClaim(Request $request)
    {
        $this->initAuth($request);

        [$fromDate, $toDate] = $this->resolveDateRange($request);
        $companyId = $this->company_id;

        $state = Location3::where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');
        $town  = Location6::where('status', 1)->where('company_id', $companyId)->pluck('name', 'id');

        /* ---------------- DGR CLAIM ---------------- */
        $approvalStatus = DB::table('dms_dealer_claim_level_approval')
            ->where('company_id', $companyId)
            ->groupBy('id')
            ->get();

        $approvalData = [];
        foreach ($approvalStatus as $row) {
            $approvalData[$row->order_id . $row->level_sequence] = $row;
        }

        $dgrQuery = DB::table('dms_dealer_claim')
            ->join('dms_dealer_claim_details', 'dms_dealer_claim_details.order_id', '=', 'dms_dealer_claim.order_id')
            ->join('dealer', 'dealer.id', '=', 'dms_dealer_claim.dealer_id')
            ->select(
                'dms_dealer_claim.dealer_id',
                'dms_dealer_claim.order_id',
                'dealer.name as dealer_name',
                'dealer.state_id',
                'dealer.town_id',
                'dms_dealer_claim.date',
                DB::raw("SUM(rate*quantity) as claim_amount"),
                'dealer.dealer_code'
            )
            ->where('dms_dealer_claim.dealer_id', $this->dealer_id)
            ->where('dms_dealer_claim.company_id', $companyId)
            ->where('dealer.company_id', $companyId)
            ->whereRaw("DATE_FORMAT(dms_dealer_claim.date,'%Y-%m-%d') >='$fromDate' and DATE_FORMAT(dms_dealer_claim.date,'%Y-%m-%d') <='$toDate'")
            ->where('claim_status', '=', '0')
            ->groupBy('dms_dealer_claim_details.order_id')
            ->get();

        $dgrData = [];
        foreach ($dgrQuery as $value) {
            $out = [
                'dealer_name'  => $value->dealer_name,
                'date'         => date('Y-m-d', strtotime($value->date)),
                'claim_type'   => 'DGR',
                'claim_amount' => round($value->claim_amount, 2),
                'dealer_code'  => $value->dealer_code,
                'claim_no'     => $value->order_id,
                'state_id'     => $value->state_id,
                'town_id'      => $value->town_id,
                'order_id'     => $value->order_id,
                'dealer_id'    => $value->dealer_id,
            ];
            $out = array_merge($out, $this->buildApprovalBlock($approvalData, $value->order_id));
            $dgrData[] = $out;
        }

        /* ---------------- SAMPLING CLAIM ---------------- */
        $approvalStatus2 = DB::table('dms_retailer_images_amount_claim_level_approval')
            ->where('company_id', $companyId)
            ->groupBy('id')
            ->get();

        $approvalData2 = [];
        foreach ($approvalStatus2 as $row) {
            $approvalData2[$row->order_id . $row->level_sequence] = $row;
        }

       $samplingQuery = DB::table('samplingclaim')
    ->join('samplingclaim_details', 'samplingclaim_details.sampling_id', '=', 'samplingclaim.id')
    ->join('dealer', 'dealer.id', '=', 'samplingclaim.dealer_id')
    ->select([
        'samplingclaim.dealer_id',
        'samplingclaim.order_id',
        'dealer.name as dealer_name',
        'dealer.state_id',
        'dealer.town_id',
        'dealer.dealer_code',
        DB::raw("DATE_FORMAT(samplingclaim.date_time, '%Y-%m-%d') as date"),
        DB::raw("SUM(rate_case * sampling_qty) as claim_amount"),
    ])
    ->where('samplingclaim.dealer_id', $this->dealer_id)
    ->where('samplingclaim.company_id', $companyId)
    ->where('dealer.company_id', $companyId)
    ->whereDate('samplingclaim.date_time', '>=', $fromDate)
    ->whereDate('samplingclaim.date_time', '<=', $toDate)
    ->where('samplingclaim.claim_status', '0')
    ->groupBy(
        'samplingclaim.order_id',
        'samplingclaim.dealer_id',
        'dealer.name',
        'dealer.state_id',
        'dealer.town_id',
        'dealer.dealer_code',
        DB::raw("DATE_FORMAT(samplingclaim.date_time, '%Y-%m-%d')")
    )
    ->get();

        $samplingData = [];
        foreach ($samplingQuery as $value) {
            $out = [
                'dealer_name'  => $value->dealer_name,
                'date'         => date('Y-m-d', strtotime($value->date)),
                'claim_type'   => 'Sampling',
                'claim_no'     => $value->order_id,
                'claim_amount' => round($value->claim_amount, 2),
                'dealer_code'  => $value->dealer_code,
                'state_id'     => $value->state_id,
                'town_id'      => $value->town_id,
                'order_id'     => $value->order_id,
                'dealer_id'    => $value->order_id,
            ];
            $out = array_merge($out, $this->buildApprovalBlock($approvalData2, $value->order_id));
            $samplingData[] = $out;
        }

        /* ---------------- RATE DIFFERENCE CLAIM ---------------- */
        $approvalStatus3 = DB::table('dms_challan_claim_level_approval')
            ->where('company_id', $companyId)
            ->groupBy('id')
            ->get();

        $approvalData3 = [];
        foreach ($approvalStatus3 as $row) {
            $approvalData3[$row->order_id . $row->level_sequence] = $row;
        }

        $rateDiffQuery = DB::table('dms_challan_claim')
    ->join('dms_challan_claim_details', 'dms_challan_claim_details.order_id', '=', 'dms_challan_claim.order_id')
    ->join('dealer', 'dealer.id', '=', 'dms_challan_claim.dealer_id')
    ->select([
        'dms_challan_claim.dealer_id',
        'dms_challan_claim.order_id',
        'dealer.name as dealer_name',
        'dealer.state_id',
        'dealer.town_id',
        'dealer.dealer_code',
        DB::raw("DATE_FORMAT(dms_challan_claim.date_time, '%Y-%m-%d') as date"),
        DB::raw("SUM(challan_rate * challan_qty) as claim_amount"),
    ])
    ->where('dms_challan_claim.company_id', $companyId)
    ->where('dms_challan_claim.dealer_id', $this->dealer_id)
    ->where('dealer.company_id', $companyId)
    ->whereDate('dms_challan_claim.date_time', '>=', $fromDate)
    ->whereDate('dms_challan_claim.date_time', '<=', $toDate)
    ->where('dms_challan_claim.claim_status', '0')
    ->groupBy(
        'dms_challan_claim.order_id',
        'dms_challan_claim.dealer_id',
        'dealer.name',
        'dealer.state_id',
        'dealer.town_id',
        'dealer.dealer_code',
        DB::raw("DATE_FORMAT(dms_challan_claim.date_time, '%Y-%m-%d')")
    )
    ->get();
        $rateDiffData = [];
        foreach ($rateDiffQuery as $value) {
            $out = [
                'dealer_name'  => $value->dealer_name,
                'date'         => date('Y-m-d', strtotime($value->date)),
                'claim_amount' => round($value->claim_amount, 2),
                'claim_type'   => 'Rate Difference',
                'claim_no'     => $value->order_id,
                'dealer_code'  => $value->dealer_code,
                'state_id'     => $value->state_id,
                'town_id'      => $value->town_id,
                'order_id'     => $value->order_id,
                'dealer_id'    => $value->order_id,
            ];
            $out = array_merge($out, $this->buildApprovalBlock($approvalData3, $value->order_id));
            $rateDiffData[] = $out;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'dgr_data'             => $dgrData,
                'sampling_data'        => $samplingData,
                'rate_difference_data' => $rateDiffData,
                'state'                => $state,
                'town'                 => $town,
                'from_date'            => $fromDate,
                'to_date'              => $toDate,
            ],
        ]);
    }

    /**
     * Builds the three-level approval block used by all three claim types.
     */
    private function buildApprovalBlock(array $approvalData, $orderId): array
    {
        $block = [];
        for ($level = 1; $level <= 3; $level++) {
            $key = $orderId . $level;
            $levelWord = ['one', 'two', 'three'][$level - 1];

            if (!empty($approvalData[$key])) {
                $block["level_{$levelWord}_approved_on"] = $approvalData[$key]->approved_on ?: '';
                $block["level_{$levelWord}_status"]      = 'Approved';
            } else {
                $block["level_{$levelWord}_approved_on"] = '';
                $block["level_{$levelWord}_status"]      = 'Pending';
            }
        }
        return $block;
    }

    /* =========================================================
     *  CLAIM DETAIL MODALS
     * ========================================================= */
    public function newClaimApproval(Request $request)
    {
        $this->initAuth($request);

        $orderId   = $request->input('order_id');
        $companyId = $this->company_id;

        $checkLevelApproval = DB::table('dms_dealer_claim_level_approval')
            ->where('order_id', $orderId)
            ->where('company_id', $companyId)
            ->get();

        $dealerClaimData = DB::table('dms_dealer_claim')
            ->where('order_id', $orderId)
            ->where('company_id', $companyId)
            ->first();

        if (!empty($dealerClaimData->order_id_string)) {
            $orderIds     = explode(',', $dealerClaimData->order_id_string);
            $retailerChallan = DB::table('dms_retailer_claim')
                ->whereIn('order_id', $orderIds)
                ->where('company_id', $companyId)
                ->pluck('return_ch_no')
                ->toArray();

            $data = DB::table('damage_order_details')
                ->join('catalog_product', 'catalog_product.id', '=', 'damage_order_details.product_id')
                ->select(
                    'catalog_product.name as prod_name',
                    'damage_order_details.batch_no',
                    'damage_order_details.mrp',
                    'damage_order_details.product_rate as rate',
                    'damage_order_details.qty as quantity',
                    'damage_order_details.mfg_date',
                    'damage_order_details.product_id'
                )
                ->whereIn('damage_order_details.ch_id', $retailerChallan)
                ->where('catalog_product.company_id', $companyId)
                ->groupBy('damage_order_details.id')
                ->get();
        } else {
            $data = DB::table('dms_dealer_claim_details')
                ->join('catalog_product', 'catalog_product.id', '=', 'dms_dealer_claim_details.product_id')
                ->select('catalog_product.name as prod_name', 'dms_dealer_claim_details.*')
                ->where('order_id', $orderId)
                ->groupBy('dms_dealer_claim_details.id')
                ->get();
        }

        $logData = [];
        if ($checkLevelApproval->isNotEmpty()) {
            $dataLogs = DB::table('dms_dealer_claim_details_log')
                ->join('catalog_product', 'catalog_product.id', '=', 'dms_dealer_claim_details_log.product_id')
                ->select('catalog_product.name as prod_name', 'dms_dealer_claim_details_log.*')
                ->where('dms_dealer_claim_details_log.order_id', $orderId)
                ->where('dms_dealer_claim_details_log.company_id', $companyId)
                ->groupBy('dms_dealer_claim_details_log.id')
                ->get();

            foreach ($dataLogs as $value) {
                $logData[$value->level][] = $value;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'rows'     => $data,
                'log_data' => $logData,
            ],
        ]);
    }

    public function newClaimApproval1(Request $request)
    {
        $this->initAuth($request);

        $orderId   = $request->input('order_id');
        $companyId = $this->company_id;

        $checkLevelApproval = DB::table('dms_retailer_images_amount_claim_level_approval')
            ->where('order_id', $orderId)
            ->where('company_id', $companyId)
            ->get();

        $challan = DB::table('samplingclaim')
            ->join('dealer', 'dealer.id', '=', 'samplingclaim.dealer_id')
            ->select('samplingclaim.*', 'dealer.name as dealer_name')
            ->where('order_id', $orderId)
            ->where('samplingclaim.company_id', $companyId)
            ->where('dealer.company_id', $companyId)
            ->first();

        if (!$challan) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }

        $data = DB::table('samplingclaim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'samplingclaim_details.product_id')
            ->select('catalog_product.name as prod_name', 'samplingclaim_details.*')
            ->where('sampling_id', $challan->id)
            ->groupBy('samplingclaim_details.id')
            ->get();

        $logData = [];
        if ($checkLevelApproval->isNotEmpty()) {
            $dataLogs = DB::table('samplingclaim_details_log')
                ->join('catalog_product', 'catalog_product.id', '=', 'samplingclaim_details_log.product_id')
                ->select('catalog_product.name as prod_name', 'samplingclaim_details_log.*')
                ->where('samplingclaim_details_log.sampling_id', $challan->id)
                ->groupBy('samplingclaim_details_log.id')
                ->get();

            foreach ($dataLogs as $value) {
                $logData[$value->level][] = $value;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'rows'     => $data,
                'log_data' => $logData,
                'challan'  => $challan,
            ],
        ]);
    }

    public function newClaimApproval2(Request $request)
    {
        $this->initAuth($request);

        $orderId   = $request->input('order_id');
        $companyId = $this->company_id;

        $checkLevelApproval = DB::table('dms_challan_claim_level_approval')
            ->where('order_id', $orderId)
            ->where('company_id', $companyId)
            ->get();

        $challan = DB::table('dms_challan_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_challan_claim.dealer_id')
            ->select('dms_challan_claim.*', 'dealer.name as dealer_name')
            ->where('order_id', $orderId)
            ->where('dms_challan_claim.company_id', $companyId)
            ->where('dealer.company_id', $companyId)
            ->first();

        if (!$challan) {
            return response()->json(['success' => false, 'message' => 'Claim not found.'], 404);
        }

        $data = DB::table('dms_challan_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_challan_claim_details.product_id')
            ->select('catalog_product.name as prod_name', 'dms_challan_claim_details.*')
            ->where('order_id', $orderId)
            ->groupBy('dms_challan_claim_details.id')
            ->get();

        $logData = [];
        if ($checkLevelApproval->isNotEmpty()) {
            $dataLogs = DB::table('dms_challan_claim_details_approval_log')
                ->join('catalog_product', 'catalog_product.id', '=', 'dms_challan_claim_details_approval_log.product_id')
                ->select('catalog_product.name as prod_name', 'dms_challan_claim_details_approval_log.*')
                ->where('dms_challan_claim_details_approval_log.order_id', $orderId)
                ->groupBy('dms_challan_claim_details_approval_log.id')
                ->get();

            foreach ($dataLogs as $value) {
                $logData[$value->level][] = $value;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'rows'     => $data,
                'log_data' => $logData,
                'challan'  => $challan,
            ],
        ]);
    }
}