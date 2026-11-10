<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RateList
{
    private function __construct(private int $companyId, private int $templateId, private int $stateId)
    {
    }

    public static function forDealer(int $dealerId, int $companyId): self
    {
        $dealer = DB::table('dealer')->where('id', $dealerId)->first(['state_id', 'csa_id', 'template_id']);
        $stateId = (int) ($dealer->state_id ?? 0) ?: (int) DB::table('csa')->where('c_id', $dealer->csa_id ?? 0)->value('state_id');

        return new self($companyId, (int) ($dealer->template_id ?? 0), $stateId);
    }

    public function row(int $productId): ?object
    {
        return $this->rows([$productId])->get($productId);
    }

    public function rows(array $productIds): Collection
    {
        $found = collect();
        if ($this->templateId > 0) {
            $found = $this->pick($this->query('product_rate_list_template', $productIds)->where('prl.template_type', $this->templateId));
        }
        if ($this->stateId > 0) {
            $found = $found->union($this->pick($this->query('product_rate_list', $productIds)->where('prl.state_id', $this->stateId)->whereNotIn('prl.product_id', $found->keys())));
        }

        return $found->union($this->pick($this->query('product_rate_list', $productIds)->whereNotIn('prl.product_id', $found->keys())));
    }

    private function query(string $table, array $productIds)
    {
        return DB::table("{$table} as prl")
            ->join('catalog_product as cp', 'cp.id', '=', 'prl.product_id')
            ->where('cp.company_id', $this->companyId)
            ->whereIn('prl.product_id', $productIds)
            ->orderByRaw('prl.status = 1 DESC, prl.id DESC')
            ->select('prl.*');
    }

    private function pick($query): Collection
    {
        return $query->get()->unique('product_id')->keyBy('product_id');
    }
}
