<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\GstState;
use App\Support\RateList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChallanHelperController extends Controller
{
    /**
     * POST /api/challans/scheme-qty
     * Mirrors legacy getRasnaSchemeQty().
     */
    public function schemeQty(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'product_id' => ['required', 'integer'],
            'qty'        => ['required', 'numeric'],
            'rate'       => ['required', 'numeric'],
        ]);
        if ($v->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $user      = $request->user();
        $companyId = (int) $user->company_id;
        $stateId   = (int) $user->state_id;

        $productId = (int)   $request->input('product_id');
        $qty       = (float) $request->input('qty');
        $rate      = (float) $request->input('rate');

        // Scheme only applies to company 213
        if ($companyId !== 213) {
            return response()->json([
                'status'         => true,
                'code'           => 200,
                'rate'           => $rate,
                'scheme_percent' => 0,
                'scheme_value'   => 0,
                'taxable_amount' => round($qty * $rate, 2),
            ]);
        }

        $today = date('Y-m-d');

        $schemes = DB::select(
            "SELECT * FROM product_wise_scheme_plan_details
              WHERE company_id = ?
                AND state_id   = ?
                AND product_id = ?
                AND DATE(valid_from_date) <= ?
                AND DATE(valid_to_date)   >= ?",
            [$companyId, $stateId, $productId, $today, $today]
        );

        $schemePercent = 0.0;
        foreach ($schemes as $s) {
            $first = (float) $s->sale_value_range_first;
            $last  = (string) $s->sale_value_range_last;

            if ($last === '~') {
                if ($qty >= $first) {
                    $schemePercent = (float) $s->value_amount_percentage;
                }
            } else {
                $lastNum = (float) $last;
                if ($qty >= $first && $qty <= $lastNum) {
                    $schemePercent = (float) $s->value_amount_percentage;
                }
            }
        }

        $schemeValue   = round(($rate * $qty) * ($schemePercent / 100), 2);
        $taxableAmount = round(($rate * $qty) - $schemeValue, 2);

        return response()->json([
            'status'         => true,
            'code'           => 200,
            'rate'           => $rate,
            'scheme_percent' => $schemePercent,
            'scheme_value'   => $schemeValue,
            'taxable_amount' => $taxableAmount,
        ]);
    }

    /**
     * GET /api/challans/product-stock/{id}
     * On product select — MRP options + current stock.
     */
    public function productStock(Request $request, $id): JsonResponse
{
    $user       = $request->user();
    $dealerId   = (int) $user->dealer_id;
    $companyId  = (int) ($user->company_id_cus ?? $user->company_id ?? 0);
    $stateId    = (int) ($user->state_id ?? 0);
    $roleName   = (string) ($user->rolename ?? '');
    $qtyType    = (string) ($user->quantity_type ?? '1');
    $productId  = (int) $id;

    $retailerId = $request->input('retailer_id');

    // ---- Same-state flag (GST split ke liye) ----
    $st = GstState::same($companyId, $stateId, (int) $retailerId) ? 1 : 0;

    // ---- Product metadata ----
    $product = DB::selectOne(
        "SELECT id, name, hsn_code, gst_percent, weight, quantity_per_case
           FROM catalog_product
          WHERE id = ? AND company_id = ?",
        [$productId, $companyId]
    );

    if (!$product) {
        return response()->json(['status' => false, 'message' => 'Product not found.'], 404);
    }

    $igst = (float) ($product->gst_percent ?? 0);
    $cgst = $igst / 2;
    $sgst = $igst / 2;

    // ---- Rate column decide karo (role + qty type) ----
    $rateColumn = $this->pickRateColumn($roleName, $qtyType, $companyId);
    $rateRow = RateList::forDealer($dealerId, $companyId)->row($productId);
    $listRate = (float) ($rateRow->{$rateColumn} ?? 0);

    $rows = DB::select(
        "SELECT s.id, s.batch_no, s.mrp, s.qty, s.remaining, s.mfg, s.expire
           FROM stock s
          WHERE s.dealer_id  = ?
            AND s.product_id = ?
            AND s.qty        > 0
          ORDER BY s.mfg ASC",
        [$dealerId, $productId]
    );

    // ---- Response build ----
    $stock = [];
    $totalAvailable = 0.0;

    foreach ($rows as $s) {
        $mrp = (float) $s->mrp;

        // Rate ko GST-inclusive se GST-exclusive me convert (agar company 213/215)
        $rate = $listRate;
        if (in_array($companyId, [213, 215], true) && $igst > 0) {
            $beforeGst = round($rate - ($rate * (100 / (100 + $igst))), 3);
            $rate = round($rate - $beforeGst, 2);
        }

        $stock[] = [
            'id'          => (int) $s->id,
            'batch_no'    => $s->batch_no,
            'mrp'         => $mrp,
            'qty'         => (float) $s->qty,
            'remaining'   => (float) $s->remaining,
            'mfg'         => $s->mfg,
            'expire'      => $s->expire,
            'rate'        => $rate,
            // Frontend display format (old mrp_dis)
            'mrp_display' => number_format($mrp, 2, '.', '')
                           . '/' . (float) $s->qty
                           . '/' . $s->batch_no
                           . '/' . date('d-M-Y', strtotime($s->mfg)),
        ];

        $totalAvailable += (float) $s->qty;
    }

    return response()->json([
        'status'          => true,
        'stock'           => $stock,
        'total_available' => $totalAvailable,
        'batches'         => count($stock),
        'gst'             => [
            'igst' => $igst,
            'cgst' => $cgst,
            'sgst' => $sgst,
        ],
        'st'              => $st,
        'product'         => [
            'id'             => (int) $product->id,
            'name'           => $product->name,
            'hsn_code'       => $product->hsn_code,
            'weight'         => (float) $product->weight,
            'qty_per_case'   => (int) $product->quantity_per_case,
        ],
    ]);
}

/**
 * Old ajax ke hisaab se rate column chuno.
 */
private function pickRateColumn(string $roleName, string $qtyType, int $companyId): string
{
    // quantity_type: '0' = pcs, '1' = case
    $pcs = $qtyType === '0';

    if ($companyId === 40 || $companyId === 215) {
        if ($roleName === 'DISTRIBUTOR') return 'retailer_pcs_rate';
        if ($roleName === 'DEPO')        return 'dealer_rate';
        return 'ss_pcs_rate';
    }

    if (!in_array($companyId, [206, 243, 213], true)) {
        return 'retailer_pcs_rate';
    }

    if ($roleName === 'DISTRIBUTOR') {
        return $pcs ? 'retailer_pcs_rate' : 'retailer_rate';
    }
    if ($roleName === 'DEPO') {
        return $pcs ? 'dealer_pcs_rate' : 'dealer_rate';
    }
    // fallback (Super Admin / others)
    return $pcs ? 'ss_pcs_rate' : 'ss_case_rate';
}
}