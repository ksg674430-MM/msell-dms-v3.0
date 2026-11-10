<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        // ── Unauthenticated → JSON 401, never redirect
        $this->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unauthenticated. Provide a valid Bearer token.',
                ], 401);
            }
        });

        // ── Validation → JSON 422
        $this->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'The given data was invalid.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // ── Model not found → JSON 404
        $this->renderable(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        // ── Route not found → JSON 404 (instead of HTML)
        $this->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Endpoint not found: ' . $request->path(),
                ], 404);
            }
        });

        $this->renderable(function (InsufficientStockException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $this->renderable(function (\InvalidArgumentException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        // ── Generic 500 → JSON
        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json([
                        'status'  => false,
                        'message' => $e->getMessage() ?: 'Request failed.',
                    ], $e->getStatusCode(), $e->getHeaders());
                }

                return response()->json([
                    'status'  => false,
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'Server error.',
                ], 500);
            }
        });
    }
}