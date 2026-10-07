<?php

namespace App\Http\Controllers\Api\Sfa;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClaimController extends Controller
{
    protected function dealerContext(Request $request)
    {
        $dealerId  = $request->attributes->get('dealer_id')  ?? auth()->user()->dealer_id  ?? null;
        $companyId = $request->attributes->get('company_id') ?? auth()->user()->company_id ?? null;

        abort_if(empty($dealerId) || empty($companyId), 401, 'Dealer context missing');

        return (object) [
            'dealer_id'  => (int) $dealerId,
            'company_id' => (int) $companyId,
        ];
    }

    protected function damageImageUrl(?string $filename): ?string
    {
        if (empty($filename)) {
            return null;
        }
        if (preg_match('#^https?://#i', $filename)) {
            return $filename;
        }
        // CONFIRM this folder — the DB stores just 'claim_100001_1.jpg'
        $base = rtrim(config('app.asset_url', url('/')), '/') . '/uploads/damage_replace_retailer';

        return $base . '/' . ltrim($filename, '/');
    }

    /* =========================================================
     * GET /claims/sfa
     * ========================================================= */
    public function sfaClaims(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'from_date'  => 'required|date',
            'to_date'    => 'required|date|after_or_equal:from_date',
            'user_ids'   => 'nullable|array',
            'user_ids.*' => 'integer',
        ]);

        $companyId = $ctx->company_id;
        $dealerId  = $ctx->dealer_id;

        $from = date('Y-m-d', strtotime($request->input('from_date'))) . ' 00:00:00';
        $to   = date('Y-m-d', strtotime($request->input('to_date')))   . ' 23:59:59';
        
        try {
            $rows = DB::table('damage_replace_retailer as drr')
                ->join('person',     'person.id',     '=', 'drr.user_id')
                ->join('dealer',     'dealer.id',     '=', 'drr.dis_code')
                ->join('retailer',   'retailer.id',   '=', 'drr.ret_code')
                ->join('location_3', 'location_3.id', '=', 'dealer.state_id')
                ->join('location_6', 'location_6.id', '=', 'dealer.town_id')
                ->join('location_5', 'location_5.id', '=', 'location_6.location_5_id')
                ->join('location_4', 'location_4.id', '=', 'location_5.location_4_id')
                ->select([
                    'drr.replaceid',
                    'dealer.dealer_code',
                    'dealer.name as dealer_name',
                    'retailer.retailer_code',
                    'retailer.name as retailer_name',
                    'location_3.name as state',
                    'location_4.name as district',
                    'location_6.name as town',
                    DB::raw("CONCAT_WS(' ', person.first_name, person.middle_name, person.last_name) as user_name"),
                    'drr.image',
                ])
                ->where('drr.company_id',    $companyId)
                ->where('person.company_id', $companyId)
                ->where('dealer.company_id', $companyId)
                ->where('dealer.id',         $dealerId)
                ->where('drr.date_time', '>=', $from)
                ->where('drr.date_time', '<=', $to)
                ->when($request->filled('user_ids'), function ($q) use ($request) {
                    $q->whereIn('person.id', (array) $request->input('user_ids'));
                })
                ->orderBy('drr.replaceid')
                ->get();
            $data = $rows->groupBy('replaceid')->map(function ($group) {
                $first = $group->first();

                $images = [];
                foreach ($group as $row) {
                    $url = $this->damageImageUrl($row->image);
                    if ($url !== null) {
                        $images[] = $url;
                    }
                }

                return [
                    'replaceid'     => $first->replaceid,
                    'state'         => $first->state,
                    'district'      => $first->district,
                    'town'          => $first->town,
                    'user_name'     => $first->user_name,
                    'dealer_code'   => $first->dealer_code,
                    'dealer_name'   => $first->dealer_name,
                    'retailer_code' => $first->retailer_code,
                    'retailer_name' => $first->retailer_name,
                    'images'        => array_values(array_unique($images)),
                ];
            })->values();

            return response()->json(['data' => $data]);

        } catch (\Throwable $e) {
            Log::error('sfaClaims failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'code'    => 500,
                'message' => 'Unable to fetch SFA claims',
            ], 500);
        }
    }

    /* =========================================================
     * GET /claims/sfa/{replaceid}
     * ========================================================= */
    public function sfaClaimDetails(Request $request, $replaceid)
    {
        $ctx = $this->dealerContext($request);

        $companyId = $ctx->company_id;
        $dealerId  = $ctx->dealer_id;

        try {
            $owns = DB::table('damage_replace_retailer')
                ->where('replaceid',  $replaceid)
                ->where('company_id', $companyId)
                ->where('dis_code',   $dealerId)
                ->exists();

            if (!$owns) {
                return response()->json([
                    'code'    => 404,
                    'message' => 'Not found',
                ], 404);
            }

            $data = DB::table('damage_replace_retailer as drr')
                ->join('catalog_product', 'catalog_product.id', '=', 'drr.prod_code')
                ->select([
                    'catalog_product.name as sku_name',
                    'drr.mrp',
                    DB::raw('SUM(drr.prod_qty)   as prod_qty'),
                    DB::raw('SUM(drr.prod_value) as prod_value'),
                ])
                ->where('drr.replaceid',  $replaceid)
                ->where('drr.company_id', $companyId)
                ->where('drr.dis_code',   $dealerId)
                ->groupBy(
                    'catalog_product.id',
                    'catalog_product.name',
                    'drr.mrp'
                )
                ->get()
                ->map(function ($row) {
                    return [
                        'sku_name'   => $row->sku_name,
                        'mfg_date'   => null,   // column does not exist on damage_replace_retailer
                        'mrp'        => is_null($row->mrp)        ? 0 : (float) $row->mrp,
                        'batch_no'   => null,   // column does not exist on damage_replace_retailer
                        'prod_qty'   => is_null($row->prod_qty)   ? 0 : (float) $row->prod_qty,
                        'prod_value' => is_null($row->prod_value) ? 0 : (float) $row->prod_value,
                    ];
                })
                ->values();

            return response()->json(['data' => $data]);

        } catch (\Throwable $e) {
            Log::error('sfaClaimDetails failed', [
                'replaceid' => $replaceid,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return response()->json([
                'code'    => 500,
                'message' => 'Unable to fetch SFA claim details',
            ], 500);
        }
    }
}