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
        if ($tables === []) {
            throw new RuntimeException('Select at least one table.');
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

    public function stream(array $tables, bool $withData, callable $write, ?callable $progress = null): void
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
