<?php
// config/menu.php
//
// Flat menu. Each item:
//   slug   : unique key
//   label  : display text
//   icon   : font-awesome class
//   route  : frontend route
//   roles  : [] = visible to ALL roles. Otherwise: allow-list.
//   group  : grouping key for the frontend (optional, for section headers)
//   companies       : only show for these company_ids (optional)
//   exclude_companies : hide for these company_ids (optional)
//   local_only      : show only in APP_ENV=local (optional)

return [

    // ---- Home
    ['slug' => 'home', 'label' => 'Home', 'icon' => 'fa-tachometer', 'route' => '/dashboard', 'group' => 'Main'],

    // ---- Master
    ['slug' => 'add_van',         'label' => 'Supply Vehicle',  'icon' => 'fa-caret-right', 'route' => '/master/vehicles',       'group' => 'Master'],
    ['slug' => 'add_threshold',   'label' => 'Stock Threshold', 'icon' => 'fa-caret-right', 'route' => '/master/threshold',      'group' => 'Master'],
    ['slug' => 'catalog_product', 'label' => 'Item List',       'icon' => 'fa-caret-right', 'route' => '/master/products',       'group' => 'Master'],
    ['slug' => 'retailer_add',    'label' => 'Retailer',        'icon' => 'fa-caret-right', 'route' => '/master/retailers',      'group' => 'Master'],
    ['slug' => 'add_user',        'label' => 'Field Staff',     'icon' => 'fa-caret-right', 'route' => '/master/users',          'group' => 'Master'],
    ['slug' => 'dispatch_beat',   'label' => 'Dispatch Route',  'icon' => 'fa-caret-right', 'route' => '/master/dispatch-route', 'group' => 'Master'],

    // ---- Stock
    ['slug' => 'balance_stock',        'label' => 'Current Stock',        'icon' => 'fa-caret-right', 'route' => '/reports/balance-stock', 'group' => 'Stock'],
    ['slug' => 'opening_stock',        'label' => 'Opening Stock',        'icon' => 'fa-caret-right', 'route' => '/stock/opening',         'group' => 'Stock'],
    ['slug' => 'import_opening_stock', 'label' => 'Import Opening Stock', 'icon' => 'fa-caret-right', 'route' => '/stock/opening/import',  'group' => 'Stock', 'exclude_companies' => [206]],
    ['slug' => 'purchase_order',       'label' => 'Receive Stock',        'icon' => 'fa-caret-right', 'route' => '/purchase-orders',       'group' => 'Stock'],

    // ---- Invoice / Billing
    ['slug' => 'direct_challan',        'label' => 'Generate Invoice',            'icon' => 'fa-caret-right', 'route' => '/challans/create',            'group' => 'Billing', 'roles' => ['DISTRIBUTOR', 'DEPO', 'Depo', 'Super Admin']],
    ['slug' => 'sale_order_details',    'label' => 'Invoice Through SFA',         'icon' => 'fa-caret-right', 'route' => '/sales-orders',               'group' => 'Billing', 'roles' => ['DISTRIBUTOR']],
    ['slug' => 'challan_against_order', 'label' => 'Billing Against Order',       'icon' => 'fa-caret-right', 'route' => '/challans/from-orders',       'group' => 'Billing', 'roles' => ['DEPO', 'Depo']],
    ['slug' => 'challan_against_super', 'label' => 'Billing Against Super Order', 'icon' => 'fa-caret-right', 'route' => '/challans/from-super-orders', 'group' => 'Billing', 'roles' => ['Super Admin']],
    ['slug' => 'make_challan',          'label' => 'Invoice Details',             'icon' => 'fa-caret-right', 'route' => '/invoices',                   'group' => 'Billing'],
    ['slug' => 'return_challan',        'label' => 'Edit Invoice',                'icon' => 'fa-caret-right', 'route' => '/invoices/edit',              'group' => 'Billing'],
    ['slug' => 'invoice_summary',       'label' => 'Invoice Summary',             'icon' => 'fa-caret-right', 'route' => '/invoices/summary',           'group' => 'Billing'],

    // ---- Daily Dispatch
    ['slug' => 'daily_dispatch', 'label' => 'Daily Dispatch', 'icon' => 'fa-share-square-o', 'route' => '/dispatch/daily', 'group' => 'Dispatch'],

    // ---- Other Modules
    ['slug' => 'old_payment',          'label' => 'Old Dues',                'icon' => 'fa-caret-right', 'route' => '/payments/old',         'group' => 'Other Modules'],
    ['slug' => 'payment_new',          'label' => 'Payment Collection',      'icon' => 'fa-caret-right', 'route' => '/payments/collect',     'group' => 'Other Modules'],
    ['slug' => 'damage_retailer',      'label' => 'Retailer Damage/Replace', 'icon' => 'fa-caret-right', 'route' => '/damage/retailer',      'group' => 'Other Modules'],
    ['slug' => 'damage_depot',         'label' => 'Depot Damage/Replace',    'icon' => 'fa-caret-right', 'route' => '/damage/depot',         'group' => 'Other Modules'],
    ['slug' => 'damage_list_retailer', 'label' => 'Retailer Damage List',    'icon' => 'fa-caret-right', 'route' => '/damage/retailer/list', 'group' => 'Other Modules'],
    ['slug' => 'damage_list_depot',    'label' => 'Depot Damage List',       'icon' => 'fa-caret-right', 'route' => '/damage/depot/list',    'group' => 'Other Modules'],

    // ---- Reports: Sale
    ['slug' => 'retailer_wise_sale_perf', 'label' => 'MKT Wise Retailer Sale',      'icon' => 'fa-caret-right', 'route' => '/reports/retailer-sale',       'group' => 'Sale Reports'],
    ['slug' => 'product_wise_sale_perf',  'label' => 'Item Wise Retailer Sale',     'icon' => 'fa-caret-right', 'route' => '/reports/product-sale',        'group' => 'Sale Reports'],
    ['slug' => 'challan_report',          'label' => 'Challan Vs Sale',             'icon' => 'fa-caret-right', 'route' => '/reports/challan-vs-sale',     'group' => 'Sale Reports'],
    ['slug' => 'tax_inv_sale_report',     'label' => 'Tax Invoice Sale',            'icon' => 'fa-caret-right', 'route' => '/reports/tax-invoice',         'group' => 'Sale Reports'],
    ['slug' => 'sale_report',             'label' => 'DSR Salesman Report',         'icon' => 'fa-caret-right', 'route' => '/reports/dsr',                 'group' => 'Sale Reports'],
    ['slug' => 'retailer_sale_perf',      'label' => 'Retailers Sale Perf',         'icon' => 'fa-caret-right', 'route' => '/reports/retailer-sale-perf',  'group' => 'Sale Reports'],
    ['slug' => 'division_wise',           'label' => 'Division Wise Invoice',       'icon' => 'fa-caret-right', 'route' => '/reports/division-invoice',    'group' => 'Sale Reports'],
    ['slug' => 'secondary_sales_book',    'label' => 'Secondary Sales Book',        'icon' => 'fa-caret-right', 'route' => '/reports/secondary-sales',     'group' => 'Sale Reports'],
    ['slug' => 'dealer_sale_perf',        'label' => 'MKT Wise Sale Vs Purchase',   'icon' => 'fa-caret-right', 'route' => '/reports/sale-vs-purchase',    'group' => 'Sale Reports', 'exclude_companies' => [213]],
    ['slug' => 'item_wise_sales',         'label' => 'User Wise SKU Summary',       'icon' => 'fa-caret-right', 'route' => '/reports/user-sku',            'group' => 'Sale Reports', 'exclude_companies' => [213]],
    ['slug' => 'item_wise_challan',       'label' => 'User Challan Summary',        'icon' => 'fa-caret-right', 'route' => '/reports/user-challan',        'group' => 'Sale Reports', 'exclude_companies' => [213]],
    ['slug' => 'division_summary_report', 'label' => 'Division Wise Sale',          'icon' => 'fa-caret-right', 'route' => '/reports/division-sale',      'group' => 'Sale Reports', 'exclude_companies' => [213]],
    ['slug' => 'bill_purchase_perf',      'label' => 'Monthly Challan Vs Purchase', 'icon' => 'fa-caret-right', 'route' => '/reports/challan-vs-purchase', 'group' => 'Sale Reports', 'exclude_companies' => [213]],

    // ---- Reports: GST
    ['slug' => 'gst_return_summary', 'label' => 'GSTR1 Report',   'icon' => 'fa-caret-right', 'route' => '/reports/gstr1',  'group' => 'GST Reports'],
    ['slug' => 'purchase_register',  'label' => 'GSTR2 Report',   'icon' => 'fa-caret-right', 'route' => '/reports/gstr2',  'group' => 'GST Reports'],
    ['slug' => 'gstr_report',        'label' => 'GSTR 3B Report', 'icon' => 'fa-caret-right', 'route' => '/reports/gstr3b', 'group' => 'GST Reports'],

    // ---- Reports: Stock
    ['slug' => 'stock_summary',    'label' => 'Stock Summary', 'icon' => 'fa-caret-right', 'route' => '/reports/stock-summary',  'group' => 'Stock Reports'],
    ['slug' => 'current_stock',    'label' => 'Current Stock', 'icon' => 'fa-caret-right', 'route' => '/reports/current-stock',  'group' => 'Stock Reports'],
    ['slug' => 'stock_details',    'label' => 'Stock Details', 'icon' => 'fa-caret-right', 'route' => '/reports/stock-details',  'group' => 'Stock Reports', 'exclude_companies' => [213]],
    ['slug' => 'stock_ledger',     'label' => 'Stock Ledger',  'icon' => 'fa-caret-right', 'route' => '/reports/stock-ledger',   'group' => 'Stock Reports', 'exclude_companies' => [213]],
    ['slug' => 'low_stock_report', 'label' => 'Low Stock',     'icon' => 'fa-caret-right', 'route' => '/reports/low-stock',      'group' => 'Stock Reports'],

    // ---- Reports: Invoice
    ['slug' => 'stock_ledger_inv',        'label' => 'Product Wise Invoice',    'icon' => 'fa-caret-right', 'route' => '/reports/product-invoice',  'group' => 'Invoice Reports'],
    ['slug' => 'product_summary',         'label' => 'Product Wise Summary',    'icon' => 'fa-caret-right', 'route' => '/reports/product-summary',  'group' => 'Invoice Reports'],
    ['slug' => 'gst_wise_summary',        'label' => 'GST Wise Invoice',        'icon' => 'fa-caret-right', 'route' => '/reports/gst-invoice',      'group' => 'Invoice Reports'],
    ['slug' => 'bill_summary',            'label' => 'Bill Wise Summary',       'icon' => 'fa-caret-right', 'route' => '/reports/bill-summary',     'group' => 'Invoice Reports'],
    ['slug' => 'retailer_bill_summary',   'label' => 'Retailer Wise Bill',      'icon' => 'fa-caret-right', 'route' => '/reports/retailer-bill',    'group' => 'Invoice Reports'],
    ['slug' => 'retailer_summary',        'label' => 'Retailer Wise Summary',   'icon' => 'fa-caret-right', 'route' => '/reports/retailer-summary', 'group' => 'Invoice Reports'],
    ['slug' => 'purchase_report',         'label' => 'Purchase Report',         'icon' => 'fa-caret-right', 'route' => '/reports/purchase',         'group' => 'Invoice Reports', 'exclude_companies' => [213]],
    ['slug' => 'purchase_report_details', 'label' => 'Purchase Report Details', 'icon' => 'fa-caret-right', 'route' => '/reports/purchase-details', 'group' => 'Invoice Reports', 'exclude_companies' => [213]],
    ['slug' => 'intransit_details',       'label' => 'In Transit Purchase',     'icon' => 'fa-caret-right', 'route' => '/reports/intransit',        'group' => 'Invoice Reports', 'exclude_companies' => [213]],

    // ---- Reports: Dispatch
    ['slug' => 'daily_dispatch_report', 'label' => 'Daily Dispatch',        'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-daily',   'group' => 'Dispatch Reports'],
    ['slug' => 'productwise_dispatch',  'label' => 'Product Wise Dispatch', 'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-product', 'group' => 'Dispatch Reports'],
    ['slug' => 'datewise_dispatch',     'label' => 'Invoice Wise Dispatch', 'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-invoice', 'group' => 'Dispatch Reports'],

    // ---- Reports: Payment
    ['slug' => 'retailer_payment_report', 'label' => 'Payment Collection',      'icon' => 'fa-caret-right', 'route' => '/reports/payment-collection', 'group' => 'Payment Reports'],
    ['slug' => 'retailer_ledger',         'label' => 'Retailer Payment Ledger', 'icon' => 'fa-caret-right', 'route' => '/reports/retailer-ledger',    'group' => 'Payment Reports'],
    ['slug' => 'retailer_dues',           'label' => 'Retailer 90 Days Dues',   'icon' => 'fa-caret-right', 'route' => '/reports/retailer-dues',      'group' => 'Payment Reports'],

    // ---- Miscellaneous
    ['slug' => 'help',            'label' => 'Help Desk',       'icon' => 'fa-caret-right', 'route' => '/help',             'group' => 'Miscellaneous'],
    ['slug' => 'feedback',        'label' => 'Feedback',        'icon' => 'fa-caret-right', 'route' => '/feedback',         'group' => 'Miscellaneous'],
    ['slug' => 'change_password', 'label' => 'Change Password', 'icon' => 'fa-caret-right', 'route' => '/account/password', 'group' => 'Miscellaneous'],
    ['slug' => 'backup_db',       'label' => 'Backup Database', 'icon' => 'fa-caret-right', 'route' => '/admin/backup',     'group' => 'Miscellaneous', 'local_only' => true],
    ['slug' => 'sync_data',       'label' => 'Sync to Server',  'icon' => 'fa-caret-right', 'route' => '/admin/sync',       'group' => 'Miscellaneous', 'local_only' => true],

    // ---- Dealer / Depo Details
    ['slug' => 'quick_order',     'label' => 'Quick Order',     'icon' => 'fa-caret-right', 'route' => '/orders/create',   'group' => 'Details'],
    ['slug' => 'order_history',   'label' => 'Order History',   'icon' => 'fa-caret-right', 'route' => '/orders',          'group' => 'Details'],
    ['slug' => 'order_status',    'label' => 'Order Status',    'icon' => 'fa-caret-right', 'route' => '/orders/status',   'group' => 'Details'],
    ['slug' => 'invoice_details', 'label' => 'Invoice Details', 'icon' => 'fa-caret-right', 'route' => '/invoices',        'group' => 'Details'],
    ['slug' => 'make_payment',    'label' => 'Make a Payment',  'icon' => 'fa-caret-right', 'route' => '/payments/create', 'group' => 'Details', 'exclude_companies' => [213]],
    ['slug' => 'payment_history', 'label' => 'Payment History', 'icon' => 'fa-caret-right', 'route' => '/payments',        'group' => 'Details', 'exclude_companies' => [213]],

    // ---- Claim (company 213 / 215 only)
    ['slug' => 'dgr_claim',         'label' => 'Direct Goods Return',  'icon' => 'fa-caret-right', 'route' => '/claims/dgr',         'group' => 'Claim', 'companies' => [213, 215]],
    ['slug' => 'sampling_claim',    'label' => 'Sampling Claim',       'icon' => 'fa-caret-right', 'route' => '/claims/sampling',    'group' => 'Claim', 'companies' => [213, 215]],
    ['slug' => 'distributor_claim', 'label' => 'Distributor Claim',    'icon' => 'fa-caret-right', 'route' => '/claims/distributor', 'group' => 'Claim', 'companies' => [213, 215]],
    ['slug' => 'apply_dgr',         'label' => 'Apply DGR Claim',      'icon' => 'fa-caret-right', 'route' => '/claims/dgr/apply',   'group' => 'Claim', 'companies' => [213, 215]],
    ['slug' => 'claim_sfa',         'label' => 'Claim through SFA',    'icon' => 'fa-caret-right', 'route' => '/claims/sfa',         'group' => 'Claim', 'companies' => [213, 215]],
    ['slug' => 'claim_central',     'label' => 'Claim Central Report', 'icon' => 'fa-caret-right', 'route' => '/claims/report',      'group' => 'Claim', 'companies' => [213, 215]],

    // ---- Tally (company 213 / 215 only)
    ['slug' => 'tally_stock',      'label' => 'Tally Stock View',   'icon' => 'fa-caret-right', 'route' => '/tally/stock',           'group' => 'Tally', 'companies' => [213, 215]],
    ['slug' => 'tally_purchase',   'label' => 'Tally Purchase',     'icon' => 'fa-caret-right', 'route' => '/tally/purchase',        'group' => 'Tally', 'companies' => [213, 215]],
    ['slug' => 'tally_sale',       'label' => 'Tally Sale',         'icon' => 'fa-caret-right', 'route' => '/tally/sale',            'group' => 'Tally', 'companies' => [213, 215]],
    ['slug' => 'tally_dgr',        'label' => 'Apply DGR Claim',    'icon' => 'fa-caret-right', 'route' => '/tally/dgr',             'group' => 'Tally', 'companies' => [213, 215]],
    ['slug' => 'dms_to_tally',     'label' => 'DMS To Tally',       'icon' => 'fa-caret-right', 'route' => '/tally/report',          'group' => 'Tally', 'companies' => [213, 215]],
    ['slug' => 'dms_purchase_rep', 'label' => 'DMS Purchase Report','icon' => 'fa-caret-right', 'route' => '/tally/purchase-report', 'group' => 'Tally', 'companies' => [213, 215]],

];