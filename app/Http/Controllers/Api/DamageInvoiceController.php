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
            'from', 'to', 'retailer_id', 'return_replace_status', 'per_page'
        ), $request->user());

        return response()->json($data);
    }

    public function show(string $id)
    {
        return response()->json($this->service->show($id));
    }

    public function store(StoreDamageInvoiceRequest $request)
    {
        $result = $this->service->save($request->validated(), $request->user());
        return response()->json($result, 201);
    }

    public function update(UpdateDamageInvoiceRequest $request, string $id)
    {
        $result = $this->service->update($id, $request->validated(), $request->user());
        return response()->json($result);
    }

    public function destroy(Request $request, string $id)
    {
        $this->service->delete($id, $request->user());
        return response()->json(['status' => true]);
    }

    /** GET /api/damage-invoices/next-number */
    public function nextNumber(Request $request)
    {
        $u = $request->user();
        return response()->json(
            $this->numbers->next((int) $u->dealer_id, (int) $u->company_id_cus)
        );
    }

    /**
     * POST /api/damage-invoices/calculate
     * Server-side mirror of pkcalculation.js (so frontend and backend agree).
     */
   public function calculate(CalculateRequest $request)
{
    $u = $request->user();
    $companyIdCus = (int) $u->company_id_cus;

    $items   = $request->input('items', []);
    $discPer = (float) $request->input('discount_per', 0);

    // check_gst determines CGST/SGST vs IGST.
    // Frontend sends .pkigst hidden input; default 0 (IGST).
    $checkGst = (int) $request->input('check_gst', 0);
    if ($companyIdCus === 213) {
        $checkGst = 1; // Rasna → always CGST/SGST
    }

    $rows        = [];
    $totTd       = 0.0;
    $totSpl      = 0.0;
    $totCd       = 0.0;
    $totTaxable  = 0.0;
    $totVat      = 0.0; // combined tax (cgst*2 or igst)
    $totAmount   = 0.0;

    // Duplicate detection (product_id + mrp)
    $seen = [];

    foreach ($items as $idx => $it) {
        $qty   = (float) $it['qty'];
        $rate  = (float) $it['product_rate'];
        $tax   = (float) ($it['tax'] ?? 0); // tax % — for IGST or per-side GST

        if ($qty <= 0) {
            throw new \InvalidArgumentException("Item #".($idx+1).": quantity must be greater than 0.");
        }

        // ---- trade discount ----
        $disType = (int) ($it['dis_type'] ?? 1);
        $disVal  = (float) ($it['dis_val'] ?? 0);
        if ($disType === 1 && $disVal > 100) {
            throw new \InvalidArgumentException("Item #".($idx+1).": trade discount cannot exceed 100%.");
        }
        $tradeAmt    = $disType === 1 ? ($qty * $rate * $disVal / 100) : $disVal;
        $afterTrade  = ($qty * $rate) - $tradeAmt;

        // ---- special discount ----
        $spType = (int) ($it['spl_disc_type'] ?? 1);
        $spVal  = (float) ($it['spl_disc_val'] ?? 0);
        if ($spType === 1 && $spVal > 100) {
            throw new \InvalidArgumentException("Item #".($idx+1).": special discount cannot exceed 100%.");
        }
        $spAmt      = $spType === 1 ? ($afterTrade * $spVal / 100) : $spVal;
        $afterSp    = $afterTrade - $spAmt;

        // ---- cash discount (kept for parity; usually 0 in damage flow) ----
        $cashAmt = (float) ($it['cash_amt'] ?? 0);
        $afterCash = $afterSp - $cashAmt;

        // ---- cash discount / CD (ATD) ----
        $cdType = (int) ($it['cd_type'] ?? 1);
        $cdVal  = (float) ($it['cd'] ?? 0);
        if ($cdType === 1 && $cdVal > 100) {
            throw new \InvalidArgumentException("Item #".($idx+1).": CD cannot exceed 100%.");
        }
        $cdAmt   = $cdType === 1 ? ($afterCash * $cdVal / 100) : $cdVal;
        $taxable = $afterCash - $cdAmt;

        if ($taxable < 0) {
            throw new \InvalidArgumentException("Item #".($idx+1).": taxable amount cannot be negative.");
        }

        // ---- tax ----
        $cgst = $sgst = $igst = $vatAmt = 0.0;
        if ($checkGst === 1) {
            $half   = round($taxable * ($tax / 2) / 100, 2);
            $cgst   = $half;
            $sgst   = $half;
            $vatAmt = round($half * 2, 2);
        } else {
            $igst   = $tax;
            $vatAmt = round($taxable * $tax / 100, 2);
        }
        $amount = round($taxable + $vatAmt, 2);

        // ---- duplicate check ----
        $pid  = (int) $it['product_id'];
        $mrp  = (float) $it['mrp'];
        $key  = $pid.'|'.$mrp;
        if (isset($seen[$key])) {
            throw new \InvalidArgumentException("Duplicate product (id {$pid}, MRP {$mrp}) not allowed.");
        }
        $seen[$key] = true;

        $rows[] = [
            'product_id'      => $pid,
            'mrp'             => $mrp,
            'qty'             => $qty,
            'product_rate'    => $rate,
            'trade_disc_amt'  => round($tradeAmt, 2),
            'spl_disc_amt'    => round($spAmt, 2),
            'cd_amt'          => round($cdAmt, 2),
            'taxable_amt'     => round($taxable, 2),
            'cgst'            => $checkGst ? round($tax / 2, 2) : 0,
            'cgst_amt'        => $cgst,
            'sgst'            => $checkGst ? round($tax / 2, 2) : 0,
            'sgst_amt'        => $sgst,
            'igst'            => $checkGst ? 0 : round($igst, 2),
            'vat_amt'         => $vatAmt,
            'amount'          => $amount,
        ];

        $totTd      += $tradeAmt;
        $totSpl     += $spAmt;
        $totCd      += $cdAmt;
        $totTaxable += $taxable;
        $totVat     += $vatAmt;
        $totAmount  += $amount;
    }

    $discAmt = round($totAmount * $discPer / 100, 2);
    $final   = round($totAmount - $discAmt, 2);

    return response()->json([
        'items'          => $rows,
        'total_td'       => round($totTd, 2),
        'total_spl'      => round($totSpl, 2),
        'total_cd'       => round($totCd, 2),
        'total_taxable'  => round($totTaxable, 2),
        'total_vat'      => round($totVat, 2),
        'total'          => round($totAmount, 2),
        'discount_per'   => $discPer,
        'discount_amt'   => $discAmt,
        'total_amount_a' => $final,
        'final_amount'   => (int) round($final), // matches JS Math.round
        'check_gst'      => $checkGst,
    ]);
}
protected function assertNoDuplicates(array $items): void
{
    $seen = [];
    foreach ($items as $i => $it) {
        $key = (int) $it['product_id'].'|'.(float) $it['mrp'];
        if (isset($seen[$key])) {
            throw new \InvalidArgumentException(
                "Duplicate product (id {$it['product_id']}, MRP {$it['mrp']}) not allowed."
            );
        }
        $seen[$key] = true;
    }
}
}