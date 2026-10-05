<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DispatchController extends Controller
{
    /**
     * GET /api/dispatches
     * List dispatches for this dealer with optional date filter.
     */
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $where    = ["dd.dealer_id = ?"];
        $bindings = [$dealerId];

        if ($from = $request->input('from_date')) {
            $where[]    = "DATE(dd.dispatch_date) >= ?";
            $bindings[] = $from;
        }
        if ($to = $request->input('to_date')) {
            $where[]    = "DATE(dd.dispatch_date) <= ?";
            $bindings[] = $to;
        }
        if ($request->filled('delivery_status')) {
            $where[]    = "dd.delivery_status = ?";
            $bindings[] = (int) $request->input('delivery_status');
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT dd.id, dd.dispatch_id, dd.dispatch_no, dd.dealer_id,
                    dd.van_no, dd.dispatch_date, dd.total_bills, dd.total_product,
                    dd.route, dd.delivery_status, dd.created_by,
                    GROUP_CONCAT(DISTINCT ch.ch_no ORDER BY ch.ch_no SEPARATOR ', ') AS challan_nos,
                    GROUP_CONCAT(DISTINCT r.name SEPARATOR ', ') AS retailer_names,
                    GROUP_CONCAT(DISTINCT l7.name SEPARATOR ', ') AS beat_names,
                    COUNT(DISTINCT ch.id) AS challan_count
               FROM daily_dispatch dd
               LEFT JOIN daily_dispatch_details ddd ON ddd.dispatch_id = dd.dispatch_id
               LEFT JOIN challan_order ch          ON ch.id  = ddd.ch_id
               LEFT JOIN retailer r                ON r.id   = ch.ch_retailer_id
               LEFT JOIN location_7 l7             ON l7.id  = r.location_id
              WHERE {$whereSql}
              GROUP BY dd.id, dd.dispatch_id, dd.dispatch_no, dd.dealer_id,
                       dd.van_no, dd.dispatch_date, dd.total_bills, dd.total_product,
                       dd.route, dd.delivery_status, dd.created_by
              ORDER BY dd.id DESC",
            $bindings
        );

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    /**
     * GET /api/dispatches/challans
     * Challans available for dispatch (dispatch_status = 0).
     */
    public function searchChallans(Request $request): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $where    = ["ch.ch_dealer_id = ?", "ch.dispatch_status = 0"];
        $bindings = [$dealerId];

        $from = $request->input('from_date', date('Y-m-d'));
        $to   = $request->input('to_date',   date('Y-m-d'));

        $where[]    = "DATE(ch.ch_date) >= ?";
        $bindings[] = $from;
        $where[]    = "DATE(ch.ch_date) <= ?";
        $bindings[] = $to;

        if ($retailerId = $request->input('retailer_id')) {
            $where[]    = "ch.ch_retailer_id = ?";
            $bindings[] = (string) $retailerId;
        }
        if ($locId = $request->input('location_id')) {
            $where[]    = "r.location_id = ?";
            $bindings[] = (int) $locId;
        }

        $whereSql = implode(' AND ', $where);

        // Weight subquery: sum((qty+free_qty)*weight) in grams per challan
        $rows = DB::select(
            "SELECT ch.id, ch.ch_no, ch.ch_date, ch.ch_retailer_id,
                    r.name AS retailer_name,
                    l7.name AS beat_name,
                    COALESCE((
                        SELECT SUM((cod.qty + cod.free_qty) * cp.weight)
                          FROM challan_order_details cod
                          LEFT JOIN catalog_product cp ON cp.id = cod.product_id
                         WHERE cod.ch_id = ch.id
                    ), 0) AS qty_gram
               FROM challan_order ch
               LEFT JOIN retailer r   ON r.id  = ch.ch_retailer_id
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
              WHERE {$whereSql}
              ORDER BY ch.ch_date ASC, ch.ch_no ASC",
            $bindings
        );

        $out = [];
        foreach ($rows as $r) {
            $gram = (float) $r->qty_gram;
            $out[] = [
                'id'            => $r->id,
                'ch_no'         => $r->ch_no,
                'ch_date'       => $r->ch_date,
                'retailer_id'   => $r->ch_retailer_id,
                'retailer_name' => $r->retailer_name,
                'beat_name'     => $r->beat_name,
                'qty_gram'      => round($gram, 2),
                'qty_kg'        => round($gram / 1000, 3),
            ];
        }

        return response()->json(['status' => true, 'rows' => $out]);
    }

    /**
     * GET /api/dispatches/next-no
     * Preview of next dispatch number (does not reserve).
     */
    public function nextNo(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $nextNum     = $this->nextDispatchNum();
        $shortPeriod = $this->getShortPeriod();

        $prefix = ($companyId === 213) ? 'RPL' : 'BDN';
        $dispatchNo = "{$prefix}{$dealerId}/{$shortPeriod}/{$nextNum}";

        return response()->json([
            'status'      => true,
            'dispatch_no' => $dispatchNo,
            'next_num'    => $nextNum,
        ]);
    }

    /**
     * POST /api/dispatches
     */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);
        $userId    = (int) ($user->dpId ?? $user->getKey() ?? 0);

        $validated = $request->validate([
            'dispatch_date' => 'required|date',
            'van_no'        => 'required|string|max:20',
            'challan_ids'   => 'required|array|min:1',
            'challan_ids.*' => 'integer',
            'route'         => 'nullable|string|max:20',
        ]);

        $challanIds = array_map('intval', $validated['challan_ids']);
        $challanIds = array_values(array_unique($challanIds));

        // Load van + capacity
        $van = DB::selectOne(
            "SELECT id, van_no, capacity FROM van
              WHERE van_no = ? AND dealer_id = ? LIMIT 1",
            [$validated['van_no'], $dealerId]
        );
        if (!$van) {
            return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
        }

        // Validate challans belong to dealer and are pending
        $challans = DB::select(
            "SELECT id, ch_no FROM challan_order
              WHERE id IN (" . implode(',', array_fill(0, count($challanIds), '?')) . ")
                AND ch_dealer_id = ?
                AND dispatch_status = 0",
            array_merge($challanIds, [$dealerId])
        );
        if (count($challans) !== count($challanIds)) {
            return response()->json([
                'status'  => false,
                'message' => 'Some challans are not available for dispatch (already dispatched or invalid).',
            ], 422);
        }

        // Total weight in KG
        $totalKg = $this->calcTotalWeightKg($challanIds);
        if ($totalKg > (float) $van->capacity) {
            return response()->json([
                'status'  => false,
                'message' => 'Selected quantity is greater than van capacity (' . $van->capacity . ' kg vs ' . $totalKg . ' kg).',
            ], 422);
        }

        $dispatchId  = $dealerId . date('YmdHis');
        $shortPeriod = $this->getShortPeriod();
        $nextNum     = $this->nextDispatchNum();
        $prefix      = ($companyId === 213) ? 'RPL' : 'BDN';
        $dispatchNo  = "{$prefix}{$dealerId}/{$shortPeriod}/{$nextNum}";

        DB::beginTransaction();
        try {
            DB::insert(
                "INSERT INTO daily_dispatch
                    (dispatch_id, dispatch_no, dealer_id, van_no, dispatch_date,
                     total_bills, total_product, company_id, created_by, route,
                     sync_status, delivery_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)",
                [
                    $dispatchId, $dispatchNo, $dealerId, $validated['van_no'],
                    $validated['dispatch_date'] . ' 00:00:00',
                    count($challanIds), 0,
                    $companyId, $userId,
                    $validated['route'] ?? '',
                ]
            );

            // Insert details
            $sort = 1;
            foreach ($challanIds as $chId) {
                DB::insert(
                    "INSERT INTO daily_dispatch_details
                        (company_id, dispatch_id, ch_id, sortorder, sync_status)
                     VALUES (?, ?, ?, ?, 1)",
                    [$companyId, $dispatchId, $chId, $sort++]
                );
            }

            // Mark challans as dispatched
            DB::update(
                "UPDATE challan_order SET dispatch_status = 1
                  WHERE id IN (" . implode(',', array_fill(0, count($challanIds), '?')) . ")",
                $challanIds
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Save failed: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status'      => true,
            'message'     => 'Dispatch saved successfully.',
            'dispatch_id' => $dispatchId,
            'dispatch_no' => $dispatchNo,
        ]);
    }

    /**
     * GET /api/dispatches/{dispatch_id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $header = DB::selectOne(
            "SELECT * FROM daily_dispatch
              WHERE dispatch_id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$header) {
            return response()->json(['status' => false, 'message' => 'Dispatch not found.'], 404);
        }

        $details = DB::select(
            "SELECT ddd.sortorder,
                    ch.id, ch.ch_no, ch.ch_date, ch.ch_retailer_id, ch.amount,
                    r.name AS retailer_name,
                    l7.name AS beat_name,
                    COALESCE((
                        SELECT SUM((cod.qty + cod.free_qty) * cp.weight)
                          FROM challan_order_details cod
                          LEFT JOIN catalog_product cp ON cp.id = cod.product_id
                         WHERE cod.ch_id = ch.id
                    ), 0) AS qty_gram
               FROM daily_dispatch_details ddd
               INNER JOIN challan_order ch ON ch.id = ddd.ch_id
               LEFT JOIN retailer r        ON r.id  = ch.ch_retailer_id
               LEFT JOIN location_7 l7     ON l7.id = r.location_id
              WHERE ddd.dispatch_id = ?
              ORDER BY ddd.sortorder ASC",
            [$id]
        );

        $items = [];
        foreach ($details as $d) {
            $items[] = [
                'sortorder'     => (int) $d->sortorder,
                'id'            => $d->id,
                'ch_no'         => $d->ch_no,
                'ch_date'       => $d->ch_date,
                'retailer_id'   => $d->ch_retailer_id,
                'retailer_name' => $d->retailer_name,
                'beat_name'     => $d->beat_name,
                'amount'        => (float) $d->amount,
                'qty_gram'      => round((float) $d->qty_gram, 2),
                'qty_kg'        => round((float) $d->qty_gram / 1000, 3),
            ];
        }

        return response()->json([
            'status'  => true,
            'header'  => $header,
            'details' => $items,
        ]);
    }

    /**
     * PUT /api/dispatches/{dispatch_id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $existing = DB::selectOne(
            "SELECT dispatch_id, van_no FROM daily_dispatch
              WHERE dispatch_id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Dispatch not found.'], 404);
        }

        $validated = $request->validate([
            'dispatch_date' => 'required|date',
            'van_no'        => 'required|string|max:20',
            'challan_ids'   => 'required|array|min:1',
            'challan_ids.*' => 'integer',
            'route'         => 'nullable|string|max:20',
        ]);

        $challanIds = array_map('intval', $validated['challan_ids']);
        $challanIds = array_values(array_unique($challanIds));

        $van = DB::selectOne(
            "SELECT capacity FROM van WHERE van_no = ? AND dealer_id = ? LIMIT 1",
            [$validated['van_no'], $dealerId]
        );
        if (!$van) {
            return response()->json(['status' => false, 'message' => 'Van not found.'], 404);
        }

        // Challans must belong to dealer. Current dispatch's challans allowed + fresh pending ones.
        $currentChallanIds = DB::table('daily_dispatch_details')
            ->where('dispatch_id', $id)
            ->pluck('ch_id')
            ->map(fn($v) => (int) $v)
            ->toArray();

        $allowedIds = array_merge($currentChallanIds, $challanIds);

        $validChallans = DB::select(
            "SELECT id FROM challan_order
              WHERE id IN (" . implode(',', array_fill(0, count($allowedIds), '?')) . ")
                AND ch_dealer_id = ?
                AND (dispatch_status = 0 OR id IN (" . implode(',', array_fill(0, count($currentChallanIds), '?')) . "))",
            array_merge($allowedIds, [$dealerId], $currentChallanIds ?: [0])
        );
        $validIds = array_map(fn($r) => (int) $r->id, $validChallans);
        foreach ($challanIds as $cid) {
            if (!in_array($cid, $validIds, true)) {
                return response()->json([
                    'status'  => false,
                    'message' => "Challan {$cid} is not available for dispatch.",
                ], 422);
            }
        }

        $totalKg = $this->calcTotalWeightKg($challanIds);
        if ($totalKg > (float) $van->capacity) {
            return response()->json([
                'status'  => false,
                'message' => 'Selected quantity is greater than van capacity.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update header
            DB::update(
                "UPDATE daily_dispatch
                    SET van_no = ?, dispatch_date = ?, company_id = ?,
                        total_bills = ?, route = ?
                  WHERE dispatch_id = ? AND dealer_id = ?",
                [
                    $validated['van_no'],
                    $validated['dispatch_date'] . ' 00:00:00',
                    $companyId,
                    count($challanIds),
                    $validated['route'] ?? '',
                    $id, $dealerId,
                ]
            );

            // Reset old challans (release)
            if (!empty($currentChallanIds)) {
                DB::update(
                    "UPDATE challan_order SET dispatch_status = 0
                      WHERE id IN (" . implode(',', array_fill(0, count($currentChallanIds), '?')) . ")",
                    $currentChallanIds
                );
            }

            // Delete old details
            DB::delete("DELETE FROM daily_dispatch_details WHERE dispatch_id = ?", [$id]);

            // Re-insert details
            $sort = 1;
            foreach ($challanIds as $chId) {
                DB::insert(
                    "INSERT INTO daily_dispatch_details
                        (company_id, dispatch_id, ch_id, sortorder, sync_status)
                     VALUES (?, ?, ?, ?, 1)",
                    [$companyId, $id, $chId, $sort++]
                );
            }

            // Mark new challans dispatched
            DB::update(
                "UPDATE challan_order SET dispatch_status = 1
                  WHERE id IN (" . implode(',', array_fill(0, count($challanIds), '?')) . ")",
                $challanIds
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['status' => true, 'message' => 'Dispatch updated successfully.']);
    }

    /**
     * DELETE /api/dispatches/{dispatch_id}
     * Removes dispatch + releases challans.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT dispatch_id, delivery_status FROM daily_dispatch
              WHERE dispatch_id = ? AND dealer_id = ? LIMIT 1",
            [$id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Dispatch not found.'], 404);
        }
        if ((int) $existing->delivery_status === 1) {
            return response()->json(['status' => false, 'message' => 'Cannot delete a delivered dispatch.'], 409);
        }

        $challanIds = DB::table('daily_dispatch_details')
            ->where('dispatch_id', $id)
            ->pluck('ch_id')
            ->map(fn($v) => (int) $v)
            ->toArray();

        DB::beginTransaction();
        try {
            if (!empty($challanIds)) {
                DB::update(
                    "UPDATE challan_order SET dispatch_status = 0
                      WHERE id IN (" . implode(',', array_fill(0, count($challanIds), '?')) . ")",
                    $challanIds
                );
            }

            DB::delete("DELETE FROM daily_dispatch_details WHERE dispatch_id = ?", [$id]);
            DB::delete("DELETE FROM daily_dispatch WHERE dispatch_id = ? AND dealer_id = ?", [$id, $dealerId]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['status' => true, 'message' => 'Dispatch deleted and challans released.']);
    }

    /* ================= Helpers ================= */

    /**
     * Total weight in KG for the given challans.
     */
    private function calcTotalWeightKg(array $challanIds): float
    {
        if (empty($challanIds)) return 0.0;

        $placeholders = implode(',', array_fill(0, count($challanIds), '?'));
        $row = DB::selectOne(
            "SELECT COALESCE(SUM((cod.qty + cod.free_qty) * cp.weight), 0) AS grams
               FROM challan_order_details cod
               LEFT JOIN catalog_product cp ON cp.id = cod.product_id
              WHERE cod.ch_id IN ({$placeholders})",
            $challanIds
        );
        return round(((float) $row->grams) / 1000, 3);
    }

    /**
     * Next dispatch sequence number (highest used + 1).
     */
    private function nextDispatchNum(): int
    {
        $row = DB::selectOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(dispatch_no, '/', -1) AS UNSIGNED)), 0) AS last_num
               FROM daily_dispatch"
        );
        return ((int) ($row->last_num ?? 0)) + 1;
    }

    /**
     * Current financial year short period from session_year.
     * e.g. "26-27"
     */
    private function getShortPeriod(): string
    {
        $row = DB::selectOne(
            "SELECT short_period FROM session_year
              WHERE CURDATE() BETWEEN session_start AND session_end
              ORDER BY sesId DESC LIMIT 1"
        );
        if ($row && !empty($row->short_period)) {
            return (string) $row->short_period;
        }
        // Fallback — compute from today (Indian FY Apr-Mar)
        $year  = (int) date('Y');
        $month = (int) date('n');
        $start = ($month >= 4) ? $year : $year - 1;
        return substr((string) $start, -2) . '-' . substr((string) ($start + 1), -2);
    }

    public function products(Request $request): JsonResponse
    {
        $q = DB::table('daily_dispatch as dd')
            ->join('daily_dispatch_details as ddd', 'ddd.dispatch_id', '=', 'dd.dispatch_id')
            ->join('challan_order_details as cod', 'cod.ch_id', '=', 'ddd.ch_id')
            ->join('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->where('dd.dealer_id', (int) $request->user()->dealer_id);

        if ($request->filled('dispatch_id')) {
            $q->where('dd.dispatch_id', $request->input('dispatch_id'))->groupBy('cod.product_id', 'cod.mrp', 'cp.itemcode', 'cp.name');
        } else {
            $q->whereDate('dd.dispatch_date', '>=', $request->input('from_date', date('Y-m-d')))
              ->whereDate('dd.dispatch_date', '<=', $request->input('to_date', date('Y-m-d')))
              ->groupBy('dd.dispatch_date', 'dd.van_no', 'cod.product_id', 'cod.mrp', 'cp.itemcode', 'cp.name')
              ->addSelect(DB::raw("DATE_FORMAT(dd.dispatch_date,'%Y-%m-%d') as dispatch_date"), 'dd.van_no');
        }

        $rows = $q->addSelect([
                'cod.product_id',
                'cp.itemcode',
                'cp.name',
                'cod.mrp',
                DB::raw('SUM(cod.qty + cod.free_qty) as qty'),
                DB::raw('ROUND(SUM(cod.qty * cod.product_rate),2) as basic'),
                DB::raw('ROUND(SUM(cod.dis_amt),2) as td_amt'),
                DB::raw('ROUND(SUM(cod.cd_amt),2) as cd_amt'),
                DB::raw('ROUND(SUM(cod.vat_amt),2) as vat_amt'),
                DB::raw('ROUND(SUM(cod.taxable_amt),2) as amount'),
                DB::raw('ROUND(SUM((cod.qty + cod.free_qty) * COALESCE(cp.weight,0)) / 1000,3) as weight_kg'),
            ])
            ->orderBy('cp.name')
            ->get();

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $rows = DB::table('daily_dispatch as dd')
            ->join('daily_dispatch_details as ddd', 'ddd.dispatch_id', '=', 'dd.dispatch_id')
            ->join('challan_order as co', 'co.id', '=', 'ddd.ch_id')
            ->leftJoin('retailer as r', 'r.id', '=', 'co.ch_retailer_id')
            ->leftJoin('location_7 as l7', 'l7.id', '=', 'r.location_id')
            ->where('dd.dealer_id', (int) $request->user()->dealer_id)
            ->whereDate('dd.dispatch_date', '>=', $request->input('from_date', date('Y-m-d')))
            ->whereDate('dd.dispatch_date', '<=', $request->input('to_date', date('Y-m-d')))
            ->when($request->filled('van_no'), fn ($q) => $q->where('dd.van_no', $request->input('van_no')))
            ->orderByDesc('co.ch_date')
            ->orderBy('co.ch_no')
            ->get([
                'co.id',
                'co.ch_no',
                'dd.van_no',
                DB::raw("DATE_FORMAT(dd.dispatch_date,'%Y-%m-%d') as dispatch_date"),
                DB::raw("DATE_FORMAT(co.ch_date,'%Y-%m-%d') as ch_date"),
                'l7.name as beat_name',
                'r.name as retailer_name',
                'co.amount',
            ]);

        return response()->json(['status' => true, 'rows' => $rows]);
    }
}
