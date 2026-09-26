<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockImportController extends Controller
{
    /**
     * GET /api/opening-stocks/import-format
     */
    public function importFormat(Request $request)
    {
        $user            = $request->user();
        $dealerId        = (int) $user->dealer_id;
        $companyId       = (int) ($user->company_id ?? 0);
        $stateId         = (string) ($user->state_id ?? 0);
        $ownershipTypeId = (int) ($user->ownership_type_id ?? 0);

        $dealer = DB::selectOne(
            "SELECT id, dealer_code, csa_id, ownership_type_id, template_id
               FROM dealer WHERE id = ? LIMIT 1",
            [$dealerId]
        );

        if (!$dealer) {
            return response()->json(['status' => false, 'message' => 'Dealer not found'], 404);
        }

        if ($ownershipTypeId === 0) {
            $ownershipTypeId = (int) ($dealer->ownership_type_id ?? 0);
        }
        $templateId = (int) ($dealer->template_id ?? 0);

        // Piece-level only (case-quantity on hold)
        $isDepo           = ($ownershipTypeId === 245);
        $useTemplateRates = ($companyId === 213 && $templateId > 0);

        if ($isDepo) {
            $rateCol = 'ss_pcs_rate';
            $mrpCol  = 'mrp_pcs';
        } else {
            $rateCol = 'dealer_pcs_rate';
            $mrpCol  = 'mrp_pcs';
        }

        $rateTable = $useTemplateRates ? 'product_rate_list_template' : 'product_rate_list';

        $rows = DB::select(
            "SELECT cp.id,
                    cp.itemcode,
                    cp.name AS product_name,
                    prl.{$mrpCol}  AS mrp,
                    prl.{$rateCol} AS rate
               FROM catalog_product cp
               LEFT JOIN {$rateTable} prl
                      ON prl.product_id = cp.id
                     AND prl.state_id   = ?
                     AND prl.status     = 1
              WHERE cp.company_id = ?
                AND cp.status     = 1
              ORDER BY cp.name ASC",
            [$stateId, $companyId]
        );

        $csv = "S.No,Item Code,Product Name,MRP,Rate,Quantity(In PCS),Batch No,Mfg Date(dd/mm/yyyy)\n";
        $i = 1;
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $i++,
                $this->csvEscape($r->itemcode),
                $this->csvEscape($r->product_name),
                number_format((float) ($r->mrp  ?? 0), 2, '.', ''),
                number_format((float) ($r->rate ?? 0), 2, '.', ''),
                0,
                '',
                '',
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="PreviousStock.csv"',
        ]);
    }

    /**
     * POST /api/opening-stocks/import
     */
    public function openingStock(Request $request): JsonResponse
    {
        $request->validate([
            'excelFile' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        // ✅ FIX: person_id = dpId (User model ka primary key)
        $userId = (int) ($user->dpId ?? $user->getKey() ?? 0);

        // ✅ csa_id from dealer
        $csaId = (int) (DB::table('dealer')->where('id', $dealerId)->value('csa_id') ?? 0);

        $itemCodeMap = DB::table('catalog_product')
            ->where('company_id', $companyId)
            ->pluck('id', 'itemcode')
            ->toArray();

        $fh = fopen($request->file('excelFile')->getRealPath(), 'r');
        if ($fh === false) {
            return response()->json(['status' => false, 'message' => 'Unable to read uploaded file.'], 400);
        }

        $inserted      = 0;
        $errors        = [];
        $importedCodes = [];
        $rowNum        = 1;

        DB::beginTransaction();
        try {
            fgetcsv($fh, 1000, ',');

            while (($row = fgetcsv($fh, 1000, ',')) !== false) {
                $rowNum++;

                if (count($row) < 8 || (trim($row[0]) === '' && trim($row[1]) === '')) {
                    continue;
                }

                $itemCode = trim($row[1]);
                $mrp      = trim($row[3]);
                $rate     = trim($row[4]);
                $qty      = trim($row[5]);
                $batchNo  = trim($row[6]);
                $mfgRaw   = trim($row[7]);

                if (!isset($itemCodeMap[$itemCode])) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Item Code ({$itemCode}) not found."];
                    continue;
                }
                $productId = (int) $itemCodeMap[$itemCode];

                if ($mrp === '' || $rate === '' || $qty === '' || $batchNo === '' || $mfgRaw === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Missing mandatory field.'];
                    continue;
                }

                $mfgDate = $this->parseDate($mfgRaw);
                if (!$mfgDate) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Invalid mfg date: {$mfgRaw}"];
                    continue;
                }

                DB::insert(
                    "INSERT IGNORE INTO opening_stocks
                        (product_id, batch_no, rate, dealer_rate, mrp,
                         person_id, csa_id, dealer_id, qty,
                         salable_damage, nonsalable_damage, remaining,
                         mfg, expire, date, pr_rate, company_id,
                         action, sync_status, update_date_time)
                     VALUES
                        (?, ?, ?, ?, ?,
                         ?, ?, ?, ?,
                         0, 0, ?,
                         ?, ?, NOW(), ?, ?,
                         1, 1, NOW())",
                    [
                        $productId, $batchNo, (float) $rate, (float) $rate, (float) $mrp,
                        $userId, $csaId, $dealerId, (float) $qty,
                        (float) $qty,
                        $mfgDate, $mfgDate, (float) $rate, $companyId,
                    ]
                );

                $existingStock = DB::selectOne(
                    "SELECT id, qty FROM stock
                      WHERE product_id = ? AND dealer_id = ? AND batch_no = ?
                        AND mrp = ?
                        AND DATE_FORMAT(mfg, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
                      LIMIT 1",
                    [$productId, $dealerId, $batchNo, $mrp, $mfgDate]
                );

                if ($existingStock) {
                    DB::update(
                        "UPDATE stock
                            SET qty = qty + ?, dealer_rate = ?, mrp = ?,
                                nonsalable_damage = 0,
                                expire = ?, date = NOW(), update_date_time = NOW()
                          WHERE id = ?",
                        [(float) $qty, (float) $rate, (float) $mrp, $mfgDate, $existingStock->id]
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
                             ?, ?, ?, ?,
                             0, 0, ?,
                             ?, ?, NOW(), 0, ?,
                             1, 1, NOW())",
                        [
                            $productId, $batchNo, (float) $rate, (float) $rate, (float) $mrp,
                            $userId, $csaId, $dealerId, (float) $qty,
                            (float) $qty,
                            $mfgDate, $mfgDate, $companyId,
                        ]
                    );
                }

                $inserted++;
                $importedCodes[] = $itemCode;
            }

            fclose($fh);

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status'   => false,
                    'message'  => 'Import aborted. First error at row ' . $errors[0]['row'] . '.',
                    'inserted' => 0,
                    'errors'   => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status'         => true,
                'message'        => "{$inserted} row(s) imported successfully.",
                'inserted'       => $inserted,
                'imported_codes' => $importedCodes,
                'errors'         => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_resource($fh)) fclose($fh);
            return response()->json(['status' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/purchase-orders/import-receive
     */
    public function receiveStock(Request $request): JsonResponse
    {
        $request->validate([
            'excelFile' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        // ✅ FIX: person_id = dpId
        $userId = (int) ($user->dpId ?? $user->getKey() ?? 0);

        // ✅ csa_id from dealer (user.csa_id was 0)
        $csaId = (int) (DB::table('dealer')->where('id', $dealerId)->value('csa_id') ?? 0);

        // Piece-level rates
        $mrpCol  = 'mrp_pcs';
        $rateCol = 'dealer_pcs_rate';

        $rateRows = DB::select(
            "SELECT cp.id, prl.{$mrpCol} AS mrp, prl.{$rateCol} AS dealer_rate
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

        $fh = fopen($request->file('excelFile')->getRealPath(), 'r');
        if ($fh === false) {
            return response()->json(['status' => false, 'message' => 'Unable to read uploaded file.'], 400);
        }

        $inserted      = 0;
        $errors        = [];
        $importedCodes = [];
        $rowNum        = 1;
        $createdIds    = [];

        DB::beginTransaction();
        try {
            fgetcsv($fh, 1000, ',');

            while (($row = fgetcsv($fh, 1000, ',')) !== false) {
                $rowNum++;

                if (count($row) < 8 || (trim($row[0]) === '' && trim($row[2]) === '')) {
                    continue;
                }

                $invoiceDate = trim($row[1]);
                $invoiceNo   = trim($row[2]);
                $skuCode     = trim($row[3]);
                $skuId       = (int) trim($row[4]);
                $skuQty      = (float) trim($row[5]);
                $saleDate    = trim($row[6]);
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
                $finalOrderId = date('Ymd', strtotime($saleDateParsed)) . $dealerId;
                $createdIds[$finalOrderId] = true;

                $rate    = $rateMap[$skuId]['dealer_rate'] ?? 0;
                $mrp     = $rateMap[$skuId]['mrp']         ?? 0;
                $mfgDate = date('Y-m-d');
                $totalValue = $rate * $skuQty;

                $exists = DB::table('purchase_order')
                    ->where('dealer_id', $dealerId)
                    ->where('order_id', $finalOrderId)
                    ->exists();

                if ($exists) {
                    $detail = DB::selectOne(
                        "SELECT auto, quantity FROM purchase_order_details
                          WHERE product_id = ? AND order_id = ? LIMIT 1",
                        [$skuId, $finalOrderId]
                    );

                    if ($detail) {
                        $newQty   = (float) $detail->quantity + $skuQty;
                        $newTotal = $rate * $newQty;
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
                                $rate, $skuQty, $skuQty, $totalValue, $totalValue,
                            ]
                        );
                    }
                } else {
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
                            $rate, $skuQty, $skuQty, $totalValue, $totalValue,
                        ]
                    );
                }

                $inserted++;
                $importedCodes[] = $skuCode;
            }

            fclose($fh);

            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'status'   => false,
                    'message'  => 'Import aborted. First error at row ' . $errors[0]['row'] . '.',
                    'inserted' => 0,
                    'errors'   => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'status'         => true,
                'message'        => "{$inserted} row(s) imported successfully.",
                'inserted'       => $inserted,
                'imported_codes' => $importedCodes,
                'orders_created' => array_keys($createdIds),
                'errors'         => [],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            if (is_resource($fh)) fclose($fh);
            return response()->json(['status' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $n = str_replace('/', '-', $raw);

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $n, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $n, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $ts = strtotime($n);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function csvEscape($v): string
    {
        $v = (string) $v;
        return (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n"))
            ? '"' . str_replace('"', '""', $v) . '"'
            : $v;
    }
}