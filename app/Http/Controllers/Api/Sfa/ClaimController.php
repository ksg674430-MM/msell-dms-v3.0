<?php
namespace App\Http\Controllers\API\Sfa;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ClaimController extends Controller
{
    /**
     * Resolve the logged-in dealer context (dealer_id + company_id) from the request.
     * Adjust to match your existing helper/trait — this is a placeholder.
     *
     * @return object{dealer_id:int, company_id:int}
     */
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

    /**
     * Build an absolute URL for a damage_replace_retailer image.
     */
    protected function damageImageUrl(?string $filename): ?string
    {
        if (empty($filename)) {
            return null;
        }

        // If the column already stores an absolute URL, return it as-is.
        if (preg_match('#^https?://#i', $filename)) {
            return $filename;
        }

        $base = rtrim(config('app.asset_url', url('/')), '/') . '/uploads/damage_replace_retailer';

        return $base . '/' . ltrim($filename, '/');
    }

    /* =============================================================
     * GET /claims/sfa
     * Query: from_date, to_date, user_ids[]
     * Returns: { data: [{ replaceid, state, district, town, user_name,
     *                     dealer_code, dealer_name, retailer_code,
     *                     retailer_name, images: [absolute urls] }] }
     * ============================================================= */
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

        $from = date('Y-m-d', strtotime($request->input('from_date')));
        $to   = date('Y-m-d', strtotime($request->input('to_date')));

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
                    'drr.image_name1',
                    'drr.image_name2',
                    'drr.image_name3',
                    'drr.image_name4',
                    'drr.image_name5',
                ])
                ->where('drr.company_id',    $companyId)
                ->where('person.company_id', $companyId)
                ->where('dealer.company_id', $companyId)
                ->where('dealer.id',         $dealerId)
                ->whereDate('drr.date_time', '>=', $from)
                ->whereDate('drr.date_time', '<=', $to)
                ->when($request->filled('user_ids'), function ($q) use ($request) {
                    $q->whereIn('person.id', (array) $request->input('user_ids'));
                })
                ->orderBy('drr.replaceid')
                ->get();

            $data = $rows->groupBy('replaceid')->map(function ($group) {
                $first = $group->first();

                $images = [];
                foreach (['image_name1', 'image_name2', 'image_name3', 'image_name4', 'image_name5'] as $col) {
                    foreach ($group as $row) {
                        $url = $this->damageImageUrl($row->{$col} ?? null);
                        if ($url !== null) {
                            $images[] = $url;
                        }
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
            Log::error('sfaClaims failed', ['error' => $e->getMessage()]);

            return response()->json([
                'code'    => 500,
                'message' => 'Unable to fetch SFA claims',
            ], 500);
        }
    }

    /* =============================================================
     * GET /claims/sfa/{replaceid}
     * Returns: { data: [{ sku_name, mfg_date, mrp, batch_no,
     *                     prod_qty, prod_value }] }
     * ============================================================= */
    public function sfaClaimDetails(Request $request, $replaceid)
    {
        $ctx = $this->dealerContext($request);

        $companyId = $ctx->company_id;
        $dealerId  = $ctx->dealer_id;

        try {
            // Ownership check — don't leak existence of another dealer's replaceid.
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
                    'drr.batch_no',
                    'drr.mrp',
                    DB::raw("CASE
                                WHEN drr.mfg_date IS NULL OR drr.mfg_date = '0000-00-00' THEN ''
                                ELSE DATE_FORMAT(drr.mfg_date, '%d-%m-%y')
                             END as mfg_date"),
                    DB::raw('SUM(drr.prod_qty)   as prod_qty'),
                    DB::raw('SUM(drr.prod_value) as prod_value'),
                ])
                ->where('drr.replaceid',  $replaceid)
                ->where('drr.company_id', $companyId)
                ->where('drr.dis_code',   $dealerId)
                ->groupBy(
                    'catalog_product.id',
                    'catalog_product.name',
                    'drr.mrp',
                    'drr.batch_no',
                    'drr.mfg_date'
                )
                ->get()
                ->map(function ($row) {
                    return [
                        'sku_name'   => $row->sku_name,
                        'mfg_date'   => $row->mfg_date ?: '',
                        'mrp'        => is_null($row->mrp) ? 0 : (float) $row->mrp,
                        'batch_no'   => $row->batch_no,
                        'prod_qty'   => is_null($row->prod_qty) ? 0 : (float) $row->prod_qty,
                        'prod_value' => is_null($row->prod_value) ? 0 : (float) $row->prod_value,
                    ];
                })
                ->values();

            return response()->json(['data' => $data]);

        } catch (\Throwable $e) {
            Log::error('sfaClaimDetails failed', [
                'replaceid' => $replaceid,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'code'    => 500,
                'message' => 'Unable to fetch SFA claim details',
            ], 500);
        }
    }
}