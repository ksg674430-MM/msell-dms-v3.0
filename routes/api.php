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

use App\Http\Controllers\Api\DispatchController;

use App\Http\Controllers\Api\SaleWiseInvoiceController;


// ============================================================
// PUBLIC (no auth)
// ============================================================
// MENU: Login screen
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {

    // ========================================================
    // AUTH  → MENU: App boot / Session
    // ========================================================
    Route::get('/me',      [AuthController::class, 'me']);       // app load pe user info
    Route::post('/logout', [AuthController::class, 'logout']);   // MENU: Logout button

    // ========================================================
    // MENU  → MENU: Sidebar / Hamburger
    // ========================================================
    Route::get('/menu', [MenuController::class, 'index']);       // sidebar items (role-wise)

    // ========================================================
    // DASHBOARD  → MENU: Dashboard / Home
    // ========================================================
    Route::get('/dashboard',     [DashboardController::class, 'index']);  // cards, today's sale
    Route::get('/dashboard/mtd', [DashboardController::class, 'mtd']);    // month-to-date view

    // ========================================================
    // PROFILE  → MENU: My Profile
    // ========================================================
    Route::get('/profile',  [ProfileController::class, 'show']);    // profile screen load
    Route::post('/profile', [ProfileController::class, 'update']);  // save button

    // ========================================================
    // MASTER DATA
    // ========================================================

    // --------------------------------------------------------
    // VAN  → MENU: Master → Supply vehicle
    // --------------------------------------------------------
    Route::get   ('/vans',      [VanController::class, 'index']);   // van list
    Route::get   ('/vans/{id}', [VanController::class, 'show']);    // van detail
    Route::post  ('/vans',      [VanController::class, 'store']);   // add van
    Route::put   ('/vans/{id}', [VanController::class, 'update']);  // edit van
    Route::delete('/vans/{id}', [VanController::class, 'destroy']); // delete van

    // --------------------------------------------------------
    // THRESHOLD  → MENU: Master → Threshold / Low-stock alert
    // --------------------------------------------------------
    Route::get   ('/thresholds',          [ThresholdController::class, 'index']);    // list
    Route::get   ('/thresholds/products', [ThresholdController::class, 'products']); // product dropdown
    Route::get   ('/thresholds/{id}',     [ThresholdController::class, 'show']);     // detail
    Route::post  ('/thresholds',          [ThresholdController::class, 'store']);    // create
    Route::put   ('/thresholds/{id}',     [ThresholdController::class, 'update']);   // edit
    Route::delete('/thresholds/{id}',     [ThresholdController::class, 'destroy']);  // delete

    // --------------------------------------------------------
    // CATALOG PRODUCT  → MENU: Master → Products
    // --------------------------------------------------------
    Route::get   ('/catalog-products',         [CatalogProductController::class, 'index']);   // product list
    Route::get   ('/catalog-products/filters', [CatalogProductController::class, 'filters']); // filter dropdowns
    Route::get   ('/catalog-products/{id}',    [CatalogProductController::class, 'show']);    // product detail
    Route::post  ('/catalog-products',         [CatalogProductController::class, 'store']);   // add product
    Route::put   ('/catalog-products/{id}',    [CatalogProductController::class, 'update']);  // edit
    Route::delete('/catalog-products/{id}',    [CatalogProductController::class, 'destroy']); // delete

    // --------------------------------------------------------
    // RETAILER  → MENU: Master → Retailers
    // --------------------------------------------------------
    Route::prefix('retailers')->group(function () {
        Route::get   ('/',           [RetailerController::class, 'index']);     // retailer list
        Route::post  ('/query',      [RetailerController::class, 'query']);     // filtered search
        Route::get   ('/form-data',  [RetailerController::class, 'formData']);  // dropdowns (beat, class, route…)
        Route::get   ('/locations',  [RetailerController::class, 'locations']); // beat/location list
        Route::post  ('/',           [RetailerController::class, 'store']);     // create retailer
        Route::get   ('/{id}',       [RetailerController::class, 'show']);      // retailer detail
        Route::put   ('/{id}',       [RetailerController::class, 'update']);    // edit
        Route::delete('/{id}',       [RetailerController::class, 'destroy']);   // delete
    });

    // ========================================================
    // STOCK
    // ========================================================

    // --------------------------------------------------------
    // BALANCE STOCK  → MENU: Reports → Current Stock / Balance Stock
    // --------------------------------------------------------
    Route::get('/reports/balance-stock',                 [BalanceStockController::class, 'index']);          // main report
    Route::get('/reports/balance-stock/filters',         [BalanceStockController::class, 'filters']);        // all filters
    Route::get('/filters/classifications',               [BalanceStockController::class, 'classifications']); // filter: class
    Route::get('/filters/categories',                    [BalanceStockController::class, 'categories']);      // filter: category
    Route::get('/filters/product-names',                 [BalanceStockController::class, 'productNames']);    // filter: name
    Route::get('/filters/skus',                          [BalanceStockController::class, 'skus']);            // filter: SKU
    Route::delete('/reports/balance-stock/{id}', [BalanceStockController::class, 'destroy']);// delete stock
    Route::post('/reports/balance-stock/edit', [BalanceStockController::class, 'bulkUpdate']);//  edit stock

    // --------------------------------------------------------
    // IMPORT  → MENU: Stock → Import (Bulk upload)
    // --------------------------------------------------------
     Route::get('/opening-stocks/import-format', [StockImportController::class,'importFormat']);  // Export Excel 
    Route::post('/opening-stocks/import',          [StockImportController::class, 'openingStock']);  // Excel upload → opening stock
    Route::post('/purchase-orders/import-receive', [StockImportController::class, 'receiveStock']);  // Excel upload → receive stock

    // --------------------------------------------------------
    // OPENING STOCK  → MENU: Stock → Opening Stock
    // --------------------------------------------------------
    Route::get   ('/opening-stocks/products', [OpeningStockController::class, 'products']); // product picker
    Route::get   ('/opening-stocks',          [OpeningStockController::class, 'index']);    // opening stock list
    Route::get   ('/opening-stocks/{id}',     [OpeningStockController::class, 'show']);     // detail
    Route::post  ('/opening-stocks',          [OpeningStockController::class, 'store']);    // add opening stock
    Route::put   ('/opening-stocks/{id}',     [OpeningStockController::class, 'update']);   // edit
    Route::delete('/opening-stocks/{id}',     [OpeningStockController::class, 'destroy']);  // delete
    Route::post  ('/opening-stocks/query',    [OpeningStockController::class, 'query']);    // filtered search

    

    // --------------------------------------------------------
    // PURCHASE ORDER / RECEIVE STOCK  → MENU: Purchase → Purchase Order, Receive Stock
    // --------------------------------------------------------
    Route::prefix('purchase-orders')->group(function () {
        Route::get ('/',              [PurchaseOrderController::class, 'index']);     // PO list
        Route::get ('/form-data',     [PurchaseOrderController::class, 'formData']);  // supplier/product dropdowns
        Route::post('/',              [PurchaseOrderController::class, 'store']);     // create PO
        Route::get ('/{id}',          [PurchaseOrderController::class, 'show']);      // PO detail
        Route::put ('/{id}',          [PurchaseOrderController::class, 'update']);    // edit PO
        Route::post('/{id}/receive',  [PurchaseOrderController::class, 'receive']);   // receive → stock in
        // Route::post('/query',         [PurchaseOrderController::class, 'query']);     // filtered search
    });

    // ========================================================
    // CHALLAN / INVOICE (Direct sale)
    // ========================================================

    // --------------------------------------------------------
    // CHALLAN HELPERS  → MENU: Invoice → Direct Invoice (form load helpers)
    // NOTE: Order matters. Static routes MUST come before /{id} else
    //       Laravel "next-number" ko id samajh lega.
    // --------------------------------------------------------
    Route::get ('/challans/next-number',            [ChallanController::class, 'nextNumber']);        // auto inv. no.
    Route::get ('/challans/form-data',              [ChallanController::class, 'formData']);          // retailer, salesman, beat dropdowns
    Route::post('/challans/scheme-qty',             [ChallanHelperController::class, 'schemeQty']);   // scheme qty calc
    Route::get ('/challans/product-stock/{id}',     [ChallanHelperController::class, 'productStock']);// batch + mrp + rate + stock
    Route::post('/challans/query',                  [ChallanController::class, 'query']);             // filtered challan search

    // --------------------------------------------------------
    // CHALLAN CRUD  → MENU: Invoice → Direct Invoice
    // --------------------------------------------------------
    Route::get   ('/challans',                [ChallanController::class, 'index']);   // challan list
    Route::get   ('/challans/{id}',           [ChallanController::class, 'show']);    // challan detail
    Route::post  ('/challans',                [ChallanController::class, 'store']);   // create direct invoice
    Route::put   ('/challans/{id}',           [ChallanController::class, 'update']);  // edit invoice
    Route::post  ('/challans/{id}/cancel',    [ChallanController::class, 'cancel']);  // cancel (move to _cancel table)
    Route::post('/challans/process-claim', [ChallanController::class, 'takeActionOnProcessChallanClaim']);

    // ========================================================
    // SALES ORDER  → MENU: Order → Sales Order
    // ========================================================
    Route::get ('/sales-orders',           [SalesOrderController::class, 'index']);  // order list
    Route::get ('/sales-orders/cancel-reasons', [SalesOrderController::class, 'cancelReasons']); // cancel reason list
    Route::get ('/sales-orders/{orderId}', [SalesOrderController::class, 'show']);   // order detail
    Route::post('/sales-orders/query',     [SalesOrderController::class, 'query']);  // filtered search
    Route::post('/sales-orders/cancel',    [SalesOrderController::class, 'cancel']); // cancel order

    Route::get ('/sale-wise-invoice/orders',      [SaleWiseInvoiceController::class, 'orders']);
Route::get ('/sale-wise-invoice/mrp-options', [SaleWiseInvoiceController::class, 'mrpOptions']);
Route::get ('/sale-wise-invoice/next-ch-no',  [SaleWiseInvoiceController::class, 'nextChNo']);
Route::get ('/sale-wise-invoice/outstanding', [SaleWiseInvoiceController::class, 'outstanding']);
Route::post('/sale-wise-invoice/save',        [SaleWiseInvoiceController::class, 'save']);

    // --------------------------------------------------------
    // CHALLAN FROM ORDERS  → MENU: Order → Convert to Invoice
    // --------------------------------------------------------
    Route::post('/challans/from-orders', [ChallanController::class, 'fromOrders']);  // selected orders → invoices

    // ========================================================
    // INVOICE (rich list)  → MENU: Invoice → Invoice Details
    // (Ye /challans ka replacement hai invoice page ke liye)
    // ========================================================
    Route::get   ('/invoices',              [InvoiceController::class, 'index']);   // invoice list with items+claim
    Route::get   ('/invoices/{id}',         [InvoiceController::class, 'show']);    // invoice detail
    Route::post  ('/invoices/query',        [InvoiceController::class, 'query']);   // filtered search
    Route::match(['PUT','PATCH'], 'invoices/{id}', [InvoiceController::class, 'update']); // edit invoice
    Route::post  ('invoices/{id}/cancel',   [InvoiceController::class, 'cancel']);  // cancel (challan_order → _cancel)
    Route::delete('invoices/{id}',          [InvoiceController::class, 'destroy']); // delete (challan_order → _cancel with DELETE)
    Route::get('/invoices/{id}/displayclaim', [InvoiceController::class, 'claimDetails']);
    Route::post('/invoices/{id}/displayclaim', [InvoiceController::class, 'storeClaim']);

    // ========================================================
    // E-INVOICE (IRN)  → MENU: Invoice → Generate IRN
    // ========================================================
    Route::post('/invoices/{challanId}/irn', [IrnController::class, 'generate']);   // GST portal IRN

    // ========================================================
    // DGR CLAIM  → MENU: Claims → DGR Claim
    // ========================================================
    Route::get  ('/dgr-claims',          [DgrClaimController::class, 'index']);   // claim list
    Route::get  ('/dgr-claims/check',    [DgrClaimController::class, 'check']);   // eligibility check
    Route::get  ('/dgr-claims/details',  [DgrClaimController::class, 'details']); // line detail
    Route::post ('/dgr-claims',          [DgrClaimController::class, 'store']);   // create claim
    Route::post ('/dgr-claims/process',  [DgrClaimController::class, 'process']); // process claim
    Route::post ('/dgr-claims/approve',  [DgrClaimController::class, 'approve']); // approve claim
    Route::post ('/dgr-claims/query',    [DgrClaimController::class, 'query']);   // filtered search

    //===================================================
    // Dialy Dispatch
    //===================================================
    Route::get   ('/dispatches',            [DispatchController::class, 'index']);
Route::get   ('/dispatches/challans',   [DispatchController::class, 'searchChallans']);
Route::get   ('/dispatches/next-no',    [DispatchController::class, 'nextNo']);
Route::post  ('/dispatches',            [DispatchController::class, 'store']);
Route::get   ('/dispatches/{id}',       [DispatchController::class, 'show']);
Route::put   ('/dispatches/{id}',       [DispatchController::class, 'update']);
Route::delete('/dispatches/{id}',       [DispatchController::class, 'destroy']);

    // ========================================================
    // LOOKUP  → MENU: Generic (dropdowns, autocompletes)
    // ========================================================
    Route::prefix('lookup')->group(function () {
        Route::get ('/',                [LookupController::class, 'directory']); // all entities list
        Route::get ('/{entity}/schema', [LookupController::class, 'schema']);    // column definitions
        Route::post('/',                [LookupController::class, 'run']);       // run dynamic query
    });
});