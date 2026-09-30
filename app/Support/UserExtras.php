<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class UserExtras
{
    /** @return array{dealerStateId:int,templateId:int,ownershipTypeId:int,rolename:string} */
    public static function for($login): array
    {
        $dealer = DB::table('dealer')->where('id', $login->dealer_id)->first();

        $dealerStateId = $dealer->state_id ?? null;
        if (empty($dealerStateId) && !empty($dealer?->csa_id)) {
            $dealerStateId = DB::table('csa')->where('c_id', $dealer->csa_id)->value('state_id');
        }

        return [
            'dealerStateId'   => (int) ($dealerStateId ?? $login->state_id),
            'templateId'      => (int) ($dealer->template_id ?? 0),
            'ownershipTypeId' => (int) ($dealer->ownership_type_id ?? 0),
            'rolename'        => self::roleName((int) $login->role_id),
        ];
    }

    protected static function roleName(int $roleId): string
    {
        foreach (['role', 'role_master', 'roles'] as $tbl) {
            try {
                $n = DB::table($tbl)->where('id', $roleId)->value('name');
                if ($n) return (string) $n;
            } catch (\Throwable $e) {}
        }
        // fallback map — update from your real role table
        return match ($roleId) {
            1  => 'ADMIN',
            2  => 'DISTRIBUTOR',
            3  => 'DEPO',
            default => '',
        };
    }
}