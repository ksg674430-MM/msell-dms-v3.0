<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\CompanyPaymentController;
use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminPaymentController extends CompanyPaymentController
{
    use RequiresAdmin;

    public function adminIndex(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $ids = array_values(array_filter(array_map('intval', (array) $request->query('distributor_ids', [])), fn ($v) => $v > 0));
        $codes = DB::table('dealer')->where('company_id', $ctx->company_id)->where('dealer_status', '<>', 9)
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->whereNotIn('dealer_code', ['', '0'])
            ->pluck('name', 'dealer_code');
        if ($codes->isEmpty()) return response()->json(['status' => true, 'rows' => []]);

        $rows = [];
        foreach (array_chunk($codes->keys()->all(), 1000) as $chunk) {
            array_push($rows, ...DB::table('payment_mast')
                ->whereIn('dealer_code', $chunk)
                ->get(['id', 'amount', 'payment_mode', 'deposit_bank_name', 'transaction_no', 'bank_detail', 'cheque_draft_no', 'date', 'remark', 'dealer_code'])
                ->all());
        }
        usort($rows, fn ($a, $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);
        foreach ($rows as $r) $r->dealer_name = $codes[$r->dealer_code] ?? '';

        return response()->json(['status' => true, 'rows' => $rows]);
    }

    public function adminBanks(Request $request): JsonResponse
    {
        if (!$this->admin($request)) return $this->adminDenied();
        return response()->json([
            'status' => true,
            'banks'  => DB::select("SELECT id, account_name, account_no, ifsc_code, branch, address FROM bank_detail_mast WHERE status = 1 ORDER BY id"),
            'modes'  => self::MODES,
        ]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();

        $dealer = DB::selectOne(
            "SELECT id, name, dealer_code FROM dealer WHERE id = ? AND company_id = ? AND dealer_status <> 9 LIMIT 1",
            [(int) $request->input('dealer_id'), $ctx->company_id]
        );
        if (!$dealer) return response()->json(['status' => false, 'message' => 'Select the distributor.'], 422);
        if (trim((string) $dealer->dealer_code) === '') return response()->json(['status' => false, 'message' => 'This distributor has no dealer code.'], 422);

        return $this->savePayment($request, $dealer);
    }
}
