<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DamageInvoiceItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'product_id'        => $this->product_id,
            'product_name'      => $this->product_name ?? null,
            'hsn_code'          => $this->hsn_code,
            'batch_no'          => $this->batch_no,
            'mfg_date'          => $this->mfg_date,
            'qty'               => $this->qty,
            'free_qty'          => $this->free_qty,
            'mrp'               => $this->mrp,
            'product_rate'      => $this->product_rate,
            'tax'               => $this->tax,
            'vat_amt'           => $this->vat_amt,
            'cd'                => $this->cd,
            'cd_type'           => $this->cd_type,
            'cd_amt'            => $this->cd_amt,
            'spl_disc_type'     => $this->spl_disc_type,
            'spl_disc_val'      => $this->spl_disc_val,
            'spl_disc_amt'      => $this->spl_disc_amt,
            'dis_type'          => $this->dis_type,
            'dis_amt'           => $this->dis_amt,
            'dis_percent'       => $this->dis_percent,
            'taxable_amt'       => $this->taxable_amt,
        ];
    }
}