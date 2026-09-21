<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IrnController extends Controller
{
    /**
     * POST /api/invoices/{challan_id}/irn
     * Generate e-invoice IRN for a challan.
     */
    public function generate(Request $request, string $challanId): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 213);

        $challan = DB::selectOne("SELECT * FROM challan_order WHERE id = ? LIMIT 1", [$challanId]);
        if (!$challan) {
            return response()->json(['status' => false, 'message' => 'Challan not found.'], 404);
        }

        // Build IRN JSON payload
        $payload = $this->buildPayload($challan, $companyId);
        if (isset($payload['error'])) {
            return response()->json(['status' => false, 'message' => $payload['error']], 422);
        }

        // Get auth token
        $tokenResult = $this->fetchToken();
        if (isset($tokenResult['error'])) {
            $this->logIrn($companyId, $challanId, 0, json_encode($payload), '', null, null, null, null);
            return response()->json(['status' => false, 'message' => $tokenResult['error']], 502);
        }

        // Generate IRN
        $irnResult = $this->callIrnApi($tokenResult['token'], json_encode($payload));
        $success   = !isset($irnResult['error']);

        $this->logIrn(
            $companyId,
            $challanId,
            $success ? 1 : 0,
            json_encode($payload),
            $irnResult['response_json'] ?? '',
            $irnResult['irn']      ?? null,
            $irnResult['ack_no']   ?? null,
            $irnResult['ack_date'] ?? null,
            $irnResult['qr_text']  ?? null
        );

        if (!$success) {
            return response()->json(['status' => false, 'message' => $irnResult['error']], 502);
        }

        return response()->json([
            'status'   => true,
            'message'  => 'IRN generated successfully.',
            'irn'      => $irnResult['irn'],
            'ack_no'   => $irnResult['ack_no'],
            'ack_date' => $irnResult['ack_date'],
        ]);
    }

    /* ================= helpers ================= */

    private function buildPayload(object $challan, int $companyId): array
    {
        $dealerId   = (int) $challan->ch_dealer_id;
        $retailerId = $challan->ch_retailer_id;

        $dealer   = DB::selectOne("SELECT * FROM dealer WHERE id = ? LIMIT 1", [$dealerId]);
        $retailer = DB::selectOne("SELECT * FROM retailer WHERE id = ? LIMIT 1", [$retailerId]);

        if (!$dealer || !$retailer) {
            return ['error' => 'Dealer or retailer not found.'];
        }

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

        if (empty($dealerLoc->state_code)) return ['error' => 'Seller state code missing.'];
        if (empty($retailerLoc->state_code)) return ['error' => 'Buyer state code missing.'];

        $invoiceNo   = $challan->ch_no;
        $invoiceDate = date('d/m/Y', strtotime($challan->ch_date));

        $seller = [
            'Gstin' => (string) $dealer->tin_no,
            'LglNm' => $dealer->name,
            'TrdNm' => $dealer->name,
            'Addr1' => $dealer->address,
            'Addr2' => null,
            'Loc'   => $dealerLoc->city_name,
            'Pin'   => (int) $dealer->pin_no,
            'Stcd'  => $dealerLoc->state_code,
            'Ph'    => $dealer->landline ?: $dealer->other_numbers ?: '',
            'Em'    => $dealer->email ?: '',
        ];

        $buyer = [
            'Gstin' => (string) ($retailer->tin_no ?? ''),
            'LglNm' => $retailer->name,
            'TrdNm' => $retailer->name,
            'Pos'   => $retailerLoc->state_code,
            'Addr1' => $retailer->address,
            'Addr2' => null,
            'Loc'   => $retailerLoc->city_name,
            'Pin'   => (int) $retailer->pin_no,
            'Stcd'  => $retailerLoc->state_code,
            'Ph'    => $retailer->landline ?: $retailer->other_numbers ?: '',
            'Em'    => $retailer->email ?: '',
        ];

        $items = DB::select(
            "SELECT cod.*, cp.name AS product_name, cp.hsn_code
               FROM challan_order_details cod
               JOIN catalog_product cp ON cp.id = cod.product_id
              WHERE cod.ch_id = ?",
            [$challan->id]
        );

        $sameState = $dealerLoc->state_code === $retailerLoc->state_code;
        $assVal = $igstVal = $cgstVal = $sgstVal = 0.0;
        $itemList = [];

        foreach ($items as $idx => $it) {
            $qty      = (float) $it->qty;
            $rate     = round((float) $it->product_rate, 2);
            $freeQty  = (float) $it->free_qty;
            $preDisc  = round($qty * $rate, 2);
            $disc     = (float) $it->cd_amt + (float) $it->spl_disc_val + (float) $it->cash_amt + (float) $it->dis_amt;
            $disc     = round($disc, 2);
            $taxable  = round($preDisc - $disc, 2);
            $gstRate  = (float) $it->tax;
            $gstAmt   = round((float) $it->vat_amt, 2);

            if ($sameState) {
                $cgst = round($gstAmt / 2, 2);
                $sgst = round($gstAmt / 2, 2);
                $igst = 0.0;
            } else {
                $cgst = 0.0; $sgst = 0.0; $igst = $gstAmt;
            }

            $lineTotal = round($taxable + $gstAmt, 2);

            $itemList[] = [
                'SlNo'      => (string) ($idx + 1),
                'PrdDesc'   => $it->product_name,
                'IsServc'   => 'N',
                'HsnCd'     => $it->hsn_code,
                'Qty'       => $qty,
                'FreeQty'   => $freeQty,
                'Unit'      => 'PCS',
                'UnitPrice' => $rate,
                'TotAmt'    => $preDisc,
                'Discount'  => $disc,
                'PreTaxVal' => 0,
                'AssAmt'    => $taxable,
                'GstRt'     => $gstRate,
                'IgstAmt'   => $igst,
                'CgstAmt'   => $cgst,
                'SgstAmt'   => $sgst,
                'CesRt'     => 0,
                'CesAmt'    => 0,
                'CesNonAdvlAmt' => 0,
                'StateCesRt'    => 0,
                'StateCesAmt'   => 0,
                'StateCesNonAdvlAmt' => 0,
                'OthChrg'   => 0,
                'TotItemVal'=> $lineTotal,
            ];

            $assVal  += $taxable;
            $igstVal += $igst;
            $cgstVal += $cgst;
            $sgstVal += $sgst;
        }
$discount    = (float) ($challan->discount_amt ?? 0);
$amountFlat  = (float) ($challan->amount ?? 0);
$amountRound = (float) ($challan->amount_round ?? 0);

// Legacy rows may have amount_round = 0 (pre-rounding-tracking).
// GST requires RndOffAmt to be in [-99.99, +99.99], so recompute it.
if ($amountRound == 0 || abs($amountRound - $amountFlat) > 100) {
    $amountRound = round($amountFlat);
}

$rndOff = round($amountRound - $amountFlat, 2);
$rndOff = max(-99.99, min(99.99, $rndOff));
        return [
            'Version'   => '1.01',
            'TranDtls'  => [
                'TaxSch'     => 'GST',
                'SupTyp'     => 'B2B',
                'IgstOnIntra'=> 'N',
                'RegRev'     => null,
                'EcmGstin'   => null,
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