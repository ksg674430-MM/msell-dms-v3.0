<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminQuarterlyController extends Controller
{
    use RequiresAdmin;

    public function options(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        return response()->json([
            'status'       => true,
            'years'        => array_map(fn ($y) => "{$y->from_year}-{$y->to_year}", DB::select("SELECT from_year, to_year FROM financial_year ORDER BY from_year DESC")),
            'distributors' => DB::select(
                "SELECT dealer_code AS value, MIN(CONCAT(dealer_code, ' - ', name)) AS label FROM dealer
                  WHERE company_id = ? AND dealer_status = 1 AND COALESCE(dealer_code, '') NOT IN ('', '0')
                  GROUP BY dealer_code ORDER BY label",
                [$ctx->company_id]
            ),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $year = (string) $request->query('fin_year', '');
        if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            return response()->json(['status' => false, 'message' => 'Select the financial year.'], 422);
        }
        $quarters = array_values(array_unique(array_filter(array_map('intval', (array) $request->query('quarters', [])), fn ($q) => $q >= 1 && $q <= 4)));
        sort($quarters);
        if (!$quarters) return response()->json(['status' => false, 'message' => 'Select at least one quarter.'], 422);

        [$fromYear, $toYear] = [(int) $m[1], (int) $m[2]];
        $open = "$fromYear-04-01";
        $closeNext = "$toYear-04-01";
        $ranges = [1 => ["$fromYear-04-01", "$fromYear-07-01"], 2 => ["$fromYear-07-01", "$fromYear-10-01"], 3 => ["$fromYear-10-01", "$toYear-01-01"], 4 => ["$toYear-01-01", "$toYear-04-01"]];
        $codes = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $request->query('dealer_codes', [])), fn ($v) => $v !== ''));

        $bind = [$ctx->company_id, $open];
        $codeSql = '';
        if ($codes) {
            $codeSql = ' AND d.dealer_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($bind, ...$codes);
        }
        $dealers = DB::select(
            "SELECT d.id, d.name, d.dealer_code FROM dealer d
              WHERE d.company_id = ? AND d.dealer_status = 1
                AND EXISTS (SELECT 1 FROM dealer_person_login l WHERE l.dealer_id = d.id AND l.lastvisit >= ?) $codeSql
              ORDER BY d.id",
            $bind
        );
        if (!$dealers) return response()->json(['status' => true, 'quarters' => $quarters, 'rows' => []]);

        $retailers = $billed = $first = $primary = [];
        $perQuarter = array_fill_keys($quarters, []);
        foreach (array_chunk(array_map(fn ($d) => (int) $d->id, $dealers), 1000) as $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::select("SELECT dealer_id, COUNT(DISTINCT retailer_id) AS c FROM user_dealer_retailer WHERE dealer_id IN ($in) GROUP BY dealer_id", $ids) as $r) $retailers[$r->dealer_id] = (int) $r->c;
            foreach (DB::select(
                "SELECT ch_dealer_id, COUNT(DISTINCT ch_retailer_id) AS c, MIN(ch_date) AS first_date FROM challan_order
                  WHERE company_id = ? AND ch_date >= ? AND ch_date < ? AND ch_retailer_id > 0 AND ch_dealer_id IN ($in)
                  GROUP BY ch_dealer_id",
                [$ctx->company_id, $open, $closeNext, ...$ids]
            ) as $r) {
                $billed[$r->ch_dealer_id] = (int) $r->c;
                $first[$r->ch_dealer_id] = substr((string) $r->first_date, 0, 10);
            }
            foreach ($quarters as $q) {
                foreach (DB::select(
                    "SELECT ch_dealer_id, COUNT(DISTINCT ch_no) AS invoices, SUM(amount) AS sale FROM challan_order
                      WHERE company_id = ? AND ch_date >= ? AND ch_date < ? AND ch_retailer_id > 0 AND ch_dealer_id IN ($in)
                      GROUP BY ch_dealer_id",
                    [$ctx->company_id, $ranges[$q][0], $ranges[$q][1], ...$ids]
                ) as $r) $perQuarter[$q][$r->ch_dealer_id] = $r;
            }
            foreach (DB::select(
                "SELECT po.dealer_id, SUM(pd.total_amount) AS total FROM purchase_order po
                   JOIN purchase_order_details pd ON pd.order_id = po.order_id AND pd.purchase_inv = po.challan_no
                  WHERE po.company_id = ? AND po.order_date >= ? AND po.order_date < ? AND po.dealer_id IN ($in)
                  GROUP BY po.dealer_id",
                [$ctx->company_id, $open, $closeNext, ...$ids]
            ) as $r) $primary[$r->dealer_id] = (float) $r->total;
        }

        $rows = array_map(function ($d) use ($quarters, $perQuarter, $retailers, $billed, $first, $primary) {
            $qs = [];
            $invoices = 0;
            $sale = 0.0;
            foreach ($quarters as $q) {
                $r = $perQuarter[$q][$d->id] ?? null;
                $qs[$q] = ['invoices' => (int) ($r->invoices ?? 0), 'sale' => round((float) ($r->sale ?? 0) / 100000, 2)];
                $invoices += (int) ($r->invoices ?? 0);
                $sale += (float) ($r->sale ?? 0);
            }
            $prim = $primary[$d->id] ?? 0.0;
            return [
                'dealer_code'     => (string) $d->dealer_code,
                'dealer_name'     => (string) $d->name,
                'total_retailer'  => $retailers[$d->id] ?? 0,
                'billed_retailer' => $billed[$d->id] ?? 0,
                'first_invoice'   => $first[$d->id] ?? null,
                'quarters'        => $qs,
                'total_invoices'  => $invoices,
                'total_sale'      => round($sale / 100000, 2),
                'primary_sale'    => round($prim / 100000, 2),
                'secondary_pct'   => $prim > 0 ? round($sale / $prim * 100, 2) : 0,
            ];
        }, $dealers);

        return response()->json(['status' => true, 'quarters' => $quarters, 'rows' => $rows]);
    }
}
