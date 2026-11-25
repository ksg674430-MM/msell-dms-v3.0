<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSaleStatementController extends Controller
{
    use RequiresAdmin;

    private const REPORTS = [
        'mainline' => [
            'products' => ['OTC', 'OT2'],
            'before'   => [
                ['key' => 'ASV', 'label' => 'ASAV', 'mktg' => ['ASV'], 'via' => 'catg', 'case' => true, 'asv' => true],
                ['key' => 'ML', 'label' => 'MAINLINE', 'mktg' => ['GEN', 'CLA', 'GLD'], 'via' => 'prod'],
            ],
            'after'    => [
                ['key' => 'OTC', 'label' => 'OTC-I', 'mktg' => ['OTC'], 'via' => 'catg'],
                ['key' => 'OT2', 'label' => 'OTC-II', 'mktg' => ['OT2'], 'via' => 'catg'],
            ],
        ],
        'ethical' => [
            'products' => ['JPS', 'JP2'],
            'before'   => [],
            'after'    => [
                ['key' => 'JPS', 'label' => 'JPS', 'mktg' => ['JPS'], 'via' => 'catg'],
                ['key' => 'JP2', 'label' => 'JP2', 'mktg' => ['JP2'], 'via' => 'catg'],
            ],
        ],
    ];

    public function territories(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        return response()->json([
            'status' => true,
            'rows'   => DB::select("SELECT id AS value, name AS label FROM location_4 WHERE company_id = ? AND status = 1 ORDER BY name", [$ctx->company_id]),
        ]);
    }

    public function report(Request $request, string $type): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();
        $cfg = self::REPORTS[$type];

        $month = (string) $request->query('month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) return response()->json(['status' => false, 'message' => 'Select the month.'], 422);
        $monthStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $fyStart = Carbon::create($monthStart->month >= 4 ? $monthStart->year : $monthStart->year - 1, 4, 1);
        $fyEnd = $fyStart->copy()->addYear()->subDay();
        $territories = array_values(array_filter(array_map('intval', (array) $request->query('territory_ids', [])), fn ($v) => $v > 0));

        $dealers = $this->dealers($ctx->company_id, $territories);
        $columns = $this->columns($cfg);
        if (!$dealers) return response()->json(['status' => true, 'columns' => $columns, 'rows' => [], 'totals' => null, 'fy' => $fyStart->format('Y') . '-' . $fyEnd->format('y')]);

        $codes = array_keys($dealers);
        $products = DB::table('PROD_CATG_MAST')->whereIn('MKTG_CATG', $cfg['products'])->orderBy('USER_SEQ')->orderBy('id')->pluck('PROD_CATG')->unique()->values()->all();
        $targetDates = [
            [$fyStart->format('d-M-y'), $fyStart->toDateString()],
            [$fyEnd->format('d-M-y'), $fyEnd->toDateString()],
        ];
        $targetDates = array_map(fn ($p) => [strtoupper($p[0]), $p[1]], $targetDates);

        $cells = [];
        foreach (array_merge($cfg['before'], $cfg['after']) as $g) {
            $t = $this->targets($codes, $targetDates, $g);
            $s = $this->sales($codes, $fyStart, $monthStart, $monthEnd, $g['mktg'], $g['via'], !empty($g['case']), null);
            foreach ($codes as $code) {
                if (!empty($g['case'])) {
                    $cells[$code][$g['key'] . ':case'] = [round((float) ($t[$code]->qty ?? 0), 2), round((float) ($s[$code]->m_qty ?? 0), 2), round((float) ($s[$code]->c_qty ?? 0), 2)];
                }
                $cells[$code][$g['key'] . ':value'] = [$this->lakh($t[$code]->amt ?? 0), $this->lakh($s[$code]->m_val ?? 0), $this->lakh($s[$code]->c_val ?? 0)];
            }
        }
        if ($products) {
            $t = $this->productTargets($codes, $targetDates, $products);
            $s = $this->sales($codes, $fyStart, $monthStart, $monthEnd, $products, 'prodcatg', true, 'PROD_CATG');
            foreach ($codes as $code) {
                foreach ($products as $p) {
                    $k = $code . '|' . $p;
                    $cells[$code]['P:' . $p] = [round((float) ($t[$k] ?? 0), 2), round((float) ($s[$k]->m_qty ?? 0), 2), round((float) ($s[$k]->c_qty ?? 0), 2)];
                }
            }
        }

        $valueKeys = array_map(fn ($g) => $g['key'] . ':value', array_merge($cfg['before'], $cfg['after']));
        $rows = [];
        $totals = array_fill_keys(array_column($columns, 'key'), [0, 0, 0]);
        foreach ($dealers as $code => $d) {
            $values = [];
            foreach ($columns as $col) {
                if ($col['key'] === 'total') {
                    $v = [0, 0, 0];
                    foreach ($valueKeys as $vk) for ($i = 0; $i < 3; $i++) $v[$i] += $cells[$code][$vk][$i];
                    $v = array_map(fn ($x) => round($x, 2), $v);
                } else {
                    $v = $cells[$code][$col['key']] ?? [0, 0, 0];
                }
                $values[$col['key']] = $v;
                for ($i = 0; $i < 3; $i++) $totals[$col['key']][$i] = round($totals[$col['key']][$i] + $v[$i], 2);
            }
            $rows[] = ['dealer_code' => $code, 'name' => $d['name'], 'city' => $d['city'], 'values' => $values];
        }

        return response()->json([
            'status'  => true,
            'fy'      => $fyStart->format('Y') . '-' . $fyEnd->format('y'),
            'columns' => $columns,
            'rows'    => $rows,
            'totals'  => $totals,
        ]);
    }

    private function columns(array $cfg): array
    {
        $cols = [];
        $push = function (array $g) use (&$cols) {
            if (!empty($g['case'])) $cols[] = ['key' => $g['key'] . ':case', 'label' => $g['label'], 'unit' => 'CASE'];
            $cols[] = ['key' => $g['key'] . ':value', 'label' => $g['label'], 'unit' => 'VALUE'];
        };
        foreach ($cfg['before'] as $g) $push($g);
        foreach (DB::table('PROD_CATG_MAST')->whereIn('MKTG_CATG', $cfg['products'])->orderBy('USER_SEQ')->orderBy('id')->get(['PROD_CATG', 'PROD_CATG_NAME'])->unique('PROD_CATG') as $p) {
            $cols[] = ['key' => 'P:' . $p->PROD_CATG, 'label' => $p->PROD_CATG_NAME ?: $p->PROD_CATG, 'unit' => 'CASE'];
        }
        foreach ($cfg['after'] as $g) $push($g);
        $cols[] = ['key' => 'total', 'label' => 'Total Sale', 'unit' => 'VALUE'];
        return $cols;
    }

    private function dealers(int $companyId, array $territories): array
    {
        $bind = [$companyId];
        $terrSql = '';
        if ($territories) {
            $terrSql = ' AND EXISTS (SELECT 1 FROM dealer_location_rate_list r JOIN location_view v ON v.l7_id = r.location_id
                                      WHERE r.dealer_id = d.id AND v.l4_id IN (' . implode(',', array_fill(0, count($territories), '?')) . '))';
            array_push($bind, ...$territories);
        }
        $rows = DB::select(
            "SELECT d.id, d.dealer_code FROM dealer d
              WHERE d.company_id = ? AND d.dealer_status = 1 AND COALESCE(d.dealer_code, '') NOT IN ('', '0')
                AND EXISTS (SELECT 1 FROM dealer_person_login l JOIN _role ro ON ro.role_id = l.role_id
                             WHERE l.dealer_id = d.id AND UPPER(TRIM(ro.rolename)) = 'DISTRIBUTOR') $terrSql",
            $bind
        );
        if (!$rows) return [];

        $byCode = [];
        foreach ($rows as $r) $byCode[(string) $r->dealer_code] ??= (int) $r->id;
        $names = [];
        foreach (array_chunk(array_keys($byCode), 1000) as $chunk) {
            foreach (DB::table('ACC_MAST')->whereIn('ACC_CODE', $chunk)->get(['ACC_CODE', 'ACC_NAME']) as $a) $names[(string) $a->ACC_CODE] = $a->ACC_NAME;
        }
        $ids = array_values(array_intersect_key($byCode, $names));
        $cities = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::select(
                "SELECT r.dealer_id, MIN(v.l6_name) AS city FROM dealer_location_rate_list r JOIN location_view v ON v.l7_id = r.location_id
                  WHERE r.dealer_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") GROUP BY r.dealer_id",
                $chunk
            ) as $c) $cities[(int) $c->dealer_id] = (string) $c->city;
        }

        $out = [];
        foreach ($names as $code => $name) $out[$code] = ['name' => $name, 'city' => $cities[$byCode[$code]] ?? ''];
        uasort($out, fn ($a, $b) => [$a['city'], $a['name']] <=> [$b['city'], $b['name']]);
        return $out;
    }

    private function targetWhere(array $targetDates): array
    {
        return ['FROM_DATE IN (?, ?) AND TO_DATE IN (?, ?)', [$targetDates[0][0], $targetDates[0][1], $targetDates[1][0], $targetDates[1][1]]];
    }

    private function targets(array $codes, array $targetDates, array $g): array
    {
        [$dateSql, $dateBind] = $this->targetWhere($targetDates);
        $out = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            $sql = "SELECT ACC_CODE, SUM(TARGET_QTY) AS qty, SUM(TARGET_AMT) AS amt FROM DEALER_TARGET_MAST
                     WHERE $dateSql AND MKTG_CATG IN (" . implode(',', array_fill(0, count($g['mktg']), '?')) . ")"
                . (!empty($g['asv']) ? " AND PROD_CATG = 'ASV'" : '')
                . " AND ACC_CODE IN (" . implode(',', array_fill(0, count($chunk), '?')) . ") GROUP BY ACC_CODE";
            foreach (DB::select($sql, [...$dateBind, ...$g['mktg'], ...$chunk]) as $r) $out[(string) $r->ACC_CODE] = $r;
        }
        return $out;
    }

    private function productTargets(array $codes, array $targetDates, array $products): array
    {
        [$dateSql, $dateBind] = $this->targetWhere($targetDates);
        $out = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            foreach (DB::select(
                "SELECT ACC_CODE, PROD_CATG, SUM(TARGET_QTY) AS qty FROM DEALER_TARGET_MAST
                  WHERE $dateSql AND PROD_CATG IN (" . implode(',', array_fill(0, count($products), '?')) . ")
                    AND ACC_CODE IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                  GROUP BY ACC_CODE, PROD_CATG",
                [...$dateBind, ...$products, ...$chunk]
            ) as $r) $out[$r->ACC_CODE . '|' . $r->PROD_CATG] = (float) $r->qty;
        }
        return $out;
    }

    private function sales(array $codes, Carbon $fyStart, Carbon $monthStart, Carbon $monthEnd, array $filter, string $via, bool $withQty, ?string $groupBy): array
    {
        $col = ['catg' => 'pc.MKTG_CATG', 'prod' => 'p.MKTG_CATG', 'prodcatg' => 'pc.PROD_CATG'][$via];
        $aum = $withQty ? 'JOIN ITEM_AUM_MAST a ON a.ITEM_CODE = i.ITEM_CODE' : '';
        $qty = $withQty
            ? "SUM(CASE WHEN h.VRDATE_FILTER >= ? THEN b.QTYISSUED / a.UMTOAUM ELSE 0 END) AS m_qty, SUM(b.QTYISSUED / a.UMTOAUM) AS c_qty,"
            : '';
        $catgJoin = $via === 'prod' ? '' : 'JOIN PROD_CATG_MAST pc ON pc.PROD_CATG = p.PROD_CATG';
        $out = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            $bind = $withQty ? [$monthStart->toDateString()] : [];
            array_push($bind, $monthStart->toDateString(), $fyStart->toDateString(), $monthEnd->toDateString(), ...$filter, ...$chunk);
            $sql = "SELECT h.ACC_CODE" . ($groupBy ? ", $col AS grp" : '') . ", $qty
                           SUM(CASE WHEN h.VRDATE_FILTER >= ? THEN b.VALISSUED ELSE 0 END) AS m_val, SUM(b.VALISSUED) AS c_val
                      FROM ITEMTRAN_HEAD h
                      JOIN ITEMTRAN_BODY b ON b.VRNO = h.VRNO AND b.DIV_CODE = h.DIV_CODE
                      JOIN ITEM_MAST i ON i.ITEM_CODE = b.ITEM_CODE
                      $aum
                      JOIN PROD_MAST p ON p.PROD_CODE = i.PROD_CODE
                      $catgJoin
                     WHERE h.VRDATE_FILTER BETWEEN ? AND ?
                       AND $col IN (" . implode(',', array_fill(0, count($filter), '?')) . ")
                       AND h.ACC_CODE IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")
                     GROUP BY h.ACC_CODE" . ($groupBy ? ", $col" : '');
            foreach (DB::select($sql, $bind) as $r) {
                $out[$groupBy ? $r->ACC_CODE . '|' . $r->grp : (string) $r->ACC_CODE] = $r;
            }
        }
        return $out;
    }

    private function lakh($value): float
    {
        return round((float) $value / 100000, 2);
    }
}
