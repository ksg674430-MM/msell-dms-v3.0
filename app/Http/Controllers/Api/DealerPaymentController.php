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
            'amount.*'      => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $lastId = null;

            foreach ($request->retailer_id as $k => $retailer_id) {
                $amount = $request->amount[$k] ?? 0;

                $lastId = DB::table('old_payment_dues')->insertGetId([
                    'ch_dealer_id'   => $dealer_id,
                    'ch_retailer_id' => $retailer_id,
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
            ->join('location_5 as l5', 'l5.id', '=', 'dlr.location_id')
            ->select('dlr.location_id as beat_id', 'l5.name as beat_name')
            ->where('dlr.dealer_id', $dealer_id)
            ->groupBy('dlr.location_id', 'l5.name')
            ->orderBy('l5.name', 'ASC')
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

        $start = $request->filled('start') ? $request->start : date('Y-m-d');
        $end   = $request->filled('end')   ? $request->end   : null;

        // Retailers having unpaid challans
        $challanQuery = DB::table('challan_order')
            ->select('ch_retailer_id as retailer_id', DB::raw('SUM(remaining) as challan_total'))
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '!=', '1');

        if ($end) {
            $challanQuery->whereDate('ch_date', '>=', $start)
                         ->whereDate('ch_date', '<=', $end);
        } else {
            $challanQuery->whereDate('ch_date', '=', $start);
        }

        if ($request->filled('retailer_id')) {
            $challanQuery->where('ch_retailer_id', $request->retailer_id);
        }

        $challanRows = $challanQuery->groupBy('ch_retailer_id')->get();

        $records      = [];
        $retailer_ids = [];

        foreach ($challanRows as $row) {
            $retailer_id = $row->retailer_id;
            $retailer_ids[] = $retailer_id;

            $old_total = (float) DB::table('old_payment_dues')
                ->where('ch_retailer_id', $retailer_id)
                ->where('payment_status', '0')
                ->sum('remaining');

            $retailer_name = DB::table('retailer')
                ->where('id', $retailer_id)
                ->value('name');

            $last = DB::table('payment_collection')
                ->where('retailer_id', $retailer_id)
                ->where('dealer_id', $dealer_id)
                ->orderByDesc('pay_date_time')
                ->first(['pay_date_time as lastdate', 'total_amount as lastamt']);

            $records[] = [
                'retailer_id'   => $retailer_id,
                'retailer_name' => $retailer_name,
                'total'         => $old_total,
                'last'          => [
                    'lastdate' => $last->lastdate ?? '00-00-0000 00:00',
                    'lastamt'  => (float) ($last->lastamt ?? 0),
                ],
            ];
        }

        // Retailers having only old dues (exclude already listed)
        $oldQuery = DB::table('old_payment_dues')
            ->select('ch_retailer_id as retailer_id', DB::raw('SUM(remaining) as total'))
            ->where('ch_dealer_id', $dealer_id)
            ->where('payment_status', '0');

        if (!empty($retailer_ids)) {
            $oldQuery->whereNotIn('ch_retailer_id', $retailer_ids);
        }

        if ($request->filled('retailer_id')) {
            $oldQuery->where('ch_retailer_id', $request->retailer_id);
        }

        $oldRows = $oldQuery->groupBy('ch_retailer_id')->get();

        foreach ($oldRows as $row) {
            $retailer_id = $row->retailer_id;

            $retailer_name = DB::table('retailer')
                ->where('id', $retailer_id)
                ->value('name');

            $last = DB::table('payment_collection')
                ->where('retailer_id', $retailer_id)
                ->where('dealer_id', $dealer_id)
                ->orderByDesc('pay_date_time')
                ->first(['pay_date_time as lastdate', 'total_amount as lastamt']);

            $records[] = [
                'retailer_id'   => $retailer_id,
                'retailer_name' => $retailer_name,
                'total'         => (float) $row->total,
                'last'          => [
                    'lastdate' => $last->lastdate ?? '00-00-0000 00:00',
                    'lastamt'  => (float) ($last->lastamt ?? 0),
                ],
            ];
        }

        return response()->json([
            'status'  => true,
            'message' => 'Payment collection list',
            'data'    => [
                'records' => $records,
            ],
        ]);
    }

    /**
     * Retailer dues breakdown: old dues + unpaid challans.
     */
   public function paymentCollectionRetailerDues(Request $request): JsonResponse
{
    $ctx       = $this->context($request);
    $dealer_id = $ctx['dealer_id'];

    $request->validate([
        'retailer_id' => 'required',
    ]);

    $retailer_id = $request->retailer_id;

    // 1. Old dues — aggregated
    $old_dues_row = DB::table('old_payment_dues')
        ->select(
            'id',
            DB::raw('SUM(amount) as total_amount'),
            DB::raw('SUM(remaining) as remaining')
        )
        ->where('ch_retailer_id', $retailer_id)
        ->where('ch_dealer_id', $dealer_id)
        ->where('payment_status', '0')
        ->first();

    $old_dues = null;
    if ($old_dues_row && (float) $old_dues_row->remaining > 0) {
        $old_dues = [
            'id'         => $old_dues_row->id,
            'amount'     => (float) $old_dues_row->total_amount,
            'remaining'  => (float) $old_dues_row->remaining,
        ];
    }

    // 2. Unpaid challans
    $invoices = DB::table('challan_order')
        ->select('id', 'ch_no', 'amount', 'remaining')
        ->where('ch_retailer_id', $retailer_id)
        ->where('ch_dealer_id', $dealer_id)
        ->where('payment_status', '!=', '1')
        ->orderBy('auto', 'ASC')
        ->get()
        ->map(function ($row) {
            return [
                'id'        => $row->id,
                'ch_no'     => $row->ch_no,
                'amount'    => (float) $row->amount,
                'remaining' => (float) $row->remaining,
            ];
        });

    $total = ($old_dues['remaining'] ?? 0) + $invoices->sum('remaining');

    return response()->json([
        'status'  => true,
        'message' => 'Retailer dues',
        'data'    => [
            'old_dues'     => $old_dues,
            'invoices'     => $invoices,
            'total_amount' => round($total, 2),
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
        $dealer_id  = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];

        $request->validate([
            'retailer_id'       => 'required',
            'reciept_no'        => 'required',
            'pay_mode'          => 'required',
            'old_ch_id'         => 'nullable',
            'old_remain_amount' => 'nullable|numeric|min:0',
            'old_recieved_amt'  => 'nullable|numeric|min:0',
            'invoice'           => 'nullable|array',
            'remain_amount'     => 'nullable|array',
            'recieved_amt'      => 'nullable|array',
            'bank_name'         => 'nullable',
            'chq_no'            => 'nullable',
            'chq_date'          => 'nullable',
            'txn_no'            => 'nullable',
            'txn_date'          => 'nullable',
            'Remark'            => 'nullable',
        ]);

        $chq_no   = $request->chq_no   ?: $request->txn_no;
        $chq_date = $request->chq_date ?: $request->txn_date;

        $id = $dealer_id . date('YmdHis');

        DB::beginTransaction();

        try {
            // 1. Old dues payment
            if ($request->filled('old_recieved_amt') && $request->filled('old_ch_id')) {
                $old_ch_id    = $request->old_ch_id;
                $old_remain   = (float) $request->old_remain_amount;
                $old_recieved = (float) $request->old_recieved_amt;

                if ($old_remain == $old_recieved) {
                    DB::table('old_payment_dues')
                        ->where('id', $old_ch_id)
                        ->update(['remaining' => 0, 'payment_status' => '1']);
                } else {
                    DB::table('old_payment_dues')
                        ->where('id', $old_ch_id)
                        ->update([
                            'remaining' => DB::raw("remaining - $old_recieved"),
                        ]);
                }

                DB::table('payment_collection')->insert([
                    'dealer_id'     => $dealer_id,
                    'retailer_id'   => $request->retailer_id,
                    'challan_id'    => $old_ch_id,
                    'total_amount'  => $old_recieved,
                    'reciept_no'    => $request->reciept_no,
                    'pay_mode'      => $request->pay_mode,
                    'bank_name'     => $request->bank_name,
                    'chq_no'        => $chq_no,
                    'chq_date'      => $chq_date,
                    'Remark'        => $request->Remark,
                    'pay_date_time' => now(),
                    'company_id'    => $company_id,
                ]);
            }

            // 2. Challan-wise payments
            if ($request->filled('invoice')) {
                foreach ($request->invoice as $challan_id => $value) {
                    $remain_amount = (float) ($request->remain_amount[$challan_id] ?? 0);
                    $recieved_amt  = (float) ($request->recieved_amt[$challan_id] ?? 0);

                    if ($recieved_amt <= 0) {
                        continue;
                    }

                    DB::table('payment_collection')->insert([
                        'dealer_id'     => $dealer_id,
                        'retailer_id'   => $request->retailer_id,
                        'challan_id'    => $challan_id,
                        'total_amount'  => $recieved_amt,
                        'reciept_no'    => $request->reciept_no,
                        'pay_mode'      => $request->pay_mode,
                        'bank_name'     => $request->bank_name,
                        'chq_no'        => $chq_no,
                        'chq_date'      => $chq_date,
                        'Remark'        => $request->Remark,
                        'pay_date_time' => now(),
                        'company_id'    => $company_id,
                    ]);

                    if ($remain_amount == $recieved_amt) {
                        DB::table('challan_order')
                            ->where('id', $challan_id)
                            ->update(['remaining' => 0, 'payment_status' => '1']);
                    } else {
                        DB::table('challan_order')
                            ->where('id', $challan_id)
                            ->update([
                                'remaining'      => DB::raw("remaining - $recieved_amt"),
                                'payment_status' => '2',
                            ]);
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