<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DepotDamageInvoiceService
{
    protected string $headerTable  = 'damage_order_depot';
    protected string $detailsTable = 'damage_order_depot_details';

    public function __construct(
        protected InvoiceNumberService $numbers,
        protected StockService $stock,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  SAVE                                                              */
    /* ------------------------------------------------------------------ */
    public function save(array $data, $user): array
    {
        $dealerId = (int) $user->dealer_id;
        $userId   = (int) $user->id;

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? 1);
        $csaId               = (int) ($data['csa_id'] ?? 0);
        $items               = $data['items'] ?? [];

        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
        if ($csaId <= 0) {
            throw new \InvalidArgumentException('Depot (CSA) is required.');
        }

        $chNo   = $data['ch_no'] ?? $this->numbers->next($dealerId, (int) $user->company_id_cus, null, $this->headerTable)['ch_no'];
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? now());
        $discPer = (float) ($data['discount_per'] ?? 0);

        [$amount, $discAmt, $finalAmount] = $this->totals($items, $discPer);

        DB::beginTransaction();
        try {
            $challanId = $dealerId . date('ymdHis');
            $attempt = 0;
            while (DB::table($this->headerTable)->where('id', $challanId)->exists()) {
                usleep(100_000);
                $challanId = $dealerId . date('ymdHis');
                if (++$attempt > 10) {
                    throw new \RuntimeException('Could not allocate challan id, retry.');
                }
            }

            DB::table($this->headerTable)->insert([
                'id'                    => $challanId,
                'ch_no'                 => $chNo,
                'ch_created_by'         => $userId,
                'ch_dealer_id'          => $dealerId,
                'ch_csa_id'             => $csaId,
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
            ]);

            $this->insertDetails($challanId, $items, $userId);

            // Stock rules — same as dealer, minus the "self" adjustment
            // Depot is always treated as CSA-side (not self), so:
            //   saleable (1)      → qty += qtyy, salable_damage += qtyy
            //   non-saleable (2)  → qty -= qtyy (must be enough), nonsalable_damage += qtyy
            $this->stock->apply($items, $dealerId, $saleableNonSaleable, 0);

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
            Log::error('Depot damage invoice save failed', ['err' => $e->getMessage()]);
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  UPDATE                                                            */
    /* ------------------------------------------------------------------ */
    public function update(string $id, array $data, $user): array
    {
        $existing = DB::table($this->headerTable)->where('id', $id)->first();
        if (!$existing) {
            throw new ModelNotFoundException('Depot damage invoice not found.');
        }

        $dealerId = (int) $existing->ch_dealer_id;
        $userId   = (int) $user->id;
        $oldType  = (int) $existing->damage_order_type_id;
        $oldItems = DB::table($this->detailsTable)->where('ch_id', $id)->get()->all();

        $saleableNonSaleable = (int) ($data['saleable_non_saleable'] ?? $oldType);
        $csaId               = (int) ($data['csa_id'] ?? (int) $existing->ch_csa_id);
        $items               = $data['items'] ?? [];
        $discPer             = (float) ($data['discount_per'] ?? 0);

        if (empty($items)) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        $chNo   = $data['ch_no'] ?? $existing->ch_no;
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? $existing->ch_date);

        [$amount, $discAmt, $finalAmount] = $this->totals($items, $discPer);

        DB::beginTransaction();
        try {
            if (!empty($oldItems)) {
                $this->stock->reverse($oldItems, $dealerId, $oldType, 0);
            }

            DB::table($this->detailsTable)->where('ch_id', $id)->delete();
            $this->insertDetails($id, $items, $userId);

            DB::table($this->headerTable)->where('id', $id)->update([
                'ch_no'                 => $chNo,
                'ch_created_by'         => $userId,
                'ch_csa_id'             => $csaId,
                'ch_date'               => $chDate,
                'discount_per'          => $discPer,
                'discount_amt'          => $discAmt,
                'amount'                => $finalAmount,
                'remaining'             => $finalAmount,
                'damage_order_type_id'  => $saleableNonSaleable,
            ]);

            $this->stock->apply($items, $dealerId, $saleableNonSaleable, 0);
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
            Log::error('Depot damage invoice update failed', ['id' => $id, 'err' => $e->getMessage()]);
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  DELETE                                                            */
    /* ------------------------------------------------------------------ */
    public function delete(string $id, $user): void
    {
        $existing = DB::table($this->headerTable)->where('id', $id)->first();
        if (!$existing) {
            throw new ModelNotFoundException('Depot damage invoice not found.');
        }

        DB::beginTransaction();
        try {
            $oldItems = DB::table($this->detailsTable)->where('ch_id', $id)->get()->all();
            if (!empty($oldItems)) {
                $this->stock->reverse($oldItems, (int) $existing->ch_dealer_id, (int) $existing->damage_order_type_id, 0);
            }
            DB::table($this->detailsTable)->where('ch_id', $id)->delete();
            DB::table($this->headerTable)->where('id', $id)->delete();
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
         $q = DB::table($this->headerTable)->select([
        "{$this->headerTable}.id",
        "{$this->headerTable}.ch_no",
        "{$this->headerTable}.ch_csa_id",
        "{$this->headerTable}.ch_user_id",
        "{$this->headerTable}.ch_date",
        "{$this->headerTable}.date_added",
        "{$this->headerTable}.discount_per",
        "{$this->headerTable}.discount_amt",
        "{$this->headerTable}.amount",
        "{$this->headerTable}.damage_order_type_id",
    ]);

        if (!empty($filters['from'])) {
            $q->where("{$this->headerTable}.ch_date", '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->where("{$this->headerTable}.ch_date", '<=', $filters['to']);
        }
        if (!empty($filters['csa_id'])) {
            $q->where("{$this->headerTable}.ch_csa_id", $filters['csa_id']);
        }

        $rows = $q->orderByDesc('auto')->paginate((int) ($filters['per_page'] ?? 25));

        // attach CSA names
        $csaIds = collect($rows->items())->pluck('ch_csa_id')->filter()->unique()->all();
        $csas = $csaIds
            ? DB::table('csa')->whereIn('c_id', $csaIds)->pluck('csa_name', 'c_id')->all()
            : [];

        foreach ($rows->items() as $r) {
            $r->csa_name = $csas[$r->ch_csa_id] ?? null;
        }

        return $rows->toArray();
    }

    /* ------------------------------------------------------------------ */
    /*  SHOW                                                              */
    /* ------------------------------------------------------------------ */
    public function show(string $id): array
    {
        $header = DB::table($this->headerTable)->where('id', $id)->first();
        if (!$header) {
            throw new ModelNotFoundException('Depot damage invoice not found.');
        }

        $items = DB::table("{$this->detailsTable} as dod")
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'dod.product_id')
            ->where('dod.ch_id', $id)
            ->get(['dod.*', 'cp.name as product_name', 'cp.hsn_code as product_hsn_code']);

        return ['header' => $header, 'items' => $items];
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
        DB::table($this->detailsTable)->insert($rows);
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

    protected function totals(array $items, float $discPer): array
    {
        $amount = 0.0;
        foreach ($items as $it) {
            $amount += (float) ($it['taxable_amt'] ?? 0) + (float) ($it['vat_amt'] ?? 0);
        }
        $discAmt = round($amount * $discPer / 100, 2);
        $final   = round($amount - $discAmt, 2);
        return [$amount, $discAmt, $final];
    }

    protected function normalizeDate(?string $d): string
    {
        if (empty($d)) return '1970-01-01';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
        $ts = strtotime(str_replace('/', '-', $d));
        return $ts ? date('Y-m-d', $ts) : '1970-01-01';
    }

    protected function normalizeDateTime($d): string
    {
        if ($d instanceof \DateTimeInterface) return $d->format('Y-m-d H:i:s');
        if (empty($d)) return now()->format('Y-m-d H:i:s');
        $ts = strtotime(str_replace('/', '-', (string) $d));
        return $ts ? date('Y-m-d H:i:s', $ts) : now()->format('Y-m-d H:i:s');
    }
}