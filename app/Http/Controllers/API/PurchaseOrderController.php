<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    // -----------------------------------------------------------------
    // GET /api/purchase-orders
    // Filters: order_no, from_date, to_date
    // -----------------------------------------------------------------
    use App\Support\FilterBuilder;

public function query(Request $request): JsonResponse
{
    $user = $request->user();

    $fb = FilterBuilder::make('purchase_order', $request)      // ← change key
        ->hardScope('po.dealer_id = ?', [(int) $user->dealer_id]);

    if ((int) $user->company_id > 0) {
        $fb->hardScope('po.company_id = ?', [(int) $user->company_id]);
    }

    if (!$fb->isValid()) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid filter.',
            'errors'  => $fb->errors(),
        ], 422);
    }

    $result = $fb->run();

    return response()->json([
        'status' => true,
        'data'   => $result['rows'],
        'meta'   => $result['meta'],
    ]);
}
    public function index(Request $request)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $where = ["po.dealer_id = ?"];
        $bind  = [$dealerId];

        if ($request->filled('order_no')) {
            $where[] = "po.order_id = ?";
            $bind[]  = $request->input('order_no');
        }
        if ($request->filled('from_date')) {
            $where[] = "DATE_FORMAT(po.order_date, '%Y-%m-%d') >= ?";
            $bind[]  = $this->parseDate($request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $where[] = "DATE_FORMAT(po.order_date, '%Y-%m-%d') <= ?";
            $bind[]  = $this->parseDate($request->input('to_date'));
        }

        $sql = "SELECT po.auto, po.id, po.order_id, po.order_no_erp,
                       po.order_date, po.receive_date, po.sale_date,
                       po.ch_date, po.challan_no, po.firm_name,
                       po.dealer_id, po.company_id, po.created_date,
                       c.csa_name,
                       COALESCE(c.csa_name, po.firm_name) AS csa_name_display,
                       (SELECT SUM(CAST(pod.total_amount AS DECIMAL(12,2)))
                          FROM purchase_order_details pod
                         WHERE pod.order_id = po.id) AS total_purchase_amount
                  FROM purchase_order po
                  LEFT JOIN csa c ON c.c_id = po.csa_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY po.created_date DESC";

        $rows = DB::select($sql, $bind);

        foreach ($rows as $r) {
            $r->is_received = !empty($r->receive_date)
                && $r->receive_date !== '0000-00-00 00:00:00';
        }

        return response()->json(['status' => true, 'data' => $rows]);
    }

    // -----------------------------------------------------------------
    // GET /api/purchase-orders/{id}
    // {id} may be numeric (auto) OR the varchar business id.
    // -----------------------------------------------------------------
    public function show(Request $request, $id)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $header = DB::selectOne(
            "SELECT po.*, c.csa_name
               FROM purchase_order po
               LEFT JOIN csa c ON c.c_id = po.csa_id
              WHERE (po.auto = ? OR po.id = ?) AND po.dealer_id = ?
              LIMIT 1",
            [$id, $id, $dealerId]
        );

        if (!$header) {
            return response()->json(['status' => false, 'message' => 'Purchase order not found'], 404);
        }

        $items = DB::select(
            "SELECT pod.*,
                    cp.name              AS product_name,
                    cp.itemcode          AS itemcode,
                    cp.quantity_per_case AS quantity_per_case
               FROM purchase_order_details pod
               LEFT JOIN catalog_product cp ON cp.id = pod.product_id
              WHERE pod.order_id = ?
              ORDER BY pod.auto",
            [$header->id]
        );

        return response()->json([
            'status' => true,
            'data'   => [
                'header' => $header,
                'items'  => $items,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // POST /api/purchase-orders
    // -----------------------------------------------------------------
    public function store(Request $request)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $v = Validator::make($request->all(), [
            'company_id' => 'required|integer',
            'firm_name'  => 'required|string|max:200',
            'order_no'   => 'required|string|max:50',
            'order_date' => 'required|string',
            'challan_no' => 'nullable|string|max:50',
            'ch_date'    => 'nullable|string',
            'items'      => 'required|array|min:1',
            'items.*.product_id' => 'required',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'errors' => $v->errors()], 422);
        }

        $orderIdStr = strtoupper(trim($request->input('order_no')));
        $orderDate  = $this->parseDate($request->input('order_date'));
        $chDate     = $request->filled('ch_date') ? $this->parseDate($request->input('ch_date')) : null;
        $challanNo  = $request->input('challan_no') ?: '0';

        // Business varchar key on the header. If client doesn't send it, reuse order_no.
        $businessId = $request->input('id') ?: $orderIdStr;

        try {
            $newAuto = DB::transaction(function () use (
                $request, $user, $dealerId,
                $businessId, $orderIdStr, $orderDate, $chDate, $challanNo
            ) {
                DB::insert(
                    "INSERT INTO purchase_order
                        (id, order_id, dealer_id, created_date, created_person_id,
                         sale_date, date_time, company_id,
                         ch_date, challan_no, csa_id, firm_name, order_date)
                     VALUES (?,?,?,?,?, ?,?,?, ?,?,?,?,?)",
                    [
                        $businessId,
                        $orderIdStr,
                        $dealerId,
                        now()->format('Y-m-d H:i:s'),
                        $user->getKey(),
                        $orderDate,                       // sale_date (NOT NULL)
                        now()->format('Y-m-d H:i:s'),     // date_time (NOT NULL)
                        (int)$request->input('company_id'),
                        $chDate,
                        $challanNo,
                        (int)($request->input('csa_id') ?: 0),
                        $request->input('firm_name'),
                        $orderDate,
                    ]
                );

                $row = DB::selectOne("SELECT LAST_INSERT_ID() AS auto_id");
                $headerAuto = $row ? (int)$row->auto_id : 0;

                $this->insertItems($businessId, (int)$request->input('company_id'), $request->input('items'));

                return $headerAuto;
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Save failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Purchase order saved.',
            'auto'    => $newAuto,
            'id'      => $businessId,
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /api/purchase-orders/{id}
    // -----------------------------------------------------------------
    public function update(Request $request, $id)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT auto, id FROM purchase_order
              WHERE (auto = ? OR id = ?) AND dealer_id = ? LIMIT 1",
            [$id, $id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Purchase order not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'company_id' => 'required|integer',
            'firm_name'  => 'required|string|max:200',
            'order_no'   => 'required|string|max:50',
            'order_date' => 'required|string',
            'items'      => 'required|array|min:1',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'errors' => $v->errors()], 422);
        }

        $orderDate = $this->parseDate($request->input('order_date'));
        $chDate    = $request->filled('ch_date') ? $this->parseDate($request->input('ch_date')) : null;
        $challanNo = $request->input('challan_no') ?: '0';
        $headerId  = $existing->id;

        try {
            DB::transaction(function () use (
                $request, $existing, $headerId,
                $orderDate, $chDate, $challanNo
            ) {
                DB::update(
                    "UPDATE purchase_order
                        SET order_id   = ?,
                            firm_name  = ?,
                            company_id = ?,
                            csa_id     = ?,
                            order_date = ?,
                            sale_date  = ?,
                            ch_date    = ?,
                            challan_no = ?
                      WHERE auto = ?",
                    [
                        strtoupper(trim($request->input('order_no'))),
                        $request->input('firm_name'),
                        (int)$request->input('company_id'),
                        (int)($request->input('csa_id') ?: 0),
                        $orderDate,
                        $orderDate,
                        $chDate,
                        $challanNo,
                        (int)$existing->auto,
                    ]
                );

                DB::delete("DELETE FROM purchase_order_details WHERE order_id = ?", [$headerId]);
                $this->insertItems($headerId, (int)$request->input('company_id'), $request->input('items'));
            });
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Purchase order updated.',
            'auto'    => (int)$existing->auto,
            'id'      => $headerId,
        ]);
    }

    // -----------------------------------------------------------------
    // POST /api/purchase-orders/{id}/receive
    // Body: { sale_date?, ch_date?, challan_no? }
    // Sets receive_date = NOW() and optionally updates bill/challan fields.
    // -----------------------------------------------------------------
    public function receive(Request $request, $id)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $existing = DB::selectOne(
            "SELECT auto, id FROM purchase_order
              WHERE (auto = ? OR id = ?) AND dealer_id = ? LIMIT 1",
            [$id, $id, $dealerId]
        );
        if (!$existing) {
            return response()->json(['status' => false, 'message' => 'Purchase order not found'], 404);
        }

        $saleDate = $request->filled('sale_date')
            ? $this->parseDate($request->input('sale_date'))
            : now()->format('Y-m-d');
        $chDate   = $request->filled('ch_date')
            ? $this->parseDate($request->input('ch_date'))
            : $saleDate;

        DB::update(
            "UPDATE purchase_order
                SET receive_date = ?,
                    sale_date    = ?,
                    ch_date      = ?,
                    challan_no   = COALESCE(NULLIF(?, ''), challan_no)
              WHERE auto = ?",
            [
                now()->format('Y-m-d H:i:s'),
                $saleDate,
                $chDate,
                $request->input('challan_no'),
                (int)$existing->auto,
            ]
        );

        return response()->json(['status' => true, 'message' => 'Purchase order marked as received.']);
    }

    // -----------------------------------------------------------------
    // GET /api/purchase-orders/form-data
    // -----------------------------------------------------------------
    public function formData(Request $request)
    {
        $user      = $request->user();
        $companyId = (int)($user->company_id ?: 213);

        $brands = DB::select(
            "SELECT id, title AS cp_name, name AS cp_short_name
               FROM company
              WHERE id = ?
              LIMIT 1",
            [$companyId]
        );

        $products = DB::select(
            "SELECT cp.id,
                    cp.itemcode,
                    cp.name,
                    cp.quantity_per_case
               FROM catalog_product cp
               INNER JOIN catalog_2 c2 ON c2.id = cp.catalog_id
              WHERE cp.company_id = ?
                AND cp.status = '1'
              GROUP BY cp.id
              ORDER BY cp.name",
            [$companyId]
        );

        return response()->json([
            'status' => true,
            'data'   => ['brands' => $brands, 'products' => $products],
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Insert item rows. Writes to both `id` and `order_id` on the detail
     * table so any legacy join works.
     */
    private function insertItems(string $headerBusinessId, int $companyId, array $items): void
    {
        foreach ($items as $it) {
            $qty       = (int)($it['quantity'] ?? 0);
            $schemeQty = (int)($it['scheme_qty'] ?? $it['scheme_quantity'] ?? 0);
            $mrp       = (string)($it['mrp'] ?? $it['base_price'] ?? '0');
            $rate      = (string)($it['rate'] ?? $it['dealer_rate'] ?? '0');
            $prRate    = (string)($it['pr_rate'] ?? $it['rate'] ?? $it['dealer_rate'] ?? '0');

            $grossAmt  = (string)($it['gross_amt']    ?? round($qty * (float)$rate, 2));
            $tdAmt     = (string)($it['td_amount']    ?? $it['trade_price'] ?? '0');
            $cdAmt     = (string)($it['cd_amount']    ?? $it['cash_amt']    ?? '0');
            $schAmt    = (string)($it['sch_amt']      ?? $it['scheme_amt']  ?? '0');
            $splAmt    = (string)($it['spl_amt']      ?? '0');
            $cgst      = (string)($it['cgst_amount']  ?? '0');
            $sgst      = (string)($it['sgst_amount']  ?? '0');
            $igst      = (string)($it['igst_amount']  ?? '0');
            $totalAmt  = (string)($it['total_amount'] ?? $it['total_amt'] ?? '0');
            $taxable   = (string)($it['taxable_amount'] ?? '0');

            DB::insert(
                "INSERT INTO purchase_order_details
                    (id, order_id, company_id, product_id,
                     rate, quantity, scheme_qty,
                     mrp, gross_amt, taxable_amount, td_amount,
                     sch_amt, spl_amt, cd_amount, atd_amt,
                     cgst_amount, sgst_amount, igst_amount,
                     total_amount, pr_rate, cases, pcs,
                     batch_no, purchase_inv,
                     mfg_date, expiry_date)
                 VALUES (?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?, ?,?, ?,?)",
                [
                    $headerBusinessId,
                    $headerBusinessId,
                    $companyId,
                    $it['product_id'],
                    $rate,
                    $qty,
                    $schemeQty,
                    $mrp,
                    $grossAmt,
                    $taxable,
                    $tdAmt,
                    $schAmt,
                    $splAmt,
                    $cdAmt,
                    (string)($it['atd_amt'] ?? '0'),
                    $cgst,
                    $sgst,
                    $igst,
                    $totalAmt,
                    $prRate,
                    (int)($it['cases'] ?? 0),
                    (int)($it['pcs'] ?? 0),
                    $it['batch_no'] ?? null,
                    $it['purchase_inv'] ?? null,
                    $this->parseDate($it['mfg_date'] ?? null),
                    $this->parseDate($it['expiry_date'] ?? null),
                ]
            );
        }
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $value = trim((string)$value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/M/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}