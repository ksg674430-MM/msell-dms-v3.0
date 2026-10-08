<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DmsReportService;
use Illuminate\Http\Request;

class DmsReportController extends Controller
{
    public function __construct(
        protected DmsReportService $service,
    ) {}

    /* ---------- DMS → Tally ---------- */
    // public function dmsToTallyReport(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->toTallyFilters($request->user()),
        // ]);
    // }

    // public function dmsToTally(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->toTallyData($request, $request->user()),
        // ]);
    // }

    /* ---------- Invoice Summary ---------- */
    // public function dmsInvoiceSummaryReport(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->invoiceSummaryFilters($request->user()),
        // ]);
    // }

    // public function dmsInvoiceSummary(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->invoiceSummaryData($request, $request->user()),
        // ]);
    // }

    /* ---------- Purchase ---------- */
    // public function dmsPurchaseReport(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->purchaseFilters($request->user()),
        // ]);
    // }

    // public function dmsPurchase(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->purchaseData($request, $request->user()),
        // ]);
    // }

    /* ---------- Common Report Section ---------- */
    // public function dmsCommonReportSection(Request $request)
    // {
        // return response()->json([
            // 'status' => true,
            // 'data'   => $this->service->commonReportSection($request, $request->user()),
        // ]);
    // }
}