<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PurchaseOrderController extends Controller
{
    // -----------------------------------------------------------------
    // GET /api/purchase-orders
    // Filters: order_no, from_date, to_date
    // -----------------------------------------------------------------
//     use App\Support\FilterBuilder;

// public function query(Request $request): JsonResponse
// {
//     $user = $request->user();

//     $fb = FilterBuilder::make('purchase_order', $request)      // ← change key
//         ->hardScope('po.dealer_id = ?', [(int) $user->dealer_id]);

//     if ((int) $user->company_id > 0) {
//         $fb->hardScope('po.company_id = ?', [(int) $user->company_id]);
//     }

//     if (!$fb->isValid()) {
//         return response()->json([
//             'status'  => false,
//             'message' => 'Invalid filter.',
//             'errors'  => $fb->errors(),
//         ], 422);
//     }

//     $result = $fb->run();

//     return response()->json([
//         'status' => true,
//         'data'   => $result['rows'],
//         'meta'   => $result['meta'],
//     ]);
// }
    private const AWAITING_BILLING = "(po.receive_date IS NULL OR po.receive_date LIKE '0000%')
        AND COALESCE(po.challan_no, '') IN ('', '0')
        AND EXISTS (SELECT 1 FROM demand_order d WHERE d.order_id = po.order_id)";

    public function index(Request $request)
    {
        $user     = $request->user();
        $dealerId = $user->dealer_id;

        $where = ["po.dealer_id = ?", "NOT (" . self::AWAITING_BILLING . ")"];
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
            cp.hsn_code          AS hsn_code,
            cp.quantity_per_case AS qty_per_case,
            cp.weight            AS weight,
            cp.gst_percent       AS gst_percentage,
            (SELECT g.igst FROM _gst g WHERE g.hsn_code = cp.hsn_code LIMIT 1) AS gst_rate
       FROM purchase_order_details pod
       LEFT JOIN catalog_product cp ON cp.id = pod.product_id
      WHERE pod.order_id = ?
      ORDER BY pod.auto ASC",
    [(string) $header->id]
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
            'challan_no' => 'required|string|max:50',
            'ch_date'    => 'required|string',
            'items'      => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|numeric|gt:0',
            'items.*.batch_no'   => 'required|string|max:50',
            'items.*.mfg_date'   => 'required|string',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'errors' => $v->errors()], 422);
        }

        $orderIdStr = strtoupper(trim($request->input('order_no')));
        $duplicate = DB::selectOne(
            "SELECT auto FROM purchase_order WHERE dealer_id = ? AND (order_id = ? OR id = ?) LIMIT 1",
            [$dealerId, $orderIdStr, $orderIdStr]
        );
        if ($duplicate) {
            return response()->json(['status' => false, 'message' => "Order Number {$orderIdStr} already exists."], 422);
        }
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
                         sale_date, receive_date, date_time, company_id,
                         ch_date, challan_no, csa_id, depo_code, firm_name, order_date)
                     VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?)",
                    [
                        $businessId,
                        $orderIdStr,
                        $dealerId,
                        now()->format('Y-m-d H:i:s'),
                        $user->getKey(),
                        $orderDate,
                        now()->format('Y-m-d H:i:s'),
                        now()->format('Y-m-d H:i:s'),
                        (int)$request->input('company_id'),
                        $chDate,
                        $challanNo,
                        (int)($request->input('csa_id') ?: 0),
                        '0',
                        $request->input('firm_name'),
                        $orderDate,
                    ]
                );

                $row = DB::selectOne("SELECT LAST_INSERT_ID() AS auto_id");
                $headerAuto = $row ? (int)$row->auto_id : 0;

                $companyId = (int)$request->input('company_id');
                $this->insertItems($businessId, $companyId, $request->input('items'), now()->format('Y-m-d'));

                foreach ($request->input('items') as $it) {
                    $this->addStock(
                        (int) $dealerId, $companyId, (int)($request->input('csa_id') ?: 0), (int) $user->getKey(),
                        (int) $it['product_id'], (string) $it['batch_no'], (string) $this->parseDate($it['mfg_date']),
                        (float) ($it['mrp'] ?? 0), (float) ($it['rate'] ?? 0),
                        (float) $it['quantity'] + (float) ($it['scheme_qty'] ?? 0)
                    );
                }

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
            'message' => 'Purchase order saved and stock received.',
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
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);
        $personId  = (int) $user->getKey();

        $po = DB::selectOne(
            "SELECT auto, id, order_id, receive_date, action, cancel_order_reason_id
               FROM purchase_order
              WHERE (auto = ? OR id = ?) AND dealer_id = ?
              LIMIT 1",
            [$id, $id, $dealerId]
        );
        if (!$po) {
            return response()->json(['status' => false, 'message' => 'Purchase order not found'], 404);
        }
        if (!empty($po->receive_date) && !str_starts_with((string) $po->receive_date, '0000')) {
            return response()->json(['status' => false, 'message' => 'Already received.'], 409);
        }
        if ((int) $po->action === 0) {
            return response()->json(['status' => false, 'message' => 'Order is not active.'], 409);
        }
        if (DB::selectOne("SELECT 1 FROM purchase_order po WHERE po.auto = ? AND " . self::AWAITING_BILLING, [(int) $po->auto])) {
            return response()->json(['status' => false, 'message' => 'This order has not been billed by the super stockist yet.'], 409);
        }
        if ((int) $po->cancel_order_reason_id !== 0) {
            return response()->json(['status' => false, 'message' => 'Order is cancelled.'], 409);
        }

        $validated = $request->validate([
            'ch_date'             => 'required|date',
            'challan_no'          => 'required|string|max:50',
            'csa_id'              => 'nullable|integer',
            'items'               => 'required|array|min:1',
            'items.*.detail_auto' => 'required|integer',
            'items.*.mrp'         => 'required|numeric|min:0',
            'items.*.rate'        => 'required|numeric|min:0',
            'items.*.qty'         => 'required|numeric|min:0',
            'items.*.free_qty'    => 'nullable|numeric|min:0',
            'items.*.batch_no'    => 'required|string|max:50',
            'items.*.mfg_date'    => 'required|date',
            'items.*.expiry_date' => 'nullable|date',
        ]);

        $details = collect(DB::select(
            "SELECT * FROM purchase_order_details WHERE order_id = ?",
            [(string) $po->id]
        ))->keyBy('auto');

        foreach ($validated['items'] as $row) {
            if (!$details->has($row['detail_auto'])) {
                return response()->json(['status' => false, 'message' => 'Order line not found.'], 422);
            }
        }

        $csaId = (int) ($validated['csa_id'] ?? 0);

        DB::transaction(function () use ($validated, $po, $details, $dealerId, $companyId, $personId, $csaId) {
            $today = now()->format('Y-m-d');

            foreach ($validated['items'] as $row) {
                $d       = $details[$row['detail_auto']];
                $qty     = (float) $row['qty'];
                $freeQty = (float) ($row['free_qty'] ?? 0);
                $rate    = (float) $row['rate'];
                $mrp     = (float) $row['mrp'];
                $gross   = round($rate * $qty, 2);
                $disc    = abs((float) $d->td_amount) + abs((float) $d->cd_amount) + abs((float) $d->sch_amt)
                         + abs((float) $d->spl_amt) + abs((float) $d->atd_amt);
                $taxable = round($gross - $disc, 2);
                $total   = round($taxable + (float) $d->cgst_amount + (float) $d->sgst_amount + (float) $d->igst_amount, 2);

                DB::update(
                    "UPDATE purchase_order_details
                        SET mrp = ?, rate = ?, pr_rate = ?, quantity = ?, scheme_qty = ?,
                            gross_amt = ?, taxable_amount = ?, total_amount = ?,
                            batch_no = ?, mfg_date = ?, expiry_date = ?,
                            receive_date = ?, recv_cases = ?
                      WHERE auto = ?",
                    [
                        $mrp, $rate, $rate, $qty, $freeQty,
                        $gross, $taxable, $total,
                        $row['batch_no'], $row['mfg_date'], $row['expiry_date'] ?? null,
                        $today, (int) $qty,
                        (int) $d->auto,
                    ]
                );

                $this->addStock(
                    $dealerId, $companyId, $csaId, $personId,
                    (int) $d->product_id, (string) $row['batch_no'], (string) $row['mfg_date'],
                    $mrp, $rate, $qty + $freeQty, $row['expiry_date'] ?? null
                );
            }

            DB::update(
                "UPDATE purchase_order
                    SET receive_date = ?, ch_date = ?, challan_no = ?, csa_id = ?
                  WHERE auto = ?",
                [now()->format('Y-m-d H:i:s'), $validated['ch_date'], $validated['challan_no'], $csaId, (int) $po->auto]
            );
        });

        return response()->json(['status' => true, 'message' => 'Stock received successfully.']);
    }

    private function addStock(
        int $dealerId, int $companyId, int $csaId, int $personId,
        int $productId, string $batchNo, string $mfg, float $mrp, float $rate, float $qty, ?string $expiry = null
    ): void {
        if ($qty <= 0) {
            return;
        }
        app(StockService::class)->receive($dealerId, $productId, $batchNo, $mfg, $mrp, $qty, [
            'rate'        => $rate,
            'dealer_rate' => $rate,
            'person_id'   => $personId,
            'csa_id'      => $csaId,
            'expire'      => $expiry,
            'pr_rate'     => $rate,
            'company_id'  => $companyId,
        ]);
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

        $csas = DB::select(
            "SELECT c.c_id AS id, c.csa_name AS name
               FROM csa c
               INNER JOIN dealer d ON FIND_IN_SET(c.c_id, d.csa_id)
              WHERE d.id = ?",
            [(int) $user->dealer_id]
        );

        return response()->json([
            'status' => true,
            'data'   => ['brands' => $brands, 'products' => $products, 'csas' => $csas],
        ]);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Insert item rows. Writes to both `id` and `order_id` on the detail
     * table so any legacy join works.
     */
    private function insertItems(string $headerBusinessId, int $companyId, array $items, ?string $receiveDate = null): void
    {
        foreach ($items as $it) {
            $qty       = (int)($it['quantity'] ?? 0);
            $schemeQty = (int)($it['scheme_qty'] ?? $it['scheme_quantity'] ?? 0);
            $mrp       = (string)($it['mrp'] ?? $it['base_price'] ?? '0');
            $rate      = (string)($it['rate'] ?? $it['dealer_rate'] ?? '0');
            $prRate    = (string)($it['pr_rate'] ?? $it['rate'] ?? $it['dealer_rate'] ?? '0');

            $grossAmt  = round($qty * (float)$rate, 2);
            $tdAmt     = (float)($it['td_amount']    ?? $it['trade_price'] ?? 0);
            $cdAmt     = (float)($it['cd_amount']    ?? $it['cash_amt']    ?? 0);
            $schAmt    = (float)($it['sch_amt']      ?? $it['scheme_amt']  ?? 0);
            $splAmt    = (float)($it['spl_amt']      ?? 0);
            $atdAmt    = (float)($it['atd_amt']      ?? 0);
            $cgst      = (float)($it['cgst_amount']  ?? 0);
            $sgst      = (float)($it['sgst_amount']  ?? 0);
            $igst      = (float)($it['igst_amount']  ?? 0);
            $taxable   = round($grossAmt - $tdAmt - $cdAmt - $schAmt - $splAmt - $atdAmt, 2);
            $totalAmt  = round($taxable + $cgst + $sgst + $igst, 2);

            DB::insert(
                "INSERT INTO purchase_order_details
                    (id, order_id, company_id, product_id,
                     rate, quantity, scheme_qty,
                     mrp, gross_amt, taxable_amount, td_amount,
                     sch_amt, spl_amt, cd_amount, atd_amt,
                     cgst_amount, sgst_amount, igst_amount,
                     total_amount, pr_rate, cases, pcs,
                     batch_no, purchase_inv,
                     mfg_date, expiry_date, receive_date)
                 VALUES (?,?,?,?, ?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?, ?,?, ?,?,?)",
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
                    $atdAmt,
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
                    $receiveDate,
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