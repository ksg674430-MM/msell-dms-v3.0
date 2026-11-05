<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    private function ctx(Request $request): array
    {
        $u = $request->user();
        $d = DB::table('dealer')->where('id', $u->dealer_id ?? 0)->first();
        return [
            'dealer_id'  => (int) ($u->dealer_id ?? 0),
            'company_id' => (int) ($d->company_id ?? ($u->company_id ?? 0)),
        ];
    }

    public function meta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);

        $types = DB::table('expense_type')
            ->where('company_id', $c['company_id'])
            ->where('status', 1)
            ->orderBy('sequence')
            ->get();

        $children = DB::table('other_expense_type')
            ->where('company_id', $c['company_id'])
            ->where('status', 1)
            ->whereIn('expense_type_id', $types->pluck('id'))
            ->orderBy('sequence')->orderBy('name')
            ->get()->groupBy('expense_type_id');

        $filledIds = [];
        $filled    = [];
        if (! empty($request->expense_month)) {
            $mk = date('Y-m', strtotime($request->expense_month . '-01'));
            $filledIds = DB::table('user_expense_details')
                ->where('company_id', $c['company_id'])->where('dealer_id', $c['dealer_id'])
                ->whereRaw("DATE_FORMAT(expense_date,'%Y-%m') = ?", [$mk])
                ->pluck('expense_type')->toArray();
            $filled = DB::table('user_expense_details as ued')
                ->leftJoin('other_expense_type as oet', 'ued.expense_type', '=', 'oet.id')
                ->leftJoin('expense_type as et', 'oet.expense_type_id', '=', 'et.id')
                ->where('ued.company_id', $c['company_id'])->where('ued.dealer_id', $c['dealer_id'])
                ->whereRaw("DATE_FORMAT(ued.expense_date,'%Y-%m') = ?", [$mk])
                ->select('ued.*', 'oet.name as expense_name', 'et.name as parent_name',
                         'oet.sequence as child_sequence', 'et.sequence as parent_sequence')
                ->orderBy('et.sequence')->orderBy('oet.sequence')
                ->get();
        }

        return response()->json([
            'status'  => true,
            'message' => 'Expense meta',
            'data'    => [
                'expense_types'        => $types,
                'other_expense_types'  => $children,
                'already_filled_ids'   => $filledIds,
                'filled_expenses'      => $filled,
            ],
        ]);
    }

    public function save(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);

        $types   = $request->input('expense_type', []);
        $fares   = $request->input('expense_fare', []);
        $remarks = $request->input('expense_remarks', []);
        $images  = $request->file('expense_image', []);

        if (! empty($request->expense_month)) {
            $expense_date = date('Y-m-01 H:i:s', strtotime($request->expense_month . '-01'));
            $mk = date('Y-m', strtotime($request->expense_month . '-01'));
        } else {
            $expense_date = date('Y-m-d H:i:s');
            $mk = date('Y-m');
        }

        if (empty($types)) {
            return response()->json(['status' => false, 'message' => 'Add at least one expense'], 422);
        }

        $incoming = [];
        foreach ($types as $k => $tid) {
            $tid  = trim($tid);
            $fare = isset($fares[$k]) ? trim($fares[$k]) : '';
            if ($tid !== '' && $fare !== '' && $fare > 0) {
                $incoming[] = (int) $tid;
            }
        }
        $incoming = array_values(array_unique($incoming));

        if ($incoming) {
            $already = DB::table('user_expense_details')
                ->where('company_id', $c['company_id'])->where('dealer_id', $c['dealer_id'])
                ->whereIn('expense_type', $incoming)
                ->whereRaw("DATE_FORMAT(expense_date,'%Y-%m') = ?", [$mk])
                ->pluck('expense_type')->toArray();
            if ($already) {
                $names = DB::table('other_expense_type')->whereIn('id', $already)->pluck('name')->toArray();
                return response()->json([
                    'status'        => false,
                    'message'       => 'Already filled: ' . implode(', ', $names),
                    'duplicate_ids' => array_values(array_unique($already)),
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $inserted = 0;
            foreach ($types as $k => $tid) {
                $tid    = trim($tid);
                $fare   = isset($fares[$k]) ? trim($fares[$k]) : '';
                $remark = isset($remarks[$k]) ? trim($remarks[$k]) : '';
                if ($tid === '' || $fare === '' || $fare <= 0) continue;

                $image = null;
                if (isset($images[$k]) && $images[$k] instanceof \Illuminate\Http\UploadedFile && $images[$k]->isValid()) {
                    $file = $images[$k];
                    $name = time() . '_' . $k . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
                    $dest = public_path('uploads/expense');
                    if (! file_exists($dest)) mkdir($dest, 0777, true);
                    $file->move($dest, $name);
                    $image = 'uploads/expense/' . $name;
                }

                DB::table('user_expense_details')->insert([
                    'expense_type'    => $tid,
                    'expense_fare'    => $fare,
                    'expense_remarks' => $remark,
                    'expense_image'   => $image ?? '',
                    'expense_date'    => $expense_date,
                    'user_id'         => 0,
                    'created_at'      => now(),
                    'company_id'      => $c['company_id'],
                    'paid_status'     => 0,
                    'dealer_id'       => $c['dealer_id'],
                ]);
                $inserted++;
            }
            if ($inserted === 0) { DB::rollBack(); return response()->json(['status' => false, 'message' => 'No valid data'], 422); }
            DB::commit();
            return response()->json(['status' => true, 'message' => "{$inserted} expense(s) saved"]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Save failed',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        $row = DB::table('user_expense_details')
            ->where('id', $request->id)
            ->where('company_id', $c['company_id'])->where('dealer_id', $c['dealer_id'])
            ->first();
        if (! $row) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        return response()->json([
            'status'  => true,
            'message' => 'Expense detail',
            'data'    => [
                'record'    => $row,
                'type_name' => DB::table('other_expense_type')->where('id', $row->expense_type)->value('name'),
            ],
        ]);
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        $id = $request->expense_id;
        $fare = $request->expense_fare;
        if (empty($id) || $fare === null || $fare === '' || $fare <= 0) {
            return response()->json(['status' => false, 'message' => 'Value required'], 422);
        }
        $row = DB::table('user_expense_details')->where('id', $id)
            ->where('company_id', $c['company_id'])->where('dealer_id', $c['dealer_id'])->first();
        if (! $row) return response()->json(['status' => false, 'message' => 'Not found'], 404);

        $image = $row->expense_image;
        if ($request->hasFile('expense_image') && $request->file('expense_image')->isValid()) {
            $file = $request->file('expense_image');
            $name = time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());
            $dest = public_path('uploads/expense');
            if (! file_exists($dest)) mkdir($dest, 0777, true);
            $file->move($dest, $name);
            $image = 'uploads/expense/' . $name;
        }

        DB::table('user_expense_details')->where('id', $id)->update([
            'expense_fare'    => $fare,
            'expense_remarks' => $request->expense_remarks,
            'expense_image'   => $image,
            'updated_at'      => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'Expense updated']);
    }
}