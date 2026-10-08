<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Exceptions\InsufficientStockException;
use App\Services\InvoiceService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaleWiseInvoiceController extends Controller
{
    /* =========================================================
     |  LOAD ORDERS + ITEMS
     |  GET /api/sale-wise-invoice/orders?ids=SO001,SO002
     |  Legacy: get_dealer_sale_list($filter, '', '', true)
     | ========================================================= */
   // public function orders(Request $request): JsonResponse
// {
    // $user      = $request->user();
    // $dealerId  = (int) $user->dealer_id;
    // $companyId = (int) ($user->company_id ?? 0);

    // $idsRaw = (string) $request->input('ids', '');
    // $ids    = array_filter(array_map('trim', explode(',', $idsRaw)));
    // if (empty($ids)) {
        // return response()->json(['status' => false, 'message' => 'No order ids given.'], 422);
    // }

    // $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // $headers = DB::select(
        // "SELECT uso.*, DATE_FORMAT(uso.date, '%d/%m/%Y') AS sale_date,
                // r.name AS retailer_name
           // FROM user_sales_order uso
           // LEFT JOIN retailer r ON r.id = uso.retailer_id
          // WHERE uso.order_id IN ({$placeholders})
            // AND uso.dealer_id = ?
          // GROUP BY uso.order_id",
        // array_merge($ids, [$dealerId])
    // );

    // $out = [];
    // foreach ($headers as $h) {
        // $orderId = $h->order_id;

        // $items = DB::select(
            // "SELECT usod.id, usod.product_id, usod.quantity, usod.rate,
                    // usod.scheme_qty, usod.remaining_qty, usod.case_qty,
                    // usod.case_rate, usod.case_quantity,
                    // cp.name AS product_name, cp.hsn_code, cp.taxable,
                    // cp.quantity_per_case, cp.weight, cp.gst_percent
               // FROM user_sales_order_details usod
               // INNER JOIN catalog_product cp ON cp.id = usod.product_id
              // WHERE usod.order_id = ?
                // AND usod.status != 2
              // ORDER BY usod.product_id",
            // [$orderId]
        // );

        // $out[] = [
            // 'order_id'      => $orderId,
            // 'order_date'    => $h->date,
            // 'sale_date'     => $h->sale_date,
            // 'retailer_id'   => (int) $h->retailer_id,
            // 'retailer_name' => $h->retailer_name,
            // 'user_id'       => (int) $h->user_id,
            // 'company_id'    => (int) $h->company_id,
            // 'location_id'   => (int) $h->location_id,
            // 'remarks'       => $h->remarks,
            // 'discount'      => (float) $h->discount,
            // 'next_ch_no'    => $this->generateChNo((int) ($user->dpId ?? $user->getKey()), $dealerId, $companyId),
            // 'outstanding'   => $this->outstandingForRetailer((int) $h->retailer_id, $dealerId),

            // // ✅ FIX: $companyId added in use() clause
            // 'items'         => array_map(function ($it) use ($dealerId, $h, $user, $companyId) {

                // $mrpOptions = DB::select(
                    // "SELECT mrp,
                            // CONCAT_WS('/',
                                // CAST(mrp AS DECIMAL(10,2)),
                                // qty, batch_no,
                                // DATE_FORMAT(mfg, '%d-%b-%Y')
                            // ) AS mrp_disp
                       // FROM stock
                      // WHERE product_id = ? AND dealer_id = ? AND qty > 0
                      // ORDER BY mrp ASC, mfg ASC",
                    // [$it->product_id, $dealerId]
                // );

                // $gst        = (float) ($it->gst_percent ?? 0);
                // $sameState  = $this->isSameState((int) $h->location_id, (int) $user->state_id);
                // $cgst       = $sameState ? round($gst / 2, 2) : 0;
                // $sgst       = $sameState ? round($gst / 2, 2) : 0;
                // $igst       = $sameState ? 0 : $gst;

                // $rateInclusive = (float) $it->rate;
                // $rateFinal     = ($companyId === 213)
                    // ? $this->stripGst($rateInclusive, $gst)
                    // : $rateInclusive;

                // $avlStock = (int) DB::table('stock')
                    // ->where('product_id', $it->product_id)
                    // ->where('dealer_id', $dealerId)
                    // ->sum('qty');

                // return [
                    // 'detail_id'       => (int) $it->id,
                    // 'product_id'      => (int) $it->product_id,
                    // 'product_name'    => $it->product_name,
                    // 'hsn_code'        => $it->hsn_code,
                    // 'taxable'         => (int) $it->taxable,
                    // 'quantity'        => (float) $it->quantity,
                    // 'rate'            => $rateFinal,
                    // 'rate_inclusive'  => $rateInclusive,
                    // 'scheme_qty'      => (float) $it->scheme_qty,
                    // 'remaining_qty'   => (float) $it->remaining_qty,
                    // 'case_qty'        => (int) $it->case_qty,
                    // 'case_rate'       => (float) $it->case_rate,
                    // 'mrp_list'        => $mrpOptions,
                    // 'available_stock' => $avlStock,
                    // 'gst_percent'     => $gst,
                    // 'cgst'            => $cgst,
                    // 'sgst'            => $sgst,
                    // 'igst'            => $igst,
                    // 'is_same_state'   => $sameState,
                    // 'weight'          => (float) $it->weight,
                    // 'qty_per_case'    => (int) $it->quantity_per_case,
                // ];
            // }, $items),
        // ];
    // }

    // return response()->json(['status' => true, 'orders' => $out]);
// }
    /* =========================================================
     |  MRP OPTIONS for a product (dropdown)
     |  GET /api/sale-wise-invoice/mrp-options?product_id=X
     | ========================================================= */
    // public function mrpOptions(Request $request): JsonResponse
    // {
        // $user      = $request->user();
        // $dealerId  = (int) $user->dealer_id;
        // $productId = (int) $request->input('product_id');

        // if ($productId <= 0) {
            // return response()->json(['status' => false, 'message' => 'product_id required.'], 422);
        // }

        // $rows = DB::select(
            // "SELECT mrp, qty, batch_no, mfg,
                    // CONCAT_WS('/',
                        // CAST(mrp AS DECIMAL(10,2)),
                        // qty, batch_no,
                        // DATE_FORMAT(mfg, '%d-%b-%Y')
                    // ) AS mrp_disp
               // FROM stock
              // WHERE product_id = ? AND dealer_id = ? AND qty > 0
              // ORDER BY mrp ASC, mfg ASC",
            // [$productId, $dealerId]
        // );

        // return response()->json(['status' => true, 'mrp_list' => $rows]);
    // }

    /* =========================================================
     |  NEXT CH NO (preview)
     |  GET /api/sale-wise-invoice/next-ch-no
     | ========================================================= */
    // public function nextChNo(Request $request): JsonResponse
    // {
        // $user      = $request->user();
        // $dealerId  = (int) $user->dealer_id;
        // $companyId = (int) ($user->company_id ?? 0);
        // $dpId      = (int) ($user->dpId ?? $user->getKey() ?? 0);

        // return response()->json([
            // 'status'  => true,
            // 'ch_no'   => $this->generateChNo($dpId, $dealerId, $companyId),
        // ]);
    // }

    /* =========================================================
     |  SAVE — multi-order → multi-challan
     |  POST /api/sale-wise-invoice/save
     | ========================================================= */
// public function save(Request $request): JsonResponse
// {
    // $user          = $request->user();
    // $dpId          = (int) ($user->dpId ?? $user->getKey() ?? 0);
    // $dealerId      = (int) $user->dealer_id;
    // $companyId     = (int) ($user->company_id ?? 0);

    // $v = Validator::make($request->all(), [
        // 'orders'                      => ['required', 'array', 'min:1'],
        // 'orders.*.order_id'           => ['required', 'string'],
        // 'orders.*.ch_no'              => ['required', 'string'],
        // 'orders.*.ch_date'            => ['required', 'string'],
        // 'orders.*.retailer_id'        => ['required', 'integer'],
        // 'orders.*.items'              => ['required', 'array', 'min:1'],
        // 'orders.*.items.*.product_id' => ['required', 'integer'],
        // 'orders.*.items.*.quantity'   => ['required', 'numeric', 'min:0'],
        // 'orders.*.items.*.rate'       => ['required', 'numeric'],
    // ]);
    // if ($v->fails()) {
        // return response()->json([
            // 'status'  => false,
            // 'message' => 'Validation failed.',
            // 'errors'  => $v->errors(),
        // ], 422);
    // }

    // $orders = $request->input('orders');

    // $headerRows = [];
    // $detailRows = [];
    // $partialOrderRows  = [];
    // $partialDetailRows = [];
    // $orderResults      = [];
    // $uniqueChIds       = [];

    // foreach ($orders as $order) {
        // if ($creditBlock = app(InvoiceService::class)->creditBlock($dealerId, (string) $order['retailer_id'])) {
            // return response()->json(['status' => false, 'message' => "Order {$order['order_id']}: {$creditBlock}"], 422);
        // }
    // }

    // DB::beginTransaction();
    // try {
        // foreach ($orders as $order) {
            // $orderId       = (string) $order['order_id'];
            // $chNo          = (string) $order['ch_no'];
            // $chDate        = $this->parseDate($order['ch_date']);
            // if (!$chDate) {
                // throw new \RuntimeException("Invalid ch_date for order {$orderId}.");
            // }
            // $retailerId    = (int) $order['retailer_id'];
            // $userId        = (int) ($order['user_id'] ?? 0) ?: $dpId;
            // $remarks       = (string) ($order['remark'] ?? '');
            // $discountPer   = (float) ($order['dis'] ?? 0);
            // $discAmt1      = (float) ($order['total_amount_a'] ?? 0);
            // $discountedAmt = (float) ($order['total_disc'] ?? 0);
            // $subTotal      = (float) ($order['total'] ?? 0);

            // // challan_order.id + challan_order_details.ch_id — legacy format
            // $chId = substr($orderId, 0, 19);
            // $uniqueChIds[] = $chId;

            // // Handle deleted order items
            // if (!empty($order['deleted_order_item'])) {
                // $delIds = array_filter(array_map('intval', explode(',', (string) $order['deleted_order_item'])));
                // if (!empty($delIds)) {
                    // DB::update(
                        // "UPDATE user_sales_order_details SET status = 2
                          // WHERE id IN (" . implode(',', array_fill(0, count($delIds), '?')) . ")
                            // AND order_id = ? AND company_id = ?",
                        // array_merge($delIds, [$orderId, $companyId])
                    // );
                // }
            // }

            // $checkStatus       = 0;
            // $allFulfilled      = true;
            // $amount            = 0.0;
            // $remainingStockVal = [];
            // $productFulfillment = [];
            // $partialDetailBatch = [];

            // foreach ($order['items'] as $item) {
                // $productId  = (int) $item['product_id'];
                // $rate       = (float) $item['rate'];
                // $qty1       = (float) $item['quantity'];
                // $schqty1    = (float) ($item['scheme'] ?? 0);
                // $qty2       = $qty1 + $schqty1;
                // $freeStock  = (float) ($item['free_stock'] ?? 0);
                // $freeQty    = (float) ($item['freeqty'] ?? 0);
                // $cashAmt    = (float) ($item['cash_amt'] ?? 0);

                // $taxId = (float) ($item['vat'] ?? 0);
                // if ($taxId <= 0) $taxId = (float) ($item['cgst'] ?? 0) * 2;

                // // MRP format: "mrp/qty/batch/mfg"
                // $mrpRaw = (string) ($item['mrp'] ?? '');
                // $parts  = explode('/', $mrpRaw);
                // $mrp    = (float) ($parts[0] ?? 0);
                // $batchNo = (string) ($parts[2] ?? '');

                // $stockRow = DB::selectOne(
                    // "SELECT id, mfg, qty FROM stock
                      // WHERE product_id = ? AND dealer_id = ? AND ABS(mrp - ?) < 0.001 AND batch_no = ?
                      // ORDER BY mfg ASC LIMIT 1
                      // FOR UPDATE",
                    // [$productId, $dealerId, $mrp, $batchNo]
                // );
                // $mfgDate   = $stockRow->mfg ?? null;
                // $avalStock = max(0.0, (float) ($stockRow->qty ?? 0));

                // $cd          = (float) ($item['cd'] ?? 0);
                // $cdType      = (int)   ($item['cd_type'] ?? 1);
                // $cdAmt       = (float) ($item['cd_amt'] ?? 0);
                // $disType     = (int)   ($item['trade_disc_type'] ?? 1);
                // $disVal      = (float) ($item['trade_disc_val'] ?? 0);
                // $disAmt      = (float) ($item['trade_disc_amt'] ?? 0);
                // $splDiscVal  = (float) ($item['spl_disc_val'] ?? 0);
                // $splDiscAmt  = (float) ($item['spl_disc_amt'] ?? 0);
                // $splDiscType = (int)   ($item['spl_disc_type'] ?? 0);
                // $billOfSupply = (int)  ($item['bos'] ?? 0);

                // $taxableAmt = (float) ($item['amount'] ?? 0);
                // $taxAmt     = $taxableAmt;
                // $vatAmt     = 0.0;

                // $qty    = 0.0;
                // $schqty = 0.0;

                // // ---- Legacy stock availability logic ----
                // if ($avalStock < $qty2) {
                    // if ($avalStock > 0) {
                        // // Partial
                        // $checkStatus = 1;
                        // $qty = $avalStock;
                        // $taxableAmt1 = $qty * $rate;

                        // $cdAmt  = ($cdType === 1)  ? ($taxableAmt1 * $cd / 100)  : $cd;
                        // $disAmt = ($disType === 1) ? ($taxableAmt1 * $disVal / 100) : $disVal;

                        // $taxableAmt2 = $taxableAmt1 - $cdAmt - $disAmt;
                        // $vatAmt      = $taxableAmt2 * $taxId / 100;
                        // $taxableAmt  = $taxableAmt2 + $vatAmt;

                        // $remainingStockVal[] = $taxAmt - $taxableAmt;

                        // app(StockService::class)->take($dealerId, $productId, $batchNo, (string) $mfgDate, $mrp, $avalStock);
                    // } else {
                        // $checkStatus = 2;
                        // $remainingStockVal[] = $taxableAmt;
                    // }
                // } else {
                    // $qty = $qty2;
                    // $qtyNew = ($freeQty > 0) ? ($qty2 + $freeQty) : $qty2;
                    // $mfgDate = $mfgDate ?: date('Y-m') . '-01';
                    // app(StockService::class)->take($dealerId, $productId, $batchNo, $mfgDate, $mrp, $qtyNew);
                // }

                // $schqty = ($freeStock < $schqty1) ? $freeStock : $schqty1;

                // // Order status
                // if ($checkStatus === 0) {
                    // DB::update("UPDATE user_sales_order SET order_status = '1'
                                 // WHERE order_id = ? AND dealer_id = ? AND company_id = ?",
                        // [$orderId, $dealerId, $companyId]);
                // } elseif ($checkStatus === 1) {
                    // DB::update("UPDATE user_sales_order SET order_status = '7'
                                 // WHERE order_id = ? AND dealer_id = ? AND company_id = ?",
                        // [$orderId, $dealerId, $companyId]);
                    // $allFulfilled = false;
                // } elseif ($checkStatus === 2) {
                    // DB::update("UPDATE user_sales_order SET order_status = '0'
                                 // WHERE order_id = ? AND dealer_id = ? AND company_id = ?",
                        // [$orderId, $dealerId, $companyId]);
                    // $allFulfilled = false;
                // }

                // if ($companyId === 215) {
                    // DB::update("UPDATE user_sales_order SET remarks = ?
                                 // WHERE order_id = ? AND dealer_id = ? AND company_id = ?",
                        // [$remarks, $orderId, $dealerId, $companyId]);
                // }

                // // ---- Detail row ----
                // if ($checkStatus !== 2) {
                    // $detailRows[] = [
                        // 'ch_id'              => $chId,
                        // 'product_id'         => $productId,
                        // 'supply_status'      => $billOfSupply,
                        // 'catalog_details_id' => 0,
                        // 'batch_no'           => $batchNo,
                        // 'tax'                => $taxId,
                        // 'vat_amt'            => $vatAmt,
                        // 'qty'                => $qty,
                        // 'product_rate'       => $rate,
                        // 'free_qty'           => $freeQty,
                        // 'order_id'           => $orderId,
                        // 'user_id'            => $userId,
                        // 'mrp'                => $mrp,
                        // 'cd'                 => $cd,
                        // 'cd_type'            => $cdType,
                        // 'cd_amt'             => $cdAmt,
                        // 'dis_type'           => $disType,
                        // 'dis_amt'            => $disAmt,
                        // 'dis_percent'        => $disVal,
                        // 'taxable_amt'        => $taxableAmt,
                        // 'remain_amount'      => $taxableAmt,
                        // 'mfg_date'           => $mfgDate ?: '1970-01-01',
                        // 'cash_amt'           => $cashAmt,
                        // 'spl_disc_val'       => $splDiscVal,
                        // 'spl_disc_amt'       => $splDiscAmt,
                        // 'spl_disc_type'      => $splDiscType,
                        // 'sync_status'        => 1,
                    // ];

                    // $amount += $taxableAmt;
                // }

                // if ($companyId === 213) {
                    // $productFulfillment[] = [
                        // 'product_id'  => $productId,
                        // 'qty'         => $qty,
                        // 'free_qty'    => $freeQty,
                        // 'rate'        => $rate,
                        // 'taxable_amt' => $taxableAmt,
                        // 'mfg_date'    => $mfgDate,
                        // 'qty1'        => $qty1,
                        // 'schqty1'     => $schqty1,
                    // ];

                    // // Partial detail log — legacy builds this per item
                    // $productName = (string) DB::table('catalog_product')->where('id', $productId)->value('name');
                    // $partialDetailBatch[] = [
                        // 'product_id'                    => $productId,
                        // 'product_name'                  => $productName,
                        // 'order_id'                      => $orderId,
                        // 'product_fullfiment_qty'        => $qty,
                        // 'product_value'                 => $taxableAmt,
                        // 'product_rate'                  => (string) $rate,
                        // 'product_qty_cases'             => 0,
                        // 'product_fullfiment_cases'      => 0,
                        // 'product_fullfiment_scheme_qty' => 0,
                        // 'product_fullfiment_scheme_cases' => 0,
                        // 'product_case_rate'             => '0',
                        // 'qty_type'                      => '',
                        // 'qty_type_p'                    => '',
                        // 'company_id'                    => $companyId,
                        // 'created_at'                    => now(),
                    // ];
                // }
            // } // end items

            // // ---- Company 213 fulfillment tracking ----
            // if ($companyId === 213 && !empty($productFulfillment)) {
                // $this->upsertFulfillment(
                    // $orderId, $retailerId, $chNo, $chDate,
                    // $dealerId, $companyId, $userId, $productFulfillment
                // );

                // // Partial logs
                // $retailerName = (string) DB::table('retailer')->where('id', $retailerId)->value('name');

                // $partialOrderRows[] = [
                    // 'order_id'           => $orderId,
                    // 'retailer_id'        => (string) $retailerId,
                    // 'retailer_name'      => $retailerName,
                    // 'date'               => now()->format('Y-m-d'),
                    // 'time'               => now()->format('H:i:s'),
                    // 'order_date'         => $chDate . ' 00:00:00',
                    // 'invoice_number'     => $chNo,
                    // 'server_date'        => now(),
                    // 'company_id'         => $companyId,
                    // 'created_by'         => (string) $userId,
                    // 'user_id'            => $userId,
                    // 'image_name'         => '',
                    // 'invoice_date'       => $chDate,
                    // 'total_sale_value'   => 0,
                    // 'percent_value'      => 0,
                    // 'payment_mode_id'    => 0,
                    // 'discount_amount'    => 0,
                    // 'payment_amount'     => 0,
                    // 'cash_amount'        => 0,
                    // 'online_amount'      => 0,
                    // 'credit_amount'      => 0,
                    // 'cheque_amount'      => '0',
                    // 'outstanding_amount' => 0,
                    // 'date_of_supply'     => $chDate,
                    // 'van_order_id'       => '',
                // ];

                // foreach ($partialDetailBatch as $pd) {
                    // $partialDetailRows[] = $pd;
                // }
            // }

            // // ---- Adjust totals if partial ----
            // if (!empty($remainingStockVal)) {
                // $adjustedAmt = $subTotal - array_sum($remainingStockVal);
                // $discAmount  = $adjustedAmt * $discountPer / 100;
                // $amount1     = $adjustedAmt - $discAmount;
            // } else {
                // $amount1     = $discAmt1;
                // $discAmount  = $discountedAmt;
            // }

            // $headerRows[] = [
                // 'id'                        => $chId,
                // 'ch_no'                     => $chNo,
                // 'ch_created_by'             => $dpId,
                // 'ch_dealer_id'              => $dealerId,
                // 'ch_retailer_id'            => $retailerId,
                // 'ch_created_for_dealer_id'  => 0,
                // 'ch_user_id'                => $userId,
                // 'ch_date'                   => $chDate,
                // 'date_added'                => now(),
                // 'company_id'                => $companyId,
                // 'dispatch_status'           => 0,
                // 'sesId'                     => 0,
                // 'remark'                    => $remarks,
                // 'sync_status'               => 1,
                // 'invoice_type'              => 2,
                // 'payment_status'            => 0,
                // 'discount_per'              => $discountPer,
                // 'discount_amt'              => $discAmount,
                // 'amount'                    => $amount1,
                // 'remaining'                 => $amount1,
                // 'amount_round'              => $amount1,
            // ];

            // $orderResults[] = [
                // 'order_id' => $orderId,
                // 'ch_id'    => $chId,
                // 'ch_no'    => $chNo,
                // 'amount'   => $amount1,
            // ];
        // } // end orders

        // // ---- Insert all ----
        // if (!empty($headerRows))       DB::table('challan_order')->insert($headerRows);
        // if (!empty($detailRows))       DB::table('challan_order_details')->insert($detailRows);
        // if (!empty($partialOrderRows)) DB::table('partial_fullfillment_order_logs')->insert($partialOrderRows);
        // if (!empty($partialDetailRows))DB::table('partial_fullfillment_order_details_logs')->insert($partialDetailRows);

        // DB::commit();

        // return response()->json([
            // 'status'   => true,
            // 'message'  => count($orderResults) . ' challan(s) saved successfully.',
            // 'challans' => $orderResults,
        // ]);

    // } catch (InsufficientStockException $e) {
        // DB::rollBack();
        // return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
    // } catch (\Throwable $e) {
        // DB::rollBack();

        // if (str_contains($e->getMessage(), 'Duplicate entry')) {
            // if (preg_match("/Duplicate entry '([^']+)'/", $e->getMessage(), $m)) {
                // $dupChNo = $m[1];
                // foreach ($orders as $order) {
                    // if ($order['ch_no'] === $dupChNo) {
                        // DB::table('user_sales_order')
                            // ->where('order_id', $order['order_id'])
                            // ->where('company_id', $companyId)
                            // ->update(['order_status' => '1']);
                        // break;
                    // }
                // }
            // }
            // return response()->json([
                // 'status'  => true,
                // 'message' => 'Duplicate challan found. Order status updated. Please refresh and retry.',
            // ]);
        // }

        // return response()->json([
            // 'status'  => false,
            // 'message' => 'Save failed: ' . $e->getMessage(),
        // ], 500);
    // }
// }
    /* =========================================================
     |  OUTSTANDING — for a retailer
     |  GET /api/sale-wise-invoice/outstanding?retailer_id=X
     | ========================================================= */
    public function outstanding(Request $request): JsonResponse
    {
        $user       = $request->user();
        $dealerId   = (int) $user->dealer_id;
        $retailerId = (int) $request->input('retailer_id');

        if ($retailerId <= 0) {
            return response()->json(['status' => false, 'message' => 'retailer_id required.'], 422);
        }

        return response()->json([
            'status' => true,
            'data'   => $this->outstandingForRetailer($retailerId, $dealerId),
        ]);
    }

    /* =========================================================
     |  HELPERS
     | ========================================================= */

    private function outstandingForRetailer(int $retailerId, int $dealerId): array
    {
        $billed = DB::selectOne(
            "SELECT COALESCE(SUM(amount_round), 0) AS total
               FROM challan_order
              WHERE ch_retailer_id = ? AND ch_dealer_id = ?",
            [(string) $retailerId, $dealerId]
        );
        $paid = DB::selectOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS total
               FROM payment_collection
              WHERE retailer_id = ? AND dealer_id = ?",
            [$retailerId, $dealerId]
        );

        $billedAmt = (float) ($billed->total ?? 0);
        $paidAmt   = (float) ($paid->total   ?? 0);

        return [
            'billed'    => round($billedAmt, 2),
            'collected' => round($paidAmt, 2),
            'balance'   => app(InvoiceService::class)->outstanding($dealerId, [$retailerId])[(string) $retailerId] ?? 0.0,
            'credit_limit' => app(InvoiceService::class)->creditLimits([$retailerId])[(string) $retailerId] ?? null,
        ];
    }

    private function isSameState(int $retailerId, int $dealerStateId): bool
    {
        $row = DB::selectOne(
            "SELECT r.state_id_retailer AS rs
               FROM retailer r
              WHERE r.id = ? LIMIT 1",
            [$retailerId]
        );
        if (!$row) return false;
        $retailerState = (int) ($row->rs ?? 0);
        return $retailerState > 0 && $retailerState === $dealerStateId;
    }

    /**
     * Strip GST from an inclusive rate. Used for company 213 where
     * rate stored is GST-inclusive.
     */
    private function stripGst(float $rateInclusive, float $gstPercent): float
    {
        if ($gstPercent <= 0) return round($rateInclusive, 2);
        $beforeGst = $rateInclusive - ($rateInclusive * (100 / (100 + $gstPercent)));
        return round($rateInclusive - $beforeGst, 2);
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) return null;
        $value = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/M/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $value);
            if ($dt && $dt->format($fmt) === $value) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /**
     * Same format as ChallanController::generateChNo().
     */
    private function generateChNo(int $dpId, int $dealerId, int $companyId): string
    {
        $sessionRow = DB::selectOne("SELECT session FROM session WHERE action = '1' LIMIT 1");
        $year       = $sessionRow->session ?? (date('Y') . '-' . (date('Y') + 1));

        $expyear = explode('-', $year);
        $ystr    = substr($expyear[0] ?? '', 2) . '-' . substr($expyear[1] ?? '', 2);

        $last = DB::selectOne(
            "SELECT ch_no FROM challan_order
              WHERE ch_dealer_id = ? AND book_status = '0'
              ORDER BY auto DESC LIMIT 1
              FOR UPDATE",
            [$dealerId]
        );

        $nextNum = 1;
        if ($last && !empty($last->ch_no)) {
            $parts = explode('/', $last->ch_no);
            $tail  = end($parts);
            if (is_numeric($tail)) {
                $nextNum = ((int) $tail) + 1;
            }
        }

        if ($companyId === 213) {
            $num = str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT);
            return "{$dpId}/{$ystr}/{$num}";
        }

        $num = str_pad((string) $nextNum, 6, '0', STR_PAD_LEFT);
        $prefix = ($companyId === 331) ? 'EF' : 'RPL';
        return "{$prefix}/{$dealerId}/{$year}/{$num}";
    }

}