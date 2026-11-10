<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class DbExportService
{
    private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection'];
    private const MAX_STATEMENT_BYTES = 524288;
    private const MAX_STATEMENT_ROWS = 500;

    public static function allowed(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }
        $users = array_filter(array_map(fn ($u) => strtolower(trim($u)), explode(',', (string) config('db-export.users'))));
        $name = strtolower(trim((string) ($user->uname ?? '')));

        return $name !== '' && in_array($name, $users, true);
    }

    public function database(): string
    {
        return (string) DB::connection()->getDatabaseName();
    }

    public function tables(): array
    {
        $database = $this->database();

        return Cache::remember('db-export:tables:' . $database, max(0, (int) config('db-export.list_cache_seconds', 300)), function () use ($database): array {
            $rows = DB::select(
                "SELECT TABLE_NAME AS name, COALESCE(TABLE_ROWS, 0) AS approx_rows, COALESCE(DATA_LENGTH, 0) AS data_bytes, COALESCE(INDEX_LENGTH, 0) AS index_bytes
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                [$database]
            );
            $largeBytes = max(1, (int) config('db-export.large_table_mb', 100)) * 1048576;
            $sampleRows = max(1, (int) config('db-export.large_table_rows', 1000));
            $tables = [];
            foreach ($rows as $row) {
                $name = (string) $row->name;
                if (self::skipped($name)) {
                    continue;
                }
                $rowsCount = (int) $row->approx_rows;
                $dataBytes = (int) $row->data_bytes;
                $capped = $dataBytes > $largeBytes;
                $exportRows = $capped ? min($rowsCount, $sampleRows) : $rowsCount;
                $exportBytes = $capped ? (int) min($dataBytes, ($dataBytes / max(1, $rowsCount)) * $sampleRows) : $dataBytes;
                $tables[] = [
                    'name' => $name,
                    'approx_rows' => $rowsCount,
                    'size_mb' => round(($dataBytes + (int) $row->index_bytes) / 1048576, 2),
                    'data_mb' => round($dataBytes / 1048576, 2),
                    'capped' => $capped,
                    'export_rows' => $exportRows,
                    'export_mb' => round($exportBytes / 1048576, 2),
                ];
            }
            usort($tables, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $tables;
        });
    }

    public function objects(): array
    {
        $database = $this->database();

        return Cache::remember('db-export:objects:' . $database, max(0, (int) config('db-export.list_cache_seconds', 300)), function () use ($database) {
            $known = [];
            foreach ($this->tables() as $table) {
                $known[strtolower($table['name'])] = $table['name'];
            }
            $viewRows = DB::select(
                'SELECT TABLE_NAME AS name, IS_UPDATABLE AS updatable, VIEW_DEFINITION AS body FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
                [$database]
            );
            foreach ($viewRows as $row) {
                $known[strtolower((string) $row->name)] = (string) $row->name;
            }
            $views = [];
            foreach ($viewRows as $row) {
                preg_match_all('/`([^`]+)`/', (string) $row->body, $m);
                $uses = [];
                foreach (array_unique(array_map('strtolower', $m[1])) as $ref) {
                    if (isset($known[$ref]) && $ref !== strtolower((string) $row->name)) {
                        $uses[] = $known[$ref];
                    }
                }
                sort($uses);
                $views[] = ['name' => (string) $row->name, 'updatable' => $row->updatable === 'YES', 'uses' => $uses];
            }

            $triggers = array_map(function ($row) {
                return [
                    'name' => (string) $row->name,
                    'table' => (string) $row->tbl,
                    'timing' => (string) $row->timing,
                    'event' => (string) $row->event,
                    'created' => $row->created_at ? substr((string) $row->created_at, 0, 19) : '',
                ];
            }, DB::select(
                'SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS tbl, ACTION_TIMING AS timing, EVENT_MANIPULATION AS event, CREATED AS created_at
                   FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER',
                [$database]
            ));

            $params = [];
            foreach (DB::select(
                'SELECT ROUTINE_TYPE AS type, SPECIFIC_NAME AS name, PARAMETER_MODE AS param_mode, PARAMETER_NAME AS param, DTD_IDENTIFIER AS dtd
                   FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = ? AND ORDINAL_POSITION > 0 ORDER BY SPECIFIC_NAME, ORDINAL_POSITION',
                [$database]
            ) as $row) {
                $params[$row->type . ':' . $row->name][] = trim(($row->type === 'PROCEDURE' ? $row->param_mode . ' ' : '') . $row->param . ' ' . $row->dtd);
            }
            $routines = array_map(function ($row) use ($params) {
                $key = $row->type . ':' . $row->name;
                return [
                    'key' => $key,
                    'name' => (string) $row->name,
                    'type' => (string) $row->type,
                    'params' => implode(', ', $params[$key] ?? []),
                    'returns' => (string) $row->return_type,
                    'deterministic' => $row->is_deterministic === 'YES',
                    'access' => str_replace('_', ' ', (string) $row->data_access),
                    'created' => substr((string) $row->created_at, 0, 19),
                    'modified' => substr((string) $row->altered_at, 0, 19),
                    'comment' => (string) $row->note,
                ];
            }, DB::select(
                "SELECT ROUTINE_TYPE AS type, ROUTINE_NAME AS name, COALESCE(DTD_IDENTIFIER, '') AS return_type, IS_DETERMINISTIC AS is_deterministic, SQL_DATA_ACCESS AS data_access,
                        CREATED AS created_at, LAST_ALTERED AS altered_at, ROUTINE_COMMENT AS note
                   FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME",
                [$database]
            ));

            return ['views' => $views, 'triggers' => $triggers, 'routines' => $routines];
        });
    }

    public function resolveObjects(array $views, array $triggers, array $routines, array $tables): array
    {
        $objects = $this->objects();
        $pick = function (array $list, array $wanted, string $key) {
            $wanted = array_flip(array_map('strval', $wanted));
            return array_values(array_filter($list, function ($item) use ($wanted, $key) {
                return isset($wanted[$item[$key]]);
            }));
        };
        $resolved = [
            'views' => array_column($pick($objects['views'], $views, 'name'), 'name'),
            'triggers' => $pick($objects['triggers'], $triggers, 'name'),
            'routines' => array_map(function ($r) {
                return ['type' => $r['type'], 'name' => $r['name']];
            }, $pick($objects['routines'], $routines, 'key')),
        ];
        $tableSet = array_flip($tables);
        $missing = [];
        foreach ($resolved['triggers'] as $trigger) {
            if (!isset($tableSet[$trigger['table']])) {
                $missing[$trigger['table']] = true;
            }
        }
        if ($missing) {
            throw new RuntimeException('Triggers can only be created on their tables. Also select: ' . implode(', ', array_keys($missing)) . '.');
        }
        $resolved['triggers'] = array_column($resolved['triggers'], 'name');

        return $resolved;
    }

    public function definition(string $type, string $name): ?string
    {
        $objects = $this->objects();
        if ($type === 'TRIGGER') {
            $found = in_array($name, array_column($objects['triggers'], 'name'), true);
        } elseif (in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
            $found = in_array($type . ':' . $name, array_column($objects['routines'], 'key'), true);
        } else {
            $found = false;
        }
        if (!$found) {
            return null;
        }
        $row = (array) DB::selectOne('SHOW CREATE ' . $type . ' ' . self::quote($name));

        return (string) ($row[$type === 'TRIGGER' ? 'SQL Original Statement' : 'Create ' . ucfirst(strtolower($type))] ?? '');
    }

    public function limits(): array
    {
        return [
            'max_bytes' => (int) config('db-export.max_bytes'),
            'large_table_mb' => max(1, (int) config('db-export.large_table_mb', 100)),
            'large_table_rows' => max(1, (int) config('db-export.large_table_rows', 1000)),
        ];
    }

    public function resolve(array $requested): array
    {
        $wanted = array_flip(array_map('strval', $requested));
        $tables = [];
        foreach ($this->tables() as $table) {
            if (isset($wanted[$table['name']])) {
                $tables[] = $table['name'];
            }
        }

        return $tables;
    }

    public function estimate(array $tables, bool $withData): array
    {
        if (!$withData) {
            return ['bytes' => 0, 'rows' => 0];
        }
        $wanted = array_flip($tables);
        $bytes = 0;
        $rows = 0;
        foreach ($this->tables() as $table) {
            if (isset($wanted[$table['name']])) {
                $bytes += (int) round($table['export_mb'] * 1048576);
                $rows += $table['export_rows'];
            }
        }

        return ['bytes' => $bytes, 'rows' => $rows];
    }

    public function stream(array $tables, bool $withData, callable $write, ?callable $progress = null, array $objects = []): void
    {
        $capped = [];
        foreach ($this->tables() as $table) {
            $capped[$table['name']] = $table['capped'];
        }
        $sampleRows = max(1, (int) config('db-export.large_table_rows', 1000));
        $write("SET NAMES utf8mb4;\nSET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;\nSET FOREIGN_KEY_CHECKS = 0;\nSET @OLD_SQL_MODE = @@SQL_MODE;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        foreach ($tables as $table) {
            $create = DB::selectOne('SHOW CREATE TABLE ' . self::quote($table));
            $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', (string) ($create->{'Create Table'} ?? ''));
            $write('DROP TABLE IF EXISTS ' . self::quote($table) . ";\n" . $ddl . ";\n");
            if ($progress !== null) {
                $progress($table, 0, false);
            }
            if ($withData) {
                $this->writeRows($table, $write, $progress, !empty($capped[$table]) ? $sampleRows : null);
            }
            if ($progress !== null) {
                $progress($table, 0, true);
            }
            $write("\n");
        }
        foreach ($objects['routines'] ?? [] as $routine) {
            $this->writeObject($routine['type'], $routine['name'], $write, $progress);
        }
        foreach ($objects['triggers'] ?? [] as $trigger) {
            $this->writeObject('TRIGGER', $trigger, $write, $progress);
        }
        foreach ($this->viewOrder($objects['views'] ?? []) as $view) {
            $this->writeObject('VIEW', $view, $write, $progress);
        }
        $write("SET SQL_MODE = @OLD_SQL_MODE;\nSET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;\n");
    }

    private function writeRows(string $table, callable $write, ?callable $progress, ?int $limit): void
    {
        $columns = array_values(array_filter(
            DB::select(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, EXTRA AS extra
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                  ORDER BY ORDINAL_POSITION',
                [$this->database(), $table]
            ),
            fn ($c) => stripos((string) $c->extra, 'GENERATED') === false
        ));
        if ($columns === []) {
            return;
        }
        $pattern = (string) config('db-export.secret_columns');
        $meta = array_map(fn ($c) => [
            'binary' => in_array(strtolower((string) $c->type), self::BINARY_TYPES, true),
            'secret' => preg_match($pattern, (string) $c->name) === 1,
            'blank' => $c->nullable === 'YES' ? 'NULL' : "''",
        ], $columns);
        $names = array_map(fn ($c) => self::quote((string) $c->name), $columns);
        $prefix = 'INSERT INTO ' . self::quote($table) . ' (' . implode(', ', $names) . ') VALUES ';

        $pdo = DB::connection()->getPdo();
        $buffered = self::bufferedAttribute();
        $pdo->setAttribute($buffered, false);
        try {
            $sql = 'SELECT ' . implode(', ', $names) . ' FROM ' . self::quote($table);
            if ($limit !== null) {
                $order = array_map(fn ($c) => self::quote((string) $c->name) . ' DESC', DB::select(
                    "SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",
                    [$this->database(), $table]
                ));
                $sql .= ($order !== [] ? ' ORDER BY ' . implode(', ', $order) : '') . ' LIMIT ' . $limit;
            }
            $statement = $pdo->query($sql);
            $values = [];
            $bytes = 0;
            while ($statement !== false && ($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $encoded = [];
                foreach ($row as $index => $value) {
                    $column = $meta[$index];
                    if ($column['secret']) {
                        $encoded[] = $column['blank'];
                    } elseif ($value === null) {
                        $encoded[] = 'NULL';
                    } elseif ($column['binary']) {
                        $encoded[] = $value === '' ? "''" : '0x' . bin2hex((string) $value);
                    } else {
                        $encoded[] = $pdo->quote((string) $value);
                    }
                }
                $tuple = '(' . implode(', ', $encoded) . ')';
                if ($values !== [] && ($bytes + strlen($tuple) > self::MAX_STATEMENT_BYTES || count($values) >= self::MAX_STATEMENT_ROWS)) {
                    $write($prefix . implode(",\n", $values) . ";\n");
                    if ($progress !== null) {
                        $progress($table, count($values), false);
                    }
                    $values = [];
                    $bytes = 0;
                }
                $values[] = $tuple;
                $bytes += strlen($tuple);
            }
            if ($statement !== false) {
                $statement->closeCursor();
            }
            if ($values !== []) {
                $write($prefix . implode(",\n", $values) . ";\n");
                if ($progress !== null) {
                    $progress($table, count($values), false);
                }
            }
        } finally {
            $pdo->setAttribute($buffered, true);
        }
    }

    private function viewOrder(array $views): array
    {
        $uses = [];
        foreach ($this->objects()['views'] as $view) {
            $uses[$view['name']] = $view['uses'];
        }
        $wanted = array_flip($views);
        $ordered = [];
        $visit = function (string $name, array $path) use (&$visit, &$ordered, $uses, $wanted) {
            if (isset($ordered[$name]) || isset($path[$name])) {
                return;
            }
            foreach ($uses[$name] ?? [] as $dep) {
                if (isset($wanted[$dep])) {
                    $visit($dep, $path + [$name => true]);
                }
            }
            $ordered[$name] = true;
        };
        foreach ($views as $view) {
            $visit($view, []);
        }

        return array_keys($ordered);
    }

    private function writeObject(string $type, string $name, callable $write, ?callable $progress): void
    {
        $row = (array) DB::selectOne('SHOW CREATE ' . $type . ' ' . self::quote($name));
        $column = $type === 'TRIGGER' ? 'SQL Original Statement' : 'Create ' . ucfirst(strtolower($type));
        $sql = trim((string) ($row[$column] ?? ''));
        $label = strtolower($type) . ' ' . self::quote($name);
        if ($sql === '') {
            $write('-- Skipped ' . $label . ": the database user cannot read its definition.\n\n");
        } else {
            $sql = preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
            $out = 'DROP ' . $type . ' IF EXISTS ' . self::quote($name) . ";\n";
            if ($type === 'VIEW') {
                $out .= $sql . ";\n\n";
            } else {
                $mode = implode(',', array_filter(explode(',', (string) ($row['sql_mode'] ?? '')), function ($m) {
                    return !in_array($m, ['NO_AUTO_CREATE_USER', ''], true);
                }));
                $out .= "SET SESSION SQL_MODE = '" . $mode . "';\nDELIMITER ;;\n" . $sql . ";;\nDELIMITER ;\n\n";
            }
            $write($out);
        }
        if ($progress !== null) {
            $progress($label, 0, true);
        }
    }

    private static function skipped(string $table): bool
    {
        foreach ((array) config('db-export.skip_tables') as $pattern) {
            if (fnmatch(strtolower((string) $pattern), strtolower($table))) {
                return true;
            }
        }

        return false;
    }

    private static function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function bufferedAttribute(): int
    {
        return defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? (int) constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : (int) constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
    }
}
