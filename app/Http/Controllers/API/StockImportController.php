<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockImportController extends Controller
{
    /**
     * POST /api/opening-stocks/import
     * multipart/form-data with field name "excelFile" (CSV)
     *
     * Mirrors legacy import_os::import_opening_stock_save()
     */
    public function openingStock(Request $request): JsonResponse
    {
        $request->validate([
            'excelFile' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], // 10 MB
        ]);

        $user       = $request->user();
        $dealerId   = (int) $user->dealer_id;
        $companyId  = (int) ($user->company_id ?? 0);

        // csa_id from dealer row — mirrors legacy myrowval('dealer','csa_id',...)
        $csaId = (int) (DB::table('dealer')->where('id', $dealerId)->value('csa_id') ?? 0);

        // Preload item_code => product_id map (mirrors legacy $ch_data build)
        $itemCodeMap = DB::table('catalog_product')
            ->where('company_id', $companyId)
            ->pluck('id', 'itemcode')
            ->toArray();

        $csvPath = $request->file('excelFile')->getRealPath();
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            return response()->json(['status' => false, 'message' => 'Unable to read uploaded file.'], 400);
        }

        $inserted = 0;
        $errors   = [];
        $rowNum   = 1; // header counts as row 1, first data row is 2 (matches legacy $inum=2)

        DB::beginTransaction();
        try {
            // Skip header row
            fgetcsv($fh, 1000, ',');

            while (($row = fgetcsv($fh, 1000, ',')) !== false) {
                $rowNum++;

                // Skip empty trailing lines
                if (count($row) < 8 || (trim($row[0]) === '' && trim($row[1]) === '')) {
                    continue;
                }

                $itemCode  = trim($row[1]);
                $mrp       = trim($row[3]);
                $rate      = trim($row[4]);
                $qty       = trim($row[5]);
                $batchNo   = trim($row[6]);
                $mfgRaw    = trim($row[7]);

                // 1. Item code lookup
                if (!isset($itemCodeMap[$itemCode])) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Item Code ({$itemCode}) not found in company {$companyId}."];
                    continue;
                }
                $productId = (int) $itemCodeMap[$itemCode];

                // 2. Mandatory fields
                if ($mrp === '' || $rate === '' || $qty === '' || $batchNo === '' || $mfgRaw === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Missing mandatory field (mrp / rate / qty / batch_no / mfg_date).'];
                    continue;
                }

                // 3. Mfg date parse (dd/mm/yyyy → Y-m-d)
                $mfgDate = $this->parseDate($mfgRaw);
                if (!$mfgDate) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Invalid mfg date: {$mfgRaw}"];
                    continue;
                }

                // 4. Insert into opening_stocks
                DB::insert(
                    "INSERT IGNORE INTO opening_stocks
                        (product_id, batch_no, rate, dealer_rate, mrp,
                         person_id, csa_id, dealer_id, qty,
                         salable_damage, nonsalable_damage, remaining,
                         mfg, expire, date, pr_rate, company_id,
                         action, sync_status, update_date_time)
                     VALUES
                        (?, ?, ?, ?, ?,
                         0, ?, ?, ?,
                         0, 0, ?,
                         ?, ?, NOW(), ?, ?,
                         1, 1, NOW())",
                    [
                        $productId, $batchNo, (float) $rate, (float) $rate, (float) $mrp,
                        $csaId, $dealerId, (float) $qty,
                        (float) $qty,
                        $mfgDate, $mfgDate, (float) $rate, $companyId,
                    ]
                );

                // 5. Sync into stock table
                //    Legacy uses LIKE on month-year: mfg like 'YYYY-MM%'
                $existingStock = DB::selectOne(
                    "SELECT id, qty, mfg FROM stock
                      WHERE product_id = ?
                        AND dealer_id  = ?
                        AND batch_no   = ?
                        AND mrp        = ?
                        AND DATE_FORMAT(mfg, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                      LIMIT 1",
                    [$productId, $dealerId, $batchNo, $mrp, $mfgDate]
                );

                if ($existingStock) {
                    DB::update(
                        "UPDATE stock
                            SET qty              = qty + ?,
                                dealer_rate      = ?,
                                mrp              = ?,
                                nonsalable_damage= 0,
                                expire           = ?,
                                date             = NOW(),
                                update_date_time = NOW()
                          WHERE id = ?",
                        [
                            (float) $qty,
                            (float) $rate,
                            (float) $mrp,
                            $mfgDate,
                            $existingStock->id,
                        ]
                    );
                } else {
                    DB::insert(
                        "INSERT INTO stock
                            (product_id, batch_no, rate, dealer_rate, mrp,
                             person_id, csa_id, dealer_id, qty,
                             salable_damage, nonsalable_damage, remaining,
                             mfg, expire, date, pr_rate, company_id,
                             action, sync_status, update_date_time)
                         VALUES
                            (?, ?, ?, ?, ?,
                             0, 0, ?, ?,
                             0, 0, ?,
                             ?, ?, NOW(), 0, ?,
                             1, 1, NOW())",
                        [
                            $productId, $batchNo, (float) $rate, (float) $rate, (float) $mrp,
                            $dealerId, (float) $qty,
                            (float) $qty,
                            $mfgDate, $mfgDate, $companyId,
                        ]
                    );
                }

                $inserted++;
            }

            fclose($fh);

            // Legacy rolls back if ANY row fails — we match that behaviour
            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status'   => false,
                    'message'  => 'Import aborted. First error at row ' . $errors[0]['row'] . '.',
                    'total'    => $inserted + count($errors),
                    'inserted' => 0,
                    'errors'   => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status'   => true,
                'message'  => "{$inserted} row(s) imported successfully.",
                'total'    => $inserted,
                'inserted' => $inserted,
                'errors'   => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_resource($fh)) fclose($fh);

            return response()->json([
                'status'  => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/purchase-orders/import-receive
     * multipart/form-data with field name "excelFile" (CSV)
     *
     * Mirrors legacy import_os::import_receive_stock_save()
     */
    public function receiveStock(Request $request): JsonResponse
    {
        $request->validate([
            'excelFile' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $user         = $request->user();
        $dealerId     = (int) $user->dealer_id;
        $companyId    = (int) ($user->company_id ?? 0);
        $userId       = (int) $user->getKey();
        $csaId        = (int) ($user->csa_id ?? 0);
        $quantityType = (int) ($user->quantity_type ?? 0);

        // Preload rate list — legacy picks dealer_rate vs dealer_pcs_rate based on quantity_type
        $mrpCol  = $quantityType === 1 ? 'mrp'         : 'mrp_pcs';
        $rateCol = $quantityType === 1 ? 'dealer_rate' : 'dealer_pcs_rate';

        $rateRows = DB::select(
            "SELECT cp.id AS id, prl.{$mrpCol} AS mrp, prl.{$rateCol} AS dealer_rate
               FROM catalog_product cp
               INNER JOIN product_rate_list prl ON prl.product_id = cp.id
              WHERE cp.company_id = ?
              GROUP BY cp.id",
            [$companyId]
        );

        $rateMap = [];
        foreach ($rateRows as $r) {
            $rateMap[(int) $r->id] = [
                'mrp'         => (float) $r->mrp,
                'dealer_rate' => (float) $r->dealer_rate,
            ];
        }

        $csvPath = $request->file('excelFile')->getRealPath();
        $fh = fopen($csvPath, 'r');
        if ($fh === false) {
            return response()->json(['status' => false, 'message' => 'Unable to read uploaded file.'], 400);
        }

        $inserted = 0;
        $errors   = [];
        $rowNum   = 1;
        $createdIds = [];

        DB::beginTransaction();
        try {
            fgetcsv($fh, 1000, ',');

            while (($row = fgetcsv($fh, 1000, ',')) !== false) {
                $rowNum++;

                if (count($row) < 8 || (trim($row[0]) === '' && trim($row[2]) === '')) {
                    continue;
                }

                $invoiceDate = trim($row[1]);   // dd/mm/yyyy
                $invoiceNo   = trim($row[2]);
                $skuCode     = trim($row[3]);
                $skuId       = (int) trim($row[4]);
                $skuQty      = (float) trim($row[5]);
                $saleDate    = trim($row[6]);   // dd/mm/yyyy
                $remarks     = trim($row[7]);

                if ($skuId <= 0 || $skuQty <= 0 || $saleDate === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Missing sku_id / qty / sale_date.'];
                    continue;
                }

                $saleDateParsed = $this->parseDate($saleDate);
                if (!$saleDateParsed) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Invalid sale_date: {$saleDate}"];
                    continue;
                }

                $invoiceDateParsed = $this->parseDate($invoiceDate) ?: $saleDateParsed;

                // finalOrderId = Ymd(sale_date) + dealer_id  (matches legacy)
                $finalOrderId = date('Ymd', strtotime($saleDateParsed)) . $dealerId;
                $createdIds[$finalOrderId] = true;

                $rate = $rateMap[$skuId]['dealer_rate'] ?? 0;
                $mrp  = $rateMap[$skuId]['mrp']         ?? 0;
                $mfgDate = date('Y-m-d');
                $totalValue = $rate * $skuQty;

                // ---- Header check ----
                $exists = DB::table('purchase_order')
                    ->where('dealer_id', $dealerId)
                    ->where('order_id', $finalOrderId)
                    ->exists();

                if ($exists) {
                    // ---- Details check ----
                    $detail = DB::selectOne(
                        "SELECT auto, quantity FROM purchase_order_details
                          WHERE product_id = ? AND order_id = ?
                          LIMIT 1",
                        [$skuId, $finalOrderId]
                    );

                    if ($detail) {
                        // Accumulate quantity
                        $newQty     = (float) $detail->quantity + $skuQty;
                        $newTotal   = $rate * $newQty;
                        DB::update(
                            "UPDATE purchase_order_details
                                SET quantity = ?, total_amount = ?, gross_amt = ?
                              WHERE product_id = ? AND order_id = ?",
                            [$newQty, $newTotal, $newTotal, $skuId, $finalOrderId]
                        );
                    } else {
                        DB::insert(
                            "INSERT INTO purchase_order_details
                                (company_id, id, order_id, product_id, mrp, mfg_date,
                                 rate, pcs, quantity, scheme_qty, pr_rate, cases,
                                 total_amount, gross_amt)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)",
                            [
                                $companyId, $finalOrderId, $finalOrderId, $skuId, $mrp, $mfgDate,
                                $rate, $skuQty, $skuQty,
                                $totalValue, $totalValue,
                            ]
                        );
                    }
                } else {
                    // ---- Insert new PO header ----
                    $createdDate = $saleDateParsed . ' ' . date('H:i:s');

                    DB::insert(
                        "INSERT INTO purchase_order
                            (company_id, id, order_id, dealer_id, created_date,
                             created_person_id, sale_date, invoice_date, ch_date,
                             order_date, date_time, remarks, challan_no, depo_code, csa_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0', ?)",
                        [
                            $companyId, $finalOrderId, $finalOrderId, $dealerId, $createdDate,
                            $userId, $saleDateParsed, $invoiceDateParsed, $invoiceDateParsed,
                            $invoiceDateParsed, $createdDate, $remarks, $invoiceNo, $csaId,
                        ]
                    );

                    DB::insert(
                        "INSERT INTO purchase_order_details
                            (company_id, id, order_id, product_id, mrp, mfg_date,
                             rate, pcs, quantity, scheme_qty, pr_rate, cases,
                             total_amount, gross_amt)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)",
                        [
                            $companyId, $finalOrderId, $finalOrderId, $skuId, $mrp, $mfgDate,
                            $rate, $skuQty, $skuQty,
                            $totalValue, $totalValue,
                        ]
                    );
                }

                $inserted++;
            }

            fclose($fh);

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status'   => false,
                    'message'  => 'Import aborted. First error at row ' . $errors[0]['row'] . '.',
                    'total'    => $inserted + count($errors),
                    'inserted' => 0,
                    'errors'   => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status'         => true,
                'message'        => "{$inserted} row(s) imported successfully.",
                'total'          => $inserted,
                'inserted'       => $inserted,
                'orders_created' => array_keys($createdIds),
                'errors'         => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_resource($fh)) fclose($fh);

            return response()->json([
                'status'  => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Parse legacy "dd/mm/yyyy" or "yyyy-mm-dd" or "d-m-yyyy" → Y-m-d
     */
    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        // Normalize slashes to dashes
        $normalized = str_replace('/', '-', $raw);

        // Case: dd-mm-yyyy
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $normalized, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        // Case: yyyy-mm-dd
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $normalized, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        // Fallback to strtotime
        $ts = strtotime($normalized);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}