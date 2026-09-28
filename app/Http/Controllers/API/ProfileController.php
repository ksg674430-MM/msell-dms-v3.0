<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = DB::selectOne(
            "SELECT name, landline, other_numbers, email, address, pin_no, tin_no, pan_no,
                    over_due_amount, over_due_days
               FROM dealer
              WHERE id = ?
              LIMIT 1",
            [(int) $request->user()->dealer_id]
        );

        if (!$profile) {
            return response()->json(['status' => false, 'message' => 'Distributor not found.'], 404);
        }

        return response()->json(['status' => true, 'profile' => $profile]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'landline'        => ['nullable', 'string', 'max:50'],
            'other_numbers'   => ['nullable', 'digits:10'],
            'email'           => ['nullable', 'email', 'max:50'],
            'address'         => ['nullable', 'string', 'max:200'],
            'pin_no'          => ['nullable', 'digits:6'],
            'tin_no'          => ['nullable', 'string', 'max:15'],
            'pan_no'          => ['nullable', 'string', 'max:15'],
            'over_due_amount' => ['nullable', 'numeric', 'min:0'],
            'over_due_days'   => ['nullable', 'integer', 'min:0'],
        ], [
            'other_numbers.digits' => 'Mobile No. must be 10 digits.',
            'pin_no.digits'        => 'Pin Code must be 6 digits.',
        ]);

        DB::table('dealer')
            ->where('id', (int) $request->user()->dealer_id)
            ->update([
                'name'            => trim($validated['name']),
                'landline'        => $validated['landline'] ?? '',
                'other_numbers'   => $validated['other_numbers'] ?? '',
                'email'           => $validated['email'] ?? '',
                'address'         => $validated['address'] ?? '',
                'pin_no'          => $validated['pin_no'] ?? '0',
                'tin_no'          => strtoupper($validated['tin_no'] ?? '') ?: '0',
                'pan_no'          => strtoupper($validated['pan_no'] ?? ''),
                'over_due_amount' => (string) ($validated['over_due_amount'] ?? 0),
                'over_due_days'   => (string) ($validated['over_due_days'] ?? 0),
            ]);

        return response()->json(['status' => true, 'message' => 'Profile successfully Updated.']);
    }
}
