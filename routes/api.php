<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BalanceStockController;

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfileController;

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\OpeningStockController;
use App\Http\Controllers\Api\ChallanController;
use App\Http\Controllers\Api\ChallanHelperController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\StockImportController;


use App\Http\Controllers\Api\DgrClaimController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\IrnController;


use App\Http\Controllers\Api\PurchaseOrderController;



Route::post('/login', [AuthController::class, 'login']);//1

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/me',      [AuthController::class, 'me']);//2
    Route::post('/logout', [AuthController::class, 'logout']);//3

    // Dashboard
    Route::get('/dashboard',     [DashboardController::class, 'index']);//4
    Route::get('/dashboard/mtd', [DashboardController::class, 'mtd']);//5

    // Profile
    Route::get('/profile',  [ProfileController::class, 'show']);//6
    Route::post('/profile', [ProfileController::class, 'update']);//7

    // Balance Stock  
    Route::get('/reports/balance-stock',         [BalanceStockController::class, 'index']);//8
    Route::get('/reports/balance-stock/filters', [BalanceStockController::class, 'filters']);//9

    //Opening Stock
    Route::get   ('/opening-stocks/products', [OpeningStockController::class, 'products']);//10
    Route::get   ('/opening-stocks',          [OpeningStockController::class, 'index']);//11
    Route::get   ('/opening-stocks/{id}',     [OpeningStockController::class, 'show']);//12
    Route::post  ('/opening-stocks',          [OpeningStockController::class, 'store']);//13
    Route::put   ('/opening-stocks/{id}',     [OpeningStockController::class, 'update']);//14
    Route::delete('/opening-stocks/{id}',     [OpeningStockController::class, 'destroy']);//15
    Route::post('/opening-stocks/import',          [StockImportController::class, 'openingStock']);
    Route::post('/purchase-orders/import-receive', [StockImportController::class, 'receiveStock']);

    

     // Challan helpers (must come before /{id})
    Route::get   ('/challans/next-number',                [ChallanController::class, 'nextNumber']);//16
    Route::get   ('/challans/form-data',                  [ChallanController::class, 'formData']);//17
    Route::post  ('/challans/scheme-qty',                 [ChallanHelperController::class, 'schemeQty']);//18
    Route::post  ('/challans/scheme-rate',                [ChallanHelperController::class, 'schemeRate']);//19
    Route::get   ('/challans/product-stock/{id}',         [ChallanHelperController::class, 'productStock']);//20

    // Challan CRUD
    Route::get   ('/challans',                            [ChallanController::class, 'index']);//21
    Route::get   ('/challans/{id}',                       [ChallanController::class, 'show']);//22
    Route::post  ('/challans',                            [ChallanController::class, 'store']);//23
    
    //Sales order
    Route::get ('/sales-orders',         [SalesOrderController::class, 'index']);//24
    Route::post('/sales-orders/cancel',  [SalesOrderController::class, 'cancel']);//25
    Route::get ('/sales-orders/{orderId}', [SalesOrderController::class, 'show']);//26

    // Challan from orders
    Route::post('/challans/from-orders', [ChallanController::class, 'fromOrders']);//27

     // Invoices (rich list replaces /challans for the invoice page)
    Route::get   ('/invoices',              [InvoiceController::class, 'index']);//28
    Route::get   ('/invoices/{id}',         [InvoiceController::class, 'show']);//29

    // IRN this need to be tested too so do not check it yet
    Route::post  ('/invoices/{challanId}/irn', [IrnController::class, 'generate']);//30

    //========== DGR claims these api's need to be TESTED =================//
    Route::get   ('/dgr-claims',                [DgrClaimController::class, 'index']);//31
    Route::get   ('/dgr-claims/check',          [DgrClaimController::class, 'check']);//32
    Route::get   ('/dgr-claims/details',        [DgrClaimController::class, 'details']);//33
    Route::post  ('/dgr-claims',                [DgrClaimController::class, 'store']);//34
    Route::post  ('/dgr-claims/process',        [DgrClaimController::class, 'process']);//35
    Route::post  ('/dgr-claims/approve',        [DgrClaimController::class, 'approve']);//36

    Route::prefix('purchase-orders')->group(function () {
    Route::get('/',            [PurchaseOrderController::class, 'index']);
    Route::get('/form-data',   [PurchaseOrderController::class, 'formData']);
    Route::post('/',           [PurchaseOrderController::class, 'store']);

    Route::get('/{id}',        [PurchaseOrderController::class, 'show']);
    Route::put('/{id}',        [PurchaseOrderController::class, 'update']);
    Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive']);
});

});    