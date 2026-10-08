<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateRequest;
use App\Http\Requests\StoreDepotDamageInvoiceRequest;
use App\Http\Requests\UpdateDepotDamageInvoiceRequest;
use App\Services\DamageInvoiceService;
use App\Services\InvoiceNumberService;
use App\Services\DepotDamageInvoiceService;
use Illuminate\Http\Request;

class DepotDamageInvoiceController extends Controller
{
    public function __construct(
        protected DepotDamageInvoiceService $service,
        protected InvoiceNumberService $numbers,
    ) {}

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list($request->only('from', 'to', 'csa_id', 'ch_no', 'per_page'), $request->user())
        );
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->service->show($id, $request->user()));
    }

    public function store(StoreDepotDamageInvoiceRequest $request)
    {
        return response()->json($this->service->save($request->validated(), $request->user()), 201);
    }

    // public function update(UpdateDepotDamageInvoiceRequest $request, string $id)
    // {
        // return response()->json($this->service->update($id, $request->validated(), $request->user()));
    // }

    // public function destroy(Request $request, string $id)
    // {
        // $this->service->delete($id, $request->user());
        // return response()->json(['status' => true, 'message' => 'Depot damage invoice deleted.']);
    // }

    public function nextNumber(Request $request)
    {
        $u = $request->user();
        return response()->json($this->numbers->nextDepot((int) $u->dealer_id, DamageInvoiceService::companyId($u)));
    }

    public function calculate(CalculateRequest $request)
    {
        return response()->json(
            $this->service->compute($request->input('items', []), $request->input('discount_per', 0), $request->user())
        );
    }
}
