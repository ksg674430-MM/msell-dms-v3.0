<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Facades\DB;

trait ScopesProducts
{
    protected function ownProduct($query, int $companyId, int $dealerId, string $alias = 'p')
    {
        return $query->where(fn ($w) => $w->where("{$alias}.company_id", $companyId)
            ->orWhereExists(fn ($s) => $s->select(DB::raw(1))
                ->from('stock')
                ->whereColumn('stock.product_id', "{$alias}.id")
                ->where('stock.dealer_id', $dealerId)));
    }
}
