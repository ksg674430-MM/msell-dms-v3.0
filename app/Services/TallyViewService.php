<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TallyViewService
{
    public function stock($user): Collection
    {
        return DB::table('tally_stock_data')
            ->where('dealer_id', $this->dealerId($user))
            ->get();
    }

    public function purchase($user): Collection
    {
        return DB::table('tally_purchase_data')
            ->where('dealer_id', $this->dealerId($user))
            ->get();
    }

    public function sale($user): Collection
    {
        return DB::table('tally_sale_data')
            ->where('dealer_id', $this->dealerId($user))
            ->whereNotNull('ItemCode')
            ->get();
    }

    protected function dealerId($user): int
    {
        return (int) ($user->dealer_id ?? 0);
    }
}