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

        $config = require config_path('menu.php');
        $items  = $this->assignedMenu($companyId, $roleName, $config) ?: $config;

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

    private function assignedMenu(int $companyId, string $roleName, array $config): array
    {
        try {
            $modules = DB::table('web_module as w')
                ->join('modules_bucket as b', 'b.id', '=', 'w.module_id')
                ->where('w.company_id', $companyId)->where('w.status', 1)
                ->where('b.status', 1)->where('b.source', 2)
                ->orderBy('w.sequence')->orderBy('b.id')
                ->get(['b.id', 'b.title as route', DB::raw("COALESCE(NULLIF(TRIM(w.title),''), b.name) as label")])
                ->unique('id');
            if ($modules->isEmpty()) {
                return [];
            }
            $subs = DB::table('sub_web_module as w')
                ->join('sub_web_module_bucket as b', 'b.id', '=', 'w.sub_module_id')
                ->where('w.company_id', $companyId)->where('w.status', 1)->where('b.status', 1)
                ->whereIn('b.module_id', $modules->pluck('id'))
                ->orderBy('b.id')
                ->get(['b.id', 'b.module_id as parent', 'b.title as route', DB::raw("COALESCE(NULLIF(TRIM(w.title),''), b.sub_module_name) as label")])
                ->unique('id');
            $subSubs = DB::table('sub_sub_web_module as w')
                ->join('sub_sub_web_module_bucket as b', 'b.id', '=', 'w.sub_sub_module_id')
                ->where('w.company_id', $companyId)->where('w.status', 1)->where('b.status', 1)
                ->whereIn('b.sub_web_module_id', $subs->pluck('id'))
                ->orderBy('b.id')
                ->get(['b.id', 'b.sub_web_module_id as parent', 'b.title as route', DB::raw("COALESCE(NULLIF(TRIM(w.title),''), b.sub_module_name) as label")])
                ->unique('id');
        } catch (\Throwable $e) {
            return [];
        }

        $levels = [[$modules, 'm'], [$subs, 's'], [$subSubs, 'ss']];
        $roleRules = $this->routeRoles($config);
        $routeSlugs = $this->routeSlugs($config);
        $usedSlugs = [];
        $build = function (int $depth, $parentId) use (&$build, &$usedSlugs, $levels, $roleRules, $routeSlugs, $roleName) {
            [$rows, $prefix] = $levels[$depth];
            $nodes = [];
            foreach ($depth ? $rows->where('parent', $parentId) : $rows as $row) {
                $node = ['slug' => $prefix . $row->id, 'label' => $row->label];
                if (isset($levels[$depth + 1]) && $levels[$depth + 1][0]->where('parent', $row->id)->isNotEmpty()) {
                    if ($children = $build($depth + 1, $row->id)) {
                        $nodes[] = $node + ['icon' => 'fa-desktop', 'children' => $children];
                    }
                    continue;
                }
                $route = in_array(trim((string) $row->route), ['', '#'], true) ? null : '/' . ltrim(trim($row->route), '/');
                if ($route === null) {
                    continue;
                }
                $allowed = $roleRules[$route] ?? null;
                if (!$allowed || in_array($roleName, $allowed, true)) {
                    $slug = $routeSlugs[$route] ?? null;
                    if ($slug !== null && !isset($usedSlugs[$slug])) {
                        $node['slug'] = $usedSlugs[$slug] = $slug;
                    }
                    $nodes[] = $node + ['icon' => 'fa-caret-right', 'route' => $route];
                }
            }
            return $nodes;
        };

        return $build(0, null);
    }

    private function routeSlugs(array $nodes): array
    {
        $slugs = [];
        foreach ($nodes as $node) {
            if (!empty($node['route']) && !empty($node['slug'])) {
                $slugs[$node['route']] ??= $node['slug'];
            }
            $slugs += $this->routeSlugs($node['children'] ?? []);
        }
        return $slugs;
    }

    private function routeRoles(array $nodes, array $inherited = []): array
    {
        $rules = [];
        foreach ($nodes as $node) {
            $roles = array_map('trim', $node['roles'] ?? $inherited);
            if (!empty($node['route']) && $roles) {
                $rules[$node['route']] = $roles;
            }
            $rules += $this->routeRoles($node['children'] ?? [], $roles);
        }
        return $rules;
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