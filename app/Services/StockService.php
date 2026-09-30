<?php

namespace App\Services;

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
            $mfgMon    = date('Y-m', strtotime($mfgDate));
            $rate      = (float) ($it['product_rate'] ?? 0);
            $qty       = (float) $it['qty'];
            $freeQty   = (float) ($it['free_qty'] ?? 0);
            $qtyy      = $qty + $freeQty;

            $stock = $this->findStock($productId, $dealerId, $mrp, $batchNo, $mfgMon);

            if ($saleableNonSaleable === 1) {
                // Saleable → increase qty + salable_damage
                if ($stock) {
                    $newQty = (float) $stock->qty + $qtyy;
                    DB::table('stock')->where('id', $stock->id)->update([
                        'qty'              => $newQty,
                        'remaining'        => $newQty,
                        'salable_damage'   => $newQty,
                        'update_date_time' => now(),
                    ]);
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
                // Self → deduct from qty (must be available)
                if ($forSelfRetailer === 0) {
                    if (!$stock || (float) $stock->qty < $qtyy) {
                        throw new \RuntimeException('Stock Qty is not available in salable stock.');
                    }
                    DB::table('stock')->where('id', $stock->id)->update([
                        'qty'              => (float) $stock->qty - $qtyy,
                        'update_date_time' => now(),
                    ]);
                }

                // Increase nonsalable_damage
                if ($stock) {
                    DB::table('stock')->where('id', $stock->id)->update([
                        'nonsalable_damage' => (float) $stock->nonsalable_damage + $qtyy,
                        'update_date_time'  => now(),
                    ]);
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

    /**
     * Reverse effect of a previous apply() — used by update().
     */
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
            $mfgMon    = date('Y-m', strtotime($mfgDate));
            $qtyy      = (float) $it->qty + (float) $it->free_qty;

            $stock = $this->findStock($productId, $dealerId, $mrp, $batchNo, $mfgMon);
            if (!$stock) {
                continue;
            }

            if ($saleableNonSaleable === 1) {
                $newQty = max(0, (float) $stock->qty - $qtyy);
                DB::table('stock')->where('id', $stock->id)->update([
                    'qty'              => $newQty,
                    'remaining'        => $newQty,
                    'salable_damage'   => $newQty,
                    'update_date_time' => now(),
                ]);
            } elseif ($saleableNonSaleable === 2) {
                if ($forSelfRetailer === 0) {
                    DB::table('stock')->where('id', $stock->id)->update([
                        'qty'              => (float) $stock->qty + $qtyy,
                        'update_date_time' => now(),
                    ]);
                }
                DB::table('stock')->where('id', $stock->id)->update([
                    'nonsalable_damage' => max(0, (float) $stock->nonsalable_damage - $qtyy),
                    'update_date_time'  => now(),
                ]);
            }
        }
    }

    protected function findStock(
        int $productId,
        int $dealerId,
        float $mrp,
        string $batchNo,
        string $mfgMon
    ) {
        return DB::table('stock')
            ->where('product_id', $productId)
            ->where('dealer_id', $dealerId)
            ->where('mrp', $mrp)
            ->where('batch_no', $batchNo)
            ->whereRaw("DATE_FORMAT(mfg, '%Y-%m') = ?", [$mfgMon])
            ->first();
    }

    protected function insertStock(array $d): void
    {
        DB::table('stock')->insert([
            'product_id'         => $d['product_id'],
            'batch_no'           => $d['batch_no'],
            'rate'               => $d['rate'],
            'dealer_rate'        => $d['dealer_rate'],
            'mrp'                => $d['mrp'],
            'person_id'          => 0,
            'csa_id'             => 0,
            'dealer_id'          => $d['dealer_id'],
            'qty'                => $d['qty'],
            'salable_damage'     => $d['salable_damage'],
            'nonsalable_damage'  => $d['nonsalable_damage'],
            'remaining'          => $d['remaining'],
            'mfg'                => $d['mfg'],
            'expire'             => null,
            'date'               => now()->format('Y-m-d'),
            'pr_rate'            => 0,
            'company_id'         => 1,
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