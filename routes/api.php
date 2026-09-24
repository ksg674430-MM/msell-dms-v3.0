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

use App\Http\Controllers\Api\MenuController;

use App\Http\Controllers\Api\RetailerController;

use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\VanController;
use App\Http\Controllers\Api\ThresholdController;

use App\Http\Controllers\Api\CatalogProductController;



Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::get('/me',      [AuthController::class, 'me']);
    //Menu controller
    Route::get('/menu', [MenuController::class, 'index']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard
    Route::get('/dashboard',     [DashboardController::class, 'index']);
    Route::get('/dashboard/mtd', [DashboardController::class, 'mtd']);
    Route::post('/challans/query',       [ChallanController::class, 'query']);






    // Profile
    Route::get('/profile',  [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);

    // MAster Routes 
    // Van 
    Route::get('/vans', [VanController::class, 'index']);
Route::get('/vans/{id}', [VanController::class, 'show']);
Route::post('/vans', [VanController::class, 'store']);
Route::put('/vans/{id}', [VanController::class, 'update']);
Route::delete('/vans/{id}', [VanController::class, 'destroy']);

// Threshold Routes
Route::get('/thresholds', [ThresholdController::class, 'index']);
Route::get('/thresholds/products', [ThresholdController::class, 'products']);
Route::get('/thresholds/{id}', [ThresholdController::class, 'show']);
Route::post('/thresholds', [ThresholdController::class, 'store']);
Route::put('/thresholds/{id}', [ThresholdController::class, 'update']);
Route::delete('/thresholds/{id}', [ThresholdController::class, 'destroy']);
// CatalogController

Route::get('/catalog-products', [CatalogProductController::class, 'index']);
Route::get('/catalog-products/filters', [CatalogProductController::class, 'filters']);
Route::get('/catalog-products/{id}', [CatalogProductController::class, 'show']);
Route::post('/catalog-products', [CatalogProductController::class, 'store']);
Route::put('/catalog-products/{id}', [CatalogProductController::class, 'update']);
Route::delete('/catalog-products/{id}', [CatalogProductController::class, 'destroy']);

//  Retailer's related methods
Route::prefix('retailers')->group(function () {
    Route::get('/',              [RetailerController::class, 'index']);
    Route::post('/query', [RetailerController::class, 'query']);
    Route::get('/form-data',     [RetailerController::class, 'formData']);   
    Route::get('/locations',     [RetailerController::class, 'locations']);
    Route::post('/',             [RetailerController::class, 'store']);
    Route::get('/{id}',          [RetailerController::class, 'show']);
    Route::put('/{id}',          [RetailerController::class, 'update']);
    Route::delete('/{id}',       [RetailerController::class, 'destroy']);
});

//Master Ends

// Stocks Start 
// Current Stock APis
    Route::get('/reports/balance-stock',                 [BalanceStockController::class, 'index']);          
    Route::get('/reports/balance-stock/catalog-options', [BalanceStockController::class, 'catalogOptions']); 
    Route::get('/reports/balance-stock/filters', [BalanceStockController::class, 'filters']);
    Route::get('/filters/classifications', [BalanceStockController::class, 'classifications']);
    Route::get('/filters/categories',      [BalanceStockController::class, 'categories']);
    Route::get('/filters/product-names',   [BalanceStockController::class, 'productNames']);
    Route::get('/filters/skus',            [BalanceStockController::class, 'skus']);

// Opening Stock Api's 
    Route::get   ('/opening-stocks/products', [OpeningStockController::class, 'products']);
    Route::get   ('/opening-stocks',          [OpeningStockController::class, 'index']);
    Route::get   ('/opening-stocks/{id}',     [OpeningStockController::class, 'show']);
    Route::post  ('/opening-stocks',          [OpeningStockController::class, 'store']);
    Route::put   ('/opening-stocks/{id}',     [OpeningStockController::class, 'update']);
    Route::delete('/opening-stocks/{id}',     [OpeningStockController::class, 'destroy']);
    Route::post('/opening-stocks/import',          [StockImportController::class, 'openingStock']);
    Route::post('/purchase-orders/import-receive', [StockImportController::class, 'receiveStock']);
    Route::post('/opening-stocks/query', [OpeningStockController::class, 'query']);
// Receive Stocks Api'and create Stocks and all things 
    Route::prefix('purchase-orders')->group(function () {
    Route::get('/',            [PurchaseOrderController::class, 'index']);
    Route::get('/form-data',   [PurchaseOrderController::class, 'formData']);
    Route::post('/',           [PurchaseOrderController::class, 'store']);

    Route::get('/{id}',        [PurchaseOrderController::class, 'show']);
    Route::put('/{id}',        [PurchaseOrderController::class, 'update']);
    Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('/query',[PurchaseOrderController::class, 'query']);
    });

     // Challan helpers (must come before /{id})
    Route::get   ('/challans/next-number',                [ChallanController::class, 'nextNumber']);
    Route::get   ('/challans/form-data',                  [ChallanController::class, 'formData']);
    Route::post  ('/challans/scheme-qty',                 [ChallanHelperController::class, 'schemeQty']);
    Route::post  ('/challans/scheme-rate',                [ChallanHelperController::class, 'schemeRate']);//19
    Route::get   ('/challans/product-stock/{id}',         [ChallanHelperController::class, 'productStock']);//20
    Route::put   ('/challans/{id}',        [ChallanController::class, 'update']);
    Route::post  ('/challans/{id}/cancel', [ChallanController::class, 'cancel']);

    // Challan CRUD
    Route::get   ('/challans',                            [ChallanController::class, 'index']);
    Route::get   ('/challans/{id}',                       [ChallanController::class, 'show']);
    Route::post  ('/challans',                            [ChallanController::class, 'store']);
    
    //Sales order
    Route::get ('/sales-orders',         [SalesOrderController::class, 'index']);
    Route::post('/sales-orders/cancel',  [SalesOrderController::class, 'cancel']);
    Route::get ('/sales-orders/{orderId}', [SalesOrderController::class, 'show']);
    Route::post('/sales-orders/query',   [SalesOrderController::class, 'query']);

    // Challan from orders
    Route::post('/challans/from-orders', [ChallanController::class, 'fromOrders']);

     // Invoices (rich list replaces /challans for the invoice page)
    Route::get   ('/invoices',              [InvoiceController::class, 'index']);
    Route::get   ('/invoices/{id}',         [InvoiceController::class, 'show']);
    Route::post('/invoices/query',       [InvoiceController::class, 'query']);

    // IRN this need to be tested too so do not check it yet
    Route::post  ('/invoices/{challanId}/irn', [IrnController::class, 'generate']);

    //========== DGR claims these api's need to be TESTED =================//
    Route::get   ('/dgr-claims',                [DgrClaimController::class, 'index']);
    Route::get   ('/dgr-claims/check',          [DgrClaimController::class, 'check']);
    Route::get   ('/dgr-claims/details',        [DgrClaimController::class, 'details']);
    Route::post  ('/dgr-claims',                [DgrClaimController::class, 'store']);
    Route::post  ('/dgr-claims/process',        [DgrClaimController::class, 'process']);
    Route::post  ('/dgr-claims/approve',        [DgrClaimController::class, 'approve']);
    Route::post('/dgr-claims/query',     [DgrClaimController::class, 'query']);

   

// Master filter
Route::prefix('lookup')->group(function () {
    Route::get('/',                 [LookupController::class, 'directory']);
    Route::get('/{entity}/schema',  [LookupController::class, 'schema']);
    Route::post('/',                [LookupController::class, 'run']);
});
});    