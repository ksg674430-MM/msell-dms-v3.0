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

    public static function companyId($user): int
    {
        return (int) ($user->company_id_cus ?? $user->company_id ?? 0);
    }

    /* ------------------------------------------------------------------ */
    /*  SAVE                                                              */
    /* ------------------------------------------------------------------ */
    public function save(array $data, $user): array
    {
        $dealerId     = (int) $user->dealer_id;
        $companyIdCus = self::companyId($user);
        $userId       = (int) $user->getKey();

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? 1);
        $returnReplace       = (int) ($data['return_replace_status'] ?? 0);
        $forSelfRetailer     = (int) ($data['for_self_retailer'] ?? 1);
        $retailerId          = (string) ($data['retailer_id'] ?? '0');
        $storedRetailerId    = $forSelfRetailer === 0 ? '0' : $retailerId;

        $this->assertRetailer($forSelfRetailer, $retailerId, $dealerId);

        $calc  = $this->compute($data['items'] ?? [], $data['discount_per'] ?? 0, $this->checkGst($user, $forSelfRetailer, $retailerId), $companyIdCus);
        $items = $calc['items'];

        $chNo   = $this->numbers->next($dealerId, $companyIdCus)['ch_no'];
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? now());

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
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'amount'                => $calc['total_amount_a'],
                'remaining'             => $calc['total_amount_a'],
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
                'message'               => 'Damage invoice saved successfully.',
                'challan_id'            => $challanId,
                'ch_no'                 => $chNo,
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'total_before_discount' => $calc['total'],
                'amount'                => $calc['total_amount_a'],
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
        $existing = $this->findOwned($id, $user);

        $dealerId     = (int) $existing->ch_dealer_id;
        $companyIdCus = self::companyId($user);
        $userId       = (int) $user->getKey();

        $oldSaleable      = (int) $existing->damage_order_type_id;
        $oldReturnReplace = (int) $existing->replace_return_id;
        $oldForSelf       = ((string) $existing->ch_retailer_id === '0') ? 0 : 1;

        $oldItems = DB::table('damage_order_details')->where('ch_id', $id)->get()->all();

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? $oldSaleable);
        $returnReplace       = (int) ($data['return_replace_status'] ?? $oldReturnReplace);
        $forSelfRetailer     = (int) ($data['for_self_retailer'] ?? $oldForSelf);
        $retailerId          = (string) ($data['retailer_id'] ?? $existing->ch_retailer_id);
        $storedRetailerId    = $forSelfRetailer === 0 ? '0' : $retailerId;

        $this->assertRetailer($forSelfRetailer, $retailerId, $dealerId);

        $calc  = $this->compute($data['items'] ?? [], $data['discount_per'] ?? 0, $this->checkGst($user, $forSelfRetailer, $retailerId), $companyIdCus);
        $items = $calc['items'];

        $chDate = $this->normalizeDateTime($data['ch_date'] ?? $existing->ch_date);

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
                'ch_created_by'         => $userId,
                'ch_retailer_id'        => $storedRetailerId,
                'ch_date'               => $chDate,
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'amount'                => $calc['total_amount_a'],
                'remaining'             => $calc['total_amount_a'],
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
                'ch_no'                 => $existing->ch_no,
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'total_before_discount' => $calc['total'],
                'amount'                => $calc['total_amount_a'],
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
        $existing = $this->findOwned($id, $user);

        DB::beginTransaction();
        try {
            if ((int) $existing->replace_return_id === 0) {
                $oldItems   = DB::table('damage_order_details')->where('ch_id', $id)->get()->all();
                $forSelf    = ((string) $existing->ch_retailer_id === '0') ? 0 : 1;
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
            ->leftJoin('saleable_non_saleable as sns', 'sns.id', '=', 'damage_order.damage_order_type_id')
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
                'sns.name as damage_type',
            ])
            ->where('damage_order.ch_dealer_id', (int) $user->dealer_id);

        if (!empty($filters['from'])) {
            $q->whereDate('damage_order.ch_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('damage_order.ch_date', '<=', $filters['to']);
        }
        if (!empty($filters['retailer_id'])) {
            $q->where('damage_order.ch_retailer_id', (string) $filters['retailer_id']);
        }
        if (isset($filters['return_replace_status']) && $filters['return_replace_status'] !== '') {
            $q->where('damage_order.replace_return_id', (int) $filters['return_replace_status']);
        }
        if (!empty($filters['ch_no'])) {
            $q->where('damage_order.ch_no', 'like', '%' . $filters['ch_no'] . '%');
        }

        $rows = $q->orderByDesc('damage_order.auto')
            ->paginate(min(1000, max(1, (int) ($filters['per_page'] ?? 25))));

        $orders      = collect($rows->items());
        $retailerIds = $orders->pluck('ch_retailer_id')->map(fn ($v) => (string) $v)->filter(fn ($v) => $v !== '' && $v !== '0')->unique()->all();
        $retailers   = $retailerIds
            ? DB::table('retailer')->whereIn('id', $retailerIds)->pluck('name', 'id')->all()
            : [];

        $items = $orders->isEmpty() ? collect() : DB::table('damage_order_details as dod')
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'dod.product_id')
            ->whereIn('dod.ch_id', $orders->pluck('id')->map(fn ($v) => (string) $v)->all())
            ->orderBy('dod.id')
            ->get(['dod.ch_id', 'dod.product_id', 'cp.name', 'dod.product_rate', 'dod.qty', 'dod.free_qty', 'dod.vat_amt', 'dod.taxable_amt'])
            ->groupBy(fn ($r) => (string) $r->ch_id);

        foreach ($orders as $r) {
            $r->retailer_name = $retailers[(string) $r->ch_retailer_id] ?? null;
            $r->items         = $items[(string) $r->id] ?? [];
        }

        return $rows->toArray();
    }

    /* ------------------------------------------------------------------ */
    /*  SHOW                                                              */
    /* ------------------------------------------------------------------ */
    public function show(string $id, $user): array
    {
        $header = $this->findOwned($id, $user);
        $header->retailer_name = DB::table('retailer')->where('id', (string) $header->ch_retailer_id)->value('name');
        $header->damage_type   = DB::table('saleable_non_saleable')->where('id', $header->damage_order_type_id)->value('name');

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

    public function checkGst($user, int $forSelfRetailer, $retailerId): int
    {
        if (self::companyId($user) === 213 || $forSelfRetailer === 0) {
            return 1;
        }
        $retailerState = DB::table('retailer')
            ->join('location_view', 'location_view.l7_id', '=', 'retailer.location_id')
            ->where('retailer.id', (string) $retailerId)
            ->value('location_view.l3_id');
        return (string) $retailerState === (string) $user->state_id ? 1 : 0;
    }

    public function compute(array $items, $discountPer, int $checkGst, int $companyIdCus): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        $productIds = array_values(array_unique(array_map(fn ($it) => (int) $it['product_id'], $items)));
        $products   = DB::table('catalog_product as cp')
            ->leftJoin('_gst as t', 't.hsn_code', '=', 'cp.hsn_code')
            ->whereIn('cp.id', $productIds)
            ->where('cp.company_id', $companyIdCus)
            ->get(['cp.id', 'cp.hsn_code', DB::raw('COALESCE(t.igst, 0) as igst')])
            ->keyBy('id');

        $rows = [];
        $seen = [];
        $totTd = $totSpl = $totCd = $totTaxable = $totVat = $totAmount = 0.0;

        foreach ($items as $idx => $it) {
            $n    = $idx + 1;
            $pid  = (int) $it['product_id'];
            $prod = $products[$pid] ?? null;
            if (!$prod) {
                throw new \InvalidArgumentException("Item #{$n}: product not found.");
            }

            $qty  = (float) $it['qty'];
            $rate = (float) $it['product_rate'];
            $mrp  = (float) $it['mrp'];
            $tax  = (float) $prod->igst;

            if ($qty <= 0) {
                throw new \InvalidArgumentException("Item #{$n}: quantity must be greater than 0.");
            }
            if ($rate < 0) {
                throw new \InvalidArgumentException("Item #{$n}: rate cannot be negative.");
            }

            $key = $pid . '|' . $mrp;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Item #{$n}: the same product and MRP is already added.");
            }
            $seen[$key] = true;

            $disType = (int) ($it['dis_type'] ?? 1);
            $disVal  = (float) ($it['dis_val'] ?? 0);
            $spType  = (int) ($it['spl_disc_type'] ?? 1);
            $spVal   = (float) ($it['spl_disc_val'] ?? 0);
            $cdType  = (int) ($it['cd_type'] ?? 1);
            $cdVal   = (float) ($it['cd'] ?? 0);
            foreach ([[$disType, $disVal, 'trade discount'], [$spType, $spVal, 'special scheme discount'], [$cdType, $cdVal, 'ATD']] as [$t, $v, $label]) {
                if ($v < 0 || ($t === 1 && $v > 100)) {
                    throw new \InvalidArgumentException("Item #{$n}: {$label} must be between 0 and 100%.");
                }
            }

            $gross      = $qty * $rate;
            $tradeAmt   = $disType === 1 ? $gross * $disVal / 100 : $disVal;
            $afterTrade = $gross - $tradeAmt;
            $spAmt      = $spType === 1 ? $afterTrade * $spVal / 100 : $spVal;
            $afterSp    = $afterTrade - $spAmt;
            $cdAmt      = $cdType === 1 ? $afterSp * $cdVal / 100 : $cdVal;
            $taxable    = round($afterSp - $cdAmt, 2);

            if ($taxable < 0) {
                throw new \InvalidArgumentException("Item #{$n}: discounts cannot exceed the item value.");
            }

            if ($checkGst === 1) {
                $half   = round($taxable * ($tax / 2) / 100, 2);
                $cgst   = $half;
                $sgst   = $half;
                $igst   = 0.0;
                $vatAmt = round($half * 2, 2);
            } else {
                $cgst   = $sgst = 0.0;
                $igst   = round($taxable * $tax / 100, 2);
                $vatAmt = $igst;
            }
            $amount = round($taxable + $vatAmt, 2);

            $rows[] = [
                'product_id'     => $pid,
                'hsn_code'       => (string) $prod->hsn_code,
                'mrp'            => $mrp,
                'qty'            => $qty,
                'free_qty'       => (float) ($it['free_qty'] ?? 0),
                'product_rate'   => $rate,
                'batch_no'       => (string) ($it['batch_no'] ?? ''),
                'mfg_date'       => (string) ($it['mfg_date'] ?? ''),
                'order_id'       => $it['order_id'] ?? null,
                'tax'            => $tax,
                'dis_type'       => $disType,
                'dis_percent'    => $disType === 1 ? $disVal : 0,
                'dis_amt'        => round($tradeAmt, 2),
                'spl_disc_type'  => $spType,
                'spl_disc_val'   => $spVal,
                'spl_disc_amt'   => round($spAmt, 2),
                'cd_type'        => $cdType,
                'cd'             => $cdVal,
                'cd_amt'         => round($cdAmt, 2),
                'taxable_amt'    => $taxable,
                'cgst'           => $checkGst ? round($tax / 2, 2) : 0,
                'cgst_amt'       => $cgst,
                'sgst'           => $checkGst ? round($tax / 2, 2) : 0,
                'sgst_amt'       => $sgst,
                'igst'           => $checkGst ? 0 : $tax,
                'igst_amt'       => $igst,
                'vat_amt'        => $vatAmt,
                'amount'         => $amount,
            ];

            $totTd      += $tradeAmt;
            $totSpl     += $spAmt;
            $totCd      += $cdAmt;
            $totTaxable += $taxable;
            $totVat     += $vatAmt;
            $totAmount  += $amount;
        }

        $discPer = (float) $discountPer;
        if ($discPer < 0 || $discPer > 100) {
            throw new \InvalidArgumentException('Discount must be between 0 and 100%.');
        }
        $discAmt = round($totAmount * $discPer / 100, 2);
        $final   = round($totAmount - $discAmt, 2);

        return [
            'items'          => $rows,
            'total_td'       => round($totTd, 2),
            'total_spl'      => round($totSpl, 2),
            'total_cd'       => round($totCd, 2),
            'total_taxable'  => round($totTaxable, 2),
            'total_vat'      => round($totVat, 2),
            'total'          => round($totAmount, 2),
            'discount_per'   => $discPer,
            'discount_amt'   => $discAmt,
            'total_amount_a' => $final,
            'final_amount'   => (int) round($final),
            'check_gst'      => $checkGst,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  HELPERS                                                           */
    /* ------------------------------------------------------------------ */
    protected function findOwned(string $id, $user)
    {
        $row = DB::table('damage_order')
            ->where('id', $id)
            ->where('ch_dealer_id', (int) $user->dealer_id)
            ->first();
        if (!$row) {
            throw new ModelNotFoundException('Damage invoice not found.');
        }
        return $row;
    }

    protected function assertRetailer(int $forSelfRetailer, string $retailerId, int $dealerId): void
    {
        if ($forSelfRetailer === 0) {
            return;
        }
        $ok = $retailerId !== '' && $retailerId !== '0'
            && DB::table('retailer')->where('id', $retailerId)->where('dealer_id', $dealerId)->exists();
        if (!$ok) {
            throw new \InvalidArgumentException('Select a retailer from your retailer list.');
        }
    }

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
                'taxable_amt'        => (float) $it['amount'],
                'remain_amount'      => (string) $it['amount'],
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
