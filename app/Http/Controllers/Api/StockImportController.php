<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class StockImportController extends Controller
{
    // /**
     // * GET /api/opening-stocks/import-format
     // */
    // public function importFormat(Request $request)
    // {
        // $user            = $request->user();
        // $dealerId        = (int) $user->dealer_id;
        // $companyId       = (int) ($user->company_id ?? 0);
        // $stateId         = (string) ($user->state_id ?? 0);
        // $ownershipTypeId = (int) ($user->ownership_type_id ?? 0);

        // $dealer = DB::selectOne(
            // "SELECT id, dealer_code, csa_id, ownership_type_id, template_id
               // FROM dealer WHERE id = ? LIMIT 1",
            // [$dealerId]
        // );

        // if (!$dealer) {
            // return response()->json(['status' => false, 'message' => 'Dealer not found'], 404);
        // }

        // if ($ownershipTypeId === 0) {
            // $ownershipTypeId = (int) ($dealer->ownership_type_id ?? 0);
        // }
        // $templateId = (int) ($dealer->template_id ?? 0);

        // // Piece-level only (case-quantity on hold)
        // $isDepo           = ($ownershipTypeId === 245);
        // $useTemplateRates = ($companyId === 213 && $templateId > 0);

        // if ($isDepo) {
            // $rateCol = 'ss_pcs_rate';
            // $mrpCol  = 'mrp_pcs';
        // } else {
            // $rateCol = 'dealer_pcs_rate';
            // $mrpCol  = 'mrp_pcs';
        // }

        // $rateTable = $useTemplateRates ? 'product_rate_list_template' : 'product_rate_list';

        // $rows = DB::select(
            // "SELECT cp.id,
                    // cp.itemcode,
                    // cp.name AS product_name,
                    // prl.{$mrpCol}  AS mrp,
                    // prl.{$rateCol} AS rate
               // FROM catalog_product cp
               // LEFT JOIN {$rateTable} prl
                      // ON prl.product_id = cp.id
                     // AND prl.state_id   = ?
                     // AND prl.status     = 1
              // WHERE cp.company_id = ?
                // AND cp.status     = 1
              // ORDER BY cp.name ASC",
            // [$stateId, $companyId]
        // );

        // $csv = "S.No,Item Code,Product Name,MRP,Rate,Quantity(In PCS),Batch No,Mfg Date(dd/mm/yyyy)\n";
        // $i = 1;
        // foreach ($rows as $r) {
            // $csv .= implode(',', [
                // $i++,
                // $this->csvEscape($r->itemcode),
                // $this->csvEscape($r->product_name),
                // number_format((float) ($r->mrp  ?? 0), 2, '.', ''),
                // number_format((float) ($r->rate ?? 0), 2, '.', ''),
                // 0,
                // '',
                // '',
            // ]) . "\n";
        // }

        // return response($csv, 200, [
            // 'Content-Type'        => 'text/csv',
            // 'Content-Disposition' => 'attachment; filename="PreviousStock.csv"',
        // ]);
    // }

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
            fgetcsv($fh, 0, ',');

            while (($row = fgetcsv($fh, 0, ',')) !== false) {
                $rowNum++;

                $row = array_pad(array_map(fn ($v) => trim((string) $v), $row), 8, '');
                $num = fn ($v) => str_replace(',', '', $v);

                $itemCode = $row[1];
                $mrp      = $num($row[3]);
                $rate     = $num($row[4]);
                $qty      = $num($row[5]);
                $batchNo  = $row[6];
                $mfgRaw   = $row[7];

                if ($itemCode === '' || $qty === '' || (is_numeric($qty) && (float) $qty == 0)) {
                    continue;
                }

                if (!isset($itemCodeMap[$itemCode])) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Item Code ({$itemCode}) not found."];
                    continue;
                }
                $productId = (int) $itemCodeMap[$itemCode];

                if (!is_numeric($qty) || (float) $qty < 0) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Invalid quantity: {$qty}"];
                    continue;
                }
                if (!is_numeric($mrp) || !is_numeric($rate)) {
                    $errors[] = ['row' => $rowNum, 'reason' => 'MRP and Rate must be numbers.'];
                    continue;
                }
                if ($batchNo === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Batch No is required (use BD123 if you have none).'];
                    continue;
                }
                if ($mfgRaw === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Mfg Date is required (dd/mm/yyyy).'];
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

                app(StockService::class)->receive(
                    $dealerId, (int) $productId, (string) $batchNo, (string) $mfgDate, (float) $mrp, (float) $qty,
                    [
                        'rate'        => (float) $rate,
                        'dealer_rate' => (float) $rate,
                        'person_id'   => $userId,
                        'csa_id'      => $csaId,
                        'expire'      => $mfgDate,
                        'company_id'  => $companyId,
                    ],
                    ['dealer_rate' => (float) $rate, 'expire' => $mfgDate]
                );

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

            if ($inserted === 0) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No rows to import. Fill Quantity for the products you want to add.',
                    'errors'  => [],
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
            return response()->json(['status' => false, 'message' => 'Import failed: ' . safe_error_message($e)], 500);
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

        $pcs     = (string) $user->quantity_type !== '1';
        $mrpCol  = $pcs ? 'mrp_pcs' : 'mrp';
        $rateCol = $pcs ? 'dealer_pcs_rate' : 'dealer_rate';

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
            fgetcsv($fh, 0, ',');

            while (($row = fgetcsv($fh, 0, ',')) !== false) {
                $rowNum++;

                $row = array_pad(array_map(fn ($v) => trim((string) $v), $row), 8, '');

                $invoiceDate = $row[1];
                $invoiceNo   = $row[2];
                $skuCode     = $row[3];
                $skuId       = (int) $row[4];
                $qtyRaw      = str_replace(',', '', $row[5]);
                $saleDate    = $row[6];
                $remarks     = $row[7];

                if ($qtyRaw === '' || (is_numeric($qtyRaw) && (float) $qtyRaw == 0)) {
                    continue;
                }
                $skuQty = (float) $qtyRaw;

                if (!is_numeric($qtyRaw) || $skuQty < 0) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Invalid Qty: {$qtyRaw}"];
                    continue;
                }
                if ($skuId <= 0 || !isset($rateMap[$skuId])) {
                    $errors[] = ['row' => $rowNum, 'reason' => "SKU Id ({$row[4]}) not found or has no rate."];
                    continue;
                }
                if ($saleDate === '') {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Sale Date is required (YYYY-mm-dd).'];
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

                $existingPo = DB::table('purchase_order')
                    ->where('dealer_id', $dealerId)
                    ->where('order_id', $finalOrderId)
                    ->first();

                if ($existingPo && !empty($existingPo->receive_date)) {
                    $errors[] = ['row' => $rowNum, 'reason' => "Order {$finalOrderId} for {$saleDateParsed} is already received."];
                    continue;
                }

                if ($existingPo) {
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

            if ($inserted === 0) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No rows to import. Fill Qty for the products you want to add.',
                    'errors'  => [],
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
            return response()->json(['status' => false, 'message' => 'Import failed: ' . safe_error_message($e)], 500);
        }
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $n = str_replace('/', '-', $raw);

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $n, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $n, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
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