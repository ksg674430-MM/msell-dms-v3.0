<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditDebitNoteController extends Controller
{
    /**
     * GET /api/credit-debit-notes
     *
     * Query params (all optional):
     *   - order_no           (string)  → partial match on VRNO
     *   - date_range_picker  (string)  → "YYYY-MM-DD - YYYY-MM-DD"
     *   - range              (int)     → last N days (anchored to from_date or today)
     *   - from_date          (date)    → optional anchor for range mode
     *   - page               (int)     → 1-based
     *   - per_page           (int)     → 10 | 25 | 50 | 100 | 250 | 500
     *
     * Behaviour:
     *   - No date params → today only
     *   - range only     → today - N days  → today
     *   - range + from_date → from_date - N days → today
     *   - date_range_picker → use both dates as given
     *   - fromDate floored at 2021-04-01
     *   - Non-admin (role != 1) is scoped to their own ACC_CODE
     *
     * Row shape returned (matches UI columns):
     *   - ref_no           ← VRNO
     *   - transaction_date ← VRDATE_FILTER
     *   - cr_amount        ← CRAMT (varchar → DECIMAL cast)
     *   - dr_amount        ← DRAMT (varchar → DECIMAL cast)
     *   - invoice_no       ← OLD_VRNO (falls back to VRNO when blank)
     *   - description      ← PARTICULAR
     *   - id, VRNO, ACC_CODE, NOTE_TYPE, APPROVED are also returned so
     *     the frontend can drive the Action column (edit / delete).
     */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $roleId = (int) $user->role_id;

        // ── Pagination ──────────────────────────────────────────
        $perPage = (int) $request->query('per_page', 100);
        if (!in_array($perPage, [10, 25, 50, 100, 250, 500], true)) {
            $perPage = 10;
        }
        $page = max(1, (int) $request->query('page', 1));

        // ── Date range resolution ───────────────────────────────
        $orderNo   = trim((string) $request->query('order_no'));
        $rangeMode = 0;
        $fromDate  = date('Y-m-d');
        $toDate    = date('Y-m-d');

        if (empty($request->date_range_picker)) {
            if (!empty($request->range)) {
                $anchor    = !empty($request->from_date) ? (string) $request->from_date : date('Y-m-d');
                $fromDate  = date('Y-m-d', strtotime('-'.(int) $request->range.' day', strtotime($anchor)));
                $toDate    = date('Y-m-d');
                $rangeMode = 1;
            }
        } else {
            $parts = preg_split('/\s*-\s*/', (string) $request->date_range_picker);
            if (is_array($parts) && count($parts) === 2) {
                $fromDate = date('Y-m-d', strtotime(trim($parts[0])));
                $toDate   = date('Y-m-d', strtotime(trim($parts[1])));
            }
        }

        if ($fromDate < '2021-04-01') {
            $fromDate = '2021-04-01';
        }

        // ── Base query ──────────────────────────────────────────
        $query = DB::table('ACCNOTE_TRAN')
            ->whereRaw(
                "DATE_FORMAT(ACCNOTE_TRAN.VRDATE_FILTER, '%Y-%m-%d') BETWEEN ? AND ?",
                [$fromDate, $toDate]
            );

        // Dealer scoping — non-admin users only see their own ACC_CODE
        if ($roleId !== 1) {
            $accCode = $this->currentDealerCode($request);
            if ($accCode === null) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Dealer account is not linked to this user.',
                ], 403);
            }
            $query->where('ACCNOTE_TRAN.ACC_CODE', $accCode);
        }

        if ($orderNo !== '') {
            $query->where('ACCNOTE_TRAN.VRNO', 'LIKE', '%'.$orderNo.'%');
        }

        // ── Total count (before pagination) ────────────────────
        $total = (clone $query)->count();

        // ── Paginated rows (aliased + cast for UI) ─────────────
        $offset = ($page - 1) * $perPage;
        $rows = $query
            ->select([
                'ACCNOTE_TRAN.id',
                'ACCNOTE_TRAN.ACC_CODE',
                'ACCNOTE_TRAN.VRNO',
                'ACCNOTE_TRAN.VRDATE',
                'ACCNOTE_TRAN.VRDATE_FILTER',
                'ACCNOTE_TRAN.NOTE_TYPE',
                'ACCNOTE_TRAN.APPROVED',
                'ACCNOTE_TRAN.PARTICULAR',
                'ACCNOTE_TRAN.OLD_VRNO',
                'ACCNOTE_TRAN.ITEM_VRNO',
                'ACCNOTE_TRAN.CRAMT',
                'ACCNOTE_TRAN.DRAMT',

                // UI aliases
                'ACCNOTE_TRAN.VRNO           as ref_no',
                'ACCNOTE_TRAN.VRDATE_FILTER as transaction_date',
                'ACCNOTE_TRAN.PARTICULAR    as description',

                // Amounts — varchar → DECIMAL (empty/blank → 0)
                DB::raw("CAST(NULLIF(TRIM(ACCNOTE_TRAN.CRAMT), '') AS DECIMAL(15,2)) as cr_amount"),
                DB::raw("CAST(NULLIF(TRIM(ACCNOTE_TRAN.DRAMT), '') AS DECIMAL(15,2)) as dr_amount"),

                // Invoice no — OLD_VRNO preferred, falls back to VRNO
                DB::raw("COALESCE(NULLIF(TRIM(ACCNOTE_TRAN.OLD_VRNO), ''), ACCNOTE_TRAN.VRNO) as invoice_no"),
            ])
            ->orderByDesc('ACCNOTE_TRAN.VRDATE_FILTER')
            ->orderByDesc('ACCNOTE_TRAN.id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        return response()->json([
            'status'  => true,
            'message' => '',
            'data'    => [
                'range_mode' => $rangeMode === 1,
                'from_date'  => $fromDate,
                'to_date'    => $toDate,
                'role_id'    => $roleId,
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'records'    => $rows,
            ],
        ]);
    }

    /**
     * DELETE /api/credit-debit-notes/{id}
     *
     * Body:
     *   - VRNO (string) → must match the row's VRNO (sanity check)
     *
     * Moves the row to ACCNOTE_TRAN_TEMP, then deletes it from ACCNOTE_TRAN.
     * Both steps run inside a transaction — either both succeed or neither does.
     *
     * Non-admin users can only delete their own notes (ACC_CODE scoping).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $roleId = (int) $user->role_id;

        $data = $request->validate([
            'VRNO' => ['required', 'string'],
        ]);

        $query = DB::table('ACCNOTE_TRAN')
            ->where('id', $id)
            ->where('VRNO', $data['VRNO']);

        // 🔒 Dealer can only delete their own notes
        if ($roleId !== 1) {
            $accCode = $this->currentDealerCode($request);
            if ($accCode === null) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Dealer account is not linked to this user.',
                ], 403);
            }
            $query->where('ACC_CODE', $accCode);
        }

        $select = $query->first();

        if (!$select) {
            return response()->json([
                'status'  => false,
                'message' => 'Record not found.',
            ], 404);
        }

        DB::beginTransaction();

        try {
            // ACCNOTE_TRAN_TEMP mirrors ACCNOTE_TRAN — cast to array for insert
            DB::table('ACCNOTE_TRAN_TEMP')->insert((array) $select);

            DB::table('ACCNOTE_TRAN')->where('id', $id)->delete();

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Record moved to log and deleted.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'status'  => false,
                'message' => 'Action cannot be completed.',
            ], 500);
        }
    }

    /**
     * Resolve the current user's dealer ACC_CODE.
     *
     * dealer_person_login has no dealer_code column on this schema — resolve
     * via dealer_id → dealer.dealer_code.
     */
    private function currentDealerCode(Request $request): ?string
    {
        $user = $request->user();

        if (!empty($user->dealer_code)) {
            return (string) $user->dealer_code;
        }

        if (!empty($user->dealer_id)) {
            $dealer = DB::table('dealer')->where('id', (int) $user->dealer_id)->first();
            if ($dealer && !empty($dealer->dealer_code)) {
                return (string) $dealer->dealer_code;
            }
        }

        return null;
    }
}