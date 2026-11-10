<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TallyViewService;
use Illuminate\Http\Request;

class TallyViewController extends Controller
{
    public function __construct(
        protected TallyViewService $service,
    ) {}

    // /** GET /api/tally-view/stock */
    // public function tallyStockView(Request $request)
    // {
        // return response()->json([
            // 'status'  => true,
            // 'records' => $this->service->stock($request->user()),
        // ]);
    // }

    // /** GET /api/tally-view/purchase */
    // public function tallyPurchase(Request $request)
    // {
        // return response()->json([
            // 'status'  => true,
            // 'records' => $this->service->purchase($request->user()),
        // ]);
    // }

    // /** GET /api/tally-view/sale */
    // public function tallySale(Request $request)
    // {
        // return response()->json([
            // 'status'  => true,
            // 'records' => $this->service->sale($request->user()),
        // ]);
    // }
}