<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BalanceStockController;   // ← ADD THIS
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\OpeningStockController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/me',      [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard',     [DashboardController::class, 'index']);
    Route::get('/dashboard/mtd', [DashboardController::class, 'mtd']);

    // Profile
    Route::get('/profile',  [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);

    // Balance Stock  
    Route::get('/reports/balance-stock',         [BalanceStockController::class, 'index']);
    Route::get('/reports/balance-stock/filters', [BalanceStockController::class, 'filters']);

    //Opening Stock
    Route::get   ('/opening-stocks/products', [OpeningStockController::class, 'products']);
    Route::get   ('/opening-stocks',          [OpeningStockController::class, 'index']);
    Route::get   ('/opening-stocks/{id}',     [OpeningStockController::class, 'show']);
    Route::post  ('/opening-stocks',          [OpeningStockController::class, 'store']);
    Route::put   ('/opening-stocks/{id}',     [OpeningStockController::class, 'update']);
    Route::delete('/opening-stocks/{id}',     [OpeningStockController::class, 'destroy']);
});