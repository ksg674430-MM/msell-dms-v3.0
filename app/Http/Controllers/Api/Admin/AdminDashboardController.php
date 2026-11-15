<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    use RequiresAdmin;

    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $from = $this->date($request->query('from_date')) ?? Carbon::today('Asia/Kolkata');
        $to   = $this->date($request->query('to_date')) ?? $from->copy();
        if ($to->lt($from)) [$from, $to] = [$to, $from];
        $end = $to->copy()->addDay()->toDateString();
        $start = $from->toDateString();

        $logins = (int) DB::scalar(
            "SELECT COUNT(*) FROM dealer_person_login WHERE company_id = ? AND lastvisit >= ? AND lastvisit < ?",
            [$ctx->company_id, $start, $end]
        );
        $demand = (int) DB::scalar(
            "SELECT COUNT(*) FROM demand_order o
              WHERE o.company_id = ? AND o.order_date >= ? AND o.order_date < ?
                AND EXISTS (SELECT 1 FROM demand_order_details d WHERE d.order_id = o.order_id)",
            [$ctx->company_id, $start, $end]
        );

        if ($ctx->erp) {
            $acc = DB::selectOne(
                "SELECT COUNT(*) total, SUM(ACC_STATUS <> '') active, SUM(ACC_STATUS = '') inactive, SUM(DEPOT_FLAG = '1') depots FROM ACC_MAST"
            );
            $invoices = (int) DB::scalar(
                "SELECT COUNT(DISTINCT h.VRNO) FROM ITEMTRAN_HEAD h
                  WHERE h.VRDATE_FILTER BETWEEN ? AND ?
                    AND EXISTS (SELECT 1 FROM ITEMTRAN_BODY b WHERE b.VRNO = h.VRNO)",
                [$start, $to->toDateString()]
            );
            $creditNotes = (int) DB::scalar(
                "SELECT COUNT(DISTINCT VRNO) FROM ACCNOTE_TRAN WHERE VRDATE_FILTER BETWEEN ? AND ?",
                [$start, $to->toDateString()]
            );
        } else {
            $acc = DB::selectOne(
                "SELECT COUNT(*) total, SUM(d.dealer_status = 1) active, SUM(d.dealer_status = 0) inactive,
                        SUM(EXISTS (SELECT 1 FROM dealer_person_login l JOIN _role r ON r.role_id = l.role_id
                                     WHERE l.dealer_id = d.id AND r.rolename IN ('DEPO', 'Depo'))) depots
                   FROM dealer d
                  WHERE d.company_id = ? AND d.dealer_status <> 9",
                [$ctx->company_id]
            );
            $invoices = (int) DB::scalar(
                "SELECT COUNT(*) FROM challan_order WHERE company_id = ? AND ch_date >= ? AND ch_date < ?",
                [$ctx->company_id, $start, $end]
            );
            $creditNotes = null;
        }

        return response()->json([
            'status'    => true,
            'from_date' => $start,
            'to_date'   => $to->toDateString(),
            'cards'     => [
                'depots'       => (int) $acc->depots,
                'distributors' => (int) $acc->total,
                'logins'       => $logins,
                'active'       => (int) $acc->active,
                'inactive'     => (int) $acc->inactive,
                'demand'       => $demand,
                'invoices'     => $invoices,
                'credit_notes' => $creditNotes,
            ],
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
