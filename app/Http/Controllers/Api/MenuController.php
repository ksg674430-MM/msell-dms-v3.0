<?php
// app/Http/Controllers/Api/MenuController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $roleName  = $this->resolveRoleName($user->role_id);
        $companyId = (int) $user->company_id;
        $appEnv    = config('app.env');

        $items = require config_path('menu.php');

        $visible = [];

        foreach ($items as $item) {

            // ---- 1. Role gate (primary)
            if (!empty($item['roles'])) {
                $allowed = array_map('trim', $item['roles']);
                if (!in_array(trim($roleName), $allowed, true)) {
                    continue;
                }
            }

            // ---- 2. Company allow-list
            if (!empty($item['companies'])
                && !in_array($companyId, $item['companies'], true)) {
                continue;
            }

            // ---- 3. Company deny-list
            if (!empty($item['exclude_companies'])
                && in_array($companyId, $item['exclude_companies'], true)) {
                continue;
            }

            // ---- 4. Local-only
            if (!empty($item['local_only']) && $appEnv !== 'local') {
                continue;
            }

            // ---- 5. Label override per role (Details group only)
            if (($item['group'] ?? '') === 'Details') {
                $item['label'] = $this->detailsLabel($item['slug'], $roleName, $item['label']);
            }
            if ($item['slug'] === 'dealer_dashboard' && in_array($roleName, ['DEPO', 'Depo'], true)) {
                $item['label'] = 'Depo Dashboard';
            }

            $visible[] = $item;
        }

        // Group for the frontend sidebar — preserves insertion order
        $grouped = [];
        foreach ($visible as $row) {
            $grouped[$row['group'] ?? 'Other'][] = $row;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'role'       => $roleName,
                'role_id'    => (int) $user->role_id,
                'company_id' => $companyId,
                'dealer_id'  => (int) $user->dealer_id,
                'total'      => count($visible),
                'items'      => $visible,      // flat list
                'grouped'    => $grouped,      // { "Master": [...], "Stock": [...] }
                'badges'     => [
                    'notifications' => $this->notificationCount($companyId, $user->dealer_id),
                ],
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Role label override (Details section)
    //   DEPO user sees "Depo Details" section label
    // -----------------------------------------------------------------

    private function detailsLabel(string $slug, string $role, string $fallback): string
    {
        if (in_array($role, ['DEPO', 'Depo'], true)) {
            // Simple override — section label flips to "Depo Details"
            // (only applied to the first item of the group to avoid noise)
            return $slug === 'quick_order' ? 'Depo Details' : $fallback;
        }
        return $fallback;
    }

    // -----------------------------------------------------------------
    // Role lookup  (_role : role_id, rolename)
    // -----------------------------------------------------------------

    private function resolveRoleName($roleId): string
    {
        if (empty($roleId)) {
            return 'Unknown';
        }
        $row = DB::selectOne(
            "SELECT rolename FROM _role WHERE role_id = ? LIMIT 1",
            [$roleId]
        );
        return trim($row->rolename ?? 'Unknown');
    }

    // -----------------------------------------------------------------
    // Badges
    // -----------------------------------------------------------------

    private function notificationCount(int $companyId, int $dealerId): int
    {
        try {
            $row = DB::selectOne(
                "SELECT COUNT(*) AS cnt
                   FROM dms_inner_notification_details
                  WHERE company_id = ? AND dealer_id = ? AND is_read = 0",
                [$companyId, $dealerId]
            );
            return (int) ($row->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}