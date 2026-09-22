<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChallanHelperController extends Controller
{
    /**
     * POST /api/challans/scheme-qty
     * Mirrors legacy getRasnaSchemeQty().
     */
    public function schemeQty(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer'],
            'qty'        => ['required', 'numeric'],
            'rate'       => ['required', 'numeric'],
        ]);

        $user      = $request->user();
        $companyId = (int) $user->company_id;
        $stateId   = (int) $user->state_id;

        $productId = (int) $request->input('product_id');
        $qty       = (float) $request->input('qty');
        $rate      = (float) $request->input('rate');
        $today     = date('Y-m-d');

        $schemes = DB::select(
            "SELECT * FROM product_wise_scheme_plan_details
              WHERE company_id = ?
                AND state_id   = ?
                AND product_id = ?
                AND DATE(valid_from_date) <= ?
                AND DATE(valid_to_date)   >= ?",
            [$companyId, $stateId, $productId, $today, $today]
        );

        $schemePercent = 0;
        foreach ($schemes as $s) {
            $first = (float) $s->sale_value_range_first;
            $last  = $s->sale_value_range_last;

            if ((string) $last === '~') {
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
            'status'          => true,
            'code'            => 200,
            'scheme_percent'  => $schemePercent,
            'scheme_value'    => $schemeValue,
            'taxable_amount'  => $taxableAmount,
        ]);
    }

    /**
     * POST /api/challans/scheme-rate
     * Mirrors ReturnProudutRate::get_rate().
     */
    public function schemeRate(Request $request): JsonResponse
    {
        $request->validate([
            'item'     => ['required', 'integer'],
            'quantity' => ['required', 'numeric'],
            'rate'     => ['required', 'numeric'],
        ]);

        $user      = $request->user();
        $companyId = (int) $user->company_id;
        $stateId   = (int) $user->state_id;

        $item       = (int)   $request->input('item');
        $qty        = (float) $request->input('quantity');
        $rate       = (float) $request->input('rate');
        $retailerId = (int)   ($request->input('retailer_id') ?? 0);
        $date       = date('Y-m-d');

        $scheme = DB::selectOne(
            "SELECT * FROM product_wise_scheme_plan_details
              WHERE product_id = ? AND state_id = ? AND company_id = ?
              LIMIT 1",
            [$item, $stateId, $companyId]
        );

        if (!$scheme) {
            return response()->json(['rate' => $rate]);
        }

        $first = (float) $scheme->sale_value_range_first;
        $last  = (float) $scheme->sale_value_range_last;
        $inRange = ($qty >= $first && $qty <= $last);

        if (!$inRange) {
            return response()->json(['rate' => $rate]);
        }

        if ((string) $scheme->sale_unit === '5') {
            // Rate-based scheme
            if ((int) $scheme->incentive_type === 1) {
                $pct  = (float) $scheme->value_amount_percentage;
                $disc = $rate * ($pct / 100);
                return response()->json(['rate' => round($rate - $disc, 2)]);
            }
            if ((int) $scheme->incentive_type === 2) {
                return response()->json(['rate' => (float) $scheme->value_amount_percentage]);
            }
            return response()->json(['rate' => $rate]);
        }

        // PCS-based scheme
        $incentiveType = (int) $scheme->incentive_type;

        if ($incentiveType === 1) {
            return response()->json(['Percentage' => (float) $scheme->value_amount_percentage]);
        }
        if ($incentiveType === 3) {
            return response()->json(['freeQuantity' => (float) $scheme->value_amount_percentage]);
        }
        if ($incentiveType === 2) {
            return response()->json(['Amt' => (float) $scheme->value_amount_percentage]);
        }

        return response()->json(['Amt' => 0]);
    }

    /**
     * GET /api/challans/product-stock/{id}
     * On product select — returns MRP options + current stock.
     */
    public function productStock(Request $request, $id): JsonResponse
    {
        $user     = $request->user();
        $dealerId = (int) $user->dealer_id;

        $productId = (int) $id;

        $stock = DB::select(
            "SELECT id, batch_no, mrp, dealer_rate, rate, qty, remaining,
                    mfg, expire
               FROM stock
              WHERE dealer_id = ? AND product_id = ? AND qty > 0
              ORDER BY mfg ASC",
            [$dealerId, $productId]
        );

        return response()->json([
            'status' => true,
            'stock'  => $stock,
        ]);
    }
}