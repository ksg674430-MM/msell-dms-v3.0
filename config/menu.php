<?php
// config/menu.php
//
// Nested menu tree. Each node:
//   slug    : unique key
//   label   : display text
//   icon    : font-awesome class
//   route   : frontend route (leaves only)        OR
//   external: path after https://{company.domain_url}/public
//   children: array of sub-nodes (parents only)
//
// Optional filters on any node:
//   roles               : allow-list
//   companies           : allow-list
//   exclude_companies   : deny-list
//   local_only          : true = only when APP_ENV=local

return [

    // ============================================================
    // Home
    // ============================================================
    ['slug' => 'home', 'label' => 'Home', 'icon' => 'fa-tachometer', 'route' => '/dashboard'],

    // ============================================================
    // Master
    // ============================================================
    [
        'slug' => 'master', 'label' => 'Master', 'icon' => 'fa-desktop',
        'children' => [
            ['slug' => 'add_van',         'label' => 'Supply Vehicle',  'icon' => 'fa-caret-right', 'route' => '/master/vehicles'],
            ['slug' => 'add_threshold',   'label' => 'Stock Threshold', 'icon' => 'fa-caret-right', 'route' => '/master/threshold'],
            ['slug' => 'catalog_product', 'label' => 'Item List',       'icon' => 'fa-caret-right', 'route' => '/master/products'],
            ['slug' => 'retailer_add',    'label' => 'Retailer',        'icon' => 'fa-caret-right', 'route' => '/master/retailers'],
            // ['slug' => 'add_user',        'label' => 'Field Staff',     'icon' => 'fa-caret-right', 'route' => '/master/users'],
            // ['slug' => 'dispatch_beat',   'label' => 'Dispatch Route',  'icon' => 'fa-caret-right', 'route' => '/master/dispatch-route'],
        ],
    ],

    // ============================================================
    // Stock
    // ============================================================
    [
        'slug' => 'stock', 'label' => 'Stock', 'icon' => 'fa-database',
        'children' => [
            ['slug' => 'balance_stock', 'label' => 'Current Stock', 'icon' => 'fa-caret-right', 'route' => '/reports/balance-stock'],
            [
                'slug' => 'opening_stock_group', 'label' => 'Opening Stock', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'opening_stock',        'label' => 'Opening Stock',        'icon' => 'fa-caret-right', 'route' => '/stock/opening'],
                    ['slug' => 'import_opening_stock', 'label' => 'Import Opening Stock', 'icon' => 'fa-caret-right', 'route' => '/stock/opening/import', 'exclude_companies' => [206]],
                ],
            ],
            ['slug' => 'purchase_order', 'label' => 'Receive Stock', 'icon' => 'fa-caret-right', 'route' => '/purchase-orders'],
        ],
    ],

    // ============================================================
    // Invoice / Billing
    // ============================================================
    [
        'slug' => 'billing', 'label' => 'Invoice/Billing', 'icon' => 'fa-list-alt',
        'children' => [
            ['slug' => 'direct_challan',        'label' => 'Generate Invoice',            'icon' => 'fa-caret-right', 'route' => '/challans/create',            'roles' => ['DISTRIBUTOR', 'DEPO', 'Super Admin']],
            ['slug' => 'sale_order_details',    'label' => 'Invoice Through SFA',         'icon' => 'fa-caret-right', 'route' => '/sales-orders',               'roles' => ['DISTRIBUTOR']],
            // ['slug' => 'challan_against_order', 'label' => 'Billing Against Order',       'icon' => 'fa-caret-right', 'route' => '/challans/from-orders',       'roles' => ['DEPO']],
            // ['slug' => 'challan_against_super', 'label' => 'Billing Against Super Order', 'icon' => 'fa-caret-right', 'route' => '/challans/from-super-orders', 'roles' => ['Super Admin']],
            ['slug' => 'make_challan',          'label' => 'Invoice Details',             'icon' => 'fa-caret-right', 'route' => '/invoices'],
            ['slug' => 'return_challan',        'label' => 'Edit Invoice',                'icon' => 'fa-caret-right', 'route' => '/invoices/edit'],
            ['slug' => 'invoice_summary',       'label' => 'Invoice Summary',             'icon' => 'fa-caret-right', 'route' => '/invoices/summary'],
        ],
    ],

    // ============================================================
    // Daily Dispatch
    // ============================================================
    ['slug' => 'daily_dispatch', 'label' => 'Daily Dispatch', 'icon' => 'fa-share-square-o', 'route' => '/dispatch/daily'],

    // ============================================================
    // Other Modules
    // ============================================================
    [
        'slug' => 'other_modules', 'label' => 'Other Modules', 'icon' => 'fa-share-square-o',
        'children' => [
            ['slug' => 'old_payment',          'label' => 'Old Dues',                   'icon' => 'fa-caret-right', 'route' => '/payments/old'],
            ['slug' => 'payment_new',          'label' => 'Payment Collection',         'icon' => 'fa-caret-right', 'route' => '/payments/collect'],
            ['slug' => 'damage_retailer',      'label' => 'Retailer Damage/Replace Details', 'icon' => 'fa-caret-right', 'route' => '/damage/retailer'],
            ['slug' => 'damage_depot',         'label' => 'Depot Damage/Replace Details',    'icon' => 'fa-caret-right', 'route' => '/damage/depot'],
            ['slug' => 'damage_list_retailer', 'label' => 'Retailer Damage/Replace List',    'icon' => 'fa-caret-right', 'route' => '/damage/retailer/list'],
            ['slug' => 'damage_list_depot',    'label' => 'Depot Damage/Replace List',       'icon' => 'fa-caret-right', 'route' => '/damage/depot/list'],
        ],
    ],

    // ============================================================
    // Reports  (up to 4 levels deep)
    // ============================================================
    [
        'slug' => 'reports', 'label' => 'Reports', 'icon' => 'fa-desktop',
        'children' => [

            // ---- Sale Reports  (DISTRIBUTOR-only, wraps whole subtree)
            [
                'slug' => 'reports_sale', 'label' => 'Sale Reports', 'icon' => 'fa-caret-right',
                'roles' => ['DISTRIBUTOR'],
                'children' => [
                    [
                        'slug' => 'reports_mkt', 'label' => 'MKT Reports', 'icon' => 'fa-caret-right',
                        'children' => [
                            // ['slug' => 'dealer_sale_perf',              'label' => 'MKT Wise Sale Vs Purchase',        'icon' => 'fa-caret-right', 'route' => '/reports/sale-vs-purchase',       'exclude_companies' => [213]],
                            ['slug' => 'retailer_wise_sale_perf',       'label' => 'MKT Wise Retailer Sale',           'icon' => 'fa-caret-right', 'route' => '/reports/retailer-sale'],
                            ['slug' => 'product_wise_sale_perf',        'label' => 'Item Wise Retailer Sale',          'icon' => 'fa-caret-right', 'route' => '/reports/product-sale'],
                            // ['slug' => 'order_sale_perf',               'label' => 'MKT Wise Sale Vs Order',           'icon' => 'fa-caret-right', 'route' => '/reports/sale-vs-order',          'exclude_companies' => [213]],
                            // ['slug' => 'retailer_wise_sale_order_perf', 'label' => 'MKT Wise Retailer Sale Vs Order',  'icon' => 'fa-caret-right', 'route' => '/reports/retailer-sale-vs-order', 'exclude_companies' => [213]],
                        ],
                    ],
                    ['slug' => 'challan_report',          'label' => 'Challan Vs Sale',              'icon' => 'fa-caret-right', 'route' => '/reports/challan-vs-sale'],
                    // ['slug' => 'bill_purchase_perf',      'label' => 'Monthly Challan Vs Purchase',  'icon' => 'fa-caret-right', 'route' => '/reports/challan-vs-purchase', 'exclude_companies' => [213]],
                    ['slug' => 'tax_inv_sale_report',     'label' => 'Tax Invoice Sale Report',      'icon' => 'fa-caret-right', 'route' => '/reports/tax-invoice'],
                    ['slug' => 'sale_report',             'label' => 'DSR Salesman wise Report',     'icon' => 'fa-caret-right', 'route' => '/reports/dsr'],
                    // ['slug' => 'division_summary_report', 'label' => 'Division Wise Sale Report',    'icon' => 'fa-caret-right', 'route' => '/reports/division-sale',       'exclude_companies' => [213]],
                    ['slug' => 'retailer_sale_perf',      'label' => 'Retailers Sale Performance',   'icon' => 'fa-caret-right', 'route' => '/reports/retailer-sale-perf'],
                    ['slug' => 'division_wise',           'label' => 'Division Wise Invoice Details','icon' => 'fa-caret-right', 'route' => '/reports/division-invoice'],
                    ['slug' => 'secondary_sales_book',    'label' => 'Secondary Sales Book Details', 'icon' => 'fa-caret-right', 'route' => '/reports/secondary-sales'],
                    // ['slug' => 'item_wise_sales',         'label' => 'User Wise SKU Summary',        'icon' => 'fa-caret-right', 'route' => '/reports/user-sku',            'exclude_companies' => [213]],
                    // ['slug' => 'item_wise_challan',       'label' => 'User Challan Summary Report',  'icon' => 'fa-caret-right', 'route' => '/reports/user-challan',        'exclude_companies' => [213]],
                ],
            ],

            // ---- GST Reports
            [
                'slug' => 'reports_gst', 'label' => 'GST Reports', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'gst_return_summary', 'label' => 'GSTR1 Report',   'icon' => 'fa-caret-right', 'route' => '/reports/gstr1'],
                    ['slug' => 'purchase_register',  'label' => 'GSTR2 Report',   'icon' => 'fa-caret-right', 'route' => '/reports/gstr2'],
                    ['slug' => 'gstr_report',        'label' => 'GSTR 3B Report', 'icon' => 'fa-caret-right', 'route' => '/reports/gstr3b'],
                ],
            ],

            // ---- Stock Reports
            [
                'slug' => 'reports_stock', 'label' => 'Stock Reports', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'stock_summary',    'label' => 'Stock Summary', 'icon' => 'fa-caret-right', 'route' => '/reports/stock-summary'],
                    ['slug' => 'current_stock',    'label' => 'Current Stock', 'icon' => 'fa-caret-right', 'route' => '/reports/current-stock'],
                    // ['slug' => 'stock_details',    'label' => 'Stock Details', 'icon' => 'fa-caret-right', 'route' => '/reports/stock-details', 'exclude_companies' => [213]],
                    // ['slug' => 'stock_ledger',     'label' => 'Stock Ledger',  'icon' => 'fa-caret-right', 'route' => '/reports/stock-ledger',  'exclude_companies' => [213]],
                    ['slug' => 'low_stock_report', 'label' => 'Low Stock',     'icon' => 'fa-caret-right', 'route' => '/reports/low-stock'],
                ],
            ],

            // ---- Invoice Reports
            [
                'slug' => 'reports_invoice', 'label' => 'Invoice Reports', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'stock_ledger_inv',        'label' => 'Product Wise Invoice List',       'icon' => 'fa-caret-right', 'route' => '/reports/product-invoice'],
                    ['slug' => 'product_summary',         'label' => 'Product Wise Summary Report',     'icon' => 'fa-caret-right', 'route' => '/reports/product-summary'],
                    ['slug' => 'gst_wise_summary',        'label' => 'GST Wise Invoice List',           'icon' => 'fa-caret-right', 'route' => '/reports/gst-invoice'],
                    ['slug' => 'bill_summary',            'label' => 'Bill Wise Summary Report',        'icon' => 'fa-caret-right', 'route' => '/reports/bill-summary'],
                    ['slug' => 'retailer_bill_summary',   'label' => 'Retailer Wise Bill Summary Report','icon'=> 'fa-caret-right', 'route' => '/reports/retailer-bill'],
                    ['slug' => 'retailer_summary',        'label' => 'Retailer Wise Summary Report',    'icon' => 'fa-caret-right', 'route' => '/reports/retailer-summary'],
                    // ['slug' => 'purchase_report',         'label' => 'Purchase Report',                 'icon' => 'fa-caret-right', 'route' => '/reports/purchase',        'exclude_companies' => [213]],
                    // ['slug' => 'purchase_report_details', 'label' => 'Purchase Report Details',         'icon' => 'fa-caret-right', 'route' => '/reports/purchase-details','exclude_companies' => [213]],
                    // ['slug' => 'intransit_details',       'label' => 'In Transit Purchase Order',       'icon' => 'fa-caret-right', 'route' => '/reports/intransit',       'exclude_companies' => [213]],
                ],
            ],

            // ---- Dispatch Reports
            [
                'slug' => 'reports_dispatch', 'label' => 'Dispatch Reports', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'daily_dispatch_report', 'label' => 'Daily Dispatch Report',        'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-daily'],
                    ['slug' => 'productwise_dispatch',  'label' => 'Product Wise Dispatch Report', 'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-product'],
                    ['slug' => 'datewise_dispatch',     'label' => 'Invoice Wise Dispatch Report', 'icon' => 'fa-caret-right', 'route' => '/reports/dispatch-invoice'],
                ],
            ],

            // ---- Payment Collection
            [
                'slug' => 'reports_payment', 'label' => 'Payment Collection', 'icon' => 'fa-caret-right',
                'children' => [
                    ['slug' => 'retailer_payment_report', 'label' => 'Payment Collection Report',  'icon' => 'fa-caret-right', 'route' => '/reports/payment-collection'],
                    ['slug' => 'retailer_ledger',         'label' => 'Retailer Payment Ledger',    'icon' => 'fa-caret-right', 'route' => '/reports/retailer-ledger'],
                    ['slug' => 'retailer_dues',           'label' => 'Retailer 90 Days Dues',      'icon' => 'fa-caret-right', 'route' => '/reports/retailer-dues'],
                ],
            ],

            // ---- External reports (legacy app links)
            ['slug' => 'ss_van_sales_report',    'label' => 'SS Van Sales Report',         'icon' => 'fa-caret-right', 'route' => '/reports/ss-van-sales'],
            ['slug' => 'retailer_ledger_report', 'label' => 'Retailer Ledger Report',      'icon' => 'fa-caret-right', 'route' => '/reports/retailer-ledger-report'],
            ['slug' => 'retailer_wise_sales',    'label' => 'Retailer Wise Sales Report',   'icon' => 'fa-caret-right', 'route' => '/reports/retailer-wise-sales'],
            ['slug' => 'van_sale_report',        'label' => 'Van Sale Report',             'icon' => 'fa-caret-right', 'route' => '/reports/van-sale'],
            ['slug' => 'van_settlement_report',  'label' => 'Van Settlement Report',       'icon' => 'fa-caret-right', 'route' => '/reports/van-settlement'],
            ['slug' => 'db_sales_report',        'label' => 'DB Sales Report',             'icon' => 'fa-caret-right', 'route' => '/reports/db-sales'],
            ['slug' => 'db_van_summary',         'label' => 'DB Van Summary Report',       'icon' => 'fa-caret-right', 'route' => '/reports/db-van-summary'],
            ['slug' => 'retailer_wise_report',   'label' => 'Retailer Wise Report',        'icon' => 'fa-caret-right', 'route' => '/reports/retailer-wise'],
            // ['slug' => 'display_claim_report',   'label' => 'Display Claim Report',        'icon' => 'fa-caret-right', 'external' => '/report-section?report_name=DisplayClaimReport'],
        ],
    ],

    // ============================================================
    // Miscellaneous
    // ============================================================
    [
        'slug' => 'misc', 'label' => 'Miscellaneous', 'icon' => 'fa-server',
        'children' => [
            ['slug' => 'help',            'label' => 'Help Desk',       'icon' => 'fa-caret-right', 'route' => '/help'],
            ['slug' => 'feedback',        'label' => 'Feedback',        'icon' => 'fa-caret-right', 'route' => '/feedback'],
            ['slug' => 'change_password', 'label' => 'Change Password', 'icon' => 'fa-caret-right', 'route' => '/account/password'],
            // ['slug' => 'backup_db',       'label' => 'Backup Database', 'icon' => 'fa-caret-right', 'route' => '/admin/backup', 'local_only' => true],
            // ['slug' => 'sync_data',       'label' => 'Sync to Server',  'icon' => 'fa-caret-right', 'route' => '/admin/sync',   'local_only' => true],
        ],
    ],

    // ============================================================
    // Dealer / Depo Details  (label flips per role in controller)
    // ============================================================
    [
        'slug' => 'details', 'label' => 'Dealer Details', 'icon' => 'fa-cart-arrow-down',
        'children' => [
            ['slug' => 'quick_order',     'label' => 'Quick Order',     'icon' => 'fa-caret-right', 'route' => '/orders/create'],
            ['slug' => 'order_history',   'label' => 'Order History',   'icon' => 'fa-caret-right', 'route' => '/orders'],
            ['slug' => 'order_status',    'label' => 'Order Status',    'icon' => 'fa-caret-right', 'route' => '/orders/status'],
            ['slug' => 'invoice_details', 'label' => 'Invoice Details', 'icon' => 'fa-caret-right', 'route' => '/orders/invoice-details'],
            // ['slug' => 'make_payment',    'label' => 'Make a Payment',  'icon' => 'fa-caret-right', 'route' => '/payments/create', 'exclude_companies' => [213]],
            // ['slug' => 'payment_history', 'label' => 'Payment History', 'icon' => 'fa-caret-right', 'route' => '/payments',        'exclude_companies' => [213]],
        ],
    ],
    // [
    //     'slug' => 'dealer_dashboard', 'label' => 'Dealer Dashboard', 'icon' => 'fa-server', 'route' => '/dealer-dashboard',
    // ],

    // ============================================================
    // Claim  (companies 213, 215 only)
    // ============================================================
    [
        'slug' => 'claim', 'label' => 'Claim', 'icon' => 'fa-share-square-o',
        'companies' => [213, 215],
        'children' => [
            ['slug' => 'dgr_claim',         'label' => 'Direct Goods Return Details', 'icon' => 'fa-caret-right', 'route' => '/claims/dgr'],
            ['slug' => 'sampling_claim',    'label' => 'Sampling Claim',              'icon' => 'fa-caret-right', 'route' => '/claims/sampling'],
            ['slug' => 'distributor_claim', 'label' => 'Distributor Claim Details',   'icon' => 'fa-caret-right', 'route' => '/claims/distributor'],
            ['slug' => 'apply_dgr',         'label' => 'Apply DGR Claim',             'icon' => 'fa-caret-right', 'route' => '/claims/dgr/apply'],
            ['slug' => 'claim_sfa',         'label' => 'Claim through SFA',           'icon' => 'fa-caret-right', 'route' => '/claims/sfa'],
            ['slug' => 'claim_central',     'label' => 'Claim Central Report',        'icon' => 'fa-caret-right', 'route' => '/claims/report'],
        ],
    ],

    // ============================================================
    // Tally  (companies 213, 215 only)
    // ============================================================
    [
        'slug' => 'tally', 'label' => 'Tally', 'icon' => 'fa-share-square-o',
        'companies' => [213, 215],
        'children' => [
            ['slug' => 'tally_stock',      'label' => 'Tally Stock View',    'icon' => 'fa-caret-right', 'route' => '/tally/stock'],
            ['slug' => 'tally_purchase',   'label' => 'Tally Purchase',      'icon' => 'fa-caret-right', 'route' => '/tally/purchase'],
            ['slug' => 'tally_sale',       'label' => 'Tally Sale',          'icon' => 'fa-caret-right', 'route' => '/tally/sale'],
            ['slug' => 'tally_dgr',        'label' => 'Apply DGR Claim',     'icon' => 'fa-caret-right', 'route' => '/tally/dgr'],
            ['slug' => 'dms_to_tally',     'label' => 'DMS To Tally Report', 'icon' => 'fa-caret-right', 'route' => '/tally/report'],
            ['slug' => 'dms_purchase_rep', 'label' => 'DMS Purchase Report', 'icon' => 'fa-caret-right', 'route' => '/tally/purchase-report'],
        ],
    ],

];