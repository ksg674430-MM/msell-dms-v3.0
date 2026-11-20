<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminLogViewerController extends Controller
{
    use RequiresAdmin;

    public const MAX_LINES = 20000;

    public function index(Request $request)
    {
        if (!$this->admin($request)) return $this->adminDenied();

        $files = $this->availableFiles();
        $file  = $this->resolveFile($request, array_keys($files));
        $lines = $this->resolveLines($request);

        return response()->json([
            'status'  => true,
            'files'   => array_map(fn ($name, $meta) => ['name' => $name] + $meta, array_keys($files), $files),
            'file'    => $file,
            'lines'   => $lines,
            'content' => $file ? $this->readTail($this->path($file), $lines) : '',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function download(Request $request)
    {
        if (!$this->admin($request)) return $this->adminDenied();

        $file = $request->input('file');
        if (!in_array($file, array_keys($this->availableFiles()), true)) {
            return response()->json(['status' => false, 'message' => 'Log file not found.'], 404);
        }

        return response()->download($this->path($file), $file, ['Content-Type' => 'text/plain']);
    }

    private function resolveFile(Request $request, array $names)
    {
        $file = $request->input('file');

        return in_array($file, $names, true) ? $file : ($names ? $names[0] : null);
    }

    private function resolveLines(Request $request)
    {
        $lines = (int) $request->input('lines', 1000);

        return in_array($lines, [200, 1000, 5000, self::MAX_LINES], true) ? $lines : 1000;
    }

    private function path($file)
    {
        return storage_path('logs/' . $file);
    }

    private function availableFiles()
    {
        $files = [];
        foreach (glob(storage_path('logs/laravel*.log')) as $path) {
            $files[basename($path)] = [
                'size'     => filesize($path),
                'modified' => filemtime($path),
            ];
        }
        krsort($files);

        return $files;
    }

    private function readTail($path, $lines)
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return '';
        }

        $buffer = '';
        $chunk  = 8192;
        $pos    = filesize($path);
        $found  = 0;

        while ($pos > 0 && $found <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $part   = fread($handle, $read);
            $buffer = $part . $buffer;
            $found  = substr_count($buffer, "\n");
        }
        fclose($handle);

        $all = explode("\n", $buffer);
        if (count($all) > $lines) {
            $all = array_slice($all, -$lines);
        }

        return mb_convert_encoding(implode("\n", $all), 'UTF-8', 'UTF-8');
    }
}
