<?php
// config/filters.php
//
// Per-resource schemas for FilterBuilder.
// Contract per resource:
//   base_sql       : base SELECT without WHERE (builder appends one)
//   count_sql      : optional precise COUNT (else base_sql wrapped in subquery)
//   fields         : whitelist of filterable/sortable columns
//   searchable     : columns used by free-text `search` (OR-LIKE)
//   default_sort   : [[column, direction], ...]
//   max_per_page   : clamp for per_page

return [

    /* =========================================================
     |  RETAILER
     | ========================================================= */
    'retailer' => [
        'base_sql' => "
            SELECT r.id, r.name, r.email, r.landline, r.other_numbers,
                   r.pin_no, r.tin_no, r.outlet_type_id, r.location_id,
                   r.state_id_retailer, r.retailer_status, r.created_on,
                   r.image_name, r.avg_per_month_pur, r.address,
                   r.contact_per_name, r.lat_long, r.mncmcclatcellid,
                   l7.name AS beat_name,
                   ot.outlet_type
              FROM retailer r
              LEFT JOIN location_7 l7            ON l7.id = r.location_id
              LEFT JOIN _retailer_outlet_type ot ON ot.id = r.outlet_type_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM retailer r
              LEFT JOIN location_7 l7            ON l7.id = r.location_id
              LEFT JOIN _retailer_outlet_type ot ON ot.id = r.outlet_type_id",

        'fields' => [
            'name'           => ['column' => 'r.name',              'ops' => ['eq','neq','like','contains','starts_with','ends_with']],
            'email'          => ['column' => 'r.email',             'ops' => ['eq','neq','like']],
            'landline'       => ['column' => 'r.landline',          'ops' => ['eq','like']],
            'other_numbers'  => ['column' => 'r.other_numbers',     'ops' => ['eq','like']],
            'pin_no'         => ['column' => 'r.pin_no',            'ops' => ['eq','like']],
            'tin_no'         => ['column' => 'r.tin_no',            'ops' => ['eq','like']],
            'status'         => ['column' => 'r.retailer_status',   'ops' => ['eq','neq','in','not_in']],
            'outlet_type_id' => ['column' => 'r.outlet_type_id',    'ops' => ['eq','neq','in','not_in','is_null','not_null']],
            'location_id'    => ['column' => 'r.location_id',       'ops' => ['eq','neq','in','not_in','is_null','not_null']],
            'state_id'       => ['column' => 'r.state_id_retailer', 'ops' => ['eq','in']],
            'created_on'     => ['column' => 'r.created_on',        'ops' => ['eq','gte','lte','between']],
            'beat_name'      => ['column' => 'l7.name',             'ops' => ['eq','like']],
            'outlet_type'    => ['column' => 'ot.outlet_type',      'ops' => ['eq','like']],
        ],

        'searchable'   => ['r.name', 'r.email', 'r.landline', 'r.tin_no'],
        'default_sort' => [['r.id', 'desc']],
        'max_per_page' => 200,
    ],

    /* =========================================================
     |  PURCHASE ORDER
     | ========================================================= */
    'purchase_order' => [
        'base_sql' => "
            SELECT po.auto, po.id, po.order_id, po.order_no_erp,
                   po.dealer_id, po.company_id, po.csa_id,
                   po.order_date, po.ch_date, po.challan_no,
                   po.firm_name, po.receive_date, po.sale_date,
                   po.discount_amount, po.final_amount,
                   po.amount_basic_value_busy, po.round_off_amt_busy,
                   c.csa_name
              FROM purchase_order po
              LEFT JOIN csa c ON c.c_id = po.csa_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM purchase_order po
              LEFT JOIN csa c ON c.c_id = po.csa_id",

        'fields' => [
            'auto'        => ['column' => 'po.auto',           'ops' => ['eq','in']],
            'id'          => ['column' => 'po.id',             'ops' => ['eq','like']],
            'order_id'    => ['column' => 'po.order_id',       'ops' => ['eq','like']],
            'firm_name'   => ['column' => 'po.firm_name',      'ops' => ['eq','like','contains']],
            'challan_no'  => ['column' => 'po.challan_no',     'ops' => ['eq','like','contains']],
            'csa_id'      => ['column' => 'po.csa_id',         'ops' => ['eq','in']],
            'company_id'  => ['column' => 'po.company_id',     'ops' => ['eq','in']],
            'order_date'  => ['column' => 'po.order_date',     'ops' => ['eq','gte','lte','between']],
            'ch_date'     => ['column' => 'po.ch_date',        'ops' => ['eq','gte','lte','between']],
            'sale_date'   => ['column' => 'po.sale_date',      'ops' => ['eq','gte','lte','between']],
            'receive_date'=> ['column' => 'po.receive_date',   'ops' => ['is_null','not_null','gte','lte','between']],
            'csa_name'    => ['column' => 'c.csa_name',        'ops' => ['eq','like']],
        ],

        'searchable'   => ['po.order_id', 'po.firm_name', 'po.challan_no'],
        'default_sort' => [['po.auto', 'desc']],
        'max_per_page' => 200,
    ],

    /* =========================================================
     |  CHALLAN  (challan_order)
     | ========================================================= */
    'challan' => [
        'base_sql' => "
            SELECT co.auto, co.id, co.ch_no, co.ch_created_by,
                   co.ch_dealer_id, co.ch_retailer_id, co.ch_user_id,
                   co.ch_date, co.date_added, co.company_id,
                   co.invoice_type, co.book_status, co.dispatch_status,
                   co.payment_status, co.sesId, co.remark,
                   co.discount_per, co.discount_amt,
                   co.amount, co.remaining, co.amount_round,
                   co.isclaim, co.istarget_claim, co.sync_status,
                   r.name AS retailer_name,
                   d.name AS dealer_name
              FROM challan_order co
              LEFT JOIN retailer r ON r.id = co.ch_retailer_id
              LEFT JOIN dealer   d ON d.id = co.ch_dealer_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM challan_order co
              LEFT JOIN retailer r ON r.id = co.ch_retailer_id
              LEFT JOIN dealer   d ON d.id = co.ch_dealer_id",

        'fields' => [
            'auto'           => ['column' => 'co.auto',            'ops' => ['eq','in']],
            'id'             => ['column' => 'co.id',              'ops' => ['eq','like']],
            'ch_no'          => ['column' => 'co.ch_no',           'ops' => ['eq','like','contains','starts_with']],
            'ch_created_by'  => ['column' => 'co.ch_created_by',   'ops' => ['eq','in']],
            'retailer_id'    => ['column' => 'co.ch_retailer_id',  'ops' => ['eq','neq','in','not_in']],
            'user_id'        => ['column' => 'co.ch_user_id',      'ops' => ['eq','in']],
            'company_id'     => ['column' => 'co.company_id',      'ops' => ['eq','in']],
            'ch_date'        => ['column' => 'co.ch_date',         'ops' => ['eq','gte','lte','between']],
            'date_added'     => ['column' => 'co.date_added',      'ops' => ['eq','gte','lte','between']],
            'invoice_type'   => ['column' => 'co.invoice_type',    'ops' => ['eq','neq','in']],
            'book_status'    => ['column' => 'co.book_status',     'ops' => ['eq','neq','in']],
            'dispatch_status'=> ['column' => 'co.dispatch_status', 'ops' => ['eq','neq','in']],
            'payment_status' => ['column' => 'co.payment_status',  'ops' => ['eq','neq','in']],
            'isclaim'        => ['column' => 'co.isclaim',         'ops' => ['eq','in']],
            'istarget_claim' => ['column' => 'co.istarget_claim',  'ops' => ['eq','in']],
            'amount'         => ['column' => 'co.amount',          'ops' => ['eq','gte','lte','between']],
            'remaining'      => ['column' => 'co.remaining',       'ops' => ['eq','gt','gte','lt','lte','between']],
            'retailer_name'  => ['column' => 'r.name',             'ops' => ['eq','like']],
            'dealer_name'    => ['column' => 'd.name',             'ops' => ['eq','like']],
        ],

        'searchable'   => ['co.ch_no', 'co.id', 'co.remark', 'r.name'],
        'default_sort' => [['co.auto', 'desc']],
        'max_per_page' => 200,
    ],

    /* =========================================================
     |  OPENING STOCK  (add once you send DESCRIBE opening_stocks)
     | ========================================================= */
    // 'opening_stock' => [ ... ],

    /* =========================================================
     |  SALES ORDER  (user_sales_order)
     | ========================================================= */
    'sales_order' => [
        'base_sql' => "
            SELECT so.order_id, so.user_id, so.retailer_id, so.dealer_id,
                   so.location_id, so.company_id, so.call_status,
                   so.date, so.time, so.remarks,
                   so.total_sale_value, so.total_sale_qty, so.order_status,
                   r.name AS retailer_name
              FROM user_sales_order so
              LEFT JOIN retailer r ON r.id = so.retailer_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM user_sales_order so
              LEFT JOIN retailer r ON r.id = so.retailer_id",

        'fields' => [
            'order_id'         => ['column' => 'so.order_id',        'ops' => ['eq','like','contains']],
            'user_id'          => ['column' => 'so.user_id',         'ops' => ['eq','in']],
            'retailer_id'      => ['column' => 'so.retailer_id',     'ops' => ['eq','in']],
            'company_id'       => ['column' => 'so.company_id',      'ops' => ['eq','in']],
            'call_status'      => ['column' => 'so.call_status',     'ops' => ['eq','in']],
            'order_status'     => ['column' => 'so.order_status',    'ops' => ['eq','in','not_in']],
            'date'             => ['column' => 'so.date',            'ops' => ['eq','gte','lte','between']],
            'total_sale_value' => ['column' => 'so.total_sale_value','ops' => ['gte','lte','between']],
            'total_sale_qty'   => ['column' => 'so.total_sale_qty',  'ops' => ['gte','lte','between']],
            'retailer_name'    => ['column' => 'r.name',             'ops' => ['eq','like']],
        ],

        'searchable'   => ['so.order_id', 'so.remarks', 'r.name'],
        'default_sort' => [['so.id', 'desc']],
        'max_per_page' => 200,
    ],

    /* =========================================================
     |  INVOICE  (challan_order — separate view/target from challan)
     | ========================================================= */
    'invoice' => [
        'base_sql' => "
            SELECT co.auto, co.id, co.ch_no, co.ch_date,
                   co.ch_retailer_id, co.company_id,
                   co.discount_per, co.discount_amt,
                   co.amount, co.remaining, co.amount_round,
                   co.payment_status, co.dispatch_status, co.invoice_type,
                   r.name AS retailer_name,
                   r.tin_no AS retailer_gstin
              FROM challan_order co
              LEFT JOIN retailer r ON r.id = co.ch_retailer_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM challan_order co
              LEFT JOIN retailer r ON r.id = co.ch_retailer_id",

        'fields' => [
            'auto'          => ['column' => 'co.auto',           'ops' => ['eq','in']],
            'id'            => ['column' => 'co.id',             'ops' => ['eq','like']],
            'ch_no'         => ['column' => 'co.ch_no',          'ops' => ['eq','like','contains']],
            'retailer_id'   => ['column' => 'co.ch_retailer_id', 'ops' => ['eq','in']],
            'company_id'    => ['column' => 'co.company_id',     'ops' => ['eq','in']],
            'ch_date'       => ['column' => 'co.ch_date',        'ops' => ['eq','gte','lte','between']],
            'payment_status'=> ['column' => 'co.payment_status', 'ops' => ['eq','in']],
            'dispatch_status'=>['column' => 'co.dispatch_status','ops' => ['eq','in']],
            'invoice_type'  => ['column' => 'co.invoice_type',   'ops' => ['eq','in']],
            'amount'        => ['column' => 'co.amount',         'ops' => ['gte','lte','between']],
            'remaining'     => ['column' => 'co.remaining',      'ops' => ['gt','gte','lt','lte','between']],
            'retailer_name' => ['column' => 'r.name',            'ops' => ['eq','like']],
        ],

        'searchable'   => ['co.ch_no', 'co.id', 'r.name'],
        'default_sort' => [['co.auto', 'desc']],
        'max_per_page' => 200,
    ],
        /* =========================================================
     |  OPENING STOCK
     | ========================================================= */
    'opening_stock' => [
        'base_sql' => "
            SELECT os.id, os.product_id, os.batch_no,
                   os.rate, os.dealer_rate, os.mrp, os.pr_rate,
                   os.qty, os.remaining,
                   os.salable_damage, os.nonsalable_damage,
                   os.mfg, os.expire, os.date,
                   os.person_id, os.csa_id, os.dealer_id, os.company_id,
                   os.action, os.sync_status, os.update_date_time,
                   cp.name           AS product_name,
                   cp.itemcode,
                   cp.quantity_per_case,
                   cp.hsn_code,
                   cp.catalog_id
              FROM opening_stocks os
              LEFT JOIN catalog_product cp ON cp.id = os.product_id",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM opening_stocks os
              LEFT JOIN catalog_product cp ON cp.id = os.product_id",

        'fields' => [
            'id'            => ['column' => 'os.id',            'ops' => ['eq','in']],
            'product_id'    => ['column' => 'os.product_id',    'ops' => ['eq','in']],
            'product_name'  => ['column' => 'cp.name',          'ops' => ['eq','like','contains','starts_with']],
            'itemcode'      => ['column' => 'cp.itemcode',      'ops' => ['eq','like','starts_with']],
            'batch_no'      => ['column' => 'os.batch_no',      'ops' => ['eq','like','contains']],
            'catalog_id'    => ['column' => 'cp.catalog_id',    'ops' => ['eq','in']],
            'mfg'           => ['column' => 'os.mfg',           'ops' => ['eq','gte','lte','between','is_null','not_null']],
            'expire'        => ['column' => 'os.expire',        'ops' => ['eq','gte','lte','between','is_null','not_null']],
            'date'          => ['column' => 'os.date',          'ops' => ['eq','gte','lte','between']],
            'update_date_time' => ['column' => 'os.update_date_time', 'ops' => ['gte','lte','between']],
            'qty'           => ['column' => 'os.qty',           'ops' => ['eq','neq','gt','gte','lt','lte','between']],
            'remaining'     => ['column' => 'os.remaining',     'ops' => ['eq','neq','gt','gte','lt','lte','between']],
            'mrp'           => ['column' => 'os.mrp',           'ops' => ['eq','gte','lte','between']],
            'rate'          => ['column' => 'os.rate',          'ops' => ['eq','gte','lte','between']],
            'dealer_rate'   => ['column' => 'os.dealer_rate',   'ops' => ['eq','gte','lte','between']],
            'pr_rate'       => ['column' => 'os.pr_rate',       'ops' => ['eq','gte','lte','between']],
            'salable_damage' => ['column' => 'os.salable_damage', 'ops' => ['gte','lte','between']],
            'nonsalable_damage' => ['column' => 'os.nonsalable_damage', 'ops' => ['gte','lte','between']],
            'person_id'     => ['column' => 'os.person_id',     'ops' => ['eq','in']],
            'csa_id'        => ['column' => 'os.csa_id',        'ops' => ['eq','in']],
        ],

        'searchable'   => ['cp.name', 'cp.itemcode', 'os.batch_no'],
        'default_sort' => [['os.id', 'desc']],
        'max_per_page' => 200,
    ],

    /* =========================================================
     |  DGR CLAIM  (dms_retailer_images_amount_claim)
     |  Note: order_id / retailer_id / claim_amount are varchar.
     |        Numeric ops rely on MySQL coercion.
     | ========================================================= */
    'dgr_claim' => [
        'base_sql' => "
            SELECT c.id, c.order_id, c.company_id, c.dealer_id, c.retailer_id,
                   c.date_time, c.contract_date, c.start_date, c.display_period,
                   c.dms_product,
                   c.claim_amount, c.claim_status, c.claim_fulfill_on,
                   c.credit_note_order_id, c.credit_note_no
              FROM dms_retailer_images_amount_claim c",

        'count_sql' => "
            SELECT COUNT(*) AS c
              FROM dms_retailer_images_amount_claim c",

        'fields' => [
            'id'                => ['column' => 'c.id',                'ops' => ['eq','in']],
            'order_id'          => ['column' => 'c.order_id',          'ops' => ['eq','like','contains','starts_with']],
            'retailer_id'       => ['column' => 'c.retailer_id',       'ops' => ['eq','like','contains']],
            'company_id'        => ['column' => 'c.company_id',        'ops' => ['eq','in']],
            'dealer_id'         => ['column' => 'c.dealer_id',         'ops' => ['eq','in']],
            'claim_status'      => ['column' => 'c.claim_status',      'ops' => ['eq','neq','in','not_in'],
                                    // 0=New, 1=Processed, 2=Approved, 3=Rejected — adjust to match your enum
                                   ],
            'claim_amount'      => ['column' => 'c.claim_amount',      'ops' => ['eq','gte','lte','between']],
            'contract_date'     => ['column' => 'c.contract_date',     'ops' => ['eq','gte','lte','between','is_null','not_null']],
            'start_date'        => ['column' => 'c.start_date',        'ops' => ['eq','gte','lte','between','is_null','not_null']],
            'claim_fulfill_on'  => ['column' => 'c.claim_fulfill_on',  'ops' => ['is_null','not_null','gte','lte','between']],
            'credit_note_order_id' => ['column' => 'c.credit_note_order_id', 'ops' => ['eq','like','contains']],
            'credit_note_no'    => ['column' => 'c.credit_note_no',    'ops' => ['eq','like','contains']],
            'dms_product'       => ['column' => 'c.dms_product',       'ops' => ['eq','like']],
            'display_period'    => ['column' => 'c.display_period',    'ops' => ['eq','like']],
        ],

        'searchable'   => ['c.order_id', 'c.retailer_id', 'c.credit_note_no'],
        'default_sort' => [['c.id', 'desc']],
        'max_per_page' => 200,
    ],
    'dgr_claim_statuses' => [
    'label'        => 'DGR Claim Status',
    'base_sql'     => "SELECT 0 AS id, 'New' AS label
                       UNION ALL SELECT 1, 'Processed'
                       UNION ALL SELECT 2, 'Approved'
                       UNION ALL SELECT 3, 'Rejected'",
    'count_sql'    => "SELECT 4 AS c",
    'parent_field' => null,
    'scopes'       => [],
    'searchable'   => ['label'],
    'default_sort' => [['id', 'asc']],
    'max_limit'    => 10,
    'fields' => [
        'id'    => ['label' => 'ID',   'column' => 'id',    'type' => 'int',    'ops' => ['eq','in']],
        'label' => ['label' => 'Name', 'column' => 'label', 'type' => 'string', 'ops' => ['eq','like']],
    ],
],

'retailers_mini' => [
    'label'        => 'Retailers (id+name only)',
    'base_sql'     => "SELECT r.id, r.name AS label FROM retailer r",
    'count_sql'    => "SELECT COUNT(*) AS c FROM retailer r",
    'parent_field' => null,
    'scopes'       => [
        'company' => 'r.company_id = ?',
        'dealer'  => 'r.dealer_id = ?',
    ],
    'searchable'   => ['r.name'],
    'default_sort' => [['r.name', 'asc']],
    'max_limit'    => 2000,
    'fields' => [
        'id'   => ['label' => 'ID',   'column' => 'r.id',   'type' => 'int',    'ops' => ['eq','in']],
        'name' => ['label' => 'Name', 'column' => 'r.name', 'type' => 'string', 'ops' => ['eq','like']],
    ],
],

];