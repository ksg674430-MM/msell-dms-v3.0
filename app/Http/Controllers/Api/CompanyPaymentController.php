<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CompanyPaymentController extends Controller
{
    private const MODES = ['RTGS/NEFT', 'Cheque', 'Draft'];

    public function index(Request $request): JsonResponse
    {
        if (!$dealer = $this->dealer($request)) return $this->denied();

        $rows = DB::select(
            "SELECT id, amount, payment_mode, deposit_bank_name, transaction_no, bank_detail, cheque_draft_no, date, remark, dealer_code
               FROM payment_mast
              WHERE dealer_code = ? AND dealer_id IN (?, ?)
              ORDER BY date DESC, id DESC",
            [$dealer->dealer_code, (string) $dealer->id, $dealer->dealer_code]
        );

        return response()->json(['status' => true, 'dealer_name' => $dealer->name, 'rows' => $rows]);
    }

    public function banks(Request $request): JsonResponse
    {
        if (!$this->dealer($request)) return $this->denied();

        return response()->json([
            'status' => true,
            'banks'  => DB::select("SELECT id, account_name, account_no, ifsc_code, branch, address FROM bank_detail_mast WHERE status = 1 ORDER BY id"),
            'modes'  => self::MODES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$dealer = $this->dealer($request)) return $this->denied();

        $rtgs = $request->input('payment_mode') === 'RTGS/NEFT';
        $v = Validator::make($request->all(), [
            'amount'            => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_mode'      => ['required', 'in:' . implode(',', self::MODES)],
            'deposit_bank_name' => ['required', 'string', 'max:20'],
            'transaction_no'    => [$rtgs ? 'required' : 'nullable', 'string', 'max:20'],
            'bank_detail'       => [$rtgs ? 'nullable' : 'required', 'string', 'max:20'],
            'cheque_draft_no'   => [$rtgs ? 'nullable' : 'required', 'string', 'max:20'],
            'date'              => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'remark'            => ['nullable', 'string', 'max:250'],
        ], [
            'transaction_no.required'  => 'Enter the Transaction No. for RTGS/NEFT.',
            'bank_detail.required'     => 'Enter the CH/DD bank details.',
            'cheque_draft_no.required' => 'Enter the Cheque/Draft No.',
            'date.before_or_equal'     => 'Payment date cannot be in the future.',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => false, 'message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
        }

        DB::table('payment_mast')->insert([
            'amount'            => number_format((float) $request->input('amount'), 2, '.', ''),
            'payment_mode'      => (string) $request->input('payment_mode'),
            'deposit_bank_name' => trim((string) $request->input('deposit_bank_name')),
            'transaction_no'    => $rtgs ? trim((string) $request->input('transaction_no')) : '0',
            'bank_detail'       => $rtgs ? '' : trim((string) $request->input('bank_detail')),
            'cheque_draft_no'   => $rtgs ? '' : trim((string) $request->input('cheque_draft_no')),
            'date'              => $request->input('date'),
            'remark'            => trim((string) $request->input('remark', '')),
            'server_date_time'  => now(),
            'dealer_id'         => (string) $dealer->id,
            'dealer_code'       => (string) $dealer->dealer_code,
        ]);

        return response()->json(['status' => true, 'message' => 'Payment saved successfully.'], 201);
    }

    private function dealer(Request $request): ?object
    {
        $user = $request->user();
        $role = trim((string) DB::table('_role')->where('role_id', $user->role_id)->value('rolename'));
        if (!in_array($role, ['DEPO', 'Depo'], true)) return null;

        $dealer = DB::selectOne("SELECT id, name, dealer_code FROM dealer WHERE id = ? LIMIT 1", [(int) $user->dealer_id]);
        return $dealer && trim((string) $dealer->dealer_code) !== '' ? $dealer : null;
    }

    private function denied(): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Payments are only for super stockist logins with a dealer code.'], 403);
    }
}
