<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'table'      => 'prohibited',
            'db'         => 'prohibited',
            'schema'     => 'prohibited',
            'connection' => 'prohibited',

            'current_password' => 'required|string|min:1',
            'new_password'     => 'required|string|min:4|max:50',
            'confirm_password' => 'required|string|same:new_password',
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Please enter the current password.',
            'new_password.required'     => 'Please enter the new password.',
            'confirm_password.required' => 'Please enter the verify password.',
            'confirm_password.same'     => 'New password & verify password do not match.',
        ];
    }
}