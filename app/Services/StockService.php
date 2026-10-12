<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Apply stock effect for a return (return_replace_id == 0).
     *
     * $saleableNonSaleable : 1 = Saleable, 2 = Non-Saleable
     * $forSelfRetailer     : 0 = Self,     1 = Retailer
     */
    public function apply(
        array $items,
        int $dealerId,
        int $saleableNonSaleable,
        int $forSelfRetailer
    ): void {
        foreach ($items as $it) {
            $productId = (int) $it['product_id'];
            $mrp       = (float) $it['mrp'];
            $batchNo   = (string) ($it['batch_no'] ?? '');
            $mfgDate   = $this->normalizeDate($it['mfg_date'] ?? '1970-01-01');
            $rate      = (float) ($it['product_rate'] ?? 0);
            $qtyy      = (float) $it['qty'] + (float) ($it['free_qty'] ?? 0);

            $stock = $this->locate($dealerId, $productId, $batchNo, $mfgDate, $mrp);

            if ($saleableNonSaleable === 1) {
                if ($stock) {
                    DB::update(
                        "UPDATE stock SET qty = qty + ?, remaining = qty, salable_damage = qty, update_date_time = NOW() WHERE id = ?",
                        [$qtyy, $stock->id]
                    );
                } else {
                    $this->insertStock([
                        'product_id'         => $productId,
                        'batch_no'           => $batchNo,
                        'rate'               => $rate,
                        'dealer_rate'        => $rate,
                        'mrp'                => $mrp,
                        'dealer_id'          => $dealerId,
                        'qty'                => $qtyy,
                        'salable_damage'     => $qtyy,
                        'nonsalable_damage'  => 0,
                        'remaining'          => $qtyy,
                        'mfg'                => $mfgDate,
                    ]);
                }
            } elseif ($saleableNonSaleable === 2) {
                if ($forSelfRetailer === 0) {
                    $this->decrement($stock, 'qty', $qtyy, $this->shortage($productId, '', $batchNo, $mrp, $qtyy, (float) ($stock->qty ?? 0)));
                }

                if ($stock) {
                    DB::update(
                        "UPDATE stock SET nonsalable_damage = nonsalable_damage + ?, update_date_time = NOW() WHERE id = ?",
                        [$qtyy, $stock->id]
                    );
                } else {
                    $this->insertStock([
                        'product_id'         => $productId,
                        'batch_no'           => $batchNo,
                        'rate'               => $rate,
                        'dealer_rate'        => $rate,
                        'mrp'                => $mrp,
                        'dealer_id'          => $dealerId,
                        'qty'                => 0,
                        'salable_damage'     => 0,
                        'nonsalable_damage'  => $qtyy,
                        'remaining'          => 0,
                        'mfg'                => $mfgDate,
                    ]);
                }
            }
        }
    }

    public function reverse(
        array $items,
        int $dealerId,
        int $saleableNonSaleable,
        int $forSelfRetailer
    ): void {
        foreach ($items as $it) {
            $productId = (int) $it->product_id;
            $mrp       = (float) $it->mrp;
            $batchNo   = (string) $it->batch_no;
            $mfgDate   = (string) $it->mfg_date;
            $qtyy      = (float) $it->qty + (float) $it->free_qty;

            $stock = $this->locate($dealerId, $productId, $batchNo, $mfgDate, $mrp);

            if ($saleableNonSaleable === 1) {
                $this->decrement($stock, 'qty', $qtyy, $this->shortage($productId, '', $batchNo, $mrp, $qtyy, (float) ($stock->qty ?? 0)));
                DB::update(
                    "UPDATE stock SET remaining = qty, salable_damage = qty, update_date_time = NOW() WHERE id = ?",
                    [$stock->id]
                );
            } elseif ($saleableNonSaleable === 2) {
                $this->decrement($stock, 'nonsalable_damage', $qtyy, $this->shortage($productId, '', $batchNo, $mrp, $qtyy, (float) ($stock->nonsalable_damage ?? 0), 'Non-salable stock'));
                if ($forSelfRetailer === 0) {
                    DB::update(
                        "UPDATE stock SET qty = qty + ?, update_date_time = NOW() WHERE id = ?",
                        [$qtyy, $stock->id]
                    );
                }
            }
        }
    }

    public function toDepot(array $items, int $dealerId, int $saleableNonSaleable): void
    {
        $column = $saleableNonSaleable === 1 ? 'qty' : 'nonsalable_damage';
        $label  = $saleableNonSaleable === 1 ? 'Stock' : 'Non-salable stock';

        foreach ($items as $it) {
            $it        = (array) $it;
            $productId = (int) $it['product_id'];
            $mrp       = (float) $it['mrp'];
            $batchNo   = (string) ($it['batch_no'] ?? '');
            $qty       = (float) $it['qty'] + (float) ($it['free_qty'] ?? 0);

            $stock = $this->locate($dealerId, $productId, $batchNo, $it['mfg_date'] ?? null, $mrp);
            $this->decrement($stock, $column, $qty, $this->shortage($productId, '', $batchNo, $mrp, $qty, (float) ($stock->{$column} ?? 0), $label));
        }
    }

    public function fromDepot(array $items, int $dealerId, int $saleableNonSaleable): void
    {
        foreach ($items as $it) {
            $it        = (array) $it;
            $productId = (int) $it['product_id'];
            $mrp       = (float) $it['mrp'];
            $batchNo   = (string) ($it['batch_no'] ?? '');
            $qty       = (float) $it['qty'] + (float) ($it['free_qty'] ?? 0);
            if ($qty <= 0) continue;

            if ($saleableNonSaleable === 1) {
                $this->give($dealerId, $productId, $batchNo, (string) ($it['mfg_date'] ?? ''), $mrp, $qty);
                continue;
            }

            $stock = $this->locate($dealerId, $productId, $batchNo, $it['mfg_date'] ?? null, $mrp);
            if ($stock) {
                DB::update(
                    "UPDATE stock SET nonsalable_damage = nonsalable_damage + ?, update_date_time = NOW() WHERE id = ?",
                    [$qty, $stock->id]
                );
                continue;
            }

            $rate = (float) ($it['product_rate'] ?? 0);
            $this->insertStock([
                'product_id'        => $productId,
                'batch_no'          => $batchNo,
                'rate'              => $rate,
                'dealer_rate'       => $rate,
                'mrp'               => $mrp,
                'dealer_id'         => $dealerId,
                'qty'               => 0,
                'salable_damage'    => 0,
                'nonsalable_damage' => $qty,
                'remaining'         => 0,
                'mfg'               => $this->normalizeDate($it['mfg_date'] ?? null),
            ]);
        }
    }

    public function receive(
        int $dealerId,
        int $productId,
        string $batchNo,
        string $mfg,
        float $mrp,
        float $qty,
        array $insert = [],
        array $set = [],
        float $salableDamage = 0,
        float $nonsalableDamage = 0
    ): void {
        if ($qty < 0 || $salableDamage < 0 || $nonsalableDamage < 0) {
            throw new InsufficientStockException('Stock quantity cannot be negative.');
        }

        $row = $this->locate($dealerId, $productId, $batchNo, $mfg, $mrp);
        if ($row) {
            $sql = 'qty = qty + ?, remaining = remaining + ?, salable_damage = salable_damage + ?, nonsalable_damage = nonsalable_damage + ?, date = CURDATE(), update_date_time = NOW()';
            $bindings = [$qty, $qty, $salableDamage, $nonsalableDamage];
            foreach ($set as $column => $value) {
                $sql .= ", `{$column}` = ?";
                $bindings[] = $value;
            }
            $bindings[] = $row->id;
            DB::update("UPDATE stock SET {$sql} WHERE id = ?", $bindings);
            return;
        }

        $this->insertStock($insert + [
            'product_id'        => $productId,
            'batch_no'          => $batchNo,
            'rate'              => 0,
            'dealer_rate'       => 0,
            'mrp'               => $mrp,
            'dealer_id'         => $dealerId,
            'qty'               => $qty,
            'salable_damage'    => $salableDamage,
            'nonsalable_damage' => $nonsalableDamage,
            'remaining'         => $qty,
            'mfg'               => $this->normalizeDate($mfg),
        ]);
    }

    public function takeFifo(int $dealerId, int $productId, float $qty, string $productName = ''): void
    {
        if ($qty <= 0) return;

        $rows = DB::select(
            "SELECT id, qty FROM stock
              WHERE dealer_id = ? AND product_id = ? AND qty > 0
              ORDER BY mfg ASC, id ASC
              FOR UPDATE",
            [$dealerId, $productId]
        );

        $available = array_sum(array_map(fn ($r) => (float) $r->qty, $rows));
        if ($available + 0.0000001 < $qty) {
            throw new InsufficientStockException($this->shortage($productId, $productName, null, null, $qty, $available));
        }

        $left = $qty;
        foreach ($rows as $row) {
            if ($left <= 0) break;
            $part = min($left, (float) $row->qty);
            $this->decrement($row, 'qty', $part, $this->shortage($productId, $productName, null, null, $qty, $available));
            $left -= $part;
        }
    }

    public function editRow(int $dealerId, int $companyId, object $existing, array $update, bool $mrpChanged): void
    {
        if ((float) $update['qty'] < 0 || (float) ($update['nonsalable_damage'] ?? 0) < 0) {
            throw new InsufficientStockException('Stock quantity cannot be negative.');
        }

        $target = DB::selectOne(
            "SELECT id FROM stock
              WHERE dealer_id = ? AND product_id = ? AND batch_no = ? AND mfg = ? AND ABS(mrp - ?) < 0.001 AND id <> ?
              LIMIT 1
              FOR UPDATE",
            [
                $dealerId, $existing->product_id,
                $update['batch_no'] ?? $existing->batch_no,
                $update['mfg'] ?? $existing->mfg,
                $update['mrp'] ?? $existing->mrp,
                $existing->id,
            ]
        );

        if ($target) {
            DB::update(
                "UPDATE stock SET qty = qty + ?, nonsalable_damage = nonsalable_damage + ?, date = NOW() WHERE id = ?",
                [(float) $update['qty'], (int) ($update['nonsalable_damage'] ?? $existing->nonsalable_damage), $target->id]
            );
            $this->clearRow($dealerId, (int) $existing->id);
            return;
        }

        if ($mrpChanged) {
            DB::table('stock')->insert($update + [
                'product_id'        => $existing->product_id,
                'batch_no'          => $existing->batch_no,
                'mfg'               => $existing->mfg,
                'nonsalable_damage' => (int) $existing->nonsalable_damage,
                'csa_id'            => $existing->csa_id,
                'dealer_id'         => $dealerId,
                'salable_damage'    => 0,
                'remaining'         => 0,
                'expire'            => date('Y-m-d', strtotime('+1 years')),
                'pr_rate'           => 0,
                'company_id'        => $companyId,
            ]);
            $this->clearRow($dealerId, (int) $existing->id);
            return;
        }

        DB::table('stock')
            ->where('id', $existing->id)
            ->where('dealer_id', $dealerId)
            ->update($update);
    }

    public function deleteRow(int $dealerId, int $id): void
    {
        DB::delete("DELETE FROM stock WHERE id = ? AND dealer_id = ?", [$id, $dealerId]);
    }

    public function locate(int $dealerId, int $productId, string $batchNo, ?string $mfg, float $mrp): ?object
    {
        if ($this->isUndated($mfg)) {
            return DB::selectOne(
                "SELECT * FROM stock
                  WHERE dealer_id = ? AND product_id = ? AND batch_no = ? AND ABS(mrp - ?) < 0.001
                    AND (mfg IS NULL OR mfg < '1971-01-01')
                  ORDER BY qty DESC, id ASC
                  LIMIT 1
                  FOR UPDATE",
                [$dealerId, $productId, $batchNo, $mrp]
            );
        }

        $mfg = $this->normalizeDate($mfg);

        return DB::selectOne(
            "SELECT * FROM stock
              WHERE dealer_id = ? AND product_id = ? AND batch_no = ? AND ABS(mrp - ?) < 0.001
                AND DATE_FORMAT(mfg, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
              ORDER BY mfg = ? DESC, qty DESC, id ASC
              LIMIT 1
              FOR UPDATE",
            [$dealerId, $productId, $batchNo, $mrp, $mfg, $mfg]
        );
    }

    public function latestBatch(int $dealerId, int $productId, ?float $mrp = null): array
    {
        $sql = "SELECT batch_no, IF(mfg < '1971-01-01', NULL, DATE_FORMAT(mfg, '%Y-%m-%d')) AS mfg
                  FROM stock
                 WHERE dealer_id = ? AND product_id = ? AND batch_no IS NOT NULL AND batch_no NOT IN ('', '0')";
        $bindings = [$dealerId, $productId];
        if ($mrp !== null) {
            $sql .= " AND ABS(mrp - ?) < 0.001";
            $bindings[] = $mrp;
        }
        $row = DB::selectOne($sql . " ORDER BY qty > 0 DESC, mfg DESC, id DESC LIMIT 1", $bindings);

        return ['batch_no' => (string) ($row->batch_no ?? ''), 'mfg_date' => (string) ($row->mfg ?? '')];
    }

    public function totalsByProduct(int $dealerId): array
    {
        return DB::table('stock')
            ->where('dealer_id', $dealerId)
            ->groupBy('product_id')
            ->pluck(DB::raw('SUM(qty)'), 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    public function available(int $dealerId, int $productId, ?float $mrp = null, ?string $batchNo = null, ?string $mfg = null): float
    {
        $sql = "SELECT COALESCE(SUM(qty), 0) AS qty FROM stock WHERE dealer_id = ? AND product_id = ? AND qty > 0";
        $bindings = [$dealerId, $productId];
        if ($mrp !== null) {
            $sql .= " AND ABS(mrp - ?) < 0.001";
            $bindings[] = $mrp;
        }
        if ($batchNo !== null) {
            $sql .= " AND batch_no = ?";
            $bindings[] = $batchNo;
        }
        if ($mfg !== null && $mfg !== '') {
            $sql .= " AND DATE_FORMAT(mfg, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')";
            $bindings[] = $this->normalizeDate($mfg);
        }

        return (float) DB::selectOne($sql, $bindings)->qty;
    }

    public function take(int $dealerId, int $productId, string $batchNo, string $mfg, float $mrp, float $qty, string $productName = '', float $held = 0): void
    {
        if ($qty <= 0) return;

        $row = $this->locate($dealerId, $productId, $batchNo, $mfg, $mrp);
        $this->decrement($row, 'qty', $qty, $this->shortage($productId, $productName, $batchNo, $mrp, $qty + $held, (float) ($row->qty ?? 0) + $held));
    }

    public function give(int $dealerId, int $productId, string $batchNo, string $mfg, float $mrp, float $qty): void
    {
        if ($qty <= 0) return;

        $row = $this->locate($dealerId, $productId, $batchNo, $mfg, $mrp);
        if ($row) {
            DB::update(
                "UPDATE stock SET qty = qty + ?, update_date_time = NOW() WHERE id = ?",
                [$qty, $row->id]
            );
            return;
        }

        $ref = DB::selectOne(
            "SELECT rate, dealer_rate FROM stock
              WHERE dealer_id = ? AND product_id = ?
              ORDER BY ABS(mrp - ?) ASC, id DESC
              LIMIT 1",
            [$dealerId, $productId, $mrp]
        );

        $this->insertStock([
            'product_id'        => $productId,
            'batch_no'          => $batchNo,
            'rate'              => $ref->rate ?? 0,
            'dealer_rate'       => $ref->dealer_rate ?? 0,
            'mrp'               => $mrp,
            'dealer_id'         => $dealerId,
            'qty'               => $qty,
            'salable_damage'    => 0,
            'nonsalable_damage' => 0,
            'remaining'         => 0,
            'mfg'               => $this->normalizeDate($mfg),
        ]);
    }

    public function sync(int $dealerId, array $before, array $after): void
    {
        $totals = [];
        foreach ([[$before, 'before'], [$after, 'after']] as [$lines, $side]) {
            foreach ($lines as $l) {
                $l   = (array) $l;
                $mfg = $this->isUndated($l['mfg_date'] ?? null) ? '0000-00-00' : $this->normalizeDate($l['mfg_date']);
                $key = implode('|', [(int) $l['product_id'], (string) ($l['batch_no'] ?? ''), $mfg, number_format((float) $l['mrp'], 2, '.', '')]);
                $totals[$key] ??= [
                    'product_id' => (int) $l['product_id'],
                    'batch_no'   => (string) ($l['batch_no'] ?? ''),
                    'mfg_date'   => $mfg,
                    'mrp'        => (float) $l['mrp'],
                    'name'       => '',
                    'before'     => 0.0,
                    'after'      => 0.0,
                ];
                $totals[$key]['name'] = $totals[$key]['name'] !== '' ? $totals[$key]['name'] : (string) ($l['name'] ?? '');
                $totals[$key][$side] += (float) $l['qty'] + (float) ($l['free_qty'] ?? 0);
            }
        }

        uasort($totals, fn ($a, $b) => ($b['before'] - $b['after']) <=> ($a['before'] - $a['after']));

        foreach ($totals as $t) {
            $delta = $t['before'] - $t['after'];
            if ($delta > 0.0000001) {
                $this->give($dealerId, $t['product_id'], $t['batch_no'], $t['mfg_date'], $t['mrp'], $delta);
            } elseif ($delta < -0.0000001) {
                $this->take($dealerId, $t['product_id'], $t['batch_no'], $t['mfg_date'], $t['mrp'], -$delta, $t['name'], $t['before']);
            }
        }
    }

    protected function decrement(?object $row, string $column, float $qty, string $message): void
    {
        if ($qty <= 0) return;

        $column = $column === 'nonsalable_damage' ? 'nonsalable_damage' : 'qty';
        $done = $row
            ? DB::update(
                "UPDATE stock SET {$column} = {$column} - ?, update_date_time = NOW() WHERE id = ? AND {$column} + 0.0000001 >= ?",
                [$qty, $row->id, $qty]
            )
            : 0;

        if (!$done) {
            throw new InsufficientStockException($message);
        }
    }

    protected function clearRow(int $dealerId, int $id): void
    {
        DB::update(
            "UPDATE stock SET qty = 0, nonsalable_damage = 0, date = NOW() WHERE id = ? AND dealer_id = ?",
            [$id, $dealerId]
        );
    }

    protected function shortage(int $productId, string $productName, ?string $batchNo, ?float $mrp, float $required, float $available, string $label = 'Stock'): string
    {
        if ($productName === '') {
            $productName = (string) (DB::table('catalog_product')->where('id', $productId)->value('name') ?? $productId);
        }

        return sprintf(
            '%s not available for product "%s"%s. Required qty: %s, Available qty: %s.',
            $label,
            $productName,
            $batchNo === null ? '' : sprintf(' (batch %s, MRP %s)', $batchNo !== '' ? $batchNo : '-', number_format((float) $mrp, 2, '.', '')),
            $this->formatQty($required),
            $this->formatQty(max($available, 0))
        );
    }

    protected function isUndated(?string $d): bool
    {
        $d = trim((string) $d);

        return $d === '' || str_starts_with($d, '0000') || str_starts_with($d, '-') || str_starts_with($d, '1970-01-01');
    }

    protected function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
    }

    protected function insertStock(array $d): void
    {
        DB::table('stock')->insert([
            'product_id'         => $d['product_id'],
            'batch_no'           => $d['batch_no'],
            'rate'               => $d['rate'],
            'dealer_rate'        => $d['dealer_rate'],
            'mrp'                => $d['mrp'],
            'person_id'          => $d['person_id'] ?? 0,
            'csa_id'             => $d['csa_id'] ?? 0,
            'dealer_id'          => $d['dealer_id'],
            'qty'                => $d['qty'],
            'salable_damage'     => $d['salable_damage'],
            'nonsalable_damage'  => $d['nonsalable_damage'],
            'remaining'          => $d['remaining'],
            'mfg'                => $d['mfg'],
            'expire'             => $d['expire'] ?? null,
            'date'               => now()->format('Y-m-d'),
            'pr_rate'            => $d['pr_rate'] ?? 0,
            'company_id'         => $d['company_id'] ?? (int) DB::table('dealer')->where('id', $d['dealer_id'])->value('company_id'),
            'action'             => '1',
            'sync_status'        => 1,
            'update_date_time'   => now(),
        ]);
    }

    protected function normalizeDate(?string $d): string
    {
        if (empty($d)) {
            return '1970-01-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        $ts = strtotime(str_replace('/', '-', $d));
        return $ts ? date('Y-m-d', $ts) : '1970-01-01';
    }
}