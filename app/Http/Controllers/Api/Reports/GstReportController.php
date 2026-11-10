<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GstReportController extends Controller
{
    use ReportFilterTrait;

    // =========================================================
    // 1) GST Return Summary — rate-wise outward + inward
    //    Legacy: dealer_sale::get_gst_wise_list()
    // =========================================================
    public function gstReturnSummary(Request $request)
    {
        [$from, $to] = $this->dateRange($request, 'start', 'end');
        ['dealer_id' => $dealer] = $this->scope($request);

        $state = (string) DB::table('dealer')->join('location_3 as l3', 'l3.id', '=', 'dealer.state_id')->where('dealer.id', $dealer)->value('l3.name');

        $outward = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->where('co.ch_dealer_id', $dealer)
            ->where('co.book_status', 0)
            ->where('cod.tax', '!=', 0)
            ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
            ->when($request->filled('ch_no'), fn ($q) => $q->where('co.ch_no', $request->ch_no))
            ->groupBy('cod.tax')
            ->select(
                'cod.tax',
                DB::raw('ROUND(SUM(cod.taxable_amt),2) as total_amt'),
                DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as taxable_amt'),
                DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt')
            )->get()
            ->map(fn($r) => [
                'particular'   => $state,
                'tax'          => (float) $r->tax,
                'total_amt'    => (float) $r->total_amt,
                'taxable_amt'  => (float) $r->taxable_amt,
                'cgst'         => round($r->vat_amt / 2, 2),
                'sgst'         => round($r->vat_amt / 2, 2),
                'total_tax'    => (float) $r->vat_amt,
            ]);

        $inward = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')
                  ->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->where('po.dealer_id', $dealer)
            ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
            ->groupBy('pod.gst_percentage')
            ->select(
                'pod.gst_percentage as tax',
                DB::raw('ROUND(SUM(CAST(pod.total_amount AS DECIMAL(16,2))),2) as total_amt'),
                DB::raw('ROUND(SUM(CAST(pod.taxable_amount AS DECIMAL(16,2))),2) as taxable_amt'),
                DB::raw('ROUND(SUM(CAST(pod.cgst_amount AS DECIMAL(16,2))),2) as cgst'),
                DB::raw('ROUND(SUM(CAST(pod.sgst_amount AS DECIMAL(16,2))),2) as sgst'),
                DB::raw('ROUND(SUM(CAST(pod.igst_amount AS DECIMAL(16,2))),2) as igst')
            )->get()
            ->map(fn($r) => [
                'particular'  => 'Input Tax',
                'tax'         => (float) $r->tax,
                'total_amt'   => (float) $r->total_amt,
                'taxable_amt' => (float) $r->taxable_amt,
                'cgst'        => (float) $r->cgst,
                'sgst'        => (float) $r->sgst,
                'igst'        => (float) $r->igst,
                'total_tax'   => (float) $r->cgst + (float) $r->sgst + (float) $r->igst,
            ]);

        return $this->ok([
            'outward' => $outward,
            'inward'  => $inward,
        ], ['from' => $from, 'to' => $to]);
    }

    // =========================================================
    // 2) GSTR-3B Summary
    //    Legacy: dispatch::gstr*()
    // =========================================================
    public function gstr3b(Request $request)
    {
        [$from, $to] = $this->dateRange($request, 'start', 'end');
        ['dealer_id' => $dealer] = $this->scope($request);

        $sale = fn (bool $exempt) => DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
            ->where('cod.tax', $exempt ? '=' : '!=', 0)
            ->first([DB::raw('ROUND(COALESCE(SUM(cod.taxable_amt - cod.vat_amt),0),2) as taxable'), DB::raw('ROUND(COALESCE(SUM(cod.vat_amt),0),2) as tax')]);

        $tax = 'CAST(pod.cgst_amount AS DECIMAL(16,2)) + CAST(pod.sgst_amount AS DECIMAL(16,2)) + CAST(pod.igst_amount AS DECIMAL(16,2))';
        $purchase = fn (bool $exempt) => DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->where('po.dealer_id', $dealer)
            ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
            ->whereRaw("({$tax}) " . ($exempt ? '= 0' : '> 0'))
            ->first([DB::raw("ROUND(COALESCE(SUM(CAST(pod.total_amount AS DECIMAL(16,2)) - ({$tax})),0),2) as taxable"), DB::raw("ROUND(COALESCE(SUM({$tax}),0),2) as tax")]);

        $rows = [];
        foreach (['Outward' => $sale, 'Inward' => $purchase] as $side => $fetch) {
            $taxed  = $fetch(false);
            $exempt = $fetch(true);
            $rows[] = ['particulars' => "{$side} Supplies", 'taxable' => (float) $taxed->taxable, 'tax' => (float) $taxed->tax];
            $rows[] = ['particulars' => "{$side} Supplies Exempted", 'taxable' => (float) $exempt->taxable, 'tax' => (float) $exempt->tax];
            $rows[] = ['particulars' => "{$side} Supplies Total", 'taxable' => round($taxed->taxable + $exempt->taxable, 2), 'tax' => round($taxed->tax + $exempt->tax, 2)];
        }

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    // =========================================================
    // 3) HSN Summary
    //    Legacy: dispatch_report::get_hsn_wise_list()
    // =========================================================
    public function hsnSummary(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);
        $mode = $request->input('mode', 'sale'); // sale | purchase

        if ($mode === 'sale') {
            $rows = DB::table('challan_order_details as cod')
                ->join('challan_order as co', 'co.id', '=', 'cod.ch_id')
                ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
                ->where('co.ch_dealer_id', $dealer)
                ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
                ->groupBy('cp.hsn_code')
                ->select('cp.hsn_code',
                    DB::raw('ROUND(SUM(cod.qty),2) as qty'),
                    DB::raw('ROUND(SUM(cod.taxable_amt),2) as total_amt'),
                    DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt'),
                    DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as taxable_amt'))
                ->orderBy('cp.hsn_code')->get();
        } else {
            $rows = DB::table('purchase_order_details as pod')
                ->join('purchase_order as po', function ($j) {
                    $j->on('pod.order_id', '=', 'po.order_id')
                      ->on('pod.purchase_inv', '=', 'po.challan_no');
                })
                ->join('catalog_product as cp', 'cp.id', '=', 'pod.product_id')
                ->where('po.dealer_id', $dealer)
                ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
                ->groupBy('cp.hsn_code')
                ->select('cp.hsn_code as hsn_code',
                    DB::raw('ROUND(SUM(pod.quantity + pod.scheme_qty),2) as qty'),
                    DB::raw('ROUND(SUM(CAST(pod.total_amount AS DECIMAL(16,2))),2) as total_amt'),
                    DB::raw('ROUND(SUM(CAST(pod.cgst_amount AS DECIMAL(16,2))),2) as cgst_amount'),
                    DB::raw('ROUND(SUM(CAST(pod.sgst_amount AS DECIMAL(16,2))),2) as sgst_amount'),
                    DB::raw('ROUND(SUM(CAST(pod.igst_amount AS DECIMAL(16,2))),2) as igst_amount'),
                    DB::raw('ROUND(SUM(CAST(pod.total_amount AS DECIMAL(16,2)) - CAST(pod.cgst_amount AS DECIMAL(16,2)) - CAST(pod.sgst_amount AS DECIMAL(16,2)) - CAST(pod.igst_amount AS DECIMAL(16,2))),2) as taxable_amt'))
                ->orderBy('cp.hsn_code')->get();
        }

        return $this->ok($rows, ['from' => $from, 'to' => $to, 'mode' => $mode]);
    }

    // =========================================================
    // 4) HSN Detail (per invoice/challan)
    //    Legacy: dispatch_report::get_hsn_detailed_list()
    // =========================================================
    // public function hsnDetail(Request $request)
    // {
        // $hsn = $request->input('hsn_code');
        // if (!$hsn) return $this->fail('hsn_code is required');

        // [$from, $to] = $this->dateRange($request);
        // ['dealer_id' => $dealer] = $this->scope($request);

        // $rows = DB::table('challan_order_details as cod')
            // ->join('challan_order as co', 'co.id', '=', 'cod.ch_id')
            // ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            // ->where('co.ch_dealer_id', $dealer)
            // ->where('cod.hsn_code', $hsn)
            // ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
            // ->groupBy('co.id', 'co.ch_no', 'co.ch_retailer_id', 'r.name', 'r.tin_no', 'r.address', 'co.ch_date', 'cod.hsn_code')
            // ->select('co.id',
                // 'co.ch_retailer_id', 'co.ch_no',
                // 'r.name as pert', 'r.tin_no',
                // DB::raw("DATE_FORMAT(co.ch_date,'%d-%m-%Y') as ch_date"),
                // 'cod.hsn_code',
                // DB::raw('ROUND(SUM(cod.qty),2) as qty'),
                // DB::raw('ROUND(SUM(cod.taxable_amt),2) as total_amt'),
                // DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt'),
                // DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as taxable_amt'))
            // ->orderBy('co.ch_no')->get();

        // return $this->ok($rows, ['from' => $from, 'to' => $to, 'hsn_code' => $hsn]);
    // }

    // =========================================================
    // 5) Bill Detailed (B2B / B2C / B2C-Large / All)
    //    Legacy: dispatch_report::get_bill_detailed_list()
    //            dispatch_report::get_bill_detailed_gst_list()
    // =========================================================
    public function billDetailed(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);
        $type = $request->input('type', 'all'); // all | b2b | b2c | b2c_large

        $q = DB::table('challan_order as co')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->where('co.ch_dealer_id', $dealer)
            ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to);

        switch ($type) {
            case 'b2b':
                $q->whereRaw("COALESCE(r.tin_no,'0') NOT IN ('0','')")->where('co.book_status', 0);
                break;
            case 'b2c':
                $q->whereRaw("COALESCE(r.tin_no,'0') IN ('0','')")->where('co.amount', '<=', 50000)->where('co.book_status', 0);
                break;
            case 'b2c_large':
                $q->whereRaw("COALESCE(r.tin_no,'0') IN ('0','')")->where('co.amount', '>', 50000)->where('co.book_status', 0);
                break;
            case 'exempt':
                $q->where('co.book_status', '!=', 0);
                break;
        }
        if ($request->filled('tax')) $q->where('co.book_status', 0)->where('cod.tax', $request->tax);

        $rows = $q->groupBy('co.id', 'co.ch_no', 'co.ch_retailer_id', 'r.name', 'r.tin_no',
                            'r.address', 'co.ch_date', 'co.amount', 'co.book_status', 'cod.tax')
            ->select('co.id',
                'co.ch_retailer_id', 'co.ch_no',
                'r.name as pert', 'r.tin_no', 'r.address',
                DB::raw("DATE_FORMAT(co.ch_date,'%d-%m-%Y') as ch_date"),
                'co.amount', 'co.book_status', 'cod.tax as rate',
                DB::raw('ROUND(SUM(cod.qty),2) as qty'),
                DB::raw('ROUND(SUM(cod.taxable_amt),2) as total_amt'),
                DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt'),
                DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as taxable_amt'))
            ->orderBy('co.ch_no')->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to, 'type' => $type]);
    }

    // =========================================================
    // 6) Purchase Detailed
    //    Legacy: dispatch_report::get_purchase_detailed_list()
    // =========================================================
    public function purchaseDetailed(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $rows = DB::table('purchase_order as po')
            ->join('purchase_order_details as pod', function ($j) {
                $j->on('pod.order_id', '=', 'po.order_id')
                  ->on('pod.purchase_inv', '=', 'po.challan_no');
            })
            ->join('dealer as d', 'd.id', '=', 'po.dealer_id')
            ->leftJoin('csa as c', 'c.c_id', '=', DB::raw('IF(po.csa_id > 0, po.csa_id, d.csa_id)'))
            ->where('po.dealer_id', $dealer)
            ->whereDate('po.created_date', '>=', $from)->whereDate('po.created_date', '<=', $to)
            ->groupBy('po.id', 'po.challan_no', 'c.c_id', 'c.csa_name', 'c.gst_no',
                      'po.created_date')
            ->select('po.id',
                'c.c_id as csa_id', 'po.challan_no',
                'c.csa_name as pert', 'c.gst_no',
                DB::raw("DATE_FORMAT(po.created_date,'%d-%m-%Y') as ch_date"),
                DB::raw('ROUND(SUM(pod.quantity + pod.scheme_qty),2) as qty'),
                DB::raw('ROUND(SUM(CAST(pod.total_amount AS DECIMAL(16,2))),2) as total_amt'),
                DB::raw('ROUND(SUM(CAST(pod.cgst_amount AS DECIMAL(16,2))),2) as cgst_amount'),
                DB::raw('ROUND(SUM(CAST(pod.sgst_amount AS DECIMAL(16,2))),2) as sgst_amount'),
                DB::raw('ROUND(SUM(CAST(pod.igst_amount AS DECIMAL(16,2))),2) as igst_amount'),
                DB::raw('ROUND(SUM(CAST(pod.total_amount AS DECIMAL(16,2)) - CAST(pod.cgst_amount AS DECIMAL(16,2)) - CAST(pod.sgst_amount AS DECIMAL(16,2)) - CAST(pod.igst_amount AS DECIMAL(16,2))),2) as taxable_amt'))
            ->orderBy('po.challan_no')->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }

    // =========================================================
    // 7) Book-wise
    //    Legacy: dispatch_report::get_book_wise_list()
    // =========================================================
   // public function bookWise(Request $request)
// {
    // [$from, $to] = $this->dateRange($request);
    // ['dealer_id' => $dealer] = $this->scope($request);
    // $status = $request->input('book_status'); // null = dono

    // $q = DB::table('challan_order as co')
        // ->join('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
        // ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
        // ->where('co.ch_dealer_id', $dealer)
        // ->whereBetween(DB::raw('DATE(co.ch_date)'), [$from, $to]);

    // if ($status !== null && $status !== '') {
        // $q->where('co.book_status', $status);
    // }

    // $rows = $q->groupBy('co.ch_no', 'co.id', 'r.name', 'r.tin_no', 'co.book_status')
        // ->select('co.id',
            // 'r.name as rname', 'r.tin_no', 'co.ch_no', 'co.book_status',
            // DB::raw('ROUND(SUM(cod.taxable_amt),2) as total_amt'),
            // DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt'),
            // DB::raw('ROUND(SUM(cod.taxable_amt - cod.vat_amt),2) as taxable_amt'))
        // ->orderBy('co.ch_no')->get();

    // return $this->ok($rows, ['from' => $from, 'to' => $to, 'book_status' => $status]);
// }

    // =========================================================
    // 8) Book-wise Document Summary (ranges)
    //    Legacy: dispatch_report::get_book_wise_doc_list()
    // =========================================================
    public function bookWiseDoc(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $sections = [
            'bos' => ['label' => 'Bill of Supply',     'book_status' => 1],
            'boc' => ['label' => 'Generated Invoice',  'book_status' => 0],
        ];

        $out = [];
        foreach ($sections as $key => $s) {
            $base = DB::table('challan_order')
                ->where('ch_dealer_id', $dealer)
                ->where('book_status', $s['book_status'])
                ->whereDate('ch_date', '>=', $from)->whereDate('ch_date', '<=', $to);

            $count = (clone $base)->count();
            $first = (clone $base)->orderBy('ch_no')->value('ch_no');
            $last  = (clone $base)->orderByDesc('ch_no')->value('ch_no');

            $out[$key] = [
                'pert'   => $s['label'],
                'bfrom'  => $first,
                'bto'    => $last,
                'tot'    => $count,
                'bcan'   => 0,
            ];
        }

        return $this->ok($out, ['from' => $from, 'to' => $to]);
    }
}