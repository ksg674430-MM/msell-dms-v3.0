<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDamageInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // add policy if needed
    }

    public function rules(): array
    {
        return [
            'ch_date'                  => 'nullable|string',
            'ch_no'                    => 'nullable|string',
            'retailer_id'              => 'required|integer',
            'for_self_retailer'        => 'required|in:0,1',
            'return_replace_status'    => 'required|in:0,1',
            'saleable_non_saleable'    => 'required|in:1,2',
            'discount_per'             => 'nullable|numeric|min:0|max:100',

            'items'                    => 'required|array|min:1',
            'items.*.product_id'       => 'required|integer',
            'items.*.qty'              => 'required|numeric|gt:0',
            'items.*.product_rate'     => 'required|numeric',
            'items.*.mrp'              => 'required|numeric',
            'items.*.taxable_amt'      => 'required|numeric',
            'items.*.free_qty'         => 'nullable|numeric',
            'items.*.batch_no'         => 'required|string',
            'items.*.mfg_date'         => 'required|string',
            'items.*.order_id'         => 'nullable',
        ];
    }
}