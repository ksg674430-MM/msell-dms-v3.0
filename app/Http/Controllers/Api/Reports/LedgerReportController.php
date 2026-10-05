<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LedgerReportController extends Controller
{
    use ReportFilterTrait;

    // =========================================================
    // 1) Party Ledger (retailer — challan debit + payment credit)
    //    Legacy: report::get_party_wise_ledger_report_list()
    // =========================================================
   public function partyLedger(Request $request)
{
    $retailerId = (int) $request->input('retailer_id');
    if (!$retailerId) return $this->fail('retailer_id is required');

    ['dealer_id' => $dealer] = $this->scope($request);

    $debits = DB::table('challan_order as co')
        ->where('co.ch_dealer_id', $dealer)
        ->where('co.ch_retailer_id', $retailerId)
        ->select(
            'co.ch_no as voucher',
            DB::raw("DATE_FORMAT(co.ch_date,'%Y-%m-%d') as voucher_date"),
            DB::raw('ROUND(co.amount,2) as dr'),
            DB::raw('0 as cr'),
            DB::raw("'challan' as type")
        )->get();

    $credits = DB::table('challan_order_wise_payment as cwp')
        ->join('challan_order_wise_payment_details as cwpd', 'cwpd.pay_id', '=', 'cwp.pay_id')
        ->where('cwp.retailer_id', $retailerId)
        ->select(
            DB::raw('cwpd.challan_no as voucher'),
            DB::raw("DATE_FORMAT(cwp.payment_date,'%Y-%m-%d') as voucher_date"),
            DB::raw('0 as dr'),
            DB::raw('ROUND(cwp.pay_amount,2) as cr'),
            DB::raw("'payment' as type")
        )->get();

    $opening = (float) DB::table('old_payment_dues')
        ->where('ch_dealer_id', $dealer)->where('ch_retailer_id', $retailerId)
        ->sum('amount');

    $rows = $debits->merge($credits)
        ->sortBy(fn($r) => $r->voucher_date.$r->voucher)
        ->values();

    $closing = $opening + $rows->sum('dr') - $rows->sum('cr');

    return $this->ok([
        'opening_balance' => $opening,
        'rows'            => $rows,
        'closing_balance' => $closing,
        'totals'          => [
            'dr' => round($rows->sum('dr'), 2),
            'cr' => round($rows->sum('cr'), 2),
        ],
    ], ['retailer_id' => $retailerId]);
}

    // =========================================================
    // 2) Retailer Ledger (with opening)
    //    Legacy: dispatch_report::get_retailer_ledger_tranc_list()
    //            + get_retailer_ledger_open_list()
    // =========================================================
    public function retailerLedger(Request $request)
{
    $retailerId = (int) $request->input('retailer_id');
    if (!$retailerId) return $this->fail('retailer_id is required');

    [$from, $to] = $this->dateRange($request);
    ['dealer_id' => $dealer] = $this->scope($request);

    // Opening up to `from - 1`
    $openChallan = (float) DB::table('challan_order')
        ->where('ch_dealer_id', $dealer)->where('ch_retailer_id', $retailerId)
        ->whereDate('ch_date', '<', $from)
        ->sum('amount_round');

    $openPaid = (float) DB::table('payment_collection')
        ->where('dealer_id', $dealer)->where('retailer_id', $retailerId)
        ->whereDate('pay_date_time', '<', $from)
        ->sum('total_amount');

    $openDues = (float) DB::table('old_payment_dues')
        ->where('ch_dealer_id', $dealer)->where('ch_retailer_id', $retailerId)
        ->sum('amount');

    $opening = $openDues + $openChallan - $openPaid;

    // In-range transactions
    $challans = DB::table('challan_order as co')
        ->join('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
        ->where('co.ch_dealer_id', $dealer)->where('co.ch_retailer_id', $retailerId)
        ->whereDate('co.ch_date', '>=', $from)->whereDate('co.ch_date', '<=', $to)
        ->select(
            'r.name as party',
            'co.id as ref_id',
            'co.ch_no as voucher',
            DB::raw("DATE_FORMAT(co.ch_date,'%Y-%m-%d') as voucher_date"),
            DB::raw('ROUND(co.amount_round,2) as debit'),
            DB::raw('0 as credit'),
            DB::raw("'invoice' as type")
        )->get();

    $payments = DB::table('payment_collection as pc')
        ->join('dealer as d', 'd.id', '=', 'pc.dealer_id')
        ->where('pc.dealer_id', $dealer)->where('pc.retailer_id', $retailerId)
        ->whereDate('pc.pay_date_time', '>=', $from)->whereDate('pc.pay_date_time', '<=', $to)
        ->select(
            'd.name as party',
            'pc.id as ref_id',
            'pc.reciept_no as voucher',
            DB::raw("DATE_FORMAT(pc.pay_date_time,'%Y-%m-%d') as voucher_date"),
            DB::raw('0 as debit'),
            DB::raw('ROUND(pc.total_amount,2) as credit'),
            DB::raw("'receipt' as type")
        )->get();

    $rows = $challans->merge($payments)
        ->sortBy(fn($r) => $r->voucher_date . ($r->type === 'invoice' ? '0' : '1') . $r->voucher)
        ->values();

    $closing = $opening + $rows->sum('debit') - $rows->sum('credit');

    return $this->ok([
        'opening_balance' => round($opening, 2),
        'rows'            => $rows,
        'closing_balance' => round($closing, 2),
    ], ['from' => $from, 'to' => $to, 'retailer_id' => $retailerId]);
}

    // =========================================================
    // 3) Retailer Payment list
    //    Legacy: report::get_retailer_payment_list()
    // =========================================================
    public function retailerPayment(Request $request)
    {
        [$from, $to] = $this->dateRange($request);
        ['dealer_id' => $dealer] = $this->scope($request);

        $q = DB::table('payment_collection as pc')
            ->join('retailer as r', 'r.id', '=', 'pc.retailer_id')
            ->where('pc.dealer_id', $dealer)
            ->whereDate('pc.pay_date_time', '>=', $from)->whereDate('pc.pay_date_time', '<=', $to);

        if ($request->filled('retailer_id')) $q->where('pc.retailer_id', $request->retailer_id);

        $rows = $q->select(
                'pc.id as pid', 'r.id as rid', 'r.name',
                'pc.total_amount as amount',
                DB::raw("DATE_FORMAT(pc.pay_date_time,'%d-%b-%Y') as rdate"),
                'pc.pay_mode', 'pc.bank_name', 'pc.chq_no', 'pc.chq_date', 'pc.reciept_no'
            )
            ->orderBy('r.name')->orderBy('pc.pay_date_time')->get();

        return $this->ok($rows, ['from' => $from, 'to' => $to]);
    }
}