<?php

namespace App\Http\Controllers\Api\Reports\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait ReportFilterTrait
{
    /** Extract authenticated scope (dealer + company). */
    protected function scope(?Request $r = null): array
{
    $u = $r ? $r->user() : request()->user();

    return [
        'dealer_id'  => (int) $u->dealer_id,
        'company_id' => (int) $u->company_id, // kept for reference, not used in WHERE
        'state_id'   => $u->state_id ?? null,
    ];
}

    /** Standard Y-m-d date range. Returns [from, to] as strings. */
    protected function dateRange(Request $r, ?string $fromCol = 'from_date', ?string $toCol = 'to_date'): array
    {
        $from = $r->input($fromCol);
        $to   = $r->input($toCol);

        $from = $from ? Carbon::parse($from)->toDateString() : Carbon::now()->startOfMonth()->toDateString();
        $to   = $to   ? Carbon::parse($to)->toDateString()   : Carbon::now()->toDateString();

        return [$from, $to];
    }

    /** Paginate params safe-bounded. */
    protected function paging(Request $r, int $default = 100, int $max = 1000): array
    {
        $per = (int) $r->input('per_page', $default);
        $per = max(1, min($per, $max));
        return [$per, (int) $r->input('page', 1)];
    }

    /** Wrap rows into a consistent JSON envelope. */
    protected function ok($rows, array $meta = [])
    {
        return response()->json([
            'status' => true,
            'meta'   => $meta,
            'data'   => $rows,
        ]);
    }

    protected function fail(string $reason, int $code = 422)
    {
        return response()->json(['status' => false, 'myreason' => $reason], $code);
    }

    /** Ensure column exists in whitelist — prevents SQL injection on sort. */
    protected function safeOrder(Request $r, array $map, string $default): string
    {
        $col = $r->input('order_by');
        return $map[$col] ?? $default;
    }
}