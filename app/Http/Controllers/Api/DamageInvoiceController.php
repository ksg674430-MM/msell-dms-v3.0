<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateRequest;
use App\Http\Requests\StoreDamageInvoiceRequest;
use App\Http\Requests\UpdateDamageInvoiceRequest;
use App\Services\DamageInvoiceService;
use App\Services\InvoiceNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DamageInvoiceController extends Controller
{
    public function __construct(
        protected DamageInvoiceService $service,
        protected InvoiceNumberService $numbers,
    ) {}

    public function index(Request $request)
    {
        $data = $this->service->list($request->only(
            'from', 'to', 'retailer_id', 'return_replace_status', 'ch_no', 'per_page'
        ), $request->user());

        return response()->json($data);
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->service->show($id, $request->user()));
    }

    public function store(StoreDamageInvoiceRequest $request)
    {
        $result = $this->service->save($request->validated(), $request->user());
        return response()->json($result, 201);
    }

    // public function update(UpdateDamageInvoiceRequest $request, string $id)
    // {
        // $result = $this->service->update($id, $request->validated(), $request->user());
        // return response()->json($result);
    // }

    // public function destroy(Request $request, string $id)
    // {
        // $this->service->delete($id, $request->user());
        // return response()->json(['status' => true]);
    // }

    /** GET /api/damage-invoices/next-number */
    public function nextNumber(Request $request)
    {
        $u = $request->user();
        return response()->json(
            $this->numbers->next((int) $u->dealer_id, DamageInvoiceService::companyId($u))
        );
    }

    /**
     * POST /api/damage-invoices/calculate
     * Server-side mirror of pkcalculation.js (so frontend and backend agree).
     */
    public function calculate(CalculateRequest $request)
    {
        $u = $request->user();
        $forSelf = (int) $request->input('for_self_retailer', 1);
        return response()->json($this->service->compute(
            $request->input('items', []),
            $request->input('discount_per', 0),
            $this->service->checkGst($u, $forSelf, (string) $request->input('retailer_id', '0')),
            DamageInvoiceService::companyId($u)
        ));
    }
}
