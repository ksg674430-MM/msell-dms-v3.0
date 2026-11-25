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

        $acc = DB::selectOne(
            "SELECT COUNT(*) total, SUM(d.dealer_status = 1) active, SUM(d.dealer_status = 0) inactive,
                    SUM(EXISTS (SELECT 1 FROM dealer_person_login l JOIN _role r ON r.role_id = l.role_id
                                 WHERE l.dealer_id = d.id AND r.rolename IN ('DEPO', 'Depo'))) depots
               FROM dealer d
              WHERE d.company_id = ? AND d.dealer_status <> 9",
            [$ctx->company_id]
        );
        $dms = DB::selectOne(
            "SELECT COUNT(*) n, COALESCE(SUM(amount), 0) value FROM challan_order WHERE company_id = ? AND ch_date >= ? AND ch_date < ?",
            [$ctx->company_id, $start, $end]
        );
        $demandValue = (float) DB::scalar(
            "SELECT COALESCE(SUM(total_value), 0) FROM demand_order WHERE company_id = ? AND order_date >= ? AND order_date < ?",
            [$ctx->company_id, $start, $end]
        );
        $retailers = (int) DB::scalar("SELECT COUNT(*) FROM retailer WHERE company_id = ? AND retailer_status = 1", [$ctx->company_id]);

        if ($ctx->erp) {
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
            $invoices = (int) $dms->n;
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
                'demand_value' => round($demandValue, 2),
                'dms_invoices' => (int) $dms->n,
                'dms_value'    => round((float) $dms->value, 2),
                'retailers'    => $retailers,
            ],
        ]);
    }

    public function details(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $type = (string) $request->query('type', 'distributors');
        $where = ['d.company_id = ?', 'd.dealer_status <> 9'];
        $bind = [$ctx->company_id];
        if ($type === 'active') $where[] = 'd.dealer_status = 1';
        elseif ($type === 'inactive') $where[] = 'd.dealer_status = 0';
        elseif ($type === 'depots') $where[] = "EXISTS (SELECT 1 FROM dealer_person_login l JOIN _role r ON r.role_id = l.role_id WHERE l.dealer_id = d.id AND r.rolename IN ('DEPO', 'Depo'))";
        elseif ($type === 'logins') {
            $from = $this->date($request->query('from_date')) ?? Carbon::today('Asia/Kolkata');
            $to = $this->date($request->query('to_date')) ?? $from->copy();
            if ($to->lt($from)) [$from, $to] = [$to, $from];
            $where[] = 'EXISTS (SELECT 1 FROM dealer_person_login l WHERE l.dealer_id = d.id AND l.lastvisit >= ? AND l.lastvisit < ?)';
            array_push($bind, $from->toDateString(), $to->copy()->addDay()->toDateString());
        } elseif ($type !== 'distributors') {
            return response()->json(['status' => false, 'message' => 'Unknown list.'], 422);
        }

        $rows = DB::select(
            "SELECT d.id, d.dealer_code AS code, d.name, d.other_numbers AS mobile, d.email, d.dealer_status AS status, s.name AS state, t.name AS town
               FROM dealer d
               LEFT JOIN location_3 s ON s.id = d.state_id
               LEFT JOIN location_6 t ON t.id = d.town_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY d.name",
            $bind
        );

        $beats = $retailers = $logins = $users = [];
        foreach (array_chunk(array_map(fn ($r) => (int) $r->id, $rows), 1000) as $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::select(
                "SELECT r.dealer_id, COUNT(DISTINCT r.location_id) beats, MIN(v.l3_name) state, MIN(v.l6_name) town, GROUP_CONCAT(DISTINCT NULLIF(r.user_id, 0)) users
                   FROM dealer_location_rate_list r
                   LEFT JOIN location_view v ON v.l7_id = r.location_id
                  WHERE r.dealer_id IN ($in)
                  GROUP BY r.dealer_id",
                $ids
            ) as $b) $beats[(int) $b->dealer_id] = $b;
            foreach (DB::select("SELECT dealer_id, COUNT(*) n FROM retailer WHERE dealer_id IN ($in) AND retailer_status = 1 GROUP BY dealer_id", $ids) as $r) $retailers[(int) $r->dealer_id] = (int) $r->n;
            foreach (DB::select("SELECT dealer_id, MAX(lastvisit) last FROM dealer_person_login WHERE dealer_id IN ($in) GROUP BY dealer_id", $ids) as $l) $logins[(int) $l->dealer_id] = $l->last;
        }
        $personIds = [];
        foreach ($beats as $b) foreach (array_filter(explode(',', (string) $b->users)) as $id) $personIds[(int) $id] = true;
        foreach (array_chunk(array_keys($personIds), 1000) as $ids) {
            foreach (DB::select(
                "SELECT id, TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name)) name FROM person WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
                $ids
            ) as $p) $users[(int) $p->id] = $p->name;
        }

        foreach ($rows as $r) {
            $b = $beats[(int) $r->id] ?? null;
            $r->state = (string) ($r->state ?: ($b->state ?? ''));
            $r->town = (string) ($r->town ?: ($b->town ?? ''));
            $r->beats = (int) ($b->beats ?? 0);
            $r->retailers = $retailers[(int) $r->id] ?? 0;
            $r->users = $b && $b->users ? implode(', ', array_filter(array_map(fn ($id) => $users[(int) $id] ?? null, explode(',', $b->users)))) : '';
            $r->last_login = $logins[(int) $r->id] ?? null;
            $r->status = (int) $r->status;
        }

        return response()->json(['status' => true, 'rows' => $rows]);
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
