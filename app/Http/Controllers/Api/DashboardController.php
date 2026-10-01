<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;
        $dpId     = (int) $user->getKey();
        $companyId = (int) ($user->company_id ?? 0);

        return response()->json([
            'status'             => true,
            'profile'            => $this->dealerProfile($dpId),
            'available_stock'    => $this->availableStock($dealerId),
            'expiry_batch'       => $this->expiryBatch($dealerId),
            'current_scheme'     => $this->currentScheme(),
            'target_focus'       => $this->targetFocus($dealerId),
            'focus_product'      => $this->focusProduct($dealerId),
            'challan_count'      => $this->challanCount($dealerId),
            'challan_prev'       => $this->challanPrev($dealerId),
            'retailer_count'     => $this->totalRetailer($dealerId),
            'complaint_list'     => $this->complaintList($companyId),
            'receive_amount'     => $this->receiveAmount($dealerId),
            'pending_invoice'    => $this->pendingInvoice($dealerId, $companyId),
            'invoice_amount'     => $this->invoiceAmount($dealerId),
            'target_vs_achieved' => $this->targetVsAchieved($dealerId, date('Y-m')),
            'invoice_details'    => $this->invoiceDetails($dealerId),
        ]);
    }

    public function mtd(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m']]);
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;
        $month    = $request->input('date', date('Y-m'));

        return response()->json([
            'status' => true,
            'month'  => $month,
            'data'   => $this->mtdReport($dealerId, $month),
        ]);
    }

    public function paymentCollection(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m']]);
        $dealerId  = (int) $request->user()->dealer_id;
        $month     = $request->input('date', date('Y-m'));
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));

        $list = fn (string $m) => DB::select(
            "SELECT pc.id, retailer.name AS retailer_name, pc.total_amount AS amount,
                    pc.pay_mode AS mode, DATE_FORMAT(pc.pay_date_time,'%Y-%m-%d') AS date
               FROM payment_collection pc
               INNER JOIN retailer ON retailer.id = pc.retailer_id
              WHERE pc.dealer_id = ?
                AND DATE_FORMAT(pc.pay_date_time,'%Y-%m') = ?
              ORDER BY pc.pay_date_time DESC, pc.id DESC",
            [$dealerId, $m]
        );

        return response()->json([
            'status'   => true,
            'month'    => $month,
            'pmonth'   => $prevMonth,
            'current'  => $list($month),
            'previous' => $list($prevMonth),
        ]);
    }

    /* ==================== PROFILE ==================== */

    private function dealerProfile(int $dpId): ?object
    {
        return DB::selectOne(
            "SELECT dpId AS id, person_name, uname, dealer_id, company_id,
                    state_id, quantity_type, lastvisit
               FROM dealer_person_login
              WHERE dpId = ?
              LIMIT 1",
            [$dpId]
        );
    }

    /* ==================== STOCK ==================== */

    private function availableStock(int $dealerId): array
    {
        return DB::select(
            "SELECT s.id, s.product_id, s.batch_no,
                    s.rate, s.dealer_rate, s.mrp,
                    s.qty, s.remaining, s.mfg, s.expire,
                    cv.product_name, cv.c1_name AS category_name
               FROM stock s
               INNER JOIN catalog_product cp ON cp.id = s.product_id
               LEFT JOIN catalog_view cv ON cv.product_id = s.product_id
              WHERE s.dealer_id = ?
              ORDER BY cv.c1_name, cv.product_name, s.expire ASC",
            [$dealerId]
        );
    }

    private function expiryBatch(int $dealerId): array
    {
        return DB::select(
            "SELECT s.id, s.product_id, s.batch_no, s.qty, s.expire,
                    cv.product_name
               FROM stock s
               INNER JOIN catalog_product cp ON cp.id = s.product_id
               LEFT JOIN catalog_view cv ON cv.product_id = s.product_id
              WHERE s.dealer_id = ?
                AND s.qty > 0
                AND s.expire IS NOT NULL
                AND s.expire <> '1970-01-01'
                AND s.expire <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
              ORDER BY s.expire ASC",
            [$dealerId]
        );
    }

    /* ==================== SCHEME ==================== */

    private function currentScheme(): array
    {
        return DB::select(
            "SELECT sv.scheme_id, sv.scheme_name, sv.type,
                    sv.start_date, sv.end_date,
                    svpd.value, svpd.value_to, svpd.scheme_gift,
                    svpd.image, svpd.remark
               FROM scheme_value sv
               INNER JOIN scheme_value_product_details svpd
                       ON svpd.scheme_id = sv.scheme_id
              WHERE CURDATE() BETWEEN sv.start_date AND sv.end_date
              ORDER BY sv.start_date DESC, svpd.value ASC"
        );
    }

    /* ==================== FOCUS TARGET ==================== */

    private function targetFocus(int $dealerId): array
    {
        $rows = DB::select(
            "SELECT fpt.id, fpt.product_id, fpt.target,
                    fpt.start_date, fpt.end_date,
                    cv.product_name
               FROM focus_product_target fpt
               LEFT JOIN catalog_view cv
                      ON cv.product_id = fpt.product_id
              WHERE CURDATE() BETWEEN fpt.start_date AND fpt.end_date
                AND (fpt.dealer_id = '0' OR FIND_IN_SET(?, fpt.dealer_id) > 0)
              ORDER BY cv.product_name",
            [$dealerId]
        );

        foreach ($rows as $r) {
            $ach = DB::selectOne(
                "SELECT COALESCE(SUM(cod.qty),0)         AS qty,
                        COALESCE(SUM(cod.taxable_amt),0) AS amt
                   FROM challan_order_details cod
                   JOIN challan_order co ON co.id = cod.ch_id
                  WHERE co.ch_dealer_id = ?
                    AND cod.product_id  = ?
                    AND co.ch_date BETWEEN ? AND ?",
                [$dealerId, $r->product_id, $r->start_date, $r->end_date]
            );
            $r->achieved_qty   = (float) ($ach->qty ?? 0);
            $r->achieved_value = (float) ($ach->amt ?? 0);
        }

        return $rows;
    }

    /* ==================== FOCUS PRODUCT (yesterday) ==================== */

    private function focusProduct(int $dealerId): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        return DB::select(
            "SELECT cod.product_id,
                    cv.product_name,
                    SUM(cod.qty)          AS qty,
                    AVG(cod.product_rate) AS rate,
                    SUM(cod.taxable_amt)  AS amount
               FROM challan_order_details cod
               JOIN challan_order co ON co.id = cod.ch_id
               LEFT JOIN catalog_view cv ON cv.product_id = cod.product_id
              WHERE co.ch_dealer_id = ?
                AND DATE(co.ch_date) = ?
              GROUP BY cod.product_id, cv.product_name",
            [$dealerId, $yesterday]
        );
    }

    /* ==================== CHALLAN COUNTS ==================== */

    private function challanCount(int $dealerId): array
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $row = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM challan_order
                  WHERE ch_dealer_id = ? AND DATE(ch_date) = ?) AS today,
                (SELECT COUNT(*) FROM challan_order
                  WHERE ch_dealer_id = ? AND DATE(ch_date) = ?) AS prev",
            [$dealerId, $today, $dealerId, $yesterday]
        );

        return [
            'today' => (int) ($row->today ?? 0),
            'prev'  => (int) ($row->prev  ?? 0),
        ];
    }

    private function challanPrev(int $dealerId): array
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        return DB::select(
            "SELECT co.auto AS ch_id, co.id AS order_id, co.ch_no,
                    co.ch_date, co.amount, co.remaining, co.payment_status,
                    r.name AS retailer_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
              WHERE co.ch_dealer_id = ?
                AND DATE(co.ch_date) = ?
              ORDER BY co.ch_date DESC",
            [$dealerId, $yesterday]
        );
    }

    /* ==================== RETAILERS ==================== */

    private function totalRetailer(int $dealerId): array
    {
        $row = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM retailer WHERE dealer_id = ?) AS total_retailer,
                (SELECT COUNT(*) FROM retailer
                  WHERE dealer_id = ?
                    AND MONTH(created_on) = MONTH(CURDATE())
                    AND YEAR(created_on)  = YEAR(CURDATE())) AS month_ret",
            [$dealerId, $dealerId]
        );

        return [
            'total_retailer' => (int) ($row->total_retailer ?? 0),
            'month_ret'      => (int) ($row->month_ret      ?? 0),
        ];
    }

    /* ==================== COMPLAINTS ==================== */

    private function complaintList(int $companyId): array
    {
        return DB::select(
            "SELECT c.id, c.complaint_id, c.date,
                    c.complaint_type, c.consumer_name, c.complaint,
                    c.action,
                    ct.name AS complaint_type_name,
                    dpl.person_name AS user_name
               FROM complaint c
               LEFT JOIN complaint_type ct ON ct.id = c.complaint_type
               LEFT JOIN dealer_person_login dpl ON dpl.dpId = c.user_id
              WHERE c.company_id = ?
              ORDER BY c.date DESC, c.id DESC
              LIMIT 20",
            [$companyId]
        );
    }

    /* ==================== AMOUNTS ==================== */

    private function receiveAmount(int $dealerId): array
    {
        $thisMonth = date('Y-m');
        $prevMonth = date('Y-m', strtotime('first day of last month'));

        $row = DB::selectOne(
            "SELECT
                (SELECT COALESCE(SUM(total_amount),0) FROM payment_collection
                  WHERE dealer_id = ? AND DATE_FORMAT(pay_date_time,'%Y-%m') = ?) AS this_m,
                (SELECT COALESCE(SUM(total_amount),0) FROM payment_collection
                  WHERE dealer_id = ? AND DATE_FORMAT(pay_date_time,'%Y-%m') = ?) AS prev_m",
            [$dealerId, $thisMonth, $dealerId, $prevMonth]
        );

        return [
            'this' => (float) ($row->this_m ?? 0),
            'prev' => (float) ($row->prev_m ?? 0),
        ];
    }

    private function pendingInvoice(int $dealerId, int $companyId): int
    {
        $row = DB::selectOne(
            "SELECT COUNT(DISTINCT uso.order_id) AS cnt
               FROM user_sales_order uso
               INNER JOIN user_sales_order_details usod ON usod.order_id = uso.order_id
              WHERE uso.dealer_id = ?
                AND uso.company_id = ?
                AND usod.status != 2
                AND uso.order_status IN ('0', '7')
                AND DATE_FORMAT(uso.date,'%Y%m%d') >= ?
                AND DATE_FORMAT(uso.date,'%Y%m%d') <= ?",
            [$dealerId, $companyId, date('Ym01'), date('Ymd')]
        );

        return (int) ($row->cnt ?? 0);
    }

    private function invoiceAmount(int $dealerId): array
    {
        $row = DB::selectOne(
            "SELECT
                COALESCE(SUM(amount),0)    AS amount,
                COALESCE(SUM(remaining),0) AS remaining
               FROM challan_order
              WHERE ch_dealer_id = ?",
            [$dealerId]
        );

        return [
            'amount'    => (float) ($row->amount    ?? 0),
            'remaining' => (float) ($row->remaining ?? 0),
        ];
    }

    /* ==================== TARGET vs ACHIEVED ==================== */

    private function targetVsAchieved(int $dealerId, string $month): array
    {
        $monthName = strtolower(date('F', strtotime($month . '-01')));

        $targetRow = DB::selectOne(
            "SELECT `{$monthName}` AS month_target
               FROM dealer_target
              WHERE dealer_id = ?
              LIMIT 1",
            [$dealerId]
        );
        $monthlyTarget = (float) ($targetRow->month_target ?? 0);

        $lastDay = (int) date('t', strtotime($month . '-01'));

        $rows = DB::select(
            "SELECT DAY(ch_date) AS d, COALESCE(SUM(amount),0) AS amount
               FROM challan_order
              WHERE ch_dealer_id = ?
                AND DATE_FORMAT(ch_date,'%Y-%m') = ?
              GROUP BY DAY(ch_date)",
            [$dealerId, $month]
        );

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(int) $r->d] = (float) $r->amount;
        }

        $dayTarget      = $lastDay > 0 ? $monthlyTarget / $lastDay : 0;
        $labels         = [];
        $targetSeries   = [];
        $achievedSeries = [];
        $cumulative     = 0.0;

        for ($day = 1; $day <= $lastDay; $day++) {
            $cumulative += $byDay[$day] ?? 0;
            $labels[]         = $day;
            $targetSeries[]   = round($dayTarget, 2);
            $achievedSeries[] = round($cumulative, 2);
        }

        return [
            'month'           => $month,
            'target'          => $monthlyTarget,
            'achieved'        => $cumulative,
            'labels'          => $labels,
            'target_series'   => $targetSeries,
            'achieved_series' => $achievedSeries,
        ];
    }

    /* ==================== INVOICE DETAILS ==================== */

    private function invoiceDetails(int $dealerId): array
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $row = DB::selectOne(
            "SELECT
                (SELECT COALESCE(SUM(amount),0) FROM challan_order
                  WHERE ch_dealer_id = ? AND DATE(ch_date) = ?) AS today,
                (SELECT COALESCE(SUM(amount),0) FROM challan_order
                  WHERE ch_dealer_id = ? AND DATE(ch_date) = ?) AS yesterday",
            [$dealerId, $today, $dealerId, $yesterday]
        );

        return [
            'today'     => (float) ($row->today     ?? 0),
            'yesterday' => (float) ($row->yesterday ?? 0),
        ];
    }

    /* ==================== MTD ==================== */

    private function mtdReport(int $dealerId, string $month): array
    {
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));

        $curBill  = $this->sumChallan($dealerId, $month);
        $prevBill = $this->sumChallan($dealerId, $prevMonth);

        $curRecv  = $this->sumPaymentCollection($dealerId, $month);
        $prevRecv = $this->sumPaymentCollection($dealerId, $prevMonth);

        $reach = $this->retailerReach($dealerId, $month);

        return [
            'totalinvoice' => [
                'month'     => $month . '-01',
                'pmonth'    => $prevMonth . '-01',
                'amount'    => $curBill,
                'amountp'   => $prevBill,
                'amtgraph'  => $curBill,
                'amtpgraph' => $prevBill,
            ],
            'received' => [
                'ramount'    => $curRecv,
                'ramountp'   => $prevRecv,
                'ramtgraph'  => $curRecv,
                'ramtpgraph' => $prevRecv,
            ],
            'payment' => [
                'pamount'    => $curRecv,
                'pamountp'   => $prevRecv,
                'pamtgraph'  => $curRecv,
                'pamtpgraph' => $prevRecv,
            ],
            'retailerreach' => $reach,
        ];
    }

    private function sumChallan(int $dealerId, string $month): float
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(amount),0) AS v
               FROM challan_order
              WHERE ch_dealer_id = ?
                AND DATE_FORMAT(ch_date,'%Y-%m') = ?",
            [$dealerId, $month]
        );
        return (float) ($row->v ?? 0);
    }

    private function sumPaymentCollection(int $dealerId, string $month): float
    {
        $row = DB::selectOne(
            "SELECT COALESCE(SUM(total_amount),0) AS v
               FROM payment_collection
              WHERE dealer_id = ?
                AND DATE_FORMAT(pay_date_time,'%Y-%m') = ?",
            [$dealerId, $month]
        );
        return (float) ($row->v ?? 0);
    }

    private function retailerReach(int $dealerId, string $month): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(DISTINCT ch_retailer_id) AS totalretailer,
                COUNT(DISTINCT CASE WHEN amount > 0 THEN ch_retailer_id END) AS totalp,
                COUNT(DISTINCT CASE WHEN amount = 0 THEN ch_retailer_id END) AS totalnp
               FROM challan_order
              WHERE ch_dealer_id = ?
                AND DATE_FORMAT(ch_date,'%Y-%m') = ?",
            [$dealerId, $month]
        );

        return [
            'totalretailer' => (int) ($row->totalretailer ?? 0),
            'totalp'        => (int) ($row->totalp        ?? 0),
            'totalnp'       => (int) ($row->totalnp       ?? 0),
        ];
    }
}