<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DamageInvoiceService
{
    public function __construct(
        protected InvoiceNumberService $numbers,
        protected StockService $stock,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  SAVE                                                              */
    /* ------------------------------------------------------------------ */
    public function save(array $data, $user): array
    {
        $dealerId     = (int) $user->dealer_id;
        $companyIdCus = (int) $user->company_id_cus;
        $userId       = (int) $user->id;

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? 1);
        $returnReplace       = (int) ($data['return_replace_status'] ?? 0);
        $forSelfRetailer     = (int) ($data['for_self_retailer'] ?? 1);
        $retailerId          = (int) ($data['retailer_id'] ?? 0);
        $storedRetailerId    = $forSelfRetailer === 0 ? 0 : $retailerId;

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        $chNo   = $data['ch_no'] ?? $this->numbers->next($dealerId, $companyIdCus)['ch_no'];
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? now());

        [$amount, $discPer, $discAmt, $finalAmount] = $this->totals($items, $data['discount_per'] ?? 0);

        DB::beginTransaction();
        try {
            // challan id (varchar) — matches old dealer_id . ymdHis pattern
            $challanId = $dealerId . date('ymdHis');

            // Guard against rare same-second collisions
            $attempt = 0;
            while (DB::table('damage_order')->where('id', $challanId)->exists()) {
                usleep(100_000);
                $challanId = $dealerId . date('ymdHis');
                if (++$attempt > 10) {
                    throw new \RuntimeException('Could not allocate challan id, please retry.');
                }
            }

            DB::table('damage_order')->insert([
                'id'                    => $challanId,
                'ch_no'                 => $chNo,
                'ch_created_by'         => $userId,
                'ch_dealer_id'          => $dealerId,
                'ch_retailer_id'        => $storedRetailerId,
                'ch_user_id'            => $userId,
                'ch_date'               => $chDate,
                'date_added'            => now(),
                'company_id'            => 1,
                'invoice_type'          => 4,
                'discount_per'          => $discPer,
                'discount_amt'          => $discAmt,
                'amount'                => $finalAmount,
                'remaining'             => $finalAmount,
                'damage_order_type_id'  => $saleableNonSaleable,
                'replace_return_id'     => $returnReplace,
            ]);

            $this->insertDetails($challanId, $items, $userId);

            if ($returnReplace === 0) {
                $this->stock->apply($items, $dealerId, $saleableNonSaleable, $forSelfRetailer);
            }

            $this->markSaleOrders($items);

            DB::commit();

            return [
                'status'                => true,
                'challan_id'            => $challanId,
                'ch_no'                 => $chNo,
                'discount_per'          => $discPer,
                'discount_amt'          => $discAmt,
                'total_before_discount' => $amount,
                'amount'                => $finalAmount,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Damage invoice save failed', [
                'err' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  UPDATE                                                            */
    /* ------------------------------------------------------------------ */
    public function update(string $id, array $data, $user): array
    {
        $existing = DB::table('damage_order')->where('id', $id)->first();
        if (!$existing) {
            throw new ModelNotFoundException('Damage invoice not found.');
        }

        $dealerId = (int) $existing->ch_dealer_id;
        $userId   = (int) $user->id;

        $oldSaleable      = (int) $existing->damage_order_type_id;
        $oldReturnReplace = (int) $existing->replace_return_id;
        $oldForSelf       = ((int) $existing->ch_retailer_id === 0) ? 0 : 1;

        $oldItems = DB::table('damage_order_details')->where('ch_id', $id)->get()->all();

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? $oldSaleable);
        $returnReplace       = (int) ($data['return_replace_status'] ?? $oldReturnReplace);
        $forSelfRetailer     = (int) ($data['for_self_retailer'] ?? $oldForSelf);
        $retailerId          = (int) ($data['retailer_id'] ?? (int) $existing->ch_retailer_id);
        $storedRetailerId    = $forSelfRetailer === 0 ? 0 : $retailerId;

        $items = $data['items'] ?? [];
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        $chNo   = $data['ch_no'] ?? $existing->ch_no;
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? $existing->ch_date);

        [$amount, $discPer, $discAmt, $finalAmount] = $this->totals($items, $data['discount_per'] ?? 0);

        DB::beginTransaction();
        try {
            // Reverse previous stock effect
            if ($oldReturnReplace === 0 && !empty($oldItems)) {
                $this->stock->reverse($oldItems, $dealerId, $oldSaleable, $oldForSelf);
            }

            // Replace details
            DB::table('damage_order_details')->where('ch_id', $id)->delete();
            $this->insertDetails($id, $items, $userId);

            // Update header
            DB::table('damage_order')->where('id', $id)->update([
                'ch_no'                 => $chNo,
                'ch_created_by'         => $userId,
                'ch_retailer_id'        => $storedRetailerId,
                'ch_date'               => $chDate,
                'discount_per'          => $discPer,
                'discount_amt'          => $discAmt,
                'amount'                => $finalAmount,
                'remaining'             => $finalAmount,
                'damage_order_type_id'  => $saleableNonSaleable,
                'replace_return_id'     => $returnReplace,
            ]);

            // Apply new stock effect
            if ($returnReplace === 0) {
                $this->stock->apply($items, $dealerId, $saleableNonSaleable, $forSelfRetailer);
            }

            $this->markSaleOrders($items);

            DB::commit();

            return [
                'status'                => true,
                'challan_id'            => $id,
                'ch_no'                 => $chNo,
                'discount_per'          => $discPer,
                'discount_amt'          => $discAmt,
                'total_before_discount' => $amount,
                'amount'                => $finalAmount,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Damage invoice update failed', [
                'id'  => $id,
                'err' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  DELETE  (also reverse stock)                                      */
    /* ------------------------------------------------------------------ */
    public function delete(string $id, $user): void
    {
        $existing = DB::table('damage_order')->where('id', $id)->first();
        if (!$existing) {
            throw new ModelNotFoundException('Damage invoice not found.');
        }

        DB::beginTransaction();
        try {
            if ((int) $existing->replace_return_id === 0) {
                $oldItems   = DB::table('damage_order_details')->where('ch_id', $id)->get()->all();
                $forSelf    = ((int) $existing->ch_retailer_id === 0) ? 0 : 1;
                if (!empty($oldItems)) {
                    $this->stock->reverse(
                        $oldItems,
                        (int) $existing->ch_dealer_id,
                        (int) $existing->damage_order_type_id,
                        $forSelf
                    );
                }
            }

            DB::table('damage_order_details')->where('ch_id', $id)->delete();
            DB::table('damage_order')->where('id', $id)->delete();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  LIST                                                              */
    /* ------------------------------------------------------------------ */
    public function list(array $filters, $user): array
    {
        $q = DB::table('damage_order')
            ->select([
                'damage_order.id',
                'damage_order.ch_no',
                'damage_order.ch_retailer_id',
                'damage_order.ch_user_id',
                'damage_order.ch_date',
                'damage_order.date_added',
                'damage_order.discount_per',
                'damage_order.discount_amt',
                'damage_order.amount',
                'damage_order.damage_order_type_id',
                'damage_order.replace_return_id',
                'damage_order.by_status',
            ]);

        if (!empty($filters['from'])) {
            $q->where('ch_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->where('ch_date', '<=', $filters['to']);
        }
        if (!empty($filters['retailer_id'])) {
            $q->where('ch_retailer_id', $filters['retailer_id']);
        }
        if (!empty($filters['return_replace_status'])) {
            $q->where('replace_return_id', $filters['return_replace_status']);
        }

        $rows = $q->orderByDesc('auto')
            ->paginate((int) ($filters['per_page'] ?? 25));

        // Attach retailer names
        $retailerIds = collect($rows->items())->pluck('ch_retailer_id')->filter()->unique()->all();
        $retailers   = $retailerIds
            ? DB::table('retailer')->whereIn('id', $retailerIds)->pluck('name', 'id')->all()
            : [];

        foreach ($rows->items() as $r) {
            $r->retailer_name = $retailers[$r->ch_retailer_id] ?? null;
        }

        return $rows->toArray();
    }

    /* ------------------------------------------------------------------ */
    /*  SHOW                                                              */
    /* ------------------------------------------------------------------ */
    public function show(string $id): array
    {
        $header = DB::table('damage_order')
            ->where('id', $id)
            ->first();

        if (!$header) {
            throw new ModelNotFoundException('Damage invoice not found.');
        }

        $items = DB::table('damage_order_details as dod')
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'dod.product_id')
            ->where('dod.ch_id', $id)
            ->get([
                'dod.*',
                'cp.name as product_name',
                'cp.hsn_code as product_hsn_code',
            ]);

        return [
            'header' => $header,
            'items'  => $items,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  HELPERS                                                           */
    /* ------------------------------------------------------------------ */
    protected function insertDetails(string $challanId, array $items, int $userId): void
    {
        $rows = [];
        foreach ($items as $it) {
            $rows[] = [
                'company_id'         => 0,
                'ch_id'              => $challanId,
                'product_id'         => (int) $it['product_id'],
                'supply_status'      => (int) ($it['bos'] ?? 0),
                'hsn_code'           => (string) ($it['hsn_code'] ?? ''),
                'catalog_details_id' => 0,
                'batch_no'           => (string) ($it['batch_no'] ?? ''),
                'mfg_date'           => $this->normalizeDate($it['mfg_date'] ?? '1970-01-01'),
                'tax'                => (float) ($it['tax'] ?? 0),
                'vat_amt'            => (float) ($it['vat_amt'] ?? 0),
                'qty'                => (float) $it['qty'],
                'product_rate'       => (float) $it['product_rate'],
                'free_qty'           => (string) ($it['free_qty'] ?? '0'),
                'order_id'           => (int) ($it['order_id'] ?? 0),
                'user_id'            => $userId,
                'mrp'                => (float) $it['mrp'],
                'cd'                 => (float) ($it['cd'] ?? 0),
                'cd_type'            => (int) ($it['cd_type'] ?? 1),
                'cd_amt'             => (float) ($it['cd_amt'] ?? 0),
                'spl_disc_type'      => (int) ($it['spl_disc_type'] ?? 1),
                'spl_disc_val'       => (float) ($it['spl_disc_val'] ?? 0),
                'spl_disc_amt'       => (float) ($it['spl_disc_amt'] ?? 0),
                'dis_type'           => (int) ($it['dis_type'] ?? 1),
                'dis_amt'            => (float) ($it['dis_amt'] ?? 0),
                'dis_percent'        => (float) ($it['dis_percent'] ?? 0),
                'taxable_amt'        => (float) ($it['taxable_amt'] ?? 0),
                'remain_amount'      => (string) ($it['taxable_amt'] ?? 0),
                'sale_order_id'      => '',
            ];
        }
        DB::table('damage_order_details')->insert($rows);
    }

    protected function markSaleOrders(array $items): void
    {
        foreach ($items as $it) {
            if (!empty($it['order_id'])) {
                DB::table('user_sales_order_details')
                    ->where('order_id', $it['order_id'])
                    ->where('product_id', $it['product_id'])
                    ->update(['status' => 2]);
            }
        }
    }

    /**
     * Mirrors the frontend: taxable_amt is sent per item; header amount
     * = sum(taxable_amt) - discount.
     */
    protected function totals(array $items, $discountPer): array
{
    $amount = 0.0;
    foreach ($items as $it) {
        // 'taxable_amt' from frontend = taxable amount AFTER all discounts,
        // but NOT including tax. Amount = taxable + tax.
        $taxable = (float) ($it['taxable_amt'] ?? 0);
        $vatAmt  = (float) ($it['vat_amt'] ?? 0);
        $amount += $taxable + $vatAmt;   // <-- matches JS .amount sum
    }

    $discPer = (float) $discountPer;
    $discAmt = round($amount * $discPer / 100, 2);
    $final   = round($amount - $discAmt, 2);

    return [$amount, $discPer, $discAmt, $final];
}

    protected function normalizeDate(?string $d): string
    {
        if (empty($d)) {
            return '1970-01-01';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $d;
        }
        $ts = strtotime(str_replace('/', '-', $d));
        return $ts ? date('Y-m-d', $ts) : '1970-01-01';
    }

    protected function normalizeDateTime($d): string
    {
        if ($d instanceof \DateTimeInterface) {
            return $d->format('Y-m-d H:i:s');
        }
        if (empty($d)) {
            return now()->format('Y-m-d H:i:s');
        }
        $ts = strtotime(str_replace('/', '-', (string) $d));
        return $ts ? date('Y-m-d H:i:s', $ts) : now()->format('Y-m-d H:i:s');
    }
}