<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderLifeCycleController extends Controller
{
    use RequiresAdmin;

    private const APPROVED_FACTOR = 0.93;

    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        $from = $this->date($request->query('from_date')) ?? Carbon::today('Asia/Kolkata')->subDays(6);
        $to = $this->date($request->query('to_date')) ?? Carbon::today('Asia/Kolkata');
        if ($to->lt($from)) return response()->json(['status' => false, 'message' => 'From Date cannot be after To Date.'], 422);
        if ($from->diffInDays($to) > 92) return response()->json(['status' => false, 'message' => 'Choose a date range of up to 3 months.'], 422);
        $codes = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), (array) $request->query('dealer_codes', [])), fn ($v) => $v !== '')));

        $bind = [$from->toDateString(), $to->toDateString()];
        $codeSql = '';
        if ($codes) {
            $codeSql = ' AND h.ACC_CODE IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($bind, ...$codes);
        }

        $orders = DB::select(
            "SELECT h.ACC_CODE AS dealer_code, b.VRNO AS order_no, b.DO_VRNO AS sl_no,
                    MIN(h.USER_CODE) AS order_type, MIN(h.VRDATE) AS order_date, MIN(h.VRDATE_FILTER) AS order_date_filter,
                    MIN(b.DIV_CODE) AS in_div_code,
                    SUM(b.AFIELD1) AS order_amt, SUM(b.QTYCANCELLED) AS qty_cancel
               FROM ORDER_HEAD h
               JOIN ORDER_BODY b ON b.VRNO = h.VRNO AND b.DIV_CODE = h.DIV_CODE
              WHERE h.VRDATE_FILTER BETWEEN ? AND ? $codeSql
              GROUP BY b.VRNO, b.DO_VRNO, h.ACC_CODE
              ORDER BY order_date_filter DESC, b.VRNO DESC, b.DO_VRNO ASC",
            $bind
        );
        if (!$orders) return response()->json(['status' => true, 'rows' => []]);

        $dealerCodes = array_values(array_unique(array_map(fn ($o) => (string) $o->dealer_code, $orders)));
        $orderNos = array_values(array_unique(array_map(fn ($o) => (string) $o->order_no, $orders)));
        $acc = $divs = $invoices = [];
        foreach (array_chunk($dealerCodes, 1000) as $chunk) {
            foreach (DB::table('ACC_MAST')->whereIn('ACC_CODE', $chunk)->get(['ACC_CODE', 'ACC_NAME', 'DIV_CODE']) as $a) $acc[$a->ACC_CODE] = $a;
            foreach (DB::select(
                "SELECT d.dealer_code, MIN(l.div_code_main) AS div_code
                   FROM dealer d JOIN dealer_person_login l ON l.dealer_id = d.id
                  WHERE d.company_id = ? AND d.dealer_status = 1 AND d.dealer_code IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                    AND COALESCE(l.div_code_main, '') <> ''
                  GROUP BY d.dealer_code",
                [$ctx->company_id, ...$chunk]
            ) as $d) $divs[$d->dealer_code] = $d->div_code;
        }
        foreach (array_chunk($orderNos, 1000) as $chunk) {
            foreach (DB::select(
                "SELECT b.ORDER_VRNO AS order_no, b.DO_VRNO AS sl_no, h.ACC_CODE AS dealer_code,
                        MIN(b.VRNO) AS invoice_no, MIN(h.VRDATE) AS invoice_date,
                        SUM(b.VALISSUED) AS net_sale, SUM(b.DRAMT) AS invoice_amt
                   FROM ITEMTRAN_HEAD h
                   JOIN ITEMTRAN_BODY b ON b.VRNO = h.VRNO AND b.DIV_CODE = h.DIV_CODE
                  WHERE b.VRDATE_FILTER >= ? AND b.ORDER_VRNO IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                  GROUP BY b.ORDER_VRNO, b.DO_VRNO, h.ACC_CODE",
                [$from->toDateString(), ...$chunk]
            ) as $i) $invoices[$i->order_no . '|' . $i->sl_no . '|' . $i->dealer_code] = $i;
        }

        $rows = array_map(function ($o) use ($acc, $divs, $invoices) {
            $inv = $invoices[$o->order_no . '|' . $o->sl_no . '|' . $o->dealer_code] ?? null;
            $a = $acc[$o->dealer_code] ?? null;
            return [
                'div_code'     => $divs[$o->dealer_code] ?? ($a->DIV_CODE ?? ''),
                'dealer_code'  => (string) $o->dealer_code,
                'party_name'   => $a->ACC_NAME ?? (string) $o->dealer_code,
                'web_order'    => strtoupper(trim((string) $o->order_type)) === 'WEB',
                'order_date'   => (string) $o->order_date,
                'order_date_filter' => (string) $o->order_date_filter,
                'sl_no'        => (string) $o->sl_no,
                'order_no'     => (string) $o->order_no,
                'approved_amt' => round((float) $o->order_amt * self::APPROVED_FACTOR, 2),
                'invoice_no'   => $inv->invoice_no ?? null,
                'invoice_date' => $inv->invoice_date ?? null,
                'net_sale'     => $inv ? round((float) $inv->net_sale, 2) : null,
                'invoice_amt'  => $inv ? round((float) $inv->invoice_amt, 2) : null,
                'qty_cancel'   => (float) $o->qty_cancel,
            ];
        }, $orders);

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    public function items(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        $orderNo = trim((string) $request->query('order_no', ''));
        $slNo = trim((string) $request->query('sl_no', ''));
        $date = $this->date($request->query('order_date'));
        if ($orderNo === '' || !$date) return response()->json(['status' => false, 'message' => 'Order not found.'], 422);

        $lines = DB::select(
            "SELECT b.ITEM_CODE AS item_code, SUM(b.QTYCANCELLED) AS qty_cancel, SUM(b.AFIELD1) AS order_amt,
                    MIN(b.REBOOK_VRNO) AS rebook_no, MIN(b.QTYCANCELLED_FLAG) AS flag
               FROM ORDER_HEAD h
               JOIN ORDER_BODY b ON b.VRNO = h.VRNO AND b.DIV_CODE = h.DIV_CODE
              WHERE h.VRDATE_FILTER = ? AND b.VRNO = ? AND b.DO_VRNO = ?
              GROUP BY b.ITEM_CODE
             HAVING SUM(b.QTYCANCELLED) <> 0",
            [$date->toDateString(), $orderNo, $slNo]
        );
        $names = $lines ? DB::table('ITEM_MAST')->whereIn('ITEM_CODE', array_map(fn ($l) => $l->item_code, $lines))->pluck('ITEM_NAME', 'ITEM_CODE') : collect();

        return response()->json([
            'status' => true,
            'rows'   => array_map(fn ($l) => [
                'item_code' => (string) $l->item_code,
                'item_name' => (string) ($names[$l->item_code] ?? ''),
                'qty'       => (float) $l->qty_cancel,
                'amount'    => round((float) $l->order_amt * self::APPROVED_FACTOR, 2),
                'status'    => strtoupper((string) $l->flag) === 'R' ? 'Re-Book' : 'Cancelled',
                'rebook_no' => $l->rebook_no ?: '-',
            ], $lines),
        ]);
    }

    private function date($value): ?Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
