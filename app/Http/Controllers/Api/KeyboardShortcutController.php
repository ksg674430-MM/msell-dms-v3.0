<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KeyboardShortcutController extends Controller
{
    private const FORMAT = '/^(Ctrl\+)?(Alt\+)?(Shift\+)?([A-Z0-9]|F[1-9]|F1[0-2])$/';

    private const RESERVED = [
        'Ctrl+A', 'Ctrl+C', 'Ctrl+V', 'Ctrl+X', 'Ctrl+Z', 'Ctrl+Y', 'Ctrl+S', 'Ctrl+K', 'Ctrl+N', 'Ctrl+T', 'Ctrl+W', 'Ctrl+Q',
        'Ctrl+R', 'Ctrl+P', 'Ctrl+F', 'Ctrl+L', 'Ctrl+H', 'Ctrl+M', 'Ctrl+1', 'Ctrl+2', 'Ctrl+3', 'Ctrl+4', 'Ctrl+5', 'Ctrl+6',
        'Ctrl+7', 'Ctrl+8', 'Ctrl+9', 'Ctrl+Shift+N', 'Ctrl+Shift+T', 'Ctrl+Shift+W', 'Ctrl+Shift+Q', 'Ctrl+Shift+I',
        'Ctrl+Shift+J', 'Ctrl+Shift+C', 'Ctrl+Shift+R', 'Ctrl+Shift+V', 'Ctrl+Shift+Z', 'Ctrl+Alt+4', 'Alt+F4', 'F5', 'F11', 'F12', 'Ctrl+F5',
    ];

    public function index(Request $request): JsonResponse
    {
        return response()->json(['status' => true, 'data' => $this->resolved($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shortcuts'   => ['required', 'array'],
            'shortcuts.*' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $pages    = $this->resolved($user);
        $bySlug   = collect($pages)->keyBy('slug');
        $wanted   = [];

        foreach ($data['shortcuts'] as $slug => $shortcut) {
            if (!$bySlug->has($slug)) {
                return response()->json(['status' => false, 'message' => "Unknown page: {$slug}."], 422);
            }
            $shortcut = $shortcut === null || trim($shortcut) === '' ? null : trim($shortcut);
            if ($shortcut !== null && $error = $this->invalid($shortcut)) {
                return response()->json(['status' => false, 'message' => "{$bySlug[$slug]['label']}: {$error}"], 422);
            }
            $wanted[$slug] = $shortcut;
        }

        $final = [];
        foreach ($pages as $page) {
            $shortcut = array_key_exists($page['slug'], $wanted) ? $wanted[$page['slug']] : $page['shortcut'];
            if ($shortcut === null) {
                continue;
            }
            if (isset($final[$shortcut])) {
                return response()->json(['status' => false, 'message' => "{$shortcut} is used for both {$final[$shortcut]} and {$page['label']}."], 422);
            }
            $final[$shortcut] = $page['label'];
        }

        [$companyId, $dealerId] = $this->owner($user);

        DB::transaction(function () use ($wanted, $bySlug, $companyId, $dealerId) {
            foreach ($wanted as $slug => $shortcut) {
                $scope = ['company_id' => $companyId, 'dealer_id' => $dealerId, 'menu_slug' => $slug];
                if ($shortcut === $bySlug[$slug]['default_shortcut']) {
                    DB::table('dms_keyboard_shortcuts')->where($scope)->delete();
                } else {
                    DB::table('dms_keyboard_shortcuts')->updateOrInsert($scope, ['shortcut' => $shortcut, 'updated_at' => now()]);
                }
            }
        });

        return response()->json(['status' => true, 'message' => 'Keyboard shortcuts saved.', 'data' => $this->resolved($user)]);
    }

    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();
        [$companyId, $dealerId] = $this->owner($user);

        DB::table('dms_keyboard_shortcuts')
            ->where('company_id', $companyId)
            ->where('dealer_id', $dealerId)
            ->delete();

        return response()->json(['status' => true, 'message' => 'Keyboard shortcuts reset to default.', 'data' => $this->resolved($user)]);
    }

    private function owner($user): array
    {
        return [(int) $user->company_id, (int) $user->dealer_id ?: -(int) $user->getAuthIdentifier()];
    }

    private function resolved($user): array
    {
        [$companyId, $dealerId] = $this->owner($user);
        $pages = $this->pages(require config_path('menu.php'));

        $rows = DB::table('dms_keyboard_shortcuts')
            ->where(function ($q) use ($companyId, $dealerId) {
                $q->where(fn ($w) => $w->where('company_id', 0)->where('dealer_id', 0))
                  ->orWhere(fn ($w) => $w->where('company_id', $companyId)->where('dealer_id', 0))
                  ->orWhere(fn ($w) => $w->where('company_id', $companyId)->where('dealer_id', $dealerId));
            })
            ->get(['company_id', 'dealer_id', 'menu_slug', 'shortcut']);

        $defaults = [];
        $own      = [];
        foreach ($rows->sortBy(fn ($r) => $r->company_id ? 1 : 0) as $row) {
            if ((int) $row->dealer_id === $dealerId) {
                $own[$row->menu_slug] = $row->shortcut;
            } elseif ((int) $row->dealer_id === 0) {
                $defaults[$row->menu_slug] = $row->shortcut;
            }
        }

        $global = $rows->where('company_id', 0)->where('dealer_id', 0)->pluck('menu_slug')->flip();
        $new    = array_values(array_filter($pages, fn ($page) => !$global->has($page['slug'])));
        if ($new) {
            DB::table('dms_keyboard_shortcuts')->insertOrIgnore(array_map(fn ($page) => ['company_id' => 0, 'dealer_id' => 0, 'menu_slug' => $page['slug'], 'shortcut' => null], $new));
        }

        return array_map(fn ($page) => [
            'slug'             => $page['slug'],
            'label'            => $page['label'],
            'route'            => $page['route'],
            'default_shortcut' => $defaults[$page['slug']] ?? null,
            'shortcut'         => array_key_exists($page['slug'], $own) ? $own[$page['slug']] : ($defaults[$page['slug']] ?? null),
            'customized'       => array_key_exists($page['slug'], $own),
        ], $pages);
    }

    private function pages(array $items): array
    {
        $pages = [];
        foreach ($items as $item) {
            if (!empty($item['children'])) {
                array_push($pages, ...$this->pages($item['children']));
            } elseif (!empty($item['route'])) {
                $pages[] = ['slug' => $item['slug'], 'label' => $item['label'], 'route' => $item['route']];
            }
        }
        return $pages;
    }

    private function invalid(string $shortcut): ?string
    {
        if (!preg_match(self::FORMAT, $shortcut, $m)) {
            return "{$shortcut} is not a valid shortcut.";
        }
        if (!str_starts_with($m[4], 'F') && !$m[1] && !$m[2]) {
            return 'add Ctrl or Alt to the key.';
        }
        if (in_array($shortcut, self::RESERVED, true)) {
            return "{$shortcut} is used by the browser or the app.";
        }
        return null;
    }
}
