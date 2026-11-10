<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealerPersonLogin;
use App\Services\DbExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DbExportController extends Controller
{
    public function access(Request $request, DbExportService $export): JsonResponse
    {
        abort_unless(DbExportService::allowed($request->user()), 404);

        return response()->json(['status' => true, 'database' => $export->database(), 'max_bytes' => (int) config('db-export.max_bytes')]);
    }

    public function tables(Request $request, DbExportService $export): JsonResponse
    {
        abort_unless(DbExportService::allowed($request->user()), 404);

        return response()->json(['status' => true, 'database' => $export->database(), 'tables' => $export->tables(), 'objects' => $export->objects()] + $export->limits());
    }

    public function definition(Request $request, DbExportService $export): JsonResponse
    {
        abort_unless(DbExportService::allowed($request->user()), 404);
        $validated = $request->validate([
            'type' => ['required', 'in:TRIGGER,PROCEDURE,FUNCTION'],
            'name' => ['required', 'string', 'max:64'],
        ]);
        $sql = $export->definition($validated['type'], $validated['name']);
        if ($sql === null) {
            return response()->json(['status' => false, 'message' => 'Not found.'], 404);
        }
        if ($sql === '') {
            return response()->json(['status' => false, 'message' => 'The database user of this app is not allowed to read this definition.'], 422);
        }

        return response()->json(['status' => true, 'sql' => $sql]);
    }

    public function prepare(Request $request, DbExportService $export): JsonResponse
    {
        $user = $request->user();
        abort_unless(DbExportService::allowed($user), 404);
        $validated = $request->validate([
            'tables' => ['nullable', 'array'],
            'tables.*' => ['string', 'max:64'],
            'views' => ['nullable', 'array'],
            'views.*' => ['string', 'max:64'],
            'triggers' => ['nullable', 'array'],
            'triggers.*' => ['string', 'max:64'],
            'routines' => ['nullable', 'array'],
            'routines.*' => ['string', 'max:80'],
            'mode' => ['required', 'in:data,schema'],
        ]);
        $tables = $export->resolve($validated['tables'] ?? []);
        try {
            $objects = $export->resolveObjects($validated['views'] ?? [], $validated['triggers'] ?? [], $validated['routines'] ?? [], $tables);
        } catch (RuntimeException $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
        $objectCount = count($objects['views']) + count($objects['triggers']) + count($objects['routines']);
        if ($tables === [] && $objectCount === 0) {
            return response()->json(['status' => false, 'message' => 'Select at least one table, view, trigger or routine.'], 422);
        }
        $withData = $validated['mode'] === 'data';
        $estimate = $export->estimate($tables, $withData);
        $limit = (int) config('db-export.max_bytes');
        if ($estimate['bytes'] > $limit) {
            return response()->json([
                'status' => false,
                'message' => sprintf('The selected tables are about %s; the download limit is %s. Select fewer tables or choose Schema only.', self::size($estimate['bytes']), self::size($limit)),
            ], 422);
        }

        $id = Str::random(40);
        Cache::put('db-export:' . $id, ['user' => $user->getKey(), 'tables' => $tables, 'objects' => $objects, 'data' => $withData, 'rows' => $estimate['rows']], now()->addMinutes(2));
        Cache::put('db-export-progress:' . $id, ['user' => $user->getKey(), 'percent' => 0, 'table' => null, 'done' => false, 'error' => null], now()->addHours(2));
        Log::info('db-export prepared', ['user' => $user->uname ?? $user->getKey(), 'database' => $export->database(), 'tables' => count($tables), 'objects' => $objectCount, 'mode' => $validated['mode'], 'ip' => $request->ip()]);

        return response()->json([
            'status' => true,
            'id' => $id,
            'url' => URL::temporarySignedRoute('db-export.download', now()->addMinutes(2), ['id' => $id], false),
        ]);
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        abort_unless(DbExportService::allowed($request->user()), 404);
        $state = Cache::get('db-export-progress:' . $id);
        abort_unless(is_array($state) && (string) $state['user'] === (string) $request->user()->getKey(), 404);
        unset($state['user']);

        return response()->json(['status' => true] + $state);
    }

    public function download(string $id, DbExportService $export): StreamedResponse
    {
        $job = Cache::pull('db-export:' . $id);
        abort_unless(is_array($job), 404);
        abort_unless(DbExportService::allowed(DealerPersonLogin::find($job['user'])), 404);

        $filename = $export->database() . '-' . ($job['data'] ? 'data' : 'schema') . '-' . now()->format('Ymd-His') . '.sql.gz';

        return response()->streamDownload(function () use ($export, $job, $id): void {
            set_time_limit(0);
            ini_set('display_errors', '0');
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $key = 'db-export-progress:' . $id;
            $limit = (int) config('db-export.max_bytes');
            $totalRows = max(0, (int) $job['rows']);
            $objects = $job['objects'] ?? [];
            $totalTables = max(1, count($job['tables']) + count($objects['views'] ?? []) + count($objects['triggers'] ?? []) + count($objects['routines'] ?? []));
            $state = ['rows' => 0, 'tables' => 0, 'bytes' => 0, 'saved' => 0.0];
            $save = function (array $values) use ($key, $job): void {
                Cache::put($key, ['user' => $job['user']] + $values, now()->addHours(2));
            };
            $progress = function (string $table, int $rows, bool $tableDone) use (&$state, $save, $totalRows, $totalTables): void {
                $state['rows'] += $rows;
                if ($tableDone) {
                    $state['tables']++;
                }
                if (!$tableDone && $rows > 0 && microtime(true) - $state['saved'] < 1.0) {
                    return;
                }
                $state['saved'] = microtime(true);
                $byRows = $totalRows > 0 ? $state['rows'] / $totalRows : 0;
                $byTables = $state['tables'] / $totalTables;
                $save(['percent' => min(99, (int) floor(100 * max($byRows, $byTables))), 'table' => $table, 'done' => false, 'error' => null]);
            };
            $gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            $write = function (string $chunk) use ($gzip, &$state, $limit): void {
                $out = deflate_add($gzip, $chunk, ZLIB_NO_FLUSH);
                $state['bytes'] += strlen($out);
                if ($state['bytes'] > $limit) {
                    throw new RuntimeException('The file passed the ' . self::size($limit) . ' limit, so the download was stopped. Select fewer tables.');
                }
                echo $out;
                flush();
            };
            try {
                $export->stream($job['tables'], (bool) $job['data'], $write, $progress, $objects);
            } catch (Throwable $e) {
                report($e);
                $save(['percent' => 0, 'table' => null, 'done' => true, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The download failed. Try again with fewer tables.']);
                return;
            }
            echo deflate_add($gzip, '', ZLIB_FINISH);
            $save(['percent' => 100, 'table' => null, 'done' => true, 'error' => null]);
        }, $filename, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 2) . ' GB' : round($bytes / 1048576, 1) . ' MB';
    }
}
