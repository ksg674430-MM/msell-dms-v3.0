<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
{
    $user = $request->user();
    $dpId = (int) $user->getKey();   // ← the fix

    $profile = DB::selectOne(
        "SELECT dpId, person_name, uname, email, phone, profile_pic,
                dealer_id, company_id, state_id, regis_status
           FROM dealer_person_login
          WHERE dpId = ?
          LIMIT 1",
        [$dpId]
    );

    return response()->json([
        'status'  => true,
        'profile' => $profile,
    ]);
}

    /**
     * POST /api/profile
     * Body (multipart/form-data if uploading image, else JSON):
     *   full_name, email_id, phone
     *   image (optional file)
     */
public function update(Request $request): JsonResponse
{
    $request->validate([
        'full_name' => ['required', 'string', 'max:150'],
        'email_id'  => ['required', 'email', 'max:150'],
        'phone'     => ['required', 'string', 'max:20'],
        'image'     => ['nullable', 'image', 'max:5120'],
    ]);

    $user = $request->user();
    $dpId = (int) $user->getKey();   // ← the fix

    $update = [
        'person_name' => $request->input('full_name'),
        'email'       => $request->input('email_id'),
        'phone'       => $request->input('phone'),
    ];

    if ($request->hasFile('image')) {
        $file = $request->file('image');
        $name = 'user_' . $dpId . '_' . time() . '.' . $file->getClientOriginalExtension();
        $dir  = public_path('myuploads/profile_images');

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file->move($dir, $name);
        $update['profile_pic'] = $name;
    }

    DB::table('dealer_person_login')
        ->where('dpId', $dpId)
        ->update($update);

    return response()->json([
        'status'  => true,
        'message' => 'Profile updated successfully.',
    ]);
}
}