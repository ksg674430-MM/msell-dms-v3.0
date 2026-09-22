<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    /**
     * GET /api/sales-orders
     * Query: from_date, to_date, user_id, order_no, company_id, status
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) $user->company_id;

        $from = $request->input('from_date', date('Y-m-d'));
        $to   = $request->input('to_date',   date('Y-m-d'));

        $where    = [
            "uso.dealer_id = ?",
            "uso.company_id = ?",
            "usod.status != 2",
            "uso.order_status != 8",
            "DATE_FORMAT(uso.date,'%Y%m%d') >= ?",
            "DATE_FORMAT(uso.date,'%Y%m%d') <= ?",
        ];
        $bindings = [$dealerId, $companyId,
                     date('Ymd', strtotime($from)),
                     date('Ymd', strtotime($to))];

        if ($uid = $request->input('user_id')) {
            $where[]    = "uso.user_id = ?";
            $bindings[] = (int) $uid;
        }
        if ($ono = $request->input('order_no')) {
            $where[]    = "uso.order_id = ?";
            $bindings[] = $ono;
        }

        $whereSql = implode(' AND ', $where);

        // Main query — matches legacy get_dealer_sale_details_list_new()
        $rows = DB::select(
            "SELECT
                uso.retailer_id, uso.location_id, uso.user_id, uso.order_id,
                uso.remarks, uso.discount, uso.order_status,
                COUNT(DISTINCT usod.id)               AS total_qty,
                COALESCE(SUM(usod.rate * usod.quantity),0) AS total_sale,
                (SELECT COUNT(id) FROM user_sales_order_details
                  WHERE order_id = uso.order_id AND remaining_qty > 0) AS rem_qty,
                (SELECT COALESCE(SUM(rate * remaining_qty),0) FROM user_sales_order_details
                  WHERE order_id = uso.order_id AND remaining_qty > 0) AS rem_sale_val,
                DATE_FORMAT(uso.date,'%d/%m/%Y') AS sale_date,
                DATE_FORMAT(uso.date,'%Y-%m-%d') AS s_date,
                DATE_FORMAT(uso.date,'%d/%b/%Y') AS fdated
               FROM user_sales_order uso
               INNER JOIN user_sales_order_details usod ON usod.order_id = uso.order_id
              WHERE {$whereSql}
              GROUP BY uso.order_id
              ORDER BY uso.order_id DESC",
            $bindings
        );

        // Weight-in-kg map (matches the $detailsData sub-query)
        $weights = $this->weightMap($dealerId, $from, $to);

        // Person + retailer + beat name caches
        $personIds   = array_unique(array_column($rows, 'user_id'));
        $retailerIds = array_unique(array_column($rows, 'retailer_id'));
        $locationIds = array_unique(array_column($rows, 'location_id'));

        $personMap   = $this->personMap($personIds);
        $retailerMap = $this->retailerMap($retailerIds);
        $beatMap     = $this->beatMap($locationIds);

        // Compute outstanding per retailer — matches the legacy sub-queries
        $outstandingMap = $this->outstandingMap($dealerId, $retailerIds);

        $out = [];
        foreach ($rows as $r) {
            $hasPending = (int) $r->rem_qty > 0;

            $out[] = [
                'order_id'       => $r->order_id,
                's_date'         => $r->s_date,
                'sale_date'      => $r->sale_date,
                'fdated'         => $r->fdated,
                'user_id'        => (int) $r->user_id,
                'person_name'    => $personMap[$r->user_id]   ?? '',
                'retailer_id'    => (int) $r->retailer_id,
                'firm_name'      => $retailerMap[$r->retailer_id] ?? '',
                'location_id'    => (int) $r->location_id,
                'beat_name'      => $beatMap[$r->location_id] ?? '',
                'outstanding'    => $outstandingMap[$r->retailer_id] ?? 0,
                'weight_in_kg'   => round(($weights[$r->order_id] ?? 0) / 1000, 3),
                'order_item'     => $hasPending ? (int) $r->rem_qty : (int) $r->total_qty,
                'sale_value'     => (float) ($hasPending ? $r->rem_sale_val : $r->total_sale),
                'discount'       => (float) $r->discount,
                'order_status'   => (int) $r->order_status,
                'remarks'        => $r->remarks,
                'call_status'    => ((float) $r->total_sale > 0) ? 1 : 0,
                'overdue_days'   => $this->overdueDays($r->s_date),
            ];
        }

        return response()->json(['status' => true, 'rows' => $out]);
    }

    /**
     * GET /api/sales-orders/{orderId}
     * Full detail — items + gift items. Mirrors `get_dealer_sale_list(..., $popup=true)`.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $header = DB::selectOne(
            "SELECT uso.*, DATE_FORMAT(uso.date,'%d/%m/%Y') AS sale_date,
                    DATE_FORMAT(uso.date,'%Y-%m-%d')  AS s_date,
                    r.name AS retailer_name, r.contact_per_name, r.landline,
                    r.other_numbers, r.tin_no, r.address, r.location_id AS r_location_id
               FROM user_sales_order uso
               LEFT JOIN retailer r ON r.id = uso.retailer_id
              WHERE uso.order_id = ? AND uso.dealer_id = ?
              LIMIT 1",
            [$orderId, $dealerId]
        );

        if (!$header) {
            return response()->json(['status' => false, 'message' => 'Order not found.'], 404);
        }

        $items = DB::select(
            "SELECT usod.id, usod.product_id, cp.name AS product_name, cp.itemcode,
                    cp.taxable, cp.weight,
                    usod.quantity, usod.rate, usod.scheme_qty, usod.remaining_qty,
                    usod.case_quantity, usod.case_rate
               FROM user_sales_order_details usod
               INNER JOIN catalog_product cp ON cp.id = usod.product_id
              WHERE usod.order_id = ? AND usod.status != 2
              ORDER BY usod.product_id",
            [$orderId]
        );
try {
    $gifts = DB::select(
        "SELECT g.id, g.gift_name AS gift_name, urgd.quantity
           FROM user_retailer_gift_details urgd
           INNER JOIN _retailer_mkt_gift g ON g.id = urgd.gift_id
          WHERE urgd.order_id = ?",
        [$orderId]
    );
} catch (\Throwable $e) {
    $gifts = [];
}

        return response()->json([
            'status'  => true,
            'header'  => $header,
            'items'   => $items,
            'gifts'   => $gifts,
        ]);
    }

    /**
     * POST /api/sales-orders/cancel
     * Body:
     * {
     *   "orders": [
     *     { "order_id": "...", "remark": "out of stock", "reason": "customer cancelled" }
     *   ]
     * }
     */
    
    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'orders'             => ['required', 'array', 'min:1'],
            'orders.*.order_id'  => ['required', 'string'],
            'orders.*.remark'    => ['nullable', 'string', 'max:500'],
            'orders.*.reason'    => ['nullable', 'string', 'max:500'],
        ]);

        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        DB::beginTransaction();
        try {
            $cancelled = [];
            foreach ($request->input('orders') as $o) {
                $orderId = $o['order_id'];
                $remark  = $o['remark'] ?? '';
                $reason  = $o['reason'] ?? '';

                // Verify ownership
                $exists = DB::selectOne(
                    "SELECT order_id FROM user_sales_order
                      WHERE order_id = ? AND dealer_id = ? LIMIT 1",
                    [$orderId, $dealerId]
                );
                if (!$exists) continue;

                // 1. Copy header into cancel_order
                DB::statement(
                    "INSERT INTO cancel_order
                        SELECT * FROM user_sales_order WHERE order_id = ?",
                    [$orderId]
                );

                // 2. Copy items into cancel_order_details
                DB::statement(
                    "INSERT INTO cancel_order_details
                        SELECT * FROM user_sales_order_details WHERE order_id = ?",
                    [$orderId]
                );

                // 3. Mark order cancelled
                DB::update(
                    "UPDATE user_sales_order SET order_status = '8'
                      WHERE order_id = ?",
                    [$orderId]
                );

                // 4. Stamp reason + remark on the cancel row
                DB::update(
                    "UPDATE cancel_order SET remarks = ?, reasons = ?
                      WHERE order_id = ?",
                    [$remark, $reason, $orderId]
                );

                $cancelled[] = $orderId;
            }

            DB::commit();
            return response()->json([
                'status'    => true,
                'message'   => count($cancelled) . ' order(s) cancelled.',
                'cancelled' => $cancelled,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Cancel failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =================== helpers =================== */

    private function weightMap(int $dealerId, string $from, string $to): array
    {
        $rows = DB::select(
            "SELECT usod.order_id,
                    (cp.weight * usod.quantity) AS gram
               FROM user_sales_order uso
               INNER JOIN user_sales_order_details usod ON usod.order_id = uso.order_id
               INNER JOIN catalog_product cp ON cp.id = usod.product_id
              WHERE DATE_FORMAT(uso.date,'%Y%m%d') >= ?
                AND DATE_FORMAT(uso.date,'%Y%m%d') <= ?
                AND uso.dealer_id = ?
              GROUP BY usod.id, usod.order_id",
            [date('Ymd', strtotime($from)), date('Ymd', strtotime($to)), $dealerId]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[$r->order_id] = ($map[$r->order_id] ?? 0) + (float) $r->gram;
        }
        return $map;
    }

    private function personMap(array $ids): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_map('intval', $ids));
        $rows = DB::select(
            "SELECT id, CONCAT_WS(' ', first_name, middle_name, last_name) AS uname
               FROM person WHERE id IN ({$in})"
        );
        $map = [];
        foreach ($rows as $r) $map[$r->id] = trim($r->uname);
        return $map;
    }

    private function retailerMap(array $ids): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_map('intval', $ids));
        $rows = DB::select("SELECT id, name FROM retailer WHERE id IN ({$in})");
        $map = [];
        foreach ($rows as $r) $map[$r->id] = $r->name;
        return $map;
    }

    private function beatMap(array $ids): array
    {
        if (empty($ids)) return [];
        $in = implode(',', array_map('intval', $ids));
        $rows = DB::select("SELECT id, name FROM location_7 WHERE id IN ({$in})");
        $map = [];
        foreach ($rows as $r) $map[$r->id] = $r->name;
        return $map;
    }

    private function outstandingMap(int $dealerId, array $retailerIds): array
    {
        if (empty($retailerIds)) return [];
        $in = implode(',', array_map('intval', $retailerIds));

        $rows = DB::select(
            "SELECT r.id AS retailer_id,
                    COALESCE((SELECT SUM(amount_round) FROM challan_order
                              WHERE ch_retailer_id = r.id AND ch_dealer_id = ?), 0)
                  - COALESCE((SELECT SUM(total_amount) FROM payment_collection
                              WHERE retailer_id = r.id AND dealer_id = ?), 0) AS outstanding
               FROM retailer r
              WHERE r.id IN ({$in})",
            [$dealerId, $dealerId]
        );

        $map = [];
        foreach ($rows as $r) $map[$r->retailer_id] = round((float) $r->outstanding, 2);
        return $map;
    }

    private function overdueDays(string $date): int
    {
        return (int) floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400);
    }
}