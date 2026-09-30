<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'table' => 'prohibited', 'db' => 'prohibited',
            'schema' => 'prohibited', 'connection' => 'prohibited', 'model' => 'prohibited',

            'check_gst'    => 'nullable|in:0,1',
            'discount_per' => 'nullable|numeric|min:0|max:100',
            'items'        => 'required|array|min:1',
            'items.*.product_id'    => 'required|integer',
            'items.*.mrp'           => 'required|numeric',
            'items.*.qty'           => 'required|numeric|gt:0',
            'items.*.product_rate'  => 'required|numeric',
            'items.*.tax'           => 'nullable|numeric|min:0|max:100',
            'items.*.cd'            => 'nullable|numeric|min:0',
            'items.*.cd_type'       => 'nullable|in:1,2',
            'items.*.cash_amt'      => 'nullable|numeric|min:0',
            'items.*.spl_disc_type' => 'nullable|in:1,2',
            'items.*.spl_disc_val'  => 'nullable|numeric|min:0',
            'items.*.dis_type'      => 'nullable|in:1,2',
            'items.*.dis_val'       => 'nullable|numeric|min:0',
        ];
    }
}