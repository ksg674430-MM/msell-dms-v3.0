<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IrnController extends Controller
{
    // /**
     // * POST /api/invoices/{challan_id}/irn
     // * Generate e-invoice IRN for a challan.
     // */
    // public function generate(Request $request, string $challanId): JsonResponse
    // {
        // $user      = $request->user();
        // $companyId = (int) ($user->company_id ?? 213);

        // $challan = DB::selectOne("SELECT * FROM challan_order WHERE id = ? LIMIT 1", [$challanId]);
        // if (!$challan) {
            // return response()->json(['status' => false, 'message' => 'Challan not found.'], 404);
        // }

        // // Build IRN JSON payload
        // $payload = $this->buildPayload($challan, $companyId);
        // if (isset($payload['error'])) {
            // return response()->json(['status' => false, 'message' => $payload['error']], 422);
        // }

        // // Get auth token
        // $tokenResult = $this->fetchToken();
        // if (isset($tokenResult['error'])) {
            // $this->logIrn($companyId, $challanId, 0, json_encode($payload), '', null, null, null, null);
            // return response()->json(['status' => false, 'message' => $tokenResult['error']], 502);
        // }

        // // Generate IRN
        // $irnResult = $this->callIrnApi($tokenResult['token'], json_encode($payload));
        // $success   = !isset($irnResult['error']);

        // $this->logIrn(
            // $companyId,
            // $challanId,
            // $success ? 1 : 0,
            // json_encode($payload),
            // $irnResult['response_json'] ?? '',
            // $irnResult['irn']      ?? null,
            // $irnResult['ack_no']   ?? null,
            // $irnResult['ack_date'] ?? null,
            // $irnResult['qr_text']  ?? null
        // );

        // if (!$success) {
            // return response()->json(['status' => false, 'message' => $irnResult['error']], 502);
        // }

        // return response()->json([
            // 'status'   => true,
            // 'message'  => 'IRN generated successfully.',
            // 'irn'      => $irnResult['irn'],
            // 'ack_no'   => $irnResult['ack_no'],
            // 'ack_date' => $irnResult['ack_date'],
        // ]);
    // }

    /* ================= helpers ================= */

 private function buildPayload(object $challan, int $companyId): array
{
    $dealerId   = (int) $challan->ch_dealer_id;
    $retailerId = $challan->ch_retailer_id;

    // ---------- 1. Load parties ----------
    $dealer   = DB::selectOne("SELECT * FROM dealer   WHERE id = ? LIMIT 1", [$dealerId]);
    $retailer = DB::selectOne("SELECT * FROM retailer WHERE id = ? LIMIT 1", [$retailerId]);

    if (!$dealer) {
        return ['error' => "Dealer not found: {$dealerId}"];
    }

    // ---------- 2. Seller GSTIN guard (fail fast, no GSP bounce) ----------
    $sellerGstin = strtoupper(trim((string) ($dealer->tin_no ?? '')));

    if (strlen($sellerGstin) !== 15) {
        return ['error' => sprintf(
            "Seller GSTIN invalid for dealer_id=%s: '%s' (len=%d). Fix dealer.tin_no.",
            $dealerId, $sellerGstin, strlen($sellerGstin)
        )];
    }
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $sellerGstin)) {
        return ['error' => "Seller GSTIN format invalid: '{$sellerGstin}'"];
    }

    // ---------- 3. Seller location (state + city) ----------
    $dealerLoc = DB::selectOne(
        "SELECT l3.state_code, l6.name AS city_name
           FROM dealer_location_rate_list dlrl
           JOIN location_7 l7 ON l7.id = dlrl.location_id
           JOIN location_6 l6 ON l6.id = l7.location_6_id
           JOIN location_5 l5 ON l5.id = l6.location_5_id
           JOIN location_4 l4 ON l4.id = l5.location_4_id
           JOIN location_3 l3 ON l3.id = l4.location_3_id
          WHERE dlrl.dealer_id = ? AND dlrl.company_id = ?
          LIMIT 1",
        [$dealerId, $companyId]
    );

    $sellerStateCode = !empty($dealerLoc->state_code)
        ? str_pad((string) $dealerLoc->state_code, 2, '0', STR_PAD_LEFT)
        : substr($sellerGstin, 0, 2);       // fall back to GSTIN prefix

    $sellerCity = $dealerLoc->city_name ?? ($dealer->city ?? 'NA');

    // ---------- 4. Buyer GSTIN / URP / B2C handling ----------
    $buyerGstinRaw = strtoupper(trim((string) ($retailer->tin_no ?? '')));

    $buyerUnregistered = $buyerGstinRaw === ''
        || $buyerGstinRaw === '0'
        || $buyerGstinRaw === 'URP'
        || strlen($buyerGstinRaw) !== 15;

    $buyerGstin = $buyerUnregistered ? 'URP' : $buyerGstinRaw;
    $supTyp     = $buyerUnregistered ? 'B2C' : 'B2B';

    // ---------- 5. Buyer location (state + city), POS fallback ----------
    $retailerLoc = DB::selectOne(
        "SELECT l3.state_code, l6.name AS city_name
           FROM dealer_location_rate_list dlrl
           JOIN retailer r ON r.location_id = dlrl.location_id
           JOIN location_7 l7 ON l7.id = dlrl.location_id
           JOIN location_6 l6 ON l6.id = l7.location_6_id
           JOIN location_5 l5 ON l5.id = l6.location_5_id
           JOIN location_4 l4 ON l4.id = l5.location_4_id
           JOIN location_3 l3 ON l3.id = l4.location_3_id
          WHERE r.id = ? AND dlrl.company_id = ?
          LIMIT 1",
        [$retailerId, $companyId]
    );

    // Buyer POS — registered: use their GSTIN prefix, else location, else seller state
    if (!$buyerUnregistered && ctype_digit(substr($buyerGstin, 0, 2))) {
        $buyerStateCode = substr($buyerGstin, 0, 2);
    } elseif (!empty($retailerLoc->state_code)) {
        $buyerStateCode = str_pad((string) $retailerLoc->state_code, 2, '0', STR_PAD_LEFT);
    } else {
        $buyerStateCode = $sellerStateCode;   // B2C fallback → intra-state
    }

    $buyerCity = $retailerLoc->city_name ?? ($retailer->city ?? $sellerCity);

    // ---------- 6. Invoice header ----------
    $invoiceNo   = (string) $challan->ch_no;
    $invoiceDate = date('d/m/Y', strtotime((string) $challan->ch_date));

    // ---------- 7. Load line items ----------
    $items = DB::select(
        "SELECT cod.*, cp.name AS product_name, cp.hsn_code
           FROM challan_order_details cod
           JOIN catalog_product cp ON cp.id = cod.product_id
          WHERE cod.ch_id = ?",
        [$challan->id]
    );

    if (empty($items)) {
        return ['error' => "No line items for challan {$challan->id}"];
    }

    // ---------- 8. Build seller / buyer blocks ----------
    $seller = [
        'Gstin' => $sellerGstin,
        'LglNm' => (string) $dealer->name,
        'TrdNm' => (string) $dealer->name,
        'Addr1' => (string) ($dealer->address ?? 'NA'),
        'Addr2' => null,
        'Loc'   => (string) $sellerCity,
        'Pin'   => (int) ($dealer->pin_no ?? 0) ?: 110001,
        'Stcd'  => $sellerStateCode,
        'Ph'    => (string) ($dealer->landline ?? $dealer->other_numbers ?? ''),
        'Em'    => (string) ($dealer->email ?? ''),
    ];

    $buyer = [
        'Gstin' => $buyerGstin,                    // 'URP' when unregistered
        'LglNm' => (string) ($retailer->name ?? 'Cash Sale'),
        'TrdNm' => (string) ($retailer->name ?? 'Cash Sale'),
        'Pos'   => $buyerStateCode,
        'Addr1' => (string) ($retailer->address ?? 'NA'),
        'Addr2' => null,
        'Loc'   => (string) $buyerCity,
        'Pin'   => (int) ($retailer->pin_no ?? 0) ?: $seller['Pin'],
        'Stcd'  => $buyerStateCode,
        'Ph'    => (string) ($retailer->landline ?? $retailer->other_numbers ?? ''),
        'Em'    => (string) ($retailer->email ?? ''),
    ];

    // ---------- 9. Line-item loop ----------
    $sameState = ($sellerStateCode === $buyerStateCode);

    $assVal = $igstVal = $cgstVal = $sgstVal = 0.0;
    $itemList = [];

    foreach ($items as $idx => $it) {
        $qty      = (float) $it->qty;
        if ($qty <= 0) continue;

        $rate     = round((float) $it->product_rate, 2);
        $freeQty  = (float) $it->free_qty;
        $preDisc  = round($qty * $rate, 2);

        $disc = (float) ($it->cd_amt ?? 0)
              + (float) ($it->spl_disc_val ?? 0)
              + (float) ($it->cash_amt ?? 0)
              + (float) ($it->dis_amt ?? 0);
        $disc = round($disc, 2);

        $taxable = round($preDisc - $disc, 2);
        $gstRate = (float) ($it->tax ?? 18);
        $gstAmt  = round((float) ($it->vat_amt ?? 0), 2);

        if ($sameState) {
            $cgst = round($gstAmt / 2, 2);
            $sgst = round($gstAmt / 2, 2);
            $igst = 0.0;
        } else {
            $cgst = 0.0;
            $sgst = 0.0;
            $igst = $gstAmt;
        }

        // HSN guard — GSP rejects empty or non-digit
        $hsn = trim((string) ($it->hsn_code ?? ''));
        if ($hsn === '' || !preg_match('/^[0-9]{4,8}$/', $hsn)) {
            return ['error' => "Invalid HSN '{$hsn}' for product_id={$it->product_id}. Must be 4-8 digits."];
        }

        $lineTotal = round($taxable + $gstAmt, 2);

        $itemList[] = [
            'SlNo'               => (string) ($idx + 1),
            'PrdDesc'            => (string) $it->product_name,
            'IsServc'            => 'N',
            'HsnCd'              => $hsn,
            'Qty'                => $qty,
            'FreeQty'            => $freeQty,
            'Unit'               => 'PCS',
            'UnitPrice'          => $rate,
            'TotAmt'             => $preDisc,
            'Discount'           => $disc,
            'PreTaxVal'          => 0,
            'AssAmt'             => $taxable,
            'GstRt'              => $gstRate,
            'IgstAmt'            => $igst,
            'CgstAmt'            => $cgst,
            'SgstAmt'            => $sgst,
            'CesRt'              => 0,
            'CesAmt'             => 0,
            'CesNonAdvlAmt'      => 0,
            'StateCesRt'         => 0,
            'StateCesAmt'        => 0,
            'StateCesNonAdvlAmt' => 0,
            'OthChrg'            => 0,
            'TotItemVal'         => $lineTotal,
        ];

        $assVal  += $taxable;
        $igstVal += $igst;
        $cgstVal += $cgst;
        $sgstVal += $sgst;
    }

    if (empty($itemList)) {
        return ['error' => "All line items have qty<=0 for challan {$challan->id}"];
    }

    // ---------- 10. Round-off / totals ----------
    $discount    = (float) ($challan->discount_amt ?? 0);
    $amountFlat  = (float) ($challan->amount ?? 0);
    $amountRound = (float) ($challan->amount_round ?? 0);

    if ($amountRound == 0 || abs($amountRound - $amountFlat) > 100) {
        $amountRound = round($amountFlat);
    }

    $rndOff = round($amountRound - $amountFlat, 2);
    $rndOff = max(-99.99, min(99.99, $rndOff));

    // ---------- 11. Assemble ----------
    return [
        'Version'   => '1.01',
        'TranDtls'  => [
            'TaxSch'      => 'GST',
            'SupTyp'      => $supTyp,       // 'B2C' for unregistered buyer
            'IgstOnIntra' => 'N',
            'RegRev'      => null,
            'EcmGstin'    => null,
        ],
        'DocDtls'   => [
            'Typ' => 'INV',
            'No'  => $invoiceNo,
            'Dt'  => $invoiceDate,
        ],
        'SellerDtls' => $seller,
        'BuyerDtls'  => $buyer,
        'ItemList'   => $itemList,
        'ValDtls'    => [
            'AssVal'    => round($assVal, 2),
            'IgstVal'   => round($igstVal, 2),
            'CgstVal'   => round($cgstVal, 2),
            'SgstVal'   => round($sgstVal, 2),
            'CesVal'    => 0,
            'StCesVal'  => 0,
            'Discount'  => round($discount, 2),
            'OthChrg'   => 0,
            'RndOffAmt' => round($rndOff, 2),
            'TotInvVal' => round($amountRound, 2),
        ],
    ];
}
    private function fetchToken(): array
    {
        $cfg = config('services.irn');
        $body = [
            'MVApiKey'         => $cfg['mv_api_key'],
            'MVSecretKey'      => $cfg['mv_secret'],
            'gstin'            => $cfg['test_gstin'],
            'eInvoiceUserName' => $cfg['test_user'],
            'eInvoicePassword' => $cfg['test_pass'],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $cfg['url_auth'],
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => ['Content-type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $arr = json_decode($response);
        if (empty($arr) || empty($arr->Status)) {
            return ['error' => 'Token API error: ' . ($arr->ErrorMessage ?? 'no response')];
        }
        if (empty($arr->AuthToken)) {
            return ['error' => 'Token not present in API response.'];
        }
        return ['token' => $arr->AuthToken];
    }

    private function callIrnApi(string $token, string $jsonBody): array
    {
        $cfg = config('services.irn');
        $headers = [
            'Content-Type:application/json',
            'MVApiKey:'     . $cfg['mv_api_key'],
            'MVSecretKey:'  . $cfg['mv_secret'],
            'Gstin:'        . $cfg['test_gstin'],
            'eInvoiceUserName:' . $cfg['test_user'],
            'eInvoicePassword:' . $cfg['test_pass'],
            'authToken: '   . $token,
            'MonthYear:'    . date('m-Y'),
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $cfg['url_generate'],
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $jsonBody,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $arr = json_decode($response);
        $ret = ['response_json' => $response, 'body' => $jsonBody];

        if (!empty($arr) && !empty($arr->Status)) {
            $ret['irn']      = $arr->IRN          ?? null;
            $ret['ack_no']   = $arr->AckNo        ?? null;
            $ret['ack_date'] = $arr->AckDate      ?? null;
            $ret['qr_text']  = $arr->SignedQRCode ?? null;
        } else {
            $ret['error'] = 'IRN API error: ' . ($arr->ErrorResponse[0]->ErrorInfo ?? 'unknown');
        }
        return $ret;
    }

    private function logIrn(
        int $companyId, string $challanId, int $status,
        string $bodyJson, string $responseJson,
        ?string $irn, ?string $ackNo, ?string $ackDate, ?string $qrText
    ): void {
        DB::table('einvoice_api_logs')->insert([
            'company_id'    => $companyId,
            'api'           => 1,
            'challan_id'    => $challanId,
            'status'        => $status,
            'body_json'     => $bodyJson,
            'response_json' => $responseJson,
            'created_at'    => now()->format('Y-m-d H:i:s'),
            'created_by'    => (string) (auth()->id() ?? 0),
            'irn'           => $irn,
            'ackno'         => $ackNo,
            'ackdate'       => $ackDate,
            'qr_path'       => null,
            'qr_text'       => $qrText,
        ]);
    }
}