<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    use RequiresAdmin;

    public function index(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $from = $this->date($request->query('from_date')) ?? Carbon::today('Asia/Kolkata')->startOfMonth();
        $to = $this->date($request->query('to_date')) ?? Carbon::today('Asia/Kolkata');
        if ($to->lt($from)) return response()->json(['status' => false, 'message' => 'From Date cannot be after To Date.'], 422);
        $perPage = min(max((int) $request->query('per_page', 25), 10), 500);
        $page = max((int) $request->query('page', 1), 1);

        $where = ['o.company_id = ?', 'o.order_date >= ?', 'o.order_date < ?'];
        $bind = [$ctx->company_id, $from->toDateString(), $to->copy()->addDay()->toDateString()];
        $orderNo = trim((string) $request->query('order_no', ''));
        if ($orderNo !== '') {
            $where[] = '(o.order_id LIKE ? OR o.erp_order_no LIKE ?)';
            $like = '%' . addcslashes($orderNo, '%_\\') . '%';
            array_push($bind, $like, $like);
        }
        $depots = array_values(array_filter(array_map('strval', (array) $request->query('depots', [])), fn ($v) => $v !== ''));
        if ($depots) {
            $where[] = 'o.sale_div_code IN (' . implode(',', array_fill(0, count($depots), '?')) . ')';
            array_push($bind, ...$depots);
        }
        $dealers = array_values(array_filter(array_map('intval', (array) $request->query('distributor_ids', [])), fn ($v) => $v > 0));
        if ($dealers) {
            $where[] = 'o.dealer_id IN (' . implode(',', array_fill(0, count($dealers), '?')) . ')';
            array_push($bind, ...$dealers);
        }
        $sqlWhere = implode(' AND ', $where) . ' AND EXISTS (SELECT 1 FROM demand_order_details dd WHERE dd.order_id = o.order_id)';

        $total = (int) DB::scalar("SELECT COUNT(*) FROM demand_order o WHERE $sqlWhere", $bind);
        $rows = DB::select(
            "SELECT o.id, o.order_id, o.prefix, o.sale_div_code, o.date_time, o.order_date, o.erp_order_no,
                    o.send_order_erp_date_time, o.order_remark, o.dealer_id, d.dealer_code, d.name AS dealer_name
               FROM demand_order o
               LEFT JOIN dealer d ON d.id = o.dealer_id
              WHERE $sqlWhere
              ORDER BY o.order_date DESC, o.id DESC
              LIMIT ? OFFSET ?",
            [...$bind, $perPage, ($page - 1) * $perPage]
        );

        if ($rows) {
            $ids = array_map(fn ($r) => (string) $r->order_id, $rows);
            $values = collect(DB::select(
                "SELECT order_id, SUM(total_rs - t1_rate) AS value FROM demand_order_details
                  WHERE order_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY order_id",
                $ids
            ))->pluck('value', 'order_id');
            foreach ($rows as $r) {
                $r->order_id = (string) $r->order_id;
                $r->order_value = round((float) ($values[(string) $r->order_id] ?? 0), 2);
                $r->order_no = ($r->prefix ?? '') . $r->order_id;
            }
        }

        return response()->json([
            'status'    => true,
            'from_date' => $from->toDateString(),
            'to_date'   => $to->toDateString(),
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
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
