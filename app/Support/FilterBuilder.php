<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Universal filter / sort / search / pagination builder.
 *
 * Contract:
 *   - Reads a JSON body: { filters: [...], sort: "-x,y", search: "...", page: 1, per_page: 25 }
 *   - Validates every field + operator against config("filters.{resource}.fields")
 *   - Combines with caller-supplied "hard scopes" (auth-based, never from request)
 *   - Runs count + paginated SELECT using raw SQL only
 *   - Returns ['rows' => [...], 'meta' => [...]]
 *
 * Client CANNOT inject column names or SQL. Every column comes from the
 * config schema. Unknown fields / ops → 422 (errors collected, caller decides).
 */
class FilterBuilder
{
    private string  $resource;
    private array   $schema;
    private array   $filters  = [];   // [{column, op, value}]
    private array   $scopes   = [];   // [{sql, bindings}]
    private array   $sort     = [];   // [[column, dir], ...]
    private ?string $search   = null;
    private int     $page     = 1;
    private int     $perPage  = 25;
    private array   $errors   = [];

    /** Supported operators → SQL translation. */
    private const OPS = [
        'eq'          => true,
        'neq'         => true,
        'gt'          => true,
        'gte'         => true,
        'lt'          => true,
        'lte'         => true,
        'like'        => true,
        'contains'    => true,
        'starts_with' => true,
        'ends_with'   => true,
        'in'          => true,
        'not_in'      => true,
        'between'     => true,
        'is_null'     => true,
        'not_null'    => true,
    ];

    private function __construct(string $resource, array $schema)
    {
        $this->resource = $resource;
        $this->schema   = $schema;
    }

    /* =========================================================
     |  Public API
     | ========================================================= */

    /**
     * Build a new instance for the given resource + request.
     * Throws if the resource has no schema in config/filters.php.
     */
    public static function make(string $resource, Request $request): self
    {
        $schema = config("filters.{$resource}");

        if (!is_array($schema) || empty($schema['fields'])) {
            throw new RuntimeException(
                "FilterBuilder: no schema defined for resource '{$resource}'."
            );
        }

        $instance = new self($resource, $schema);
        $instance->parseRequest($request);

        return $instance;
    }

    /**
     * Attach a server-side condition (auth scoping).
     * Not client-controllable. Uses `?` placeholders.
     *
     *   $fb->hardScope('r.dealer_id = ?', [$dealerId]);
     *   $fb->hardScope('r.company_id = ?', [$companyId]);
     */
    public function hardScope(string $sql, array $bindings = []): self
    {
        $this->scopes[] = ['sql' => $sql, 'bindings' => array_values($bindings)];
        return $this;
    }

    /** Validation errors collected during parsing. */
    public function errors(): array
    {
        return $this->errors;
    }

    /** TRUE if the request was well-formed. */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Execute the query.
     * @return array{rows: array, meta: array}
     */
    public function run(): array
    {
        if (!empty($this->errors)) {
            return ['rows' => [], 'meta' => $this->emptyMeta()];
        }

        $baseSql  = $this->schema['base_sql'];
        $countSql = $this->schema['count_sql'] ?? null;

        // ── Build WHERE fragment + ordered bindings
        [$whereSql, $whereBindings] = $this->buildWhere();

        // ── Count
        $total = $this->runCount($countSql, $baseSql, $whereSql, $whereBindings);

        // ── Order
        $orderSql = $this->buildOrderSql();

        // ── Paginate
        $offset = ($this->page - 1) * $this->perPage;
        $sql = $baseSql
             . ' WHERE ' . $whereSql
             . $orderSql
             . ' LIMIT ' . $this->perPage
             . ' OFFSET ' . $offset;

        $rows = DB::select($sql, $whereBindings);

        return [
            'rows' => $rows,
            'meta' => [
                'total'           => $total,
                'page'            => $this->page,
                'per_page'        => $this->perPage,
                'total_pages'     => $this->perPage > 0
                                        ? (int) ceil($total / $this->perPage)
                                        : 0,
                'sort'            => $orderSql,
                'filters_applied' => count($this->filters),
                'search'          => $this->search,
            ],
        ];
    }

    /* =========================================================
     |  Request parsing
 | ========================================================= */

    private function parseRequest(Request $request): void
    {
        $this->parseFilters((array) $request->input('filters', []));
        $this->parseSort($request->input('sort'));
        $this->parseSearch($request->input('search'));
        $this->parsePagination($request);
    }

    private function parseFilters(array $input): void
    {
        $fields = $this->schema['fields'];

        foreach ($input as $i => $f) {
            if (!is_array($f) || !isset($f['field']) || !isset($f['op'])) {
                $this->errors[] = "filters[{$i}]: missing 'field' or 'op'.";
                continue;
            }

            $field = (string) $f['field'];
            $op    = (string) $f['op'];
            $value = $f['value'] ?? null;

            if (!isset($fields[$field])) {
                $this->errors[] = "filters[{$i}]: unknown field '{$field}'.";
                continue;
            }

            if (!isset(self::OPS[$op])) {
                $this->errors[] = "filters[{$i}]: unsupported operator '{$op}'.";
                continue;
            }

            $allowed = $fields[$field]['ops'] ?? [];
            if (!in_array($op, $allowed, true)) {
                $this->errors[] = "filters[{$i}]: operator '{$op}' not allowed on field '{$field}'.";
                continue;
            }

            // Value validation per operator
            if (in_array($op, ['in', 'not_in'], true)) {
                if (!is_array($value) || count($value) === 0) {
                    $this->errors[] = "filters[{$i}]: '{$op}' requires a non-empty array value.";
                    continue;
                }
            } elseif ($op === 'between') {
                if (!is_array($value) || count($value) !== 2) {
                    $this->errors[] = "filters[{$i}]: 'between' requires a 2-element array value.";
                    continue;
                }
            } elseif (in_array($op, ['is_null', 'not_null'], true)) {
                // value ignored
            } else {
                if ($value === null || $value === '') {
                    $this->errors[] = "filters[{$i}]: value is required for '{$op}'.";
                    continue;
                }
            }

            $this->filters[] = [
                'column' => $fields[$field]['column'],
                'op'     => $op,
                'value'  => $value,
                'field'  => $field,
            ];
        }
    }

    private function parseSort($input): void
    {
        $default = $this->schema['default_sort'] ?? [];

        if ($input === null || $input === '') {
            $this->sort = $default;
            return;
        }

        if (!is_string($input)) {
            $this->errors[] = "sort must be a comma-separated string.";
            $this->sort = $default;
            return;
        }

        $fields = $this->schema['fields'];
        $sort   = [];

        foreach (explode(',', $input) as $part) {
            $part = trim($part);
            if ($part === '') continue;

            $dir = 'asc';
            if ($part[0] === '-') {
                $dir  = 'desc';
                $part = substr($part, 1);
            } elseif ($part[0] === '+') {
                $part = substr($part, 1);
            }

            if (!isset($fields[$part])) {
                $this->errors[] = "sort: unknown field '{$part}'.";
                continue;
            }

            $sort[] = [$fields[$part]['column'], $dir];
        }

        $this->sort = $sort ?: $default;
    }

    private function parseSearch($input): void
    {
        if ($input === null || $input === '') {
            $this->search = null;
            return;
        }
        if (!is_string($input)) {
            $this->errors[] = "search must be a string.";
            return;
        }
        $this->search = trim($input);
    }

    private function parsePagination(Request $request): void
    {
        $page    = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 25);

        if ($page < 1) $page = 1;
        if ($perPage < 1) $perPage = 25;

        $max = (int) ($this->schema['max_per_page'] ?? 200);
        if ($perPage > $max) $perPage = $max;

        $this->page    = $page;
        $this->perPage = $perPage;
    }

    /* =========================================================
     |  SQL assembly
     | ========================================================= */

    /**
     * @return array{0: string, 1: array}  [WHERE fragment, ordered bindings]
     */
    private function buildWhere(): array
    {
        $parts    = ['1=1'];
        $bindings = [];

        // 1. Hard scopes (auth-derived, order preserved)
        foreach ($this->scopes as $scope) {
            $parts[] = '(' . $scope['sql'] . ')';
            foreach ($scope['bindings'] as $b) {
                $bindings[] = $b;
            }
        }

        // 2. Client filters
        foreach ($this->filters as $f) {
            [$frag, $binds] = $this->translateFilter($f);
            $parts[] = $frag;
            foreach ($binds as $b) {
                $bindings[] = $b;
            }
        }

        // 3. Free-text search (OR across searchable columns)
        if ($this->search !== null && $this->search !== '') {
            $searchable = $this->schema['searchable'] ?? [];
            if (!empty($searchable)) {
                $orParts     = [];
                $searchValue = '%' . $this->search . '%';
                foreach ($searchable as $col) {
                    $orParts[]  = "{$col} LIKE ?";
                    $bindings[] = $searchValue;
                }
                $parts[] = '(' . implode(' OR ', $orParts) . ')';
            }
        }

        return [implode(' AND ', $parts), $bindings];
    }

    /**
     * Translate one validated filter into [sql fragment, bindings].
     */
    private function translateFilter(array $f): array
    {
        $col   = $f['column'];
        $op    = $f['op'];
        $value = $f['value'];

        switch ($op) {
            case 'eq':          return ["{$col} = ?", [$value]];
            case 'neq':         return ["{$col} <> ?", [$value]];
            case 'gt':          return ["{$col} > ?", [$value]];
            case 'gte':         return ["{$col} >= ?", [$value]];
            case 'lt':          return ["{$col} < ?", [$value]];
            case 'lte':         return ["{$col} <= ?", [$value]];
            case 'like':
            case 'contains':    return ["{$col} LIKE ?", ['%' . $value . '%']];
            case 'starts_with': return ["{$col} LIKE ?", [$value . '%']];
            case 'ends_with':   return ["{$col} LIKE ?", ['%' . $value]];

            case 'in':
                $ph = implode(',', array_fill(0, count($value), '?'));
                return ["{$col} IN ({$ph})", array_values($value)];

            case 'not_in':
                $ph = implode(',', array_fill(0, count($value), '?'));
                return ["{$col} NOT IN ({$ph})", array_values($value)];

            case 'between':
                return ["{$col} BETWEEN ? AND ?", [$value[0], $value[1]]];

            case 'is_null':     return ["{$col} IS NULL", []];
            case 'not_null':    return ["{$col} IS NOT NULL", []];
        }

        // Unreachable — parseFilters() guarantees a valid op.
        return ['1=1', []];
    }

    private function buildOrderSql(): string
    {
        if (empty($this->sort)) {
            return '';
        }
        $parts = [];
        foreach ($this->sort as [$col, $dir]) {
            $parts[] = $col . ' ' . strtoupper($dir);
        }
        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * Run the count query.
     *  - Uses schema's `count_sql` when provided.
     *  - Otherwise wraps base_sql in a subquery.
     */
    private function runCount(
        ?string $countSql,
        string  $baseSql,
        string  $whereSql,
        array   $bindings
    ): int {
        if ($countSql !== null && $countSql !== '') {
            $row = DB::selectOne(
                $countSql . ' WHERE ' . $whereSql,
                $bindings
            );
        } else {
            $row = DB::selectOne(
                "SELECT COUNT(*) AS c FROM ({$baseSql} WHERE {$whereSql}) AS sub",
                $bindings
            );
        }
        return (int) ($row->c ?? 0);
    }

    private function emptyMeta(): array
    {
        return [
            'total'           => 0,
            'page'            => $this->page,
            'per_page'        => $this->perPage,
            'total_pages'     => 0,
            'sort'            => '',
            'filters_applied' => 0,
            'search'          => $this->search,
        ];
    }
}