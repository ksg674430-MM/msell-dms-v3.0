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
        protected DamageInvoiceService $damage,
    ) {}

    public function checkGst($user): int
    {
        return DamageInvoiceService::companyId($user) === 213 ? 1 : 0;
    }

    public function compute(array $items, $discountPer, $user): array
    {
        return $this->damage->compute($items, $discountPer, $this->checkGst($user), DamageInvoiceService::companyId($user));
    }

    public function save(array $data, $user): array
    {
        $dealerId     = (int) $user->dealer_id;
        $companyIdCus = DamageInvoiceService::companyId($user);
        $userId       = (int) $user->getKey();

        $saleableNonSaleable = (int) $data['saleable_non_saleable'];
        $csaId               = (int) $data['csa_id'];
        $this->assertCsa($csaId, $dealerId);

        $calc  = $this->compute($data['items'] ?? [], $data['discount_per'] ?? 0, $user);
        $items = $calc['items'];

        $chNo   = $this->numbers->nextDepot($dealerId, $companyIdCus)['ch_no'];
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? now());

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
                'id'                   => $challanId,
                'ch_no'                => $chNo,
                'ch_created_by'        => $userId,
                'ch_dealer_id'         => $dealerId,
                'ch_csa_id'            => $csaId,
                'ch_user_id'           => $dealerId,
                'ch_date'              => $chDate,
                'date_added'           => now(),
                'company_id'           => 1,
                'invoice_type'         => 4,
                'discount_per'         => $calc['discount_per'],
                'discount_amt'         => $calc['discount_amt'],
                'amount'               => $calc['total_amount_a'],
                'remaining'            => $calc['total_amount_a'],
                'damage_order_type_id' => $saleableNonSaleable,
            ]);

            $this->insertDetails($challanId, $items, $userId);
            $this->stock->toDepot($items, $dealerId, $saleableNonSaleable);

            DB::commit();

            return [
                'status'                => true,
                'message'               => 'Depot damage invoice saved successfully.',
                'challan_id'            => $challanId,
                'ch_no'                 => $chNo,
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'total_before_discount' => $calc['total'],
                'amount'                => $calc['total_amount_a'],
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Depot damage invoice save failed', ['err' => $e->getMessage()]);
            throw $e;
        }
    }

    public function update(string $id, array $data, $user): array
    {
        $existing = $this->findOwned($id, $user);
        $dealerId = (int) $existing->ch_dealer_id;
        $userId   = (int) $user->getKey();
        $oldType  = (int) $existing->damage_order_type_id;
        $oldItems = DB::table($this->detailsTable)->where('ch_id', $id)->get()->all();

        $saleableNonSaleable = (int) $data['saleable_non_saleable'];
        $csaId               = (int) $data['csa_id'];
        $this->assertCsa($csaId, $dealerId);

        $calc   = $this->compute($data['items'] ?? [], $data['discount_per'] ?? 0, $user);
        $items  = $calc['items'];
        $chDate = $this->normalizeDateTime($data['ch_date'] ?? $existing->ch_date);

        DB::beginTransaction();
        try {
            $this->stock->fromDepot($oldItems, $dealerId, $oldType);

            DB::table($this->detailsTable)->where('ch_id', $id)->delete();
            $this->insertDetails($id, $items, $userId);

            DB::table($this->headerTable)->where('id', $id)->update([
                'ch_created_by'        => $userId,
                'ch_csa_id'            => $csaId,
                'ch_date'              => $chDate,
                'discount_per'         => $calc['discount_per'],
                'discount_amt'         => $calc['discount_amt'],
                'amount'               => $calc['total_amount_a'],
                'remaining'            => $calc['total_amount_a'],
                'damage_order_type_id' => $saleableNonSaleable,
            ]);

            $this->stock->toDepot($items, $dealerId, $saleableNonSaleable);

            DB::commit();

            return [
                'status'                => true,
                'message'               => 'Depot damage invoice updated successfully.',
                'challan_id'            => $id,
                'ch_no'                 => $existing->ch_no,
                'discount_per'          => $calc['discount_per'],
                'discount_amt'          => $calc['discount_amt'],
                'total_before_discount' => $calc['total'],
                'amount'                => $calc['total_amount_a'],
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Depot damage invoice update failed', ['id' => $id, 'err' => $e->getMessage()]);
            throw $e;
        }
    }

    public function delete(string $id, $user): void
    {
        $existing = $this->findOwned($id, $user);

        DB::beginTransaction();
        try {
            $oldItems = DB::table($this->detailsTable)->where('ch_id', $id)->get()->all();
            $this->stock->fromDepot($oldItems, (int) $existing->ch_dealer_id, (int) $existing->damage_order_type_id);
            DB::table($this->detailsTable)->where('ch_id', $id)->delete();
            DB::table($this->headerTable)->where('id', $id)->delete();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function list(array $filters, $user): array
    {
        $h = $this->headerTable;
        $q = DB::table($h)
            ->leftJoin('saleable_non_saleable as sns', 'sns.id', '=', "{$h}.damage_order_type_id")
            ->leftJoin('csa', 'csa.c_id', '=', "{$h}.ch_csa_id")
            ->select([
                "{$h}.id",
                "{$h}.ch_no",
                "{$h}.ch_csa_id",
                "{$h}.ch_date",
                "{$h}.date_added",
                "{$h}.discount_per",
                "{$h}.discount_amt",
                "{$h}.amount",
                "{$h}.damage_order_type_id",
                'sns.name as damage_type',
                'csa.csa_name',
            ])
            ->where("{$h}.ch_dealer_id", (int) $user->dealer_id);

        if (!empty($filters['from'])) {
            $q->whereDate("{$h}.ch_date", '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate("{$h}.ch_date", '<=', $filters['to']);
        }
        if (!empty($filters['csa_id'])) {
            $q->where("{$h}.ch_csa_id", (int) $filters['csa_id']);
        }
        if (!empty($filters['ch_no'])) {
            $q->where("{$h}.ch_no", 'like', '%' . $filters['ch_no'] . '%');
        }

        $rows = $q->orderByDesc("{$h}.ch_no")
            ->paginate(min(1000, max(1, (int) ($filters['per_page'] ?? 25))));

        $orders = collect($rows->items());
        $items  = $orders->isEmpty() ? collect() : DB::table("{$this->detailsTable} as dod")
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'dod.product_id')
            ->whereIn('dod.ch_id', $orders->pluck('id')->map(fn ($v) => (string) $v)->all())
            ->orderBy('dod.id')
            ->get(['dod.ch_id', 'dod.product_id', 'cp.name', 'dod.mfg_date', 'dod.product_rate', 'dod.qty', 'dod.free_qty', 'dod.vat_amt', 'dod.taxable_amt'])
            ->groupBy(fn ($r) => (string) $r->ch_id);

        foreach ($orders as $r) {
            $r->items = $items[(string) $r->id] ?? [];
        }

        return $rows->toArray();
    }

    public function show(string $id, $user): array
    {
        $header = $this->findOwned($id, $user);
        $csa = DB::table('csa')->where('c_id', $header->ch_csa_id)->first(['csa_name', 'adress', 'mobile', 'gst_no']);
        $header->csa_name    = $csa->csa_name ?? null;
        $header->csa_address = $csa->adress ?? null;
        $header->csa_mobile  = $csa->mobile ?? null;
        $header->csa_gst_no  = $csa->gst_no ?? null;
        $header->damage_type = DB::table('saleable_non_saleable')->where('id', $header->damage_order_type_id)->value('name');

        $items = DB::table("{$this->detailsTable} as dod")
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'dod.product_id')
            ->where('dod.ch_id', $id)
            ->orderBy('dod.id')
            ->get(['dod.*', 'cp.name as product_name', 'cp.hsn_code as product_hsn_code']);

        return ['header' => $header, 'items' => $items];
    }

    protected function findOwned(string $id, $user)
    {
        $row = DB::table($this->headerTable)
            ->where('id', $id)
            ->where('ch_dealer_id', (int) $user->dealer_id)
            ->first();
        if (!$row) {
            throw new ModelNotFoundException('Depot damage invoice not found.');
        }
        return $row;
    }

    protected function assertCsa(int $csaId, int $dealerId): void
    {
        $ok = $csaId > 0 && DB::table('dealer')->where('id', $dealerId)->where('csa_id', $csaId)->exists();
        if (!$ok) {
            throw new \InvalidArgumentException('Select your depot.');
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
                'supply_status'      => 0,
                'hsn_code'           => (string) ($it['hsn_code'] ?? ''),
                'catalog_details_id' => 0,
                'batch_no'           => (string) ($it['batch_no'] ?? ''),
                'mfg_date'           => $this->normalizeDate($it['mfg_date'] ?? null),
                'tax'                => (float) ($it['tax'] ?? 0),
                'vat_amt'            => (float) ($it['vat_amt'] ?? 0),
                'qty'                => (float) $it['qty'],
                'product_rate'       => (float) $it['product_rate'],
                'free_qty'           => (string) ($it['free_qty'] ?? '0'),
                'order_id'           => 0,
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
        DB::table($this->detailsTable)->insert($rows);
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
