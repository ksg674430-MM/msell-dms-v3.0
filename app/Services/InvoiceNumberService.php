<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    /**
     * Format:  PREFIX/{dealer_id}/{year}/{000001}
     * PREFIX = RPL when company_id_cus == 213, else BDN
     */
    public function next(int $dealerId, int $companyIdCus, ?int $companyId = null): array
    {
        $prefix = $companyIdCus === 213 ? 'RPL' : 'BDN';

        $year = DB::table('session')
            ->where('action', '1')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->value('session');

        if (empty($year)) {
            $year = date('Y');
        }

        // Take the highest sequence for this dealer for the *current year*.
        $rows = DB::table('damage_order')
            ->where('ch_dealer_id', $dealerId)
            ->where('ch_no', 'like', "{$prefix}/{$dealerId}/{$year}/%")
            ->pluck('ch_no');

        $max = 0;
        foreach ($rows as $chNo) {
            $parts = explode('/', (string) $chNo);
            if (isset($parts[3])) {
                $max = max($max, (int) $parts[3]);
            }
        }

        $seq = str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);

        return [
            'ch_no'      => "{$prefix}/{$dealerId}/{$year}/{$seq}",
            'sequence'   => $max + 1,
            'year'       => $year,
            'prefix'     => $prefix,
            'dealer_id'  => $dealerId,
        ];
    }
}