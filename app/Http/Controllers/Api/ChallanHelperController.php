<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     *
     * Filters on `remaining` so it agrees with reduceStock().
     * If your schema treats `qty` as authoritative, change the WHERE
     * here AND in ChallanController::reduceStock() + formData().
     */
    public function productStock(Request $request, $id): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $productId = (int) $id;

        $stock = DB::select(
            "SELECT id, batch_no, mrp, dealer_rate, rate,
                    qty, remaining, mfg, expire
               FROM stock
              WHERE dealer_id = ?
                AND product_id = ?
                AND remaining > 0
              ORDER BY mfg ASC",
            [$dealerId, $productId]
        );

        $summary = DB::selectOne(
            "SELECT COALESCE(SUM(remaining), 0) AS total_available,
                    COUNT(*) AS batches
               FROM stock
              WHERE dealer_id = ? AND product_id = ? AND remaining > 0",
            [$dealerId, $productId]
        );

        return response()->json([
            'status'          => true,
            'stock'           => $stock,
            'total_available' => (float) ($summary->total_available ?? 0),
            'batches'         => (int)   ($summary->batches ?? 0),
        ]);
    }
}