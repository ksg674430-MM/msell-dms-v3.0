<?php
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

if (! function_exists('str_slug')) {
    function str_slug($string, $separator = '-') {
        return Str::slug($string, $separator);
    }
}

if (!function_exists('array_sum_laravel')) {
    function array_sum_laravel(array $arr): float {
        $numericValues = array_map(function($v) {
            return is_numeric($v) ? $v + 0 : 0;
        }, $arr);

        return \array_sum($numericValues);
    }
}

if (!function_exists('formatInr')) {
    function formatInr($number) {
        if ($number == null) return 0;

        $num = (string) round($number);
        $last3 = substr($num, -3);
        $rest = substr($num, 0, -3);

        if ($rest != '') {
            $last3 = ',' . $last3;
        }

        return preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest) . $last3;
    }
}

if (!function_exists('formatInrDecimal')) {
    function formatInrDecimal($number)
    {
        if ($number === null || $number === '') {
            return '0.00';
        }

        $number = number_format((float)$number, 2, '.', '');

        $parts = explode('.', $number);
        $integer = $parts[0];
        $decimal = $parts[1];

        if (strlen($integer) > 3) {
            $last3 = substr($integer, -3);
            $rest = substr($integer, 0, -3);
            $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest);
            $integer = $rest . ',' . $last3;
        }

        return $integer . '.' . $decimal;
    }
}

/* ---------- Added for the API port ---------- */

if (!function_exists('myrowval')) {
    function myrowval(string $table, string $column, string $where)
    {
        $row = DB::selectOne("SELECT {$column} FROM {$table} WHERE {$where} LIMIT 1");
        return $row ? $row->{$column} : null;
    }
}

if (!function_exists('myrowval_assoc')) {
    function myrowval_assoc(string $table, string $columns, string $keyCol, string $where)
    {
        return DB::select("SELECT {$columns} FROM {$table} WHERE {$where}");
    }
}

if (!function_exists('encrypt_legacy')) {
    function encrypt_legacy(string $plain): string
    {
        return openssl_encrypt(
            $plain,
            'aes-128-ecb',
            config('app.edsalt'),
            OPENSSL_RAW_DATA
        );
    }
}

if (!function_exists('pre')) {
    function pre($data)
    {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
    }
}

if (!function_exists('h1')) {
    function h1($data)
    {
        echo '<h1>' . htmlspecialchars((string) $data) . '</h1>';
    }
}

if (!function_exists('legacy_public_url')) {
    function legacy_public_url(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '' || strtoupper($path) === 'NA') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = (string) config('app.legacy_public_url') ?: 'https://demo.msell.in/public';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('safe_error_message')) {
    function safe_error_message(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException || $e instanceof \Error) {
            report($e);
            return 'A server error occurred. Please try again.';
        }
        return $e->getMessage();
    }
}
