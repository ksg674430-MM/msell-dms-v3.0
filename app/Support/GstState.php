<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class GstState
{
    public static function retailer(int $retailerId): int
    {
        return (int) DB::table('retailer')
            ->join('location_view', 'location_view.l7_id', '=', 'retailer.location_id')
            ->where('retailer.id', $retailerId)
            ->value('location_view.l3_id');
    }

    public static function same(int $companyId, int $dealerStateId, int $retailerId): bool
    {
        if ($companyId === 213) return true;
        $retailerState = self::retailer($retailerId);
        return $retailerState > 0 && $retailerState === $dealerStateId;
    }

    public static function tax(float $taxable, float $rate, bool $same): float
    {
        return $same
            ? 2 * round($taxable * $rate / 200, 2)
            : round($taxable * $rate / 100, 2);
    }
}
