<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Api\SchemeController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminSchemeController extends Controller
{
    use RequiresAdmin;

    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        return response()->json([
            'status' => true,
            'rows'   => DB::select(
                "SELECT m.scheme_no, m.scheme_from_date, m.scheme_to_date, m.scheme_type, m.scheme_des,
                        MIN(d.circular_no) AS circular_no, MIN(c.circular_date) AS circular_date
                   FROM scheme_master_man m
                   JOIN scheme_domain_man d ON d.scheme_no = m.scheme_no AND d.status = 1
                   LEFT JOIN circular_master_man c ON c.circular_no = d.circular_no
                  GROUP BY m.scheme_no, m.scheme_from_date, m.scheme_to_date, m.scheme_type, m.scheme_des
                  ORDER BY m.scheme_from_date DESC, m.scheme_no"
            ),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        return response()->json([
            'status' => true,
            'mktg'   => DB::select("SELECT MKTG_CATG AS value, CONCAT(MKTG_CATG, ' - ', MKTG_CATG_NAME) AS label FROM MKTG_CATG_MAST ORDER BY MKTG_CATG_NAME"),
            'prod'   => DB::select("SELECT PROD_CATG AS value, MIN(CONCAT(PROD_CATG, ' - ', PROD_CATG_NAME)) AS label FROM PROD_CATG_MAST GROUP BY PROD_CATG ORDER BY label"),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        $v = Validator::make($request->all(), [
            'circular_no'        => ['required', 'string', 'max:12'],
            'circular_date'      => ['required', 'date_format:Y-m-d'],
            'scheme_no'          => ['required', 'string', 'max:12', Rule::unique('scheme_master_man', 'scheme_no')],
            'scheme_from_date'   => ['required', 'date_format:Y-m-d'],
            'scheme_to_date'     => ['required', 'date_format:Y-m-d', 'after_or_equal:scheme_from_date'],
            'scheme_type'        => ['required', Rule::in(['1', '2'])],
            'scheme_benefically' => ['required', Rule::in(['1'])],
            'scheme_description' => ['nullable', 'string', 'max:120'],
            'sale_type'          => ['required', Rule::in(['BOX', 'PCS', 'AMT'])],
            'mktg_all'           => ['boolean'],
            'mktg_selected'      => ['nullable', 'array'],
            'mktg_selected.*'    => ['string', 'max:101'],
            'mainline_all'       => ['boolean'],
            'prod_all'           => ['boolean'],
            'prod_selected'      => ['nullable', 'array'],
            'prod_selected.*'    => ['string', 'max:20'],
            'dealer_all'         => ['boolean'],
            'slabs'                     => ['required', 'array', 'min:1'],
            'slabs.*.target_from'       => ['required', 'date_format:Y-m-d'],
            'slabs.*.target_to'         => ['required', 'date_format:Y-m-d'],
            'slabs.*.incentive_from'    => ['required', 'date_format:Y-m-d'],
            'slabs.*.incentive_to'      => ['required', 'date_format:Y-m-d'],
            'slabs.*.base_from'         => ['required', 'numeric', 'min:0'],
            'slabs.*.base_to'           => ['nullable', 'numeric', 'min:0'],
            'slabs.*.rate'              => ['required', 'numeric', 'gt:0'],
        ], [
            'scheme_no.unique'             => 'This scheme no. already exists.',
            'scheme_to_date.after_or_equal'=> 'Scheme To Date cannot be before From Date.',
            'slabs.required'               => 'Add at least one item details row.',
            'slabs.*.target_from.required' => 'Fill every date in the item details rows.',
            'slabs.*.target_to.required'   => 'Fill every date in the item details rows.',
            'slabs.*.incentive_from.required' => 'Fill every date in the item details rows.',
            'slabs.*.incentive_to.required'   => 'Fill every date in the item details rows.',
            'slabs.*.base_from.required'   => 'Enter the From % in every row.',
            'slabs.*.rate.gt'              => 'Rate % must be above 0 in every row.',
        ]);
        if ($v->fails()) return response()->json(['status' => false, 'message' => $v->errors()->first(), 'errors' => $v->errors()], 422);

        foreach ($request->input('slabs') as $s) {
            if ($s['target_to'] < $s['target_from'] || $s['incentive_to'] < $s['incentive_from']) {
                return response()->json(['status' => false, 'message' => 'A To date in the item details is before its From date.'], 422);
            }
            if (($s['base_to'] ?? '') !== '' && $s['base_to'] !== null && (float) $s['base_to'] < (float) $s['base_from']) {
                return response()->json(['status' => false, 'message' => 'To % cannot be less than From % in the item details.'], 422);
            }
        }

        $mktg = $request->boolean('mktg_all') ? DB::table('MKTG_CATG_MAST')->pluck('MKTG_CATG')->all()
            : DB::table('MKTG_CATG_MAST')->whereIn('MKTG_CATG', (array) $request->input('mktg_selected', []))->pluck('MKTG_CATG')->all();
        $prod = $request->boolean('prod_all') ? DB::table('PROD_CATG_MAST')->distinct()->pluck('PROD_CATG')->all()
            : DB::table('PROD_CATG_MAST')->whereIn('PROD_CATG', (array) $request->input('prod_selected', []))->distinct()->pluck('PROD_CATG')->all();
        if (!$mktg && !$prod && !$request->boolean('mainline_all')) {
            return response()->json(['status' => false, 'message' => 'Select at least one item category for the scheme.'], 422);
        }

        $circular = trim($request->input('circular_no'));
        $scheme = trim($request->input('scheme_no'));
        $saleType = $request->input('sale_type');
        $now = now();
        $domain = fn (array $row) => $row + ['circular_no' => $circular, 'scheme_no' => $scheme, 'created_at' => $now, 'status' => 1];

        $dealerCount = DB::transaction(function () use ($request, $circular, $scheme, $saleType, $now, $mktg, $prod, $domain) {
            if (!DB::table('circular_master_man')->where('circular_no', $circular)->exists()) {
                DB::table('circular_master_man')->insert(['circular_no' => $circular, 'circular_date' => $request->input('circular_date'), 'created_at' => $now, 'status' => 1]);
            }
            DB::table('scheme_master_man')->insert([
                'scheme_no'        => $scheme,
                'scheme_from_date' => $request->input('scheme_from_date'),
                'scheme_to_date'   => $request->input('scheme_to_date'),
                'scheme_type'      => $request->input('scheme_type'),
                'scheme_benific'   => $request->input('scheme_benefically'),
                'scheme_des'       => trim((string) $request->input('scheme_description', '')),
                'created_at'       => $now->toDateString(),
                'status'           => 1,
            ]);

            $rows = array_merge(
                array_map(fn ($c) => $domain(['item_code' => '0', 'mktg_catg' => $c, 'prod_catg' => '0', 'dealer_code' => '0', 'sale_type' => $saleType]), $mktg),
                array_map(fn ($c) => $domain(['item_code' => '0', 'mktg_catg' => '0', 'prod_catg' => $c, 'dealer_code' => '0', 'sale_type' => $saleType]), $prod),
                $request->boolean('mainline_all') ? [$domain(['item_code' => '0', 'mktg_catg' => 'mainline', 'prod_catg' => '0', 'dealer_code' => '0', 'sale_type' => $saleType])] : [],
            );
            foreach (array_chunk($rows, 500) as $chunk) DB::table('scheme_domain_man')->insert($chunk);

            $dealers = 0;
            if ($request->boolean('dealer_all')) {
                $codes = [];
                $active = DB::table('dealer')->where('company_id', self::ERP_COMPANY)->where('dealer_status', 1)->whereNotIn('dealer_code', ['', '0'])->distinct()->pluck('dealer_code')->all();
                foreach (array_chunk($active, 1000) as $chunk) {
                    array_push($codes, ...DB::table('ACC_MAST')->whereIn('ACC_CODE', $chunk)->where('ACC_STATUS', '!=', 'C')->distinct()->pluck('ACC_CODE')->all());
                }
                foreach (array_chunk($codes, 500) as $chunk) {
                    DB::table('scheme_domain_man')->insert(array_map(fn ($c) => $domain(['item_code' => '0', 'mktg_catg' => '', 'prod_catg' => '0', 'dealer_code' => $c, 'sale_type' => null]), $chunk));
                }
                $dealers = count($codes);
            }

            DB::table('scheme_slab_man')->insert(array_map(fn ($s) => [
                'target_sale_from_date'    => $s['target_from'],
                'target_sale_to_date'      => $s['target_to'],
                'incentive_sale_from_date' => $s['incentive_from'],
                'incentive_sale_to_date'   => $s['incentive_to'],
                'base_from'                => (string) $s['base_from'],
                'base_to'                  => ($s['base_to'] ?? '') === '' || $s['base_to'] === null ? 'Above' : (string) $s['base_to'],
                'free_final_rate'          => (string) $s['rate'],
                'scheme_no'                => $scheme,
                'circular_no'              => $circular,
                'created_at'               => $now,
                'status'                   => 1,
            ], $request->input('slabs')));

            return $dealers;
        });

        return response()->json([
            'status'  => true,
            'message' => "Scheme {$scheme} created" . ($dealerCount ? " for {$dealerCount} distributors." : ($request->boolean('dealer_all') ? '. No active distributor matched the ERP account list, so no distributor will see it yet.' : '. No distributors were selected, so no distributor will see it yet.')),
        ], 201);
    }

    public function distributors(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        return response()->json([
            'status' => true,
            'rows'   => DB::select(
                "SELECT dealer_code AS value, MIN(CONCAT(dealer_code, ' - ', name)) AS label
                   FROM dealer
                  WHERE company_id = ? AND dealer_status = 1 AND COALESCE(dealer_code, '') NOT IN ('', '0')
                  GROUP BY dealer_code
                  ORDER BY label",
                [$ctx->company_id]
            ),
        ]);
    }

    public function distributorScheme(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        $code = trim((string) $request->query('dealer_code', ''));
        if ($code === '' || !DB::table('dealer')->where('company_id', $ctx->company_id)->where('dealer_code', $code)->exists()) {
            return response()->json(['status' => false, 'message' => 'Select a distributor.'], 422);
        }
        $request->merge(['admin_status' => 1, 'dealer_code' => $code]);
        return app(SchemeController::class)->dealerScheme($request);
    }
}
