<?php

namespace App\Support;

final class LegacySchemeOptions
{
    public static function usableSlabs(array $slabs): array
    {
        $out = [];
        foreach ($slabs as $s) {
            $base = $s['base'] ?? null;
            $free = $s['free'] ?? null;
            if ($base === null || $free === null || $base === '' || $free === '') {
                continue;
            }
            $out[] = [(float) $base, (float) $free];
        }
        return $out;
    }

    public static function slabsText(array $slabs): string
    {
        $parts = [];
        foreach (self::usableSlabs($slabs) as [$base, $free]) {
            $sum = $base + $free;
            $parts[] = self::num($sum) . ' (' . self::num($base) . '+' . self::num($free) . '=' . self::num($sum) . ')';
        }
        return implode(' , ', $parts);
    }

    public static function build(array $slabs, int $term, bool $isBox, float $conv, float $puda): array
    {
        if ($term <= 0 || ($isBox && $conv <= 0)) {
            return [];
        }

        $qty = $isBox ? $term * $conv : (float) $term;
        $raw = self::usableSlabs($slabs);
        $hasScheme = $raw !== [];

        $out = [];
        foreach ($hasScheme ? $raw : [[$qty, 0.0]] as [$base, $free]) {
            $out[] = [$hasScheme ? $base + $free : $base, $free];
        }
        usort($out, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $n = count($out);
        $pick = $n - 1;
        for ($k = 0; $k < $n - 1; $k++) {
            $t = $out[$k][0];
            if (($qty > $t && $qty < $out[$k + 1][0]) || $t >= $qty) {
                $pick = $k;
                break;
            }
        }

        [$title, $free] = $out[$pick];
        $billed = $title - $free;
        $nextSlab = $out[$pick + 1][0] ?? 0;

        $list = [];
        $step = $title == 0 ? 0 : round($qty / $title);
        for ($i = 0; $i < 10; $i++) {
            if ($step == 0) {
                $step = 1;
            }
            $t = $billed * $step + $free * $step;
            if ($nextSlab == 0 || $t <= $nextSlab) {
                $list[] = [$t, $free * $step, $pick];
            }
            $step++;
        }

        $lastTitle = $list ? end($list)[0] : 0;
        foreach ($out as $k => [$t, $f]) {
            if ($lastTitle < $t) {
                $list[] = [$t, $f, $k];
            }
        }

        $snapshot = $list;
        foreach ($out as $k => [$t0, $f0]) {
            if ($f0 == 0) {
                break;
            }
            $perc = $t0 == 0 ? 0 : $f0 / $t0;
            $ratio = ($t0 - $f0) / $f0;
            $stop = $snapshot[$k + 1][0] ?? null;
            $t = $t0;
            for ($i = 0, $max = $stop === null ? 10 : 9; $i < $max; $i++) {
                if ($stop !== null && $t == $stop) {
                    break;
                }
                $t += $puda;
                $f = (int) ($t * $perc);
                if ($f > 0 && ($t - $f) / $f == $ratio && ($stop === null || $t != $stop)) {
                    $list[] = [$t, (float) $f, $k];
                }
            }
        }
        if ($hasScheme) {
            foreach ($out as $k => [$t, $f]) {
                if ((int) $t === (int) $qty) {
                    $list[] = [$t, $f, $k];
                }
            }
        }
        usort($list, fn ($a, $b) => $a[0] <=> $b[0]);

        $qtyInt = (int) $qty;
        $picked = [];
        $rank = fn (array $c) => $out[$c[2]][0] <= $c[0] ? $c[2] : -1;
        foreach ($list as $c) {
            if ($qtyInt > (int) $c[0]) {
                continue;
            }
            $key = self::num($c[0]);
            if (!isset($picked[$key]) || $rank($c) >= $rank($picked[$key])) {
                $picked[$key] = $c;
            }
        }

        $options = [];
        foreach ($picked as [$t, $f]) {
            $b = $t - $f;
            $inUnit = $isBox ? $t / $conv : $t;
            $options[] = [
                'label' => self::num($inUnit) . '(' . self::num($b) . '+' . self::num($f) . '=' . self::num($t) . ')',
                'qty' => round($inUnit, 4),
                'title' => $t,
                'free_qty' => $f,
                'bill_qty' => $b,
            ];
        }
        return $options;
    }

    public static function find(array $options, float $title): ?array
    {
        foreach ($options as $o) {
            if (abs($o['title'] - $title) < 0.0001) {
                return $o;
            }
        }
        return null;
    }

    public static function num(float $n): string
    {
        $s = number_format($n, 3, '.', '');
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
