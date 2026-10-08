<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Facades\DB;

trait ScopesClaimDealers
{
    protected function claimDealerAllowed(object $ctx, int $dealerId): bool
    {
        if ($dealerId === (int) $ctx->dealer_id) {
            return true;
        }
        return (int) $ctx->csa_id > 0 && DB::table('dealer')
            ->where('id', $dealerId)
            ->where('company_id', $ctx->company_id)
            ->where('csa_id', $ctx->csa_id)
            ->exists();
    }
}
