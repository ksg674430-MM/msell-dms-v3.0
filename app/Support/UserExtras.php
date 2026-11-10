<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class UserExtras
{
    /** @return array{dealerStateId:int,templateId:int,ownershipTypeId:int,rolename:string} */
    public static function for($login): array
    {
        $dealer = DB::table('dealer')->where('id', $login->dealer_id)->first();

        $dealerStateId = (int) ($dealer->state_id ?? 0);

        return [
            'dealerStateId'   => $dealerStateId ?: (int) $login->getAttributes()['state_id'],
            'templateId'      => (int) ($dealer->template_id ?? 0),
            'ownershipTypeId' => (int) ($dealer->ownership_type_id ?? 0),
            'rolename'        => self::roleName((int) $login->role_id),
        ];
    }

    protected static function roleName(int $roleId): string
    {
        return trim((string) DB::table('_role')->where('role_id', $roleId)->value('rolename'));
    }
}
