<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FilterBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /* =========================================================
     |  Directory
     |  GET /api/lookup
     |  Lists every entity available in config/lookups.php.
     | ========================================================= */
    public function directory(): JsonResponse
    {
        $entities = [];
        foreach (config('lookups', []) as $key => $cfg) {
            $entities[] = [
                'entity'       => $key,
                'label'        => $cfg['label']        ?? $key,
                'parent_field' => $cfg['parent_field'] ?? null,
                'max_limit'    => $cfg['max_limit']    ?? 200,
            ];
        }

        return response()->json([
            'status'   => true,
            'entities' => $entities,
            'count'    => count($entities),
        ]);
    }

    /* =========================================================
     |  Schema
     |  GET /api/lookup/{entity}/schema
     |  Column metadata — lets the frontend build filter UIs.
     | ========================================================= */
    public function schema(string $entity): JsonResponse
    {
        $cfg = $this->getConfig($entity);
        if (!$cfg) {
            return response()->json([
                'status'  => false,
                'message' => "Unknown entity '{$entity}'.",
            ], 404);
        }

        $columns = [];
        foreach ($cfg['fields'] as $field => $meta) {
            $columns[] = [
                'field' => $field,
                'label' => $meta['label'] ?? $field,
                'type'  => $meta['type']  ?? 'string',
                'ops'   => $meta['ops']   ?? ['eq'],
                'options' => $meta['options'] ?? null,
                'sortable' => true,   // all whitelisted fields are sortable
            ];
        }

        return response()->json([
            'status'       => true,
            'entity'       => $entity,
            'label'        => $cfg['label']        ?? $entity,
            'parent_field' => $cfg['parent_field'] ?? null,
            'max_limit'    => $cfg['max_limit']    ?? 200,
            'searchable'   => $cfg['searchable']   ?? [],
            'columns'      => $columns,
        ]);
    }

    /* =========================================================
     |  Run a lookup
     |  POST /api/lookup
     |  Body: {
     |    entity: "retailers",
     |    filters: [ {field, op, value} ],
     |    parent_id: 22,
     |    search: "quick search",
     |    limit: 50,
     |    sort: "name"
     |  }
     | ========================================================= */
    public function run(Request $request): JsonResponse
    {
        $entity = (string) $request->input('entity', '');
        $cfg    = $this->getConfig($entity);
        if (!$cfg) {
            return response()->json([
                'status'  => false,
                'message' => "Unknown entity '{$entity}'.",
            ], 404);
        }

        // ── Translate `limit` → `per_page` for FilterBuilder.
        // Lookups always page=1, capped at max_limit.
        $maxLimit = (int) ($cfg['max_limit'] ?? 200);
        $limit    = (int) $request->input('limit', 50);
        if ($limit < 1)        $limit = 50;
        if ($limit > $maxLimit) $limit = $maxLimit;

        $request->merge([
            'page'     => 1,
            'per_page' => $limit,
            'filters'  => (array) $request->input('filters', []),
            'search'   => $request->input('search'),
            'sort'     => $request->input('sort', ''),
        ]);

        // ── Instantiate FilterBuilder against the lookup config
        // (FilterBuilder reads from config('filters.{entity}') — we point
        //  it at the lookups config by temporarily binding it.)
        config(["filters.{$entity}" => [
            'base_sql'     => $cfg['base_sql'],
            'count_sql'    => $cfg['count_sql']   ?? null,
            'fields'       => $cfg['fields'],
            'searchable'   => $cfg['searchable']  ?? [],
            'default_sort' => $cfg['default_sort'] ?? [['id', 'asc']],
            'max_per_page' => $maxLimit,
        ]]);

        $fb = FilterBuilder::make($entity, $request);

        // ── Auth scopes
        foreach ($cfg['scopes'] ?? [] as $scopeName => $sql) {
            $value = $this->resolveScope($scopeName, $request);
            if ($value !== null) {
                $fb->hardScope($sql, [$value]);
            }
        }

        // ── Parent cascade
        $parentId = $request->input('parent_id');
        if ($parentId !== null && $parentId !== ''
            && !empty($cfg['parent_field'])) {
            $parentCol = $cfg['parent_field'];
            // resolve column alias from fields if defined, else assume alias.table
            $parentColumn = $cfg['fields'][$parentCol]['column']
                ?? $this->guessColumn($cfg['base_sql'], $parentCol);
            $fb->hardScope("{$parentColumn} = ?", [(int) $parentId]);
        }

        if (!$fb->isValid()) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid filter request.',
                'errors'  => $fb->errors(),
            ], 422);
        }

        $result = $fb->run();

        return response()->json([
            'status' => true,
            'entity' => $entity,
            'data'   => $result['rows'],
            'meta'   => [
                'count'   => count($result['rows']),
                'limit'   => $limit,
                'has_more'=> count($result['rows']) >= $limit && $result['meta']['total'] > $limit,
                'total'   => $result['meta']['total'],
            ],
        ]);
    }

    /* =========================================================
     |  HELPERS
     | ========================================================= */

    private function getConfig(string $entity): ?array
    {
        $cfg = config("lookups.{$entity}");
        return is_array($cfg) && !empty($cfg['fields']) ? $cfg : null;
    }

    private function resolveScope(string $name, Request $request): mixed
    {
        $user = $request->user();
        return match ($name) {
            'company' => $user->company_id ?? null,
            'dealer'  => $user->dealer_id  ?? null,
            'state'   => $user->state_id   ?? null,
            'csa'     => $user->csa_id     ?? null,
            default   => null,
        };
    }

    /** Fallback when parent_field has no explicit column mapping. */
    private function guessColumn(string $baseSql, string $field): string
    {
        // Very small heuristic: find `FROM xxx yyy` alias and prefix
        if (preg_match('/FROM\s+\w+\s+(\w+)/i', $baseSql, $m)) {
            return $m[1] . '.' . $field;
        }
        return $field;
    }
}