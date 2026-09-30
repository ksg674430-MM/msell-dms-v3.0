<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discount_per'             => 'nullable|numeric|min:0|max:100',
            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|integer',
            'items.*.mrp'              => 'required|numeric',
            'items.*.qty'              => 'required|numeric',
            'items.*.product_rate'     => 'required|numeric',
            'items.*.cd'               => 'nullable|numeric',
            'items.*.cd_type'          => 'nullable|in:1,2',
            'items.*.spl_disc_type'    => 'nullable|in:1,2',
            'items.*.spl_disc_val'     => 'nullable|numeric',
            'items.*.dis_type'         => 'nullable|in:1,2',
            'items.*.dis_val'          => 'nullable|numeric',
            'items.*.tax'              => 'nullable|numeric',
        ];
    }
}