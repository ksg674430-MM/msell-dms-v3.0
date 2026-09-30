<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealerPaymentController extends Controller
{
    /**
     * Resolve dealer context from authenticated user.
     */
   private function context(Request $request): array
{
    $user    = $request->user();
    $user_id = $user->id ?? 0;

    // 1. Direct dealer_id (auth table = dealer_person_login)
    $dealer_id = $user->dealer_id ?? 0;

    // 2. By dpId (if auth table is different, id maps to dpId)
    if (empty($dealer_id)) {
        $login = DB::table('dealer_person_login')->where('dpId', $user_id)->first();
        $dealer_id = $login->dealer_id ?? 0;
    }

    // 3. By uname
    if (empty($dealer_id) && !empty($user->uname)) {
        $login = DB::table('dealer_person_login')->where('uname', $user->uname)->first();
        $dealer_id = $login->dealer_id ?? 0;
    }

    // 4. By email
    if (empty($dealer_id) && !empty($user->email)) {
        $login = DB::table('dealer_person_login')->where('email', $user->email)->first();
        $dealer_id = $login->dealer_id ?? 0;
    }

    $dealer = $dealer_id ? DB::table('dealer')->where('id', $dealer_id)->first() : null;

    return [
        'user'          => $user,
        'dealer'        => $dealer,
        'dealer_id'     => $dealer_id,
        'company_id'    => $dealer->company_id    ?? ($user->company_id    ?? 0),
        'dealer_code'   => $dealer->dealer_code   ?? ($user->dealer_code   ?? ''),
        'csa_id'        => $dealer->csa_id        ?? ($user->csa_id        ?? 0),
        'role_id'       => $user->role_id         ?? ($dealer->role_id     ?? 0),
        'domain_url'    => $user->domain_url      ?? '',
        'quantity_type' => $dealer->quantity_type ?? 0,
        'date'          => date('Y-m-d'),
    ];
}
    /* =========================================================
     |  OLD PAYMENT DUES
     | ========================================================= */

    /**
     * Retailer list for the old payment form dropdown.
     */
    public function oldPaymentRetailers(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $retailers = DB::table('retailer')
            ->select('id', DB::raw("CONCAT(name, ' [', address, '] ') as name"))
            ->where('dealer_id', $dealer_id)
            ->where('retailer_status', '1')
            ->orderBy('name', 'ASC')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Retailer list',
            'data'    => [
                'retailers' => $retailers,
            ],
        ]);
    }

    /**
     * Old dues list (dealer wise, only unpaid).
     */
    public function oldPaymentList(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $perPage = (int) ($request->perpage ?? 10);

        $query = DB::table('old_payment_dues')
            ->join('retailer', 'retailer.id', '=', 'old_payment_dues.ch_retailer_id')
            ->select(
                'old_payment_dues.id',
                'old_payment_dues.ch_dealer_id',
                'old_payment_dues.ch_retailer_id',
                'old_payment_dues.amount',
                'old_payment_dues.remaining',
                'old_payment_dues.payment_status',
                'retailer.name as retailer_name'
            )
            ->where('old_payment_dues.ch_dealer_id', $dealer_id)
            ->where('old_payment_dues.payment_status', '0');

        if ($request->filled('retailer_id')) {
            $query->where('old_payment_dues.ch_retailer_id', (string) $request->retailer_id);
        }
        if ($request->filled('name')) {
            $query->where('retailer.name', 'LIKE', '%' . $request->name . '%');
        }

        $records = $query->orderBy('old_payment_dues.id', 'ASC')->paginate($perPage);

        return response()->json([
            'status'  => true,
            'message' => 'Old payment list',
            'data'    => [
                'records' => $records,
            ],
        ]);
    }

    /**
     * Save old payment dues (multiple rows).
     */
    public function oldPaymentSave(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $request->validate([
            'retailer_id'   => 'required|array|min:1',
            'retailer_id.*' => 'required',
            'amount'        => 'required|array|min:1',
            'amount.*'      => 'required|numeric|gt:0',
        ], [
            'amount.*.gt' => 'Every row needs an amount greater than 0.',
        ]);

        $retailer_ids = array_map('strval', $request->retailer_id);
        $own = DB::table('retailer')
            ->where('dealer_id', $dealer_id)
            ->whereIn('id', $retailer_ids)
            ->pluck('id')
            ->map(fn ($v) => (string) $v)
            ->all();
        if (array_diff($retailer_ids, $own)) {
            return response()->json([
                'status'  => false,
                'message' => 'Select retailers from your own retailer list.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $lastId = null;

            foreach ($request->retailer_id as $k => $retailer_id) {
                $amount = $request->amount[$k] ?? 0;

                $lastId = DB::table('old_payment_dues')->insertGetId([
                    'company_id'     => $ctx['company_id'],
                    'ch_dealer_id'   => $dealer_id,
                    'ch_retailer_id' => (string) $retailer_id,
                    'amount'         => $amount,
                    'remaining'      => $amount,
                    'payment_status' => '0',
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Old payment successfully saved',
                'rId'     => $lastId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'   => false,
                'message'  => 'Old payment save failed',
                'myreason' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  PAYMENT COLLECTION
     | ========================================================= */

    /**
     * Beat list for payment collection filter.
     */
    public function paymentCollectionBeats(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $beats = DB::table('dealer_location_rate_list as dlr')
            ->join('location_7 as l7', 'l7.id', '=', 'dlr.location_id')
            ->select('dlr.location_id as beat_id', 'l7.name as beat_name')
            ->where('dlr.dealer_id', $dealer_id)
            ->groupBy('dlr.location_id', 'l7.name')
            ->orderBy('l7.name', 'ASC')
            ->get();

        return response()->json([
            'status'  => true,
            'message' => 'Beat list',
            'data'    => [
                'beats' => $beats,
            ],
        ]);
    }

    /**
     * Retailer-wise payment collection list with outstanding dues.
     * Mirrors dealer_sale::get_payment_data().
     */
    public function paymentCollectionList(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $start = $request->filled('start') ? date('Y-m-d', strtotime($request->start)) : date('Y-m-d');
        $end   = $request->filled('end') ? date('Y-m-d', strtotime($request->end)) : $start;

        $scope = function ($q, string $column) use ($request, $dealer_id) {
            if ($request->filled('retailer_id')) {
                $q->where($column, (string) $request->retailer_id);
            }
            if ($request->filled('location_id')) {
                $q->whereIn($column, DB::table('retailer')
                    ->where('dealer_id', $dealer_id)
                    ->where('location_id', $request->location_id)
                    ->select('id'));
            }
            return $q;
        };

        $challan_ids = $scope(DB::table('challan_order')
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '!=', '1')
            ->whereDate('ch_date', '>=', $start)
            ->whereDate('ch_date', '<=', $end), 'ch_retailer_id')
            ->distinct()
            ->pluck('ch_retailer_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        $old_ids = $scope(DB::table('old_payment_dues')
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '0')
            ->where('remaining', '>', 0), 'ch_retailer_id')
            ->distinct()
            ->pluck('ch_retailer_id')
            ->map(fn ($v) => (string) $v)
            ->all();

        $retailer_ids = array_values(array_unique(array_merge($challan_ids, $old_ids)));
        if (!$retailer_ids) {
            return response()->json(['status' => true, 'message' => 'Payment collection list', 'data' => ['records' => []]]);
        }

        $challan_due = DB::table('challan_order')
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '!=', '1')
            ->whereIn('ch_retailer_id', $retailer_ids)
            ->groupBy('ch_retailer_id')
            ->pluck(DB::raw('SUM(remaining)'), 'ch_retailer_id');

        $old_due = DB::table('old_payment_dues')
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '0')
            ->whereIn('ch_retailer_id', $retailer_ids)
            ->groupBy('ch_retailer_id')
            ->pluck(DB::raw('SUM(remaining)'), 'ch_retailer_id');

        $names = DB::table('retailer')->whereIn('id', $retailer_ids)->pluck('name', 'id');

        $last = DB::table('payment_collection as pc')
            ->joinSub(
                DB::table('payment_collection')
                    ->select('retailer_id', DB::raw('MAX(pay_date_time) as last_at'))
                    ->where('dealer_id', $dealer_id)
                    ->whereIn('retailer_id', $retailer_ids)
                    ->groupBy('retailer_id'),
                'x',
                fn ($j) => $j->on('x.retailer_id', '=', 'pc.retailer_id')->on('x.last_at', '=', 'pc.pay_date_time')
            )
            ->where('pc.dealer_id', $dealer_id)
            ->groupBy('pc.retailer_id', 'x.last_at')
            ->get(['pc.retailer_id', 'x.last_at', DB::raw('SUM(pc.total_amount) as amount')])
            ->keyBy(fn ($r) => (string) $r->retailer_id);

        $records = [];
        foreach ($retailer_ids as $retailer_id) {
            $lp = $last[$retailer_id] ?? null;
            $records[] = [
                'retailer_id'   => $retailer_id,
                'retailer_name' => $names[$retailer_id] ?? '',
                'total'         => round((float) ($challan_due[$retailer_id] ?? 0) + (float) ($old_due[$retailer_id] ?? 0), 2),
                'last'          => [
                    'lastdate' => $lp->last_at ?? null,
                    'lastamt'  => (float) ($lp->amount ?? 0),
                ],
            ];
        }
        usort($records, fn ($a, $b) => strcasecmp((string) $a['retailer_name'], (string) $b['retailer_name']));

        return response()->json([
            'status'  => true,
            'message' => 'Payment collection list',
            'data'    => [
                'records' => $records,
            ],
        ]);
    }

    private function dealerRetailer(int $dealer_id, $retailer_id): bool
    {
        $retailer_id = (string) $retailer_id;
        return DB::table('retailer')->where('id', $retailer_id)->where('dealer_id', $dealer_id)->exists()
            || DB::table('challan_order')->where('ch_retailer_id', $retailer_id)->where('ch_dealer_id', $dealer_id)->exists()
            || DB::table('old_payment_dues')->where('ch_retailer_id', $retailer_id)->where('ch_dealer_id', $dealer_id)->exists();
    }

    /**
     * Retailer dues breakdown: old dues + unpaid challans.
     */
    public function paymentCollectionRetailerDues(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = (int) $ctx['dealer_id'];

        $retailer_id = (string) ($request->route('retailer_id') ?? $request->retailer_id);
        if ($retailer_id === '' || !$this->dealerRetailer($dealer_id, $retailer_id)) {
            return response()->json(['status' => false, 'message' => 'Retailer not found.'], 404);
        }

        $old_remaining = (float) DB::table('old_payment_dues')
            ->where('ch_retailer_id', $retailer_id)
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '0')
            ->sum('remaining');

        $old_dues = $old_remaining > 0 ? ['remaining' => round($old_remaining, 2)] : null;

        $invoices = DB::table('challan_order')
            ->select('id', 'ch_no', 'ch_date', 'amount', 'remaining')
            ->where('ch_retailer_id', $retailer_id)
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '!=', '1')
            ->where('remaining', '>', 0)
            ->orderBy('auto', 'ASC')
            ->get()
            ->map(fn ($row) => [
                'id'        => (string) $row->id,
                'ch_no'     => $row->ch_no,
                'ch_date'   => $row->ch_date,
                'amount'    => (float) $row->amount,
                'remaining' => round((float) $row->remaining, 2),
            ]);

        return response()->json([
            'status'  => true,
            'message' => 'Retailer dues',
            'data'    => [
                'retailer_name' => DB::table('retailer')->where('id', $retailer_id)->value('name'),
                'old_dues'      => $old_dues,
                'invoices'      => $invoices,
                'total_amount'  => round(($old_dues['remaining'] ?? 0) + $invoices->sum('remaining'), 2),
            ],
        ]);
    }

    /**
     * Save payment collection (old dues + challan-wise).
     * Mirrors payment::save_payment_new().
     */
    public function paymentCollectionSave(Request $request): JsonResponse
    {
        $ctx        = $this->context($request);
        $dealer_id  = (int) $ctx['dealer_id'];
        $company_id = $ctx['company_id'];

        $request->validate([
            'retailer_id'      => 'required',
            'reciept_no'       => 'required|string|max:500',
            'pay_mode'         => 'required|in:0,1,2',
            'old_recieved_amt' => 'nullable|numeric|min:0',
            'recieved_amt'     => 'nullable|array',
            'recieved_amt.*'   => 'nullable|numeric|min:0',
            'bank_name'        => 'nullable|string|max:100',
            'chq_no'           => 'nullable|string|max:100',
            'chq_date'         => 'nullable|string|max:200',
            'txn_no'           => 'nullable|string|max:100',
            'txn_date'         => 'nullable|string|max:200',
            'Remark'           => 'nullable|string',
        ]);

        $retailer_id = (string) $request->retailer_id;
        if (!$this->dealerRetailer($dealer_id, $retailer_id)) {
            return response()->json(['status' => false, 'message' => 'Retailer not found.'], 404);
        }

        $old_amount = round((float) $request->old_recieved_amt, 2);
        $received   = collect((array) $request->recieved_amt)
            ->map(fn ($v) => round((float) $v, 2))
            ->filter(fn ($v) => $v > 0);

        if ($old_amount <= 0 && $received->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'Enter the received amount for at least one invoice or old dues.'], 422);
        }

        $pay_mode = (int) $request->pay_mode;
        $chq_no   = $pay_mode === 2 ? $request->txn_no : $request->chq_no;
        $chq_date = $pay_mode === 2 ? $request->txn_date : $request->chq_date;
        $base_row = [
            'dealer_id'     => $dealer_id,
            'retailer_id'   => $retailer_id,
            'reciept_no'    => $request->reciept_no,
            'pay_mode'      => $pay_mode,
            'bank_name'     => $pay_mode === 0 ? '' : (string) $request->bank_name,
            'chq_no'        => $pay_mode === 0 ? '' : (string) $chq_no,
            'chq_date'      => $pay_mode === 0 ? '' : (string) $chq_date,
            'Remark'        => (string) $request->Remark,
            'pay_date_time' => now(),
            'company_id'    => $company_id,
        ];

        DB::beginTransaction();

        try {
            if ($old_amount > 0) {
                $old_rows = DB::table('old_payment_dues')
                    ->where('ch_retailer_id', $retailer_id)
                    ->where('ch_dealer_id', $dealer_id)
                    ->where('payment_status', '0')
                    ->where('remaining', '>', 0)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($old_amount > round((float) $old_rows->sum('remaining'), 2)) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => "Received amount can't be greater than the remaining old dues."], 422);
                }

                $left = $old_amount;
                foreach ($old_rows as $row) {
                    if ($left <= 0) {
                        break;
                    }
                    $take = min($left, (float) $row->remaining);
                    $rest = round((float) $row->remaining - $take, 2);
                    DB::table('old_payment_dues')->where('id', $row->id)->update([
                        'remaining'      => $rest,
                        'payment_status' => $rest <= 0 ? '1' : '0',
                    ]);
                    $left = round($left - $take, 2);
                }

                DB::table('payment_collection')->insert($base_row + [
                    'challan_id'   => (string) $old_rows->first()->id,
                    'total_amount' => $old_amount,
                ]);
            }

            if ($received->isNotEmpty()) {
                $challans = DB::table('challan_order')
                    ->whereIn('id', $received->keys()->map(fn ($k) => (string) $k)->all())
                    ->where('ch_dealer_id', $dealer_id)
                    ->where('ch_retailer_id', $retailer_id)
                    ->where('payment_status', '!=', '1')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(fn ($c) => (string) $c->id);

                foreach ($received as $challan_id => $amount) {
                    $challan = $challans[(string) $challan_id] ?? null;
                    if (!$challan) {
                        DB::rollBack();
                        return response()->json(['status' => false, 'message' => 'One of the selected invoices is no longer pending.'], 422);
                    }
                    $remaining = round((float) $challan->remaining, 2);
                    if ($amount > $remaining) {
                        DB::rollBack();
                        return response()->json(['status' => false, 'message' => "Received amount can't be greater than the remaining amount of invoice {$challan->ch_no}."], 422);
                    }

                    DB::table('payment_collection')->insert($base_row + [
                        'challan_id'   => (string) $challan_id,
                        'total_amount' => $amount,
                    ]);

                    $rest = round($remaining - $amount, 2);
                    DB::table('challan_order')->where('id', (string) $challan_id)->update([
                        'remaining'      => $rest,
                        'payment_status' => $rest <= 0 ? '1' : '2',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Payment successfully saved',
                'rId'     => $dealer_id . date('YmdHis'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'   => false,
                'message'  => 'Payment save failed',
                'myreason' => $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
     |  CHALLAN-WISE PAYMENT (Reports)
     | ========================================================= */

    /**
     * Challan-wise payment list (Pending/Paid status).
     * Mirrors dealer_sale::get_payment_list().
     */
    public function challanPaymentList(Request $request): JsonResponse
    {
        $ctx       = $this->context($request);
        $dealer_id = $ctx['dealer_id'];

        $perPage = (int) ($request->perpage ?? 10);

        $query = DB::table('challan_order')
            ->select(
                'challan_order.*',
                'challan_order.id as ch_id',
                DB::raw("IF(payment_status = 0, 'Pending', 'Paid') as payment"),
                DB::raw("DATE_FORMAT(ch_date, '%Y-%m-%d') as ch_date")
            )
            ->where('ch_dealer_id', $dealer_id);

        if ($request->filled('start')) {
            $query->whereDate('ch_date', '>=', $request->start);
        }
        if ($request->filled('end')) {
            $query->whereDate('ch_date', '<=', $request->end);
        }
        if ($request->filled('retailer_id')) {
            $query->where('ch_retailer_id', $request->retailer_id);
        }
        if ($request->filled('ch_no')) {
            $query->where('ch_no', 'LIKE', '%' . $request->ch_no . '%');
        }
        if ($request->filled('payment')) {
            if ($request->payment === 'paid') {
                $query->where('payment_status', '1');
            } elseif ($request->payment === 'pending') {
                $query->where('payment_status', '!=', '1');
            }
        }

        $records = $query->orderBy('challan_order.ch_no', 'ASC')->paginate($perPage);

        return response()->json([
            'status'  => true,
            'message' => 'Challan payment list',
            'data'    => [
                'records' => $records,
            ],
        ]);
    }

    /**
     * Paid challan list (challans having a payment entry).
     * Mirrors dealer_sale::get_paid_challan_list().
     */
    public function paidChallanList(Request $request): JsonResponse
{
    $ctx       = $this->context($request);
    $dealer_id = $ctx['dealer_id'];

    $perPage = (int) ($request->perpage ?? 10);

    $query = DB::table('challan_order')
        ->join('payment_collection', 'payment_collection.challan_id', '=', 'challan_order.id')
        ->select(
            'challan_order.ch_no',
            'challan_order.payment_status',
            'challan_order.amount',
            'challan_order.remaining',
            'challan_order.ch_retailer_id',
            'challan_order.id as chln_id',
            DB::raw("DATE_FORMAT(challan_order.ch_date, '%d-%b-%Y') as ch_date"),
            DB::raw("DATE_FORMAT(payment_collection.pay_date_time, '%Y-%m-%d') as payment_date"),
            'payment_collection.total_amount as paid_amount',
            'payment_collection.reciept_no'
        )
        ->where('challan_order.ch_dealer_id', $dealer_id);

    if ($request->filled('start')) {
        $query->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') >= ?", [$request->start]);
    }
    if ($request->filled('end')) {
        $query->whereRaw("DATE_FORMAT(challan_order.ch_date, '%Y-%m-%d') <= ?", [$request->end]);
    }
    if ($request->filled('retailer_id')) {
        $query->where('challan_order.ch_retailer_id', $request->retailer_id);
    }

    $paginator = $query->orderBy('payment_collection.pay_date_time', 'DESC')->paginate($perPage);

    return response()->json([
        'status'  => true,
        'message' => 'Paid challan list',
        'data'    => [
            'records' => $paginator->items(),
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ],
    ]);
}


/**
 * Legacy payment save — comma-separated challan_id, auto-distribute.
 * Mirrors payment::save_payment().
 */
public function paymentCollectionSaveLegacy(Request $request): JsonResponse
{
    $ctx        = $this->context($request);
    $dealer_id  = $ctx['dealer_id'];

    $request->validate([
        'retailer_id'   => 'required',
        'challan_id'    => 'required',
        'total_amount'  => 'required|numeric|min:0',
        'pay_mode'      => 'required',
        'reciept_no'    => 'required',
        'bank_name'     => 'nullable',
        'chq_no'        => 'nullable',
        'chq_date'      => 'nullable',
        'txn_no'        => 'nullable',
        'txn_date'      => 'nullable',
        'Remark'        => 'nullable',
    ]);

    $chq_no   = $request->chq_no   ?: $request->txn_no;
    $chq_date = $request->chq_date ?: $request->txn_date;

    $challan_id_str = $request->challan_id;               // "101,102"
    $challan_ids    = array_filter(explode(',', $challan_id_str));
    $amt            = (float) $request->total_amount;

    $id = $dealer_id . date('Ymdhis');

    DB::beginTransaction();

    try {
        // 1. Insert payment_collection (one row, comma-separated challan_id)
        DB::table('payment_collection')->insert([
            'dealer_id'     => $dealer_id,
            'retailer_id'   => $request->retailer_id,
            'challan_id'    => implode(',', $challan_ids),
            'total_amount'  => $amt,
            'reciept_no'    => $request->reciept_no,
            'pay_mode'      => $request->pay_mode,
            'bank_name'     => $request->bank_name,
            'chq_no'        => $chq_no,
            'chq_date'      => $chq_date,
            'Remark'        => $request->Remark,
            'pay_date_time' => now(),
        ]);

        // 2. First id might be old_payment_dues — reduce it first
        $first_id = $challan_ids[0] ?? null;

        if ($first_id) {
            $old = DB::table('old_payment_dues')->where('id', $first_id)->first();

            if ($old) {
                $old_remain = (float) $old->remaining;

                if ($amt >= $old_remain) {
                    $amt -= $old_remain;
                    DB::table('old_payment_dues')
                        ->where('id', $first_id)
                        ->update(['remaining' => 0, 'payment_status' => '1']);
                } elseif ($amt > 0) {
                    DB::table('old_payment_dues')
                        ->where('id', $first_id)
                        ->update(['remaining' => $old_remain - $amt]);
                    $amt = 0;
                }
            }
        }

        // 3. Distribute remaining amount across challan_order rows
        if ($amt > 0) {
            foreach ($challan_ids as $challan_id) {
                $challan = DB::table('challan_order')
                    ->where('id', $challan_id)
                    ->orderBy('auto', 'ASC')
                    ->first();

                if (!$challan) {
                    continue;
                }

                $remain = (float) $challan->remaining;

                if ($amt >= $remain) {
                    $amt -= $remain;
                    DB::table('challan_order')
                        ->where('id', $challan_id)
                        ->update(['remaining' => 0, 'payment_status' => '1']);
                } elseif ($amt > 0) {
                    DB::table('challan_order')
                        ->where('id', $challan_id)
                        ->update([
                            'remaining'      => $remain - $amt,
                            'payment_status' => '2',
                        ]);
                    $amt = 0;
                }

                if ($amt <= 0) {
                    break;
                }
            }
        }

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Payment successfully saved',
            'rId'     => $id,
        ]);
    } catch (\Exception $e) {
        DB::rollBack();

        return response()->json([
            'status'   => false,
            'message'  => 'Payment save failed',
            'myreason' => $e->getMessage(),
        ], 500);
    }
}
}