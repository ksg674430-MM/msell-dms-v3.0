<?php
// config/lookups.php
//
// Master lookup registry. Every filterable / dropdown-able entity in DMS.
//
// Per entity:
//   base_sql     : SELECT <id>, <label>, <extra>... FROM ...
//                  MUST alias the display column as `label` and return an `id`.
//   count_sql    : optional precise COUNT(*) — else base_sql wrapped in subquery.
//   parent_field : column used for cascades (e.g. 'catalog_0_id'). Optional.
//   scopes       : server-enforced auth scopes. Column alias must match base_sql.
//   searchable   : columns used for free-text `search`.
//   default_sort : [[column, direction]]
//   max_limit    : clamp for `limit` (default 200)
//   fields       : whitelist of filterable/sortable columns.
//                  Each field has { label, column, type, ops, [options] }.
//   label        : human-readable entity name (for the /lookup directory)

return [

    /* =========================================================
     |  CATALOG — 3-level cascade
     | ========================================================= */
    'catalog_0' => [
        'label'        => 'Product Classification',
        'base_sql'     => "SELECT c0.id, c0.name AS label FROM catalog_0 c0",
        'count_sql'    => "SELECT COUNT(*) AS c FROM catalog_0 c0",
        'parent_field' => null,
        'scopes'       => ['company' => 'c0.company_id = ?'],
        'searchable'   => ['c0.name'],
        'default_sort' => [['c0.sequence', 'asc'], ['c0.name', 'asc']],
        'max_limit'    => 200,
        'fields' => [
            'id'   => ['label' => 'ID',   'column' => 'c0.id',   'type' => 'int',    'ops' => ['eq', 'in']],
            'name' => ['label' => 'Name', 'column' => 'c0.name', 'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
        ],
    ],

    'catalog_1' => [
        'label'        => 'Product Category',
        'base_sql'     => "SELECT c1.id, c1.name AS label FROM catalog_1 c1",
        'count_sql'    => "SELECT COUNT(*) AS c FROM catalog_1 c1",
        'parent_field' => 'catalog_0_id',
        'scopes'       => ['company' => 'c1.company_id = ?'],
        'searchable'   => ['c1.name'],
        'default_sort' => [['c1.catalog_1_sequence', 'asc'], ['c1.name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'            => ['label' => 'ID',           'column' => 'c1.id',            'type' => 'int',    'ops' => ['eq', 'in']],
            'name'          => ['label' => 'Name',         'column' => 'c1.name',          'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
            'catalog_0_id'  => ['label' => 'Classification', 'column' => 'c1.catalog_0_id', 'type' => 'int',   'ops' => ['eq', 'in']],
        ],
    ],

    'catalog_2' => [
        'label'        => 'Product Name',
        'base_sql'     => "SELECT c2.id, c2.name AS label FROM catalog_2 c2",
        'count_sql'    => "SELECT COUNT(*) AS c FROM catalog_2 c2",
        'parent_field' => 'catalog_1_id',
        'scopes'       => ['company' => 'c2.company_id = ?'],
        'searchable'   => ['c2.name'],
        'default_sort' => [['c2.sequence', 'asc'], ['c2.name', 'asc']],
        'max_limit'    => 1000,
        'fields' => [
            'id'           => ['label' => 'ID',           'column' => 'c2.id',           'type' => 'int',    'ops' => ['eq', 'in']],
            'name'         => ['label' => 'Name',         'column' => 'c2.name',         'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
            'catalog_1_id' => ['label' => 'Category',     'column' => 'c2.catalog_1_id', 'type' => 'int',    'ops' => ['eq', 'in']],
        ],
    ],

    /* =========================================================
     |  PRODUCTS — the leaf of the catalog tree
     | ========================================================= */
    'products' => [
        'label'        => 'Products',
        'base_sql'     => "SELECT cp.id,
                                  CONCAT(cp.name, ' [', cp.quantity_per_case, ']') AS label,
                                  cp.itemcode,
                                  cp.quantity_per_case,
                                  cp.hsn_code,
                                  cp.catalog_id
                             FROM catalog_product cp",
        'count_sql'    => "SELECT COUNT(*) AS c FROM catalog_product cp",
        'parent_field' => 'catalog_id',
        'scopes'       => ['company' => 'cp.company_id = ?'],
        'searchable'   => ['cp.name', 'cp.itemcode'],
        'default_sort' => [['cp.name', 'asc']],
        'max_limit'    => 2000,
        'fields' => [
            'id'                  => ['label' => 'ID',           'column' => 'cp.id',            'type' => 'int',    'ops' => ['eq', 'in']],
            'name'                => ['label' => 'Name',         'column' => 'cp.name',          'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
            'itemcode'            => ['label' => 'Item Code',    'column' => 'cp.itemcode',      'type' => 'string', 'ops' => ['eq', 'like']],
            'catalog_id'          => ['label' => 'Sub-Category', 'column' => 'cp.catalog_id',    'type' => 'int',    'ops' => ['eq', 'in']],
            'status'              => ['label' => 'Status',       'column' => 'cp.status',        'type' => 'enum',
                                     'options' => [['id'=>1,'label'=>'Active'],['id'=>0,'label'=>'Inactive']],
                                     'ops' => ['eq', 'in']],
        ],
    ],

    /* =========================================================
     |  RETAILERS
     | ========================================================= */
    'retailers' => [
        'label'        => 'Retailers',
        'base_sql'     => "SELECT r.id, r.name AS label, r.pin_no, r.tin_no, r.landline
                             FROM retailer r",
        'count_sql'    => "SELECT COUNT(*) AS c FROM retailer r",
        'parent_field' => null,
        'scopes'       => [
            'company' => 'r.company_id = ?',
            'dealer'  => 'r.dealer_id = ?',
        ],
        'searchable'   => ['r.name', 'r.tin_no', 'r.landline'],
        'default_sort' => [['r.name', 'asc']],
        'max_limit'    => 1000,
        'fields' => [
            'id'             => ['label' => 'ID',        'column' => 'r.id',              'type' => 'int',    'ops' => ['eq', 'in']],
            'name'           => ['label' => 'Name',      'column' => 'r.name',            'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
            'tin_no'         => ['label' => 'GSTIN',     'column' => 'r.tin_no',          'type' => 'string', 'ops' => ['eq', 'like']],
            'outlet_type_id' => ['label' => 'Outlet',    'column' => 'r.outlet_type_id',  'type' => 'int',    'ops' => ['eq', 'in']],
            'location_id'    => ['label' => 'Location',  'column' => 'r.location_id',     'type' => 'int',    'ops' => ['eq', 'in']],
            'status'         => ['label' => 'Status',    'column' => 'r.retailer_status', 'type' => 'enum',
                                 'options' => [['id'=>1,'label'=>'Active'],['id'=>0,'label'=>'Inactive']],
                                 'ops' => ['eq', 'in']],
        ],
    ],

    /* =========================================================
     |  DEALERS
     | ========================================================= */
    'dealers' => [
        'label'        => 'Dealers',
        'base_sql'     => "SELECT d.id, d.name AS label, d.tin_no, d.address
                             FROM dealer d",
        'count_sql'    => "SELECT COUNT(*) AS c FROM dealer d",
        'parent_field' => null,
        'scopes'       => ['company' => 'd.company_id = ?'],
        'searchable'   => ['d.name', 'd.tin_no'],
        'default_sort' => [['d.name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'     => ['label' => 'ID',    'column' => 'd.id',           'type' => 'int',    'ops' => ['eq', 'in']],
            'name'   => ['label' => 'Name',  'column' => 'd.name',         'type' => 'string', 'ops' => ['eq', 'like', 'starts_with']],
            'is_depo'=> ['label' => 'Type',  'column' => 'd.is_depo',      'type' => 'enum',
                         'options' => [['id'=>0,'label'=>'Distributor'],['id'=>1,'label'=>'Depo']],
                         'ops' => ['eq', 'in']],
            'csa_id' => ['label' => 'CSA',   'column' => 'd.csa_id',       'type' => 'int',    'ops' => ['eq', 'in']],
        ],
    ],

    /* =========================================================
     |  OUTLET TYPES
     | ========================================================= */
    'outlet_types' => [
        'label'        => 'Outlet Types',
        'base_sql'     => "SELECT ot.id, ot.outlet_type AS label FROM _retailer_outlet_type ot",
        'count_sql'    => "SELECT COUNT(*) AS c FROM _retailer_outlet_type ot",
        'parent_field' => null,
        'scopes'       => ['company' => 'ot.company_id = ?'],
        'searchable'   => ['ot.outlet_type'],
        'default_sort' => [['ot.sequence', 'asc'], ['ot.outlet_type', 'asc']],
        'max_limit'    => 100,
        'fields' => [
            'id'          => ['label' => 'ID',     'column' => 'ot.id',          'type' => 'int',    'ops' => ['eq','in']],
            'outlet_type' => ['label' => 'Name',   'column' => 'ot.outlet_type', 'type' => 'string', 'ops' => ['eq','like']],
            'status'      => ['label' => 'Status', 'column' => 'ot.status',      'type' => 'enum',
                              'options' => [['id'=>1,'label'=>'Active'],['id'=>0,'label'=>'Inactive']],
                              'ops' => ['eq','in']],
        ],
    ],

    /* =========================================================
     |  FIELD STAFF — person rows attached to a dealer
     | ========================================================= */
    'field_staff' => [
        'label'        => 'Field Staff',
        'base_sql'     => "SELECT DISTINCT dlrl.user_id AS id,
                                  CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name,
                                            '[', rl.rolename, ']') AS label,
                                  p.id AS person_id,
                                  rl.rolename
                             FROM dealer_location_rate_list dlrl
                             INNER JOIN person       p  ON p.id = dlrl.user_id
                             INNER JOIN person_login pl ON pl.person_id = dlrl.user_id
                             INNER JOIN _role        rl ON rl.role_id   = p.role_id",
        'count_sql'    => "SELECT COUNT(DISTINCT dlrl.user_id) AS c
                             FROM dealer_location_rate_list dlrl
                             INNER JOIN person       p  ON p.id = dlrl.user_id
                             INNER JOIN person_login pl ON pl.person_id = dlrl.user_id
                             INNER JOIN _role        rl ON rl.role_id   = p.role_id",
        'parent_field' => null,
        'scopes'       => ['dealer' => 'dlrl.dealer_id = ?'],
        'searchable'   => ['p.first_name', 'p.last_name'],
        'default_sort' => [['p.first_name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'         => ['label' => 'ID',       'column' => 'dlrl.user_id',   'type' => 'int',    'ops' => ['eq','in']],
            'first_name' => ['label' => 'First',    'column' => 'p.first_name',   'type' => 'string', 'ops' => ['eq','like','starts_with']],
            'last_name'  => ['label' => 'Last',     'column' => 'p.last_name',    'type' => 'string', 'ops' => ['eq','like','starts_with']],
            'role'       => ['label' => 'Role',     'column' => 'rl.rolename',    'type' => 'string', 'ops' => ['eq','in']],
        ],
    ],

    /* =========================================================
     |  STATES
     | ========================================================= */
    'states' => [
        'label'        => 'States',
        'base_sql'     => "SELECT s.id, s.name AS label, s.state_code FROM state s",
        'count_sql'    => "SELECT COUNT(*) AS c FROM state s",
        'parent_field' => null,
        'scopes'       => [],
        'searchable'   => ['s.name', 's.state_code'],
        'default_sort' => [['s.name', 'asc']],
        'max_limit'    => 100,
        'fields' => [
            'id'         => ['label' => 'ID',         'column' => 's.id',         'type' => 'int',    'ops' => ['eq','in']],
            'name'       => ['label' => 'Name',       'column' => 's.name',       'type' => 'string', 'ops' => ['eq','like']],
            'state_code' => ['label' => 'State Code', 'column' => 's.state_code', 'type' => 'string', 'ops' => ['eq','in']],
        ],
    ],

    /* =========================================================
     |  COMPANIES
     | ========================================================= */
    'companies' => [
        'label'        => 'Companies',
        'base_sql'     => "SELECT c.id, c.name AS label, c.title FROM company c",
        'count_sql'    => "SELECT COUNT(*) AS c FROM company c",
        'parent_field' => null,
        'scopes'       => [],
        'searchable'   => ['c.name', 'c.title'],
        'default_sort' => [['c.name', 'asc']],
        'max_limit'    => 100,
        'fields' => [
            'id'    => ['label' => 'ID',    'column' => 'c.id',    'type' => 'int',    'ops' => ['eq','in']],
            'name'  => ['label' => 'Name',  'column' => 'c.name',  'type' => 'string', 'ops' => ['eq','like']],
            'title' => ['label' => 'Title', 'column' => 'c.title', 'type' => 'string', 'ops' => ['eq','like']],
        ],
    ],

    /* =========================================================
     |  CSA
     | ========================================================= */
    'csa' => [
        'label'        => 'CSA',
        'base_sql'     => "SELECT c.c_id AS id, c.csa_name AS label, c.csa_code FROM csa c",
        'count_sql'    => "SELECT COUNT(*) AS c FROM csa c",
        'parent_field' => null,
        'scopes'       => ['company' => 'c.company_id = ?'],
        'searchable'   => ['c.csa_name', 'c.csa_code'],
        'default_sort' => [['c.csa_name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'       => ['label' => 'ID',   'column' => 'c.c_id',       'type' => 'int',    'ops' => ['eq','in']],
            'csa_name' => ['label' => 'Name', 'column' => 'c.csa_name',   'type' => 'string', 'ops' => ['eq','like']],
            'csa_code' => ['label' => 'Code', 'column' => 'c.csa_code',   'type' => 'string', 'ops' => ['eq','like']],
        ],
    ],

    /* =========================================================
     |  LOCATIONS — cascade through 7 levels
     | ========================================================= */
    'locations_1' => [
        'label'        => 'Country',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_1 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_1 l",
        'parent_field' => null,
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 200,
        'fields' => [
            'id'   => ['label' => 'ID',   'column' => 'l.id',   'type' => 'int',    'ops' => ['eq','in']],
            'name' => ['label' => 'Name', 'column' => 'l.name', 'type' => 'string', 'ops' => ['eq','like']],
        ],
    ],

    'locations_2' => [
        'label'        => 'Zone',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_2 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_2 l",
        'parent_field' => 'location_1_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'            => ['label' => 'ID',            'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name',          'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'location_1_id' => ['label' => 'Country',       'column' => 'l.location_1_id', 'type' => 'int',    'ops' => ['eq','in']],
        ],
    ],

    'locations_3' => [
        'label'        => 'State',
        'base_sql'     => "SELECT l.id, l.name AS label, l.state_code FROM location_3 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_3 l",
        'parent_field' => 'location_2_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name', 'l.state_code'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 500,
        'fields' => [
            'id'            => ['label' => 'ID',         'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name',       'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'state_code'    => ['label' => 'State Code', 'column' => 'l.state_code',    'type' => 'string', 'ops' => ['eq','in']],
            'location_2_id' => ['label' => 'Zone',       'column' => 'l.location_2_id', 'type' => 'int',    'ops' => ['eq','in']],
        ],
    ],

    'locations_4' => [
        'label'        => 'District',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_4 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_4 l",
        'parent_field' => 'location_3_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 1000,
        'fields' => [
            'id'            => ['label' => 'ID',       'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name',     'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'location_3_id' => ['label' => 'State',    'column' => 'l.location_3_id', 'type' => 'int',    'ops' => ['eq','in']],
        ],
    ],

    'locations_5' => [
        'label'        => 'Area',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_5 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_5 l",
        'parent_field' => 'location_4_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 2000,
        'fields' => [
            'id'            => ['label' => 'ID',    'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name',  'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'location_4_id' => ['label' => 'District', 'column' => 'l.location_4_id', 'type' => 'int', 'ops' => ['eq','in']],
        ],
    ],

    'locations_6' => [
        'label'        => 'Location',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_6 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_6 l",
        'parent_field' => 'location_5_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 5000,
        'fields' => [
            'id'            => ['label' => 'ID',   'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name', 'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'location_5_id' => ['label' => 'Area', 'column' => 'l.location_5_id', 'type' => 'int',    'ops' => ['eq','in']],
        ],
    ],

    'locations_7' => [
        'label'        => 'Beat',
        'base_sql'     => "SELECT l.id, l.name AS label FROM location_7 l",
        'count_sql'    => "SELECT COUNT(*) AS c FROM location_7 l",
        'parent_field' => 'location_6_id',
        'scopes'       => ['company' => 'l.company_id = ?'],
        'searchable'   => ['l.name'],
        'default_sort' => [['l.name', 'asc']],
        'max_limit'    => 5000,
        'fields' => [
            'id'            => ['label' => 'ID',            'column' => 'l.id',            'type' => 'int',    'ops' => ['eq','in']],
            'name'          => ['label' => 'Name',          'column' => 'l.name',          'type' => 'string', 'ops' => ['eq','like']],
            'location_6_id' => ['label' => 'Location',      'column' => 'l.location_6_id', 'type' => 'int',    'ops' => ['eq','in']],
        ],
    ],

    /* =========================================================
     |  DAMAGE TYPES
     | ========================================================= */
    'damage_types' => [
        'label'        => 'Damage Types',
        'base_sql'     => "SELECT s.id, s.name AS label FROM saleable_non_saleable s",
        'count_sql'    => "SELECT COUNT(*) AS c FROM saleable_non_saleable s",
        'parent_field' => null,
        'scopes'       => [],
        'searchable'   => ['s.name'],
        'default_sort' => [['s.id', 'asc']],
        'max_limit'    => 50,
        'fields' => [
            'id'   => ['label' => 'ID',   'column' => 's.id',   'type' => 'int',    'ops' => ['eq','in']],
            'name' => ['label' => 'Name', 'column' => 's.name', 'type' => 'string', 'ops' => ['eq','like']],
        ],
    ],

    /* =========================================================
     |  EXISTING DOCUMENTS — for filter panels on list pages
     | ========================================================= */
    'purchase_orders' => [
        'label'        => 'Purchase Orders',
        'base_sql'     => "SELECT po.auto AS id,
                                  CONCAT(po.order_id, ' / ', po.firm_name) AS label,
                                  po.order_id, po.firm_name, po.challan_no
                             FROM purchase_order po",
        'count_sql'    => "SELECT COUNT(*) AS c FROM purchase_order po",
        'parent_field' => null,
        'scopes'       => ['dealer' => 'po.dealer_id = ?'],
        'searchable'   => ['po.order_id', 'po.firm_name', 'po.challan_no'],
        'default_sort' => [['po.auto', 'desc']],
        'max_limit'    => 500,
        'fields' => [
            'id'         => ['label' => 'ID',         'column' => 'po.auto',       'type' => 'int',    'ops' => ['eq','in']],
            'order_id'   => ['label' => 'Order No.',  'column' => 'po.order_id',   'type' => 'string', 'ops' => ['eq','like']],
            'firm_name'  => ['label' => 'Firm',       'column' => 'po.firm_name',  'type' => 'string', 'ops' => ['eq','like']],
            'challan_no' => ['label' => 'Challan No.','column' => 'po.challan_no', 'type' => 'string', 'ops' => ['eq','like']],
            'order_date' => ['label' => 'Order Date', 'column' => 'po.order_date', 'type' => 'date',   'ops' => ['eq','gte','lte','between']],
        ],
    ],

    'sales_orders' => [
        'label'        => 'Sales Orders',
        'base_sql'     => "SELECT so.order_id AS id,
                                  CONCAT(so.order_id, ' / ', r.name) AS label,
                                  so.retailer_id, r.name AS retailer_name
                             FROM user_sales_order so
                             LEFT JOIN retailer r ON r.id = so.retailer_id",
        'count_sql'    => "SELECT COUNT(*) AS c FROM user_sales_order so",
        'parent_field' => null,
        'scopes'       => ['dealer' => 'so.dealer_id = ?'],
        'searchable'   => ['so.order_id', 'r.name'],
        'default_sort' => [['so.id', 'desc']],
        'max_limit'    => 500,
        'fields' => [
            'order_id'    => ['label' => 'Order No.', 'column' => 'so.order_id',   'type' => 'string', 'ops' => ['eq','like']],
            'retailer_id' => ['label' => 'Retailer',  'column' => 'so.retailer_id','type' => 'int',    'ops' => ['eq','in']],
            'date'        => ['label' => 'Date',      'column' => 'so.date',       'type' => 'date',   'ops' => ['gte','lte','between']],
        ],
    ],

];