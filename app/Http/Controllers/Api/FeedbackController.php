<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
    public function types(): JsonResponse
    {
        return response()->json(['status' => true, 'data' => DB::table('_feedback_type')->orderBy('id')->get(['id', 'name'])]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('feedback as f')
            ->join('_feedback_type as t', 't.id', '=', 'f.feedback_type')
            ->where('f.dealer_id', (int) $request->user()->dealer_id)
            ->orderByDesc('f.id');

        if ($no = trim((string) $request->query('feedback_no'))) {
            $q->where('f.feedback_no', 'like', "%{$no}%");
        }

        return response()->json(['status' => true, 'data' => $q->get(['f.id', 'f.feedback_no', 'f.feedback_type', 't.name as feedback_type_name', 'f.feedback', 'f.date'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data     = $this->validated($request);
        $dealerId = (int) $request->user()->dealer_id;

        DB::table('feedback')->insert([
            'dealer_id'        => $dealerId,
            'date'             => now(),
            'feedback_no'      => $dealerId . now()->format('YmdHis'),
            'feedback_type'    => $data['feedback_type'],
            'feedback'         => $data['feedback'],
            'status'           => 1,
            'server_date_time' => now(),
        ]);

        return response()->json(['status' => true, 'message' => 'Feedback successfully saved.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validated($request);

        $updated = DB::table('feedback')
            ->where('id', $id)
            ->where('dealer_id', (int) $request->user()->dealer_id)
            ->update([
                'feedback_type'    => $data['feedback_type'],
                'feedback'         => $data['feedback'],
                'server_date_time' => now(),
            ]);

        if (!$updated && !DB::table('feedback')->where('id', $id)->where('dealer_id', (int) $request->user()->dealer_id)->exists()) {
            return response()->json(['status' => false, 'message' => 'Feedback not found.'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Feedback successfully updated.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'feedback_type' => ['required', 'integer', 'exists:_feedback_type,id'],
            'feedback'      => ['required', 'string', 'max:5000'],
        ], [
            'feedback_type.required' => 'Please select the title.',
            'feedback.required'      => 'Please enter the feedback.',
        ]);
    }
}
