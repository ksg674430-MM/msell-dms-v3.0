<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    public function next(int $dealerId, int $companyId): array
    {
        $prefix = $companyId === 213 ? 'RPL' : 'BDN';
        return $this->build('damage_order', $prefix, $dealerId, $companyId);
    }

    public function nextDepot(int $dealerId, int $companyId): array
    {
        return $this->build('damage_order_depot', 'BDN', $dealerId, $companyId);
    }

    protected function build(string $table, string $prefix, int $dealerId, int $companyId): array
    {
        $year = DB::table('session')
            ->where('action', '1')
            ->where('company_id', $companyId)
            ->value('session')
            ?: DB::table('session')->where('action', '1')->value('session')
            ?: date('Y');

        $rows = DB::table($table)
            ->where('ch_dealer_id', $dealerId)
            ->where('ch_no', 'like', "{$prefix}/{$dealerId}/{$year}/%")
            ->pluck('ch_no');

        $max = 0;
        foreach ($rows as $chNo) {
            $parts = explode('/', (string) $chNo);
            if (isset($parts[3])) $max = max($max, (int) $parts[3]);
        }

        return [
            'ch_no'     => "{$prefix}/{$dealerId}/{$year}/" . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT),
            'sequence'  => $max + 1,
            'year'      => $year,
            'dealer_id' => $dealerId,
        ];
    }
}