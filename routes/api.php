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
use App\Http\Controllers\Api\QuickOrderController;

use App\Http\Controllers\Api\DealerPaymentController;

use App\Http\Controllers\Api\DamageInvoiceController;
use App\Http\Controllers\Api\DamageLookupController;


use App\Http\Controllers\Api\DepotDamageInvoiceController;

use App\Http\Controllers\Api\DepotDamageLookupController;


use App\Http\Controllers\Api\ChangePasswordController;


use App\Http\Controllers\Api\Reports\SalesReportController;
use App\Http\Controllers\Api\Reports\PerformanceReportController;

use App\Http\Controllers\Api\Reports\StockReportController;      
use App\Http\Controllers\Api\Reports\GstReportController;         
use App\Http\Controllers\Api\Reports\LedgerReportController;

use App\Http\Controllers\Api\SsVanSalesReportController;
use App\Http\Controllers\Api\MainReportApiController;

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
    Route::post('/change-password', [ChangePasswordController::class, 'update']);

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
    Route::get   ('/thresholds/low-stock', [ThresholdController::class, 'lowStock']); // header low stock list
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
// Old Dues
Route::get ('dealer/old-payment/retailers', [DealerPaymentController::class, 'oldPaymentRetailers']);
Route::get ('dealer/old-payment/list',      [DealerPaymentController::class, 'oldPaymentList']);
Route::post('dealer/old-payment/save',      [DealerPaymentController::class, 'oldPaymentSave']);

// Payment Collection
Route::get ('dealer/payment-collection/beats', [DealerPaymentController::class, 'paymentCollectionBeats']);
Route::get ('dealer/payment-collection/list',  [DealerPaymentController::class, 'paymentCollectionList']);
Route::get ('dealer/payment-collection/dues',  [DealerPaymentController::class, 'paymentCollectionRetailerDues']);
Route::get('dealer/payment-collection/dues/{retailer_id}', [DealerPaymentController::class, 'paymentCollectionRetailerDues']);
Route::post('dealer/payment-collection/save',  [DealerPaymentController::class, 'paymentCollectionSave']);

// Challan Payment Reports
Route::get ('dealer/challan-payment/list',      [DealerPaymentController::class, 'challanPaymentList']);
Route::get ('dealer/challan-payment/paid-list', [DealerPaymentController::class, 'paidChallanList']);

Route::post('dealer/payment-collection/save-legacy', [DealerPaymentController::class, 'paymentCollectionSaveLegacy']);


Route::get('/ss-van-sales',        [SsVanSalesReportController::class, 'index']);
Route::get('/ss-van-sales/report', [SsVanSalesReportController::class, 'report']);



  /* ------------- Damage Invoices ------------- */
    Route::get   ('damage-invoices/next-number', [DamageInvoiceController::class, 'nextNumber']);
    Route::post  ('damage-invoices/calculate',   [DamageInvoiceController::class, 'calculate']);
    Route::get   ('damage-invoices',             [DamageInvoiceController::class, 'index']);
    Route::post  ('damage-invoices',             [DamageInvoiceController::class, 'store']);
    Route::get   ('damage-invoices/{id}',        [DamageInvoiceController::class, 'show']);
    Route::put   ('damage-invoices/{id}',        [DamageInvoiceController::class, 'update']);
    Route::delete('damage-invoices/{id}',        [DamageInvoiceController::class, 'destroy']);

    /* ------------- Lookups ------------- */
    Route::prefix('damage-lookups')->group(function () {
        Route::get('retailers',                        [DamageLookupController::class, 'retailers']);
        Route::get('retailers/{id}/location',          [DamageLookupController::class, 'retailerLocation']);
        Route::get('products',                         [DamageLookupController::class, 'products']);
        Route::get('products/{id}/mrp',                [DamageLookupController::class, 'productMrp']);
        Route::get('products/{id}/rate-stock',         [DamageLookupController::class, 'productRateStock']);
        Route::get('products/{id}/dealer-rate',        [DamageLookupController::class, 'productDealerRate']);
        Route::get('products/{id}/gst',                [DamageLookupController::class, 'productGst']);
        Route::get('saleable-non-saleable',            [DamageLookupController::class, 'saleableNonSaleable']);
    });


     /* ------------- Depot Damage Invoices ------------- */
    Route::get   ('depot-damage-invoices/next-number', [DepotDamageInvoiceController::class, 'nextNumber']);
    Route::post  ('depot-damage-invoices/calculate',   [DepotDamageInvoiceController::class, 'calculate']);
    Route::get   ('depot-damage-invoices',             [DepotDamageInvoiceController::class, 'index']);
    Route::post  ('depot-damage-invoices',             [DepotDamageInvoiceController::class, 'store']);
    Route::get   ('depot-damage-invoices/{id}',        [DepotDamageInvoiceController::class, 'show']);
    Route::put   ('depot-damage-invoices/{id}',        [DepotDamageInvoiceController::class, 'update']);
    Route::delete('depot-damage-invoices/{id}',        [DepotDamageInvoiceController::class, 'destroy']);

    /* ------------- Depot Lookups ------------- */
    Route::prefix('depot-damage-lookups')->group(function () {
        Route::get('csas',                          [DepotDamageLookupController::class, 'csas']);
        Route::get('products',                      [DepotDamageLookupController::class, 'products']);
        Route::get('products/{id}/mrp',             [DepotDamageLookupController::class, 'productMrp']);
        Route::get('products/{id}/rate-stock',      [DepotDamageLookupController::class, 'productRateStock']);
        Route::get('products/{id}/dealer-rate',     [DepotDamageLookupController::class, 'productDealerRate']);
        Route::get('products/{id}/gst',             [DepotDamageLookupController::class, 'productGst']);
        Route::get('saleable-non-saleable',         [DepotDamageLookupController::class, 'saleableNonSaleable']);
    });


    
// ========================================================
// REPORTS → MENU: Reports (grouped by domain)
// ========================================================
Route::prefix('reports')->group(function () {

    // ---------- SALES ----------
    Route::get('/sales/item-wise',                    [SalesReportController::class, 'itemWise']);
    Route::get('/sales/item-summary',                 [SalesReportController::class, 'itemSummary']);
    Route::get('/sales/product-sale',                 [SalesReportController::class, 'productSale']);
    Route::get('/sales/item-sale',                    [SalesReportController::class, 'itemSale']);
    Route::get('/sales/item-challan',                 [SalesReportController::class, 'itemChallan']);
    Route::get('/sales/retailer-bill-summary',        [SalesReportController::class, 'retailerBillSummary']);
    Route::get('/sales/retailer-dues-ageing',         [SalesReportController::class, 'retailerDuesAgeing']);
    Route::get('/sales/sales-purchase-performance',   [SalesReportController::class, 'salesPurchasePerformance']);
    Route::get('/sales/sales-purchase-list',          [SalesReportController::class, 'salesPurchaseList']);

    // ---------- PERFORMANCE ----------
    Route::get('/performance/user-sales',             [PerformanceReportController::class, 'userSales']);
    Route::get('/performance/user-bill',              [PerformanceReportController::class, 'userBill']);
    Route::get('/performance/retailer-sales',         [PerformanceReportController::class, 'retailerSales']);
    Route::get('/performance/secondary-sales-book',   [PerformanceReportController::class, 'secondarySalesBook']);
       // ---------- STOCK ----------
    Route::get('/stock/balance',                 [StockReportController::class, 'balanceStock']);
    Route::get('/stock/balance/edit',            [StockReportController::class, 'balanceStockEdit']);
    Route::get('/stock/primary',                 [StockReportController::class, 'primaryStock']);
    Route::get('/stock/item-opening',            [StockReportController::class, 'itemOpeningStock']);
    Route::get('/stock/summary',                 [StockReportController::class, 'stockSummary']);
    Route::get('/stock/opening-closing',         [StockReportController::class, 'openingClosingStock']);
    Route::get('/stock/ledger',                  [StockReportController::class, 'stockLedger']);
    Route::get('/stock/category-qty-value',      [StockReportController::class, 'categoryQtyValue']);

    // ---------- GST ----------
    Route::get('/gst/return-summary',            [GstReportController::class, 'gstReturnSummary']);
    Route::get('/gst/gstr-3b',                   [GstReportController::class, 'gstr3b']);
    Route::get('/gst/hsn-summary',               [GstReportController::class, 'hsnSummary']);
    Route::get('/gst/hsn-detail',                [GstReportController::class, 'hsnDetail']);
    Route::get('/gst/bill-detailed',             [GstReportController::class, 'billDetailed']);
    Route::get('/gst/purchase-detailed',         [GstReportController::class, 'purchaseDetailed']);
    Route::get('/gst/book-wise',                 [GstReportController::class, 'bookWise']);
    Route::get('/gst/book-wise-doc',             [GstReportController::class, 'bookWiseDoc']);

    // ---------- LEDGER ----------
    Route::get('/ledger/party',                  [LedgerReportController::class, 'partyLedger']);
    Route::get('/ledger/retailer',               [LedgerReportController::class, 'retailerLedger']);
    Route::get('/ledger/retailer-payment',       [LedgerReportController::class, 'retailerPayment']);

     });
    // Dispatch Order
    Route::get ('reports/dispatch-order',          [MainReportApiController::class, 'dispatchOrderIndex']);
    Route::post('reports/dispatch-order/data',     [MainReportApiController::class, 'dispatchOrderAjax']);

    // Dispatch Amount
    Route::get ('reports/dispatch-amount',         [MainReportApiController::class, 'dispatchAmountIndex']);
    Route::post('reports/dispatch-amount/data',    [MainReportApiController::class, 'dispatchAmountAjax']);

    // Dashboard zero-order
    Route::get ('dashboard/zero-order',            [MainReportApiController::class, 'dashboardZeroOrder']);
    Route::post('dashboard/zero-order/chart',      [MainReportApiController::class, 'dashboardZeroOrderChart']);
    Route::post('dashboard/zero-order/users',      [MainReportApiController::class, 'dashboardUserZeroOrder']);
    Route::post('dashboard/zero-order/reason-bar', [MainReportApiController::class, 'dashboardUserZeroOrderReasonBar']);

    // Competitor
    Route::get ('competitor/analysis',             [MainReportApiController::class, 'competitorAnalysisReport']);
    Route::get ('competitor/products/{visitId}',   [MainReportApiController::class, 'getProducts']);
    Route::get ('competitor/images/{visitId}',     [MainReportApiController::class, 'getImages']);

    // Attendance export
    Route::post('reports/attendance/export',       [MainReportApiController::class, 'simpleExport']);

    // Retailer ledger / sales
    Route::get ('reports/retailer-ledger',         [MainReportApiController::class, 'retailerLedgerIndex']);
    Route::post('reports/retailer-ledger/data',    [MainReportApiController::class, 'retailerLedgerAjax']);
    Route::get ('reports/retailer-sales',          [MainReportApiController::class, 'retailerWiseSalesIndex']);
    Route::post('reports/retailer-sales/data',     [MainReportApiController::class, 'retailerWiseSalesAjax']);

    // Van sale / settlement
    Route::get ('reports/van-sale',                [MainReportApiController::class, 'vanSaleIndex']);
    Route::post('reports/van-sale/data',           [MainReportApiController::class, 'vanSaleAjax']);
    Route::get ('reports/van-settlement',          [MainReportApiController::class, 'vanSettlementIndex']);
    Route::post('reports/van-settlement/data',     [MainReportApiController::class, 'vanSettlementAjax']);

    // DB sales / DB van summary
    Route::get ('reports/db-sales',                [MainReportApiController::class, 'dbSalesIndex']);
    Route::post('reports/db-sales/data',           [MainReportApiController::class, 'dbSalesAjax']);
    Route::get ('reports/db-van-summary',          [MainReportApiController::class, 'dbVanSummaryIndex']);
    Route::post('reports/db-van-summary/data',     [MainReportApiController::class, 'dbVanSummaryAjax']);

    // DGR
    Route::get ('reports/dgr-claim',               [MainReportApiController::class, 'dgrClaimReport']);
    Route::post('reports/dgr-claim/data',          [MainReportApiController::class, 'dgrClaimAjax']);

    // Sales score card
    Route::get ('reports/sales-score-card',        [MainReportApiController::class, 'salesScoreCard']);
    Route::post('reports/sales-score-card/data',   [MainReportApiController::class, 'salesScoreCardData']);

    // Complaint
    Route::get   ('complaint/form',                [MainReportApiController::class, 'complaintForm']);
    Route::post  ('complaint',                     [MainReportApiController::class, 'storeComplaint']);
    Route::post  ('complaint/running',             [MainReportApiController::class, 'getRunningCase']);
    Route::post  ('complaint/list',                [MainReportApiController::class, 'complaintList']);
    Route::post  ('complaint/show',                [MainReportApiController::class, 'showComplaint']);
    Route::put   ('complaint',                     [MainReportApiController::class, 'updateComplaint']);
    Route::delete('complaint',                     [MainReportApiController::class, 'deleteComplaint']);

});



Route::middleware('auth:sanctum')->prefix('quick-orders')->group(function () {

    // ---------- CORE ----------
    Route::get   ('/',                [QuickOrderController::class, 'index']);            // order list
    Route::get   ('/create-data',     [QuickOrderController::class, 'createData']);       // create screen data
    Route::post  ('/store',           [QuickOrderController::class, 'store']);            // add to cart
    Route::post  ('/store-final',     [QuickOrderController::class, 'storeFinal']);       // cart → demand_order
    Route::post  ('/cart-data',       [QuickOrderController::class, 'cartDataForModal']); // cart modal
    Route::delete('/cart-item',       [QuickOrderController::class, 'deleteCartItem']);   // delete row from cart
    Route::get   ('/invoice-details', [QuickOrderController::class, 'invoiceDetails']);
    Route::get   ('/{id}',            [QuickOrderController::class, 'edit']);             // perfoma / view order
    Route::get   ('/{id}/pdf',        [QuickOrderController::class, 'downloadPdf']);      // perfoma PDF
    Route::post  ('/send-to-erp',     [QuickOrderController::class, 'sendToErp']);        // mark for ERP

    // ---------- HELPERS ----------
    Route::get   ('/helpers/product-rate',       [QuickOrderController::class, 'productRate']);
    Route::post  ('/helpers/check-scheme',       [QuickOrderController::class, 'checkScheme']);
    Route::get   ('/helpers/scheme-autocomplete',[QuickOrderController::class, 'schemeAutocomplete']);

    // ---------- REPORTS ----------
    Route::get('/reports/otc',                    [QuickOrderController::class, 'otc']);
    Route::get('/reports/ot2',                    [QuickOrderController::class, 'ot2']);
    Route::get('/reports/ethical',                [QuickOrderController::class, 'ethical']);
    Route::get('/reports/fmcg',                   [QuickOrderController::class, 'fmcg']);
    Route::get('/reports/jps',                    [QuickOrderController::class, 'jps']);
    Route::get('/reports/jp2',                    [QuickOrderController::class, 'jp2']);
    Route::get('/reports/sale-statement',         [QuickOrderController::class, 'saleStatement']);
    Route::get('/reports/sale-statement-ethical', [QuickOrderController::class, 'saleStatementEthical']);
    Route::get('/reports/dms-to-tally',           [QuickOrderController::class, 'dmsToTally']);
    Route::get('/reports/invoice-summary',        [QuickOrderController::class, 'invoiceSummary']);
    Route::get('/reports/purchase',               [QuickOrderController::class, 'purchaseReport']);
    Route::get('/reports/dealer-dashboard',       [QuickOrderController::class, 'dealerDashboard']);
    Route::post('/reports/common',                [QuickOrderController::class, 'commonReportSection']);

    // ---------- CSV FORMATS ----------
    Route::get('/formats/order',        [QuickOrderController::class, 'orderFormat']);
    Route::get('/formats/dms-challan',  [QuickOrderController::class, 'dmsChallanFormat']);
});

//------------ Claim Api's --------------

use App\Http\Controllers\Api\DmsDgrCreditNoteApiController;

Route::middleware('auth:sanctum')->prefix('dms/credit-note')->group(function () {

    // 1. Listing + filter data
    Route::post('index',           [DmsDgrCreditNoteApiController::class, 'index']);       // retailer claim list (dealer claims)
    Route::post('create',          [DmsDgrCreditNoteApiController::class, 'create']);      // new claim form data
    Route::post('store',           [DmsDgrCreditNoteApiController::class, 'store']);       // create claim
    Route::get ('edit/{id}',       [DmsDgrCreditNoteApiController::class, 'edit']);        // edit claim by encrypted id
    Route::put ('update/{id}',     [DmsDgrCreditNoteApiController::class, 'update']);      // fulfill retailer claim

    // 2. Product rates
    Route::post('claim-product-rates',           [DmsDgrCreditNoteApiController::class, 'claimProductRates']);
    Route::post('non-saleable-qty',              [DmsDgrCreditNoteApiController::class, 'getNonSaleableQty']);
    Route::post('rasna-scheme-qty',              [DmsDgrCreditNoteApiController::class, 'getRasnaSchemeQty']);

    // 3. PDF
    Route::post('pdf/retailer-claim',            [DmsDgrCreditNoteApiController::class, 'pdfGenerationRetailerClaim']);

    // 4. Dealer claims — credit note
    Route::post('generate-credit-note',          [DmsDgrCreditNoteApiController::class, 'takeActionGenerateCreditNote']);

    // 5. Retailer images/amount claim
    Route::post('mt-images',                     [DmsDgrCreditNoteApiController::class, 'creditNoteMtImages']);
    Route::post('mt-images/details',             [DmsDgrCreditNoteApiController::class, 'mtRetailerClaimDetailsDms']);
    Route::post('mt-images/update',              [DmsDgrCreditNoteApiController::class, 'mtRetailerClaimUpdateDms']); // multipart
    Route::post('mt-images/generate-credit-note',[DmsDgrCreditNoteApiController::class, 'takeActionGenerateCreditNoteDms']);

    // 6. Challan claim
    Route::post('challan/process',               [DmsDgrCreditNoteApiController::class, 'takeActionOnProcessChallanClaim']);
    Route::post('challan/list',                  [DmsDgrCreditNoteApiController::class, 'creditNoteChallan']);
    Route::post('challan/details',               [DmsDgrCreditNoteApiController::class, 'mtChallanClaimDetailsSs']);
    Route::post('challan/update',                [DmsDgrCreditNoteApiController::class, 'mtChallanClaimUpdateSs']);
    Route::post('challan/generate-credit-note',  [DmsDgrCreditNoteApiController::class, 'takeActionGenerateChallanCreditNote']);
});