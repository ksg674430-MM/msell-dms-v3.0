<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class DepotDamageLookupController extends Controller
{
    /** CSA list for the current dealer */
    public function csas(Request $request)
    {
        $u = $request->user();

        $rows = DB::table('csa')
            ->join('dealer', 'dealer.csa_id', '=', 'csa.c_id')
            ->where('dealer.id', $u->dealer_id)
            ->get([
                'csa.c_id as id',
                DB::raw("csa.csa_name as display"),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function products(Request $request)
    {
        $u = $request->user();
        // dd($u);
        $companyIdCus = (int) $u->company_id;

        if ($companyIdCus === 213) {
            // All products of the company — matches depot form 213 branch
            $rows = DB::table('catalog_product as cp')
                ->where('cp.company_id', $companyIdCus)
                ->groupBy('cp.id')
                ->orderBy('cp.id')
                ->get([
                    'cp.id',
                    DB::raw("CONCAT_WS(' ', cp.name, '[', SUM(cp.quantity_per_case), ']') as display"),
                ]);
        } else {
            $rows = DB::table('catalog_product as cp')
                ->join('stock as s', 'cp.id', '=', 's.product_id')
                ->leftJoin('other_company as oc', 'cp.company_id', '=', 'oc.id')
                ->where('s.dealer_id', $u->dealer_id)
                ->where('cp.company_id', $companyIdCus)
                ->groupBy('s.product_id')
                ->orderBy('cp.id')
                ->get([
                    'cp.id',
                    DB::raw("CONCAT_WS(' ', cp.name, '[', COALESCE(oc.cp_short_name,''), ']', '[', SUM(s.qty), ']') as display"),
                ]);
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Product MRP / GST for a depot user.
     *   pkigst = 0 in the form, but for 213 the tax split is CGST/SGST.
     */
    public function productMrp(Request $request, int $id)
    {
        $u = $request->user();
        $dealerId     = (int) $u->dealer_id;
        $companyIdCus = (int) $u->company_id;
        $stateId      = (string) ($u->state_id ?? 0);
        $templateId   = (int) ($u->template_id ?? 0);
        $qtyType      = (string) ($u->quantity_type ?? '1');
        $mrpCol       = $qtyType === '0' ? 'mrp_pcs' : 'mrp';

        if ($companyIdCus === 213) {
            $base = DB::table('catalog_product as p')
                ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
                ->where('p.id', $id);

            if ($templateId > 0) {
                $base->join('product_rate_list_template as prl', 'prl.product_id', '=', 'p.id')
                     ->where('prl.template_type', (string) $templateId);
            } else {
                $base->join('product_rate_list as prl', 'prl.product_id', '=', 'p.id')
                     ->where('prl.state_id', $stateId);
            }

            $row = $base->first([
                'p.id as product_id',
                DB::raw("CAST(prl.{$mrpCol} AS DECIMAL(16,2)) as mrp"),
                DB::raw("CAST(COALESCE(t.igst,0) AS DECIMAL(6,2)) as gst"),
                DB::raw("CAST(COALESCE(t.sgst,0) AS DECIMAL(6,2)) as sgst"),
                DB::raw("CAST(COALESCE(t.cgst,0) AS DECIMAL(6,2)) as cgst"),
            ]);
        } else {
            $row = DB::table('catalog_product as p')
                ->join('stock as s', 'p.id', '=', 's.product_id')
                ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
                ->where('s.dealer_id', $dealerId)
                ->where('p.id', $id)
                ->first([
                    'p.id as product_id',
                    DB::raw('CAST(s.mrp AS DECIMAL(16,2)) as mrp'),
                    DB::raw('CAST(COALESCE(t.igst,0) AS DECIMAL(6,2)) as gst'),
                    DB::raw('CAST(COALESCE(t.sgst,0) AS DECIMAL(6,2)) as sgst'),
                    DB::raw('CAST(COALESCE(t.cgst,0) AS DECIMAL(6,2)) as cgst'),
                ]);
        }

        return response()->json(['data' => $row]);
    }

    public function productRateStock(Request $request, int $id)
    {
        $u = $request->user();
        $dealerId     = (int) $u->dealer_id;
        $companyIdCus = (int) $u->company_id;
        $stateId      = (string) ($u->state_id ?? 0);
        $templateId   = (int) ($u->template_id ?? 0);
        $qtyType      = (string) ($u->quantity_type ?? '1');
        $role         = strtoupper((string) ($u->rolename ?? ''));

        $mrp     = $request->query('mrp');
        $batchNo = $request->query('batch_no');
        $mfgDate = $request->query('mfg_date');

        // Depot always picks pcs rate for 213's depot form per the sample
        // (see `rateNstockDamage` depot branch → `dealer_pcs_rate`).
        $rateCol = 'dealer_pcs_rate';  // this is what the depot branch used

        $rate = DB::table('catalog_product as p')
            ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
            ->where('p.id', $id);
        $rateTable = $companyIdCus === 213 && $templateId > 0 ? 'product_rate_list_template' : 'product_rate_list';
        $rate->leftJoin("$rateTable as prl", function ($j) use ($rateTable, $templateId, $stateId, $companyIdCus) {
            $j->on('prl.product_id', '=', 'p.id');
            if ($rateTable === 'product_rate_list_template') {
                $j->where('prl.template_type', (string) $templateId);
            } elseif ($companyIdCus === 213) {
                $j->where('prl.state_id', $stateId);
            }
        });
        $row = $rate->first([
            DB::raw("CAST(COALESCE(prl.{$rateCol},0) AS DECIMAL(16,2)) as raw_rate"),
            DB::raw('CAST(COALESCE(t.igst,0) AS DECIMAL(6,2)) as igst'),
        ]);
        if (!$row) {
            return response()->json(['data' => null]);
        }

        $mfg = null;
        if ($mfgDate) {
            $ts  = strtotime(str_replace('/', '-', (string) $mfgDate));
            $mfg = $ts ? date('Y-m-d', $ts) : null;
        }
        $qty = app(StockService::class)->available(
            $dealerId, $id,
            $mrp !== null && $mrp !== '' ? (float) $mrp : null,
            $batchNo !== null && $batchNo !== '' ? (string) $batchNo : null,
            $mfg
        );

        $beforeGst = round($row->raw_rate - ($row->raw_rate * (100 / (100 + (float) $row->igst))), 3);
        $netRate   = round($row->raw_rate - $beforeGst, 2);

        return response()->json(['data' => [
            'product_id' => $id,
            'qty'        => $qty,
            'igst'       => $row->igst,
            'rate'       => $netRate,
        ]]);
    }

    public function productDealerRate(Request $request, int $id)
    {
        // same as dealer version — reuse that controller if you want
        return app(\App\Http\Controllers\Api\DamageLookupController::class)
            ->productDealerRate($request, $id);
    }

    public function productGst(Request $request, int $id)
    {
        return app(\App\Http\Controllers\Api\DamageLookupController::class)
            ->productGst($request, $id);
    }

    public function saleableNonSaleable(Request $request)
    {
        $u = $request->user();
        $rows = DB::table('saleable_non_saleable')
            ->where('company_id', $u->company_id)
            ->get(['id', 'name']);
        return response()->json(['data' => $rows]);
    }
}