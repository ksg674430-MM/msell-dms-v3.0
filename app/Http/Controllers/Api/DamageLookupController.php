<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DamageLookupController extends Controller
{
    /* ---------------- Retailers ---------------- */

    public function retailers(Request $request)
    {
        $u = $request->user();

        $rows = DB::table('retailer')
            ->where('dealer_id', $u->dealer_id)
            ->where('retailer_status', 1)
            ->groupBy('id')
            ->orderBy('name')
            ->get([
                'id',
                DB::raw("CONCAT(name, ' [', COALESCE(address,''), ']') as display"),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function retailerLocation(Request $request, int $id)
    {
        $loc = DB::table('retailer')->where('id', $id)->value('location_id');
        return response()->json(['location_id' => $loc]);
    }

    /* ---------------- Products ---------------- */

    public function products(Request $request)
    {
        $u = $request->user();

        if ((int) $u->company_id === 213) {
            $rows = DB::table('catalog_product as cp')
                ->where('cp.company_id', $u->company_id)
                ->groupBy('cp.id')
                ->orderBy('cp.id')
                ->get([
                    'cp.id',
                    DB::raw("CONCAT(cp.name,'[',SUM(cp.quantity_per_case),']') as display"),
                ]);
        } else {
            $rows = DB::table('catalog_product as cp')
                ->join('stock as s', 'cp.id', '=', 's.product_id')
                ->leftJoin('other_company as oc', 'cp.company_id', '=', 'oc.id')
                ->where('s.dealer_id', $u->dealer_id)
                ->groupBy('s.product_id')
                ->orderBy('cp.id')
                ->get([
                    'cp.id',
                    DB::raw("CONCAT(cp.name,'[',COALESCE(oc.cp_short_name,''),'][',SUM(s.qty),']') as display"),
                ]);
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * MRP for a product — respects quantity_type and template_id,
     * mirrors getItemDetailsDamage.
     */
    public function productMrp(Request $request, int $id)
    {
        $u = $request->user();
        $dealerId     = (int) $u->dealer_id;
        $companyIdCus = (int) $u->company_id;
        $stateId      = (string) ($u->state_id ?? 0);
        $templateId   = (int) ($u->template_id ?? 0);
        $qtyType      = (string) ($u->quantity_type ?? '1');

        $mrpCol = $qtyType === '0' ? 'mrp_pcs' : 'mrp';

        if ($companyIdCus === 213) {

            $base = DB::table('catalog_product as p')
                ->join('product_rate_list as prl', 'prl.product_id', '=', 'p.id')
                ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
                ->where('p.id', $id);

            if ($templateId > 0) {
                $base = DB::table('catalog_product as p')
                    ->join('product_rate_list_template as prl', 'prl.product_id', '=', 'p.id')
                    ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
                    ->where('p.id', $id)
                    ->where('prl.template_type', (string) $templateId);
            } else {
                $base->where('prl.state_id', $stateId);
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

    /**
     * Rate + stock for a specific batch — mirrors rateNstockDamage.
     */
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
        $mfgDate = $request->query('mfg_date'); // e.g. '01-Jan-2024'

        // Choose rate column by role + quantity_type (from rateNstockDamage)
        if ($role === 'DEPO') {
            $rateCol = $qtyType === '0' ? 'dealer_pcs_rate' : 'dealer_rate';
        } elseif ($role === 'DISTRIBUTOR') {
            $rateCol = $qtyType === '0' ? 'retailer_pcs_rate' : 'retailer_rate';
        } else {
            $rateCol = $qtyType === '0' ? 'ss_pcs_rate' : 'ss_case_rate';
        }

        $stockQ = DB::table('stock as s')
            ->join('catalog_product as p', 's.product_id', '=', 'p.id')
            ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
            ->where('s.product_id', $id)
            ->where('s.batch_no', $batchNo)
            ->whereRaw("DATE_FORMAT(s.mfg, '%d-%b-%Y') = ?", [$mfgDate]);

        if ($companyIdCus === 213) {
            // MRP 213 has numeric(16,2) — cast on both sides
            $stockQ->whereRaw("ROUND(s.mrp,1) = ROUND(CAST(? AS DECIMAL(16,2)),1)", [(float) $mrp]);

            $rateTable = $templateId > 0 ? 'product_rate_list_template' : 'product_rate_list';
            $stockQ->join("$rateTable as prl", 'prl.product_id', '=', 'p.id');

            if ($templateId > 0) {
                $stockQ->where('prl.template_type', (string) $templateId);
            } else {
                $stockQ->where('prl.state_id', $stateId);
            }
            $stockQ->where('s.dealer_id', $dealerId);
        } else {
            $stockQ->where('s.mrp', $mrp)->where('s.dealer_id', $dealerId);
            $stockQ->leftJoin('product_rate_list as prl', 'prl.product_id', '=', 'p.id');
        }

        $row = $stockQ->first([
            's.product_id',
            's.qty',
            DB::raw("CAST(COALESCE(prl.{$rateCol},0) AS DECIMAL(16,2)) as raw_rate"),
            DB::raw('CAST(COALESCE(t.igst,0) AS DECIMAL(6,2)) as igst'),
        ]);

        if (!$row) {
            return response()->json(['data' => null]);
        }

        // For company 213 & most, strip GST from the rate (mirrors JS/PHP)
        $beforeGst = round($row->raw_rate - ($row->raw_rate * (100 / (100 + (float) $row->igst))), 3);
        $netRate   = round($row->raw_rate - $beforeGst, 2);

        return response()->json(['data' => [
            'product_id' => $row->product_id,
            'qty'        => $row->qty,
            'igst'       => $row->igst,
            'rate'       => $netRate,
        ]]);
    }

    public function productDealerRate(Request $request, int $id)
    {
        $u = $request->user();
        $stateId  = (string) ($u->state_id ?? 0);
        $role     = strtoupper((string) ($u->rolename ?? ''));
        $qtyType  = (string) ($u->quantity_type ?? '1');

        $rateCol = match (true) {
            $role === 'DEPO'        && $qtyType === '0' => 'ss_pcs_rate',
            $role === 'DEPO'                            => 'ss_case_rate',
            $role === 'DISTRIBUTOR' && $qtyType === '0' => 'dealer_pcs_rate',
            $role === 'DISTRIBUTOR'                     => 'dealer_rate',
            $qtyType === '0'                            => 'dealer_pcs_rate',
            default                                     => 'dealer_rate',
        };
        $mrpCol = $qtyType === '0' ? 'mrp_pcs' : 'mrp';

        $row = DB::table('product_rate_list as prl')
            ->join('catalog_product as p', 'p.id', '=', 'prl.product_id')
            ->leftJoin('_gst as t', 'p.hsn_code', '=', 't.hsn_code')
            ->where('prl.state_id', $stateId)
            ->where('prl.product_id', $id)
            ->first([
                DB::raw("CAST(prl.{$rateCol} AS DECIMAL(16,2)) as dealer_rate"),
                DB::raw("CAST(prl.{$mrpCol} AS DECIMAL(16,2)) as mrp"),
                DB::raw('CAST(COALESCE(t.igst,0) AS DECIMAL(6,2)) as gst'),
            ]);

        return response()->json(['data' => $row]);
    }

    public function productGst(Request $request, int $id)
    {
        $row = DB::table('catalog_product as cp')
            ->join('_gst', '_gst.hsn_code', '=', 'cp.hsn_code')
            ->where('cp.id', $id)
            ->first([DB::raw('CAST(_gst.igst AS DECIMAL(6,2)) as vat')]);

        return response()->json(['vat' => $row?->vat]);
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