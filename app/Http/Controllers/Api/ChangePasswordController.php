<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChangePasswordController extends Controller
{
    public function update(ChangePasswordRequest $request)
    {
        $u    = $request->user();
        $id   = (int) $u->dpId;
        $salt = (string) config('app.edsalt');

        if (empty($salt)) {
            return response()->json([
                'status'  => false,
                'message' => 'Server config error: EDSALT not set.',
            ], 500);
        }

        $current = $request->input('current_password');
        $new     = $request->input('new_password');

        // 1. Verify current password — same as legacy AES_ENCRYPT check
        $row = DB::selectOne(
            "SELECT dpId FROM dealer_person_login
             WHERE dpId = ? AND pass = AES_ENCRYPT(?, ?) LIMIT 1",
            [$id, $current, $salt]
        );

        if (!$row) {
            return response()->json([
                'status'  => false,
                'message' => 'Sorry, current password not valid.',
            ], 422);
        }

        // 2. Update to new password
        DB::statement(
            "UPDATE dealer_person_login
             SET pass = AES_ENCRYPT(?, ?)
             WHERE dpId = ? LIMIT 1",
            [$new, $salt, $id]
        );

        // 3. Optional history log — agar legacy table hai to
        try {
            DB::table('history_log')->insert([
                'user_id'     => $id,
                'particulars' => 'Password update for user ' . ($u->uname ?? $id) . ' in system',
                'operation'   => 'Update',
                'ipaddress'   => (string) $request->ip(),
                'dated'       => now(),
                'identifier'  => '',
            ]);
        } catch (\Throwable $e) {
            // silently ignore if history_log table doesn't exist
            Log::info('Password change history skipped: '.$e->getMessage());
        }

        return response()->json([
            'status'  => true,
            'message' => 'Password successfully updated.',
        ]);
    }
}