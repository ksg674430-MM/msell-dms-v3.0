<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait RequiresAdmin
{
    protected const ERP_COMPANY = 405;

    protected function admin(Request $request): ?object
    {
        $user = $request->user();
        $role = trim((string) DB::table('_role')->where('role_id', $user->role_id)->value('rolename'));
        if (strcasecmp($role, 'Super Admin') !== 0) {
            return null;
        }
        return (object) [
            'company_id' => (int) $user->company_id,
            'dealer_id'  => (int) $user->dealer_id,
            'dp_id'      => (int) $user->dpId,
            'erp'        => (int) $user->company_id === self::ERP_COMPANY,
        ];
    }

    protected function adminDenied(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'This page is only for admin logins.'], 403);
    }

    protected function erpOnly(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'This page is not available for your company.'], 403);
    }
}
