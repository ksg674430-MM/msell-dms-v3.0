<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalculateRequest;
use App\Http\Requests\StoreDepotDamageInvoiceRequest;
use App\Http\Requests\UpdateDepotDamageInvoiceRequest;
use App\Services\InvoiceNumberService;
use App\Services\DepotDamageInvoiceService;
use Illuminate\Http\Request;

class DepotDamageInvoiceController extends Controller
{
    public function __construct(
        protected DepotDamageInvoiceService $service,
        protected InvoiceNumberService $nextForDepot,
    ) {}

    public function index(Request $request)
    {
        return response()->json(
            $this->service->list(
                $request->only('from', 'to', 'csa_id', 'per_page'),
                $request->user()
            )
        );
    }

    public function show(string $id)
    {
        return response()->json($this->service->show($id));
    }

    public function store(StoreDepotDamageInvoiceRequest $request)
    {
        $r = $this->service->save($request->validated(), $request->user());
        return response()->json($r, 201);
    }

    public function update(UpdateDepotDamageInvoiceRequest $request, string $id)
    {
        return response()->json(
            $this->service->update($id, $request->validated(), $request->user())
        );
    }

    public function destroy(Request $request, string $id)
    {
        $this->service->delete($id, $request->user());
        return response()->json(['status' => true]);
    }

    public function nextNumber(Request $request)
    {
        // dd($request->all());
        $u = $request->user();
        return response()->json(
            $this->nextForDepot->nextForDepot(
                (int) $u->dealer_id,
                (int) $u->company_id,
                null,
                'damage_order_depot'  // forces BDN/ prefix
            )
        );
    }

    /**
     * Depot calculate — same math as dealer, but pkigst is always 0 in the form
     * unless company 213.
     */
    public function calculate(CalculateRequest $request)
    {
        $u = $request->user();
        $companyIdCus = (int) $u->company_id;

        $checkGst = (int) $request->input('check_gst', 0);
        if ($companyIdCus === 213) {
            $checkGst = 1;  // depot form for 213 uses CGST+SGST
        }

        $discPer = (float) $request->input('discount_per', 0);
        $items   = $request->input('items', []);

        $rows = [];
        $totTd = $totSpl = $totCd = $totTaxable = $totVat = $totAmount = 0.0;
        $seen = [];

        foreach ($items as $idx => $it) {
            $qty   = (float) $it['qty'];
            $rate  = (float) $it['product_rate'];
            $tax   = (float) ($it['tax'] ?? 0);

            if ($qty <= 0) throw new \InvalidArgumentException("Item #".($idx+1).": qty must be > 0.");

            $disType = (int) ($it['dis_type'] ?? 1);
            $disVal  = (float) ($it['dis_val'] ?? 0);
            if ($disType === 1 && $disVal > 100) throw new \InvalidArgumentException("Item #".($idx+1).": trade disc > 100%.");
            $tradeAmt   = $disType === 1 ? ($qty * $rate * $disVal / 100) : $disVal;
            $afterTrade = ($qty * $rate) - $tradeAmt;

            $spType = (int) ($it['spl_disc_type'] ?? 1);
            $spVal  = (float) ($it['spl_disc_val'] ?? 0);
            if ($spType === 1 && $spVal > 100) throw new \InvalidArgumentException("Item #".($idx+1).": special disc > 100%.");
            $spAmt   = $spType === 1 ? ($afterTrade * $spVal / 100) : $spVal;
            $afterSp = $afterTrade - $spAmt;

            $cashAmt   = (float) ($it['cash_amt'] ?? 0);
            $afterCash = $afterSp - $cashAmt;

            $cdType = (int) ($it['cd_type'] ?? 1);
            $cdVal  = (float) ($it['cd'] ?? 0);
            if ($cdType === 1 && $cdVal > 100) throw new \InvalidArgumentException("Item #".($idx+1).": CD > 100%.");
            $cdAmt   = $cdType === 1 ? ($afterCash * $cdVal / 100) : $cdVal;
            $taxable = $afterCash - $cdAmt;
            if ($taxable < 0) throw new \InvalidArgumentException("Item #".($idx+1).": negative taxable.");

            if ($checkGst) {
                $half   = round($taxable * ($tax / 2) / 100, 2);
                $cgst   = $sgst = $half;
                $vatAmt = round($half * 2, 2);
                $igst   = 0;
            } else {
                $cgst = $sgst = 0;
                $igst   = $tax;
                $vatAmt = round($taxable * $tax / 100, 2);
            }
            $amount = round($taxable + $vatAmt, 2);

            $pid = (int) $it['product_id'];
            $mrp = (float) $it['mrp'];
            $key = $pid.'|'.$mrp;
            if (isset($seen[$key])) throw new \InvalidArgumentException("Duplicate product ({$pid}, MRP {$mrp}).");
            $seen[$key] = true;

            $rows[] = [
                'product_id' => $pid, 'mrp' => $mrp, 'qty' => $qty, 'product_rate' => $rate,
                'trade_disc_amt' => round($tradeAmt, 2),
                'spl_disc_amt'   => round($spAmt, 2),
                'cd_amt'         => round($cdAmt, 2),
                'taxable_amt'    => round($taxable, 2),
                'cgst' => $checkGst ? round($tax / 2, 2) : 0, 'cgst_amt' => $cgst,
                'sgst' => $checkGst ? round($tax / 2, 2) : 0, 'sgst_amt' => $sgst,
                'igst' => $checkGst ? 0 : round($igst, 2),
                'vat_amt' => $vatAmt, 'amount' => $amount,
            ];

            $totTd += $tradeAmt; $totSpl += $spAmt; $totCd += $cdAmt;
            $totTaxable += $taxable; $totVat += $vatAmt; $totAmount += $amount;
        }

        $discAmt = round($totAmount * $discPer / 100, 2);
        $final   = round($totAmount - $discAmt, 2);

        return response()->json([
            'items' => $rows,
            'total_td' => round($totTd, 2),
            'total_spl' => round($totSpl, 2),
            'total_cd' => round($totCd, 2),
            'total_taxable' => round($totTaxable, 2),
            'total_vat' => round($totVat, 2),
            'total' => round($totAmount, 2),
            'discount_per' => $discPer,
            'discount_amt' => $discAmt,
            'total_amount_a' => $final,
            'final_amount' => (int) round($final),
            'check_gst' => $checkGst,
        ]);
    }
}