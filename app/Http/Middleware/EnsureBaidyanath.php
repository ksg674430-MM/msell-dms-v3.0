<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnsureBaidyanath
{
    public function handle(Request $request, Closure $next, int $companyId = 405)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $dealerId      = $user->dealer_id ?? 0;
        $dealerCompany = $dealerId
            ? (int) DB::table('dealer')->where('id', $dealerId)->value('company_id')
            : 0;
        $actualCompany = $dealerCompany ?: (int) ($user->company_id ?? 0);

        if ($actualCompany !== (int) $companyId) {
            return response()->json([
                'status'  => false,
                'message' => "This endpoint is only available for company {$companyId}.",
            ], 403);
        }

        return $next($request);
    }
}