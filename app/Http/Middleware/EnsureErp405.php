<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureErp405
{
    public function handle(Request $request, Closure $next)
    {
        $token = (string) config('services.erp405.token');
        if ($token !== '' && !hash_equals($token, (string) ($request->header('X-ERP-Token') ?? $request->input('erp_token', '')))) {
            return response()->json(['response' => false, 'message' => 'Unauthorized ERP request.'], 401);
        }

        $ips = array_filter(array_map('trim', explode(',', (string) config('services.erp405.allowed_ips'))));
        if ($ips && !in_array($request->ip(), $ips, true)) {
            return response()->json(['response' => false, 'message' => 'Unauthorized ERP request.'], 403);
        }

        return $next($request);
    }
}
