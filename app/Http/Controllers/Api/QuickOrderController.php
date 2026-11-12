<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LegacySchemeOptions;
use App\Support\RateList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDF;

class QuickOrderController extends Controller
{
    /* ============================================================
     |  COMPANY DISPATCH
     |    company_id === 405  -> legacy (session/ERP-style) path
     |    otherwise           -> current  (Sanctum/catalog) path
     * ============================================================ */
    private const LEGACY_COMPANY_ID     = 405;
    private const LEGACY_ERP_COMPANY_ID = 1;

    private function isLegacy(array $ctx): bool
    {
        return (int) ($ctx['company_id'] ?? 0) === self::LEGACY_COMPANY_ID;
    }

    private function ensureBlockOrderDateColumn(): void
    {
        static $done = false;
        if ($done) return;
        if (! Schema::hasColumn('dealer', 'block_order_date')) {
            try {
                DB::statement("ALTER TABLE `dealer` ADD COLUMN `block_order_date` DATE NULL");
            } catch (\Throwable $e) {
                Log::warning('[legacy] alter dealer.block_order_date failed', ['error' => $e->getMessage()]);
            }
        }
        $done = true;
    }

    /* ============================================================
     |  EXISTING (CURRENT) HELPERS
     * ============================================================ */

  private function context(Request $request): array
{
    $user      = $request->user();
    $dealer_id = $user->dealer_id ?? 0;
    $dealer    = DB::table('dealer')->where('id', $dealer_id)->first();

    /* ── dealer_code resolution (legacy-compatible, crash-proof) ──
     |  Priority:
     |    1) dealer.dealer_code          ← legacy session me yahi set hota tha
     |    2) user->dealer_code (Sanctum) ← fallback
     |    3) dealer_person_login.dealer_code → sirf agar column exist karta ho
     * ─────────────────────────────────────────────────────────── */
    $dealerCode = trim((string) ($dealer->dealer_code ?? ''));

    if ($dealerCode === '' && !empty($user->dealer_code)) {
        $dealerCode = trim((string) $user->dealer_code);
    }

    if ($dealerCode === '' && Schema::hasColumn('dealer_person_login', 'dealer_code')) {
        $dealerCode = trim((string) (DB::table('dealer_person_login')
            ->where('dealer_id', $dealer_id)
            ->value('dealer_code') ?? ''));
    }

    // dev/staging pe mismatch warn (sirf agar login table me column ho)
    if (app()->environment('local', 'staging')
        && Schema::hasColumn('dealer_person_login', 'dealer_code')
        && $dealerCode !== '') {
        $loginCode = trim((string) (DB::table('dealer_person_login')
            ->where('dealer_id', $dealer_id)->value('dealer_code') ?? ''));
        if ($loginCode !== '' && $loginCode !== $dealerCode) {
            Log::warning('[context] dealer_code MISMATCH', [
                'dealer.dealer_code'              => $dealer->dealer_code ?? null,
                'dealer_person_login.dealer_code' => $loginCode,
                'used'                            => $dealerCode,
                'dealer_id'                       => $dealer_id,
            ]);
        }
    }

    return [
        'user'          => $user,
        'dealer'        => $dealer,
        'dealer_id'     => $dealer_id,
        'company_id'    => (int) ($dealer->company_id ?? ($user->company_id ?? 0)),
        'dealer_code'   => $dealerCode,
        'csa_id'        => $dealer->csa_id ?? ($user->csa_id ?? 0),
        'role_id'       => $user->role_id ?? ($dealer->role_id ?? 0),
        'domain_url'    => $user->domain_url ?? '',
        'quantity_type' => $dealer->quantity_type ?? 0,
        'date'          => date('Y-m-d'),
    ];
}

    private function erpOrderUrl(int $companyId): string
    {
        if ($companyId !== 379 && !DB::table('url_list')
                ->join('assign_url_list', 'assign_url_list.url_list_id', '=', 'url_list.id')
                ->where('assign_url_list.status', 1)
                ->where('assign_url_list.company_id', $companyId)
                ->where('url_list.code', 'sumbitOrderToErp')
                ->exists()) {
            return '';
        }
        $base = rtrim(trim((string) DB::table('company')->where('id', $companyId)->value('erp_url')), '/');
        if ($base === '') {
            return '';
        }
        return (preg_match('#^https?://#i', $base) ? $base : 'http://' . $base) . '/public/api/submitOrderToErp';
    }

private function legacyCommonDiscount(int $dealerId, string $productId, float $totalRs): array
{
    $dealerCode = trim((string) DB::table('dealer')->where('id', $dealerId)->value('dealer_code'));
    $stateCode = $dealerCode === '' ? null : DB::table('ACC_MAST')->where('ACC_CODE', $dealerCode)->value('STATE_CODE');
    if ($dealerCode === '' || $stateCode === null) {
        return [0.0, 0.0, 0.0];
    }

    $today = date('Y-m-d');
    $open = fn ($q, string $t) => $q->where(fn ($w) => $w->whereNull("$t.TO_DATE")->orWhereIn("$t.TO_DATE", ['', ' ', 'NULL', '0000-00-00'])->orWhere("$t.TO_DATE", '>=', $today));
    $blank = fn ($r) => trim((string) $r->PROD_CODE) === '' && trim((string) $r->PROD_CATG) === '' && trim((string) $r->PRICE_LIST_CATG) === '';
    $item = DB::table('ITEM_MAST')->where('ITEM_CODE', $productId)->first(['PROD_CODE', 'PROD_CATG']);
    $priceListCatg = $item ? DB::table('PROD_MAST')->where('PROD_CODE', $item->PROD_CODE)->value('PRICE_LIST_CATG') : null;
    $levels = array_filter([
        'PROD_CODE' => $item->PROD_CODE ?? null,
        'PROD_CATG' => $item->PROD_CATG ?? null,
        'PRICE_LIST_CATG' => $priceListCatg,
    ], fn ($v) => trim((string) $v) !== '');

    $pick = function (string $table, string $rateCol, callable $scope, bool $dealerScoped) use ($open, $blank, $levels) {
        $rows = $open($scope(DB::table($table)), $table)->orderByDesc('id')->get(['id', 'PROD_CODE', 'PROD_CATG', 'PRICE_LIST_CATG', $rateCol]);
        $rate = null;
        foreach ($rows as $r) {
            if ($dealerScoped && $blank($r)) {
                $rate = $r->$rateCol;
            }
        }
        foreach ($levels as $col => $value) {
            $hit = $rows->filter(fn ($r) => trim((string) $r->$col) === trim((string) $value));
            if ($hit->isNotEmpty()) {
                return (float) $hit->first()->$rateCol;
            }
        }
        return (float) ($rate ?? 0);
    };

    $stax = $pick('PROD_TAX_MAST', 'STAX_RATE', fn ($q) => $q->where('STATE_CODE', $stateCode), true);
    $dealerHasRows = DB::table('DEALER_BILLTAX_MAST')->where('ACC_CODE', $dealerCode)->exists();
    $tr = $dealerHasRows
        ? $pick('DEALER_BILLTAX_MAST', 'TI_RATE', fn ($q) => $q->where('ACC_CODE', $dealerCode), true)
        : $pick('DEALER_BILLTAX_MAST', 'TI_RATE', fn ($q) => $q->whereNull('ACC_CODE'), false);

    $atdRate = $stax > 0 ? ($stax * 100) / ($stax + 100) : 0;
    $tdDisc  = ($totalRs * $tr) / 100;
    $atdDisc = round((($totalRs - $tdDisc) * $atdRate) / 100, 2);

    return [$atdDisc, $tdDisc, $tr];
}

    private function submitOrderToErp(string $orderId, int $dealerId, int $companyId, array $lines): void
    {
        try {
            $this->queueOrderToErp($orderId, $dealerId, $companyId, $lines);
        } catch (\Throwable $e) {
            Log::warning('[SendToERP] could not prepare ERP request', ['company_id' => $companyId, 'order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }

    private function queueOrderToErp(string $orderId, int $dealerId, int $companyId, array $lines): void
    {
        $log = ['company_id' => $companyId, 'order_id' => $orderId];
        $url = $this->erpOrderUrl($companyId);
        if ($url === '') {
            Log::info('[SendToERP] skipped: no ERP assigned to company', $log);
            return;
        }

        $lines = array_values($lines);
        $products = DB::table('catalog_product')->whereIn('id', array_column($lines, 'product_id'))->get(['id', 'itemcode', 'gst_percent'])->keyBy('id');
        $remarks = fn ($l) => trim((string) ($l['remarks'] ?? '')) !== '' ? trim((string) $l['remarks']) : 'NA';

        if ($companyId === 379) {
            $asForm = true;
            $payload = [
                'order_id' => $orderId,
                'dealer_id' => $dealerId,
                'company_id' => $companyId,
                'primary_sale_summary' => json_encode(array_map(function ($l) use ($remarks) {
                    $pcs = ($l['order_unit'] ?: 'PCS') === 'PCS';
                    $qty = (float) $l['quantity'];
                    return [
                        'product_id' => $l['product_id'],
                        'rate' => $pcs ? (float) $l['rate'] : 0,
                        'case_rate' => $pcs ? 0 : (float) $l['rate'],
                        'case' => $pcs ? 0 : $qty,
                        'quantity' => $pcs ? $qty : 0,
                        'pcs' => $pcs ? $qty : 0,
                        'remarks' => $remarks($l),
                    ];
                }, $lines)),
            ];
        } else {
            $asForm = false;
            $dealerCode = trim((string) DB::table('dealer')->where('id', $dealerId)->value('dealer_code'));
            $unmapped = array_values(array_filter(array_column($lines, 'product_id'), fn ($id) => empty(trim((string) ($products[$id]->itemcode ?? '')))));
            if (empty($dealerCode) || $unmapped) {
                Log::warning('[SendToERP] skipped: ' . (empty($dealerCode) ? 'dealer code not mapped' : 'item code not mapped'), $log + ['dealer_id' => $dealerId, 'product_ids' => $unmapped]);
                return;
            }
            $payload = [
                'DMS_ORDER_ID' => $orderId,
                'ACC_CODE' => $dealerCode,
                'company_id' => (string) $companyId,
                'item_details' => array_map(function ($l) use ($products, $companyId, $remarks) {
                    $gst = (float) ($products[$l['product_id']]->gst_percent ?? 0);
                    $rate = (float) $l['rate'];
                    return [
                        'ITEM_CODE' => trim((string) $products[$l['product_id']]->itemcode),
                        'RATE' => round($companyId === 309 ? $rate * (1 + $gst / 100) : $rate / (1 + $gst / 100), 4),
                        'QTY' => (string) (float) $l['quantity'],
                        'REMARKS' => $remarks($l),
                        'UM' => (string) ($l['order_unit'] ?: 'PCS'),
                    ];
                }, $lines),
            ];
        }

        app()->terminating(function () use ($url, $payload, $asForm, $companyId, $log, $orderId) {
            try {
                $http = Http::timeout(15)->acceptJson();
                $res = $asForm ? $http->asForm()->post($url, $payload) : $http->post($url, $payload);
                $json = $res->json() ?? [];
                $vrno = trim((string) ($json['VRNO'] ?? $json['vrno'] ?? ''));
                $vrno = strtoupper($vrno) === 'NFDC' ? '' : $vrno;
                $ok = $companyId === 379 ? (int) ($json['status'] ?? 0) === 200 : $vrno !== '';
                if ($ok && $vrno !== '') {
                    DB::table('purchase_order')->where('order_id', $orderId)->update(['against_order_remarks' => $vrno]);
                    DB::table('demand_order')->where('order_id', $orderId)->update(['erp_order_no' => $vrno]);
                }
                Log::info('[SendToERP] ' . ($ok ? 'sent' : 'ERP did not accept the order'), $log + [
                    'url' => $url,
                    'http_status' => $res->status(),
                    'payload' => $payload,
                    'response' => mb_substr($res->body(), 0, 2000),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[SendToERP] could not reach ERP', $log + ['url' => $url, 'error' => $e->getMessage(), 'payload' => $payload]);
            }
        });
    }

    private function newOrderId($dealerId): string
    {
        $id = date('YmdHis') . (int) $dealerId;
        return strlen($id) < 19 || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) <= 0) ? $id : date('ymdHis') . (int) $dealerId;
    }

    private function allowedDealerId(array $ctx, $requested)
    {
        $own = (int) $ctx['dealer_id'];
        $requested = (int) $requested;
        if (!$requested || $requested === $own) return $own;
        if ((int) ($ctx['dealer']->is_depo ?? 0) !== 1) return null;

        $login = DB::table('dealer_person_login')->where('dealer_id', $own)->where('company_id', $ctx['company_id'])->first();
        $allowed = $login && DB::table('dealer')
            ->where('id', $requested)
            ->where('company_id', $ctx['company_id'])
            ->where('csa_id', $login->csa_id)
            ->where('dealer_status', 1)
            ->exists();

        return $allowed ? $requested : null;
    }

    private function canAccessOrder(array $ctx, $order): bool
    {
        if (!$order || (int) $order->company_id !== (int) $ctx['company_id']) return false;
        $role_id = (int) $ctx['role_id'];
        if ($role_id === 1 || (int) $order->dealer_id === (int) $ctx['dealer_id'] || (int) ($order->created_person_id ?? 0) === (int) $ctx['dealer_id']) return true;
        if (in_array($role_id, [37, 823], true)) {
            return (string) $order->sale_div_code === (string) $this->resolveDivCode($ctx['dealer_id'], $ctx['dealer_code']);
        }
        return false;
    }


    private function legacySchemeSlabs(string $itemCode, string $dealerCode): array
    {
        $item = DB::table('ITEM_MAST')->where('ITEM_CODE', $itemCode)->first(['PROD_CODE', 'PROD_CATG', 'ITEM_SIZE', 'SIZE_UM']);
        $size = trim((string) ($item->ITEM_SIZE ?? ''));
        $blank = fn ($v) => trim((string) $v) === '' || trim((string) $v) === '0';

        $tiers = [1 => fn ($q) => $q->where('sd.ITEM_CODE', $itemCode)];
        if ($item && !$blank($item->PROD_CODE)) {
            if ($size !== '') {
                $tiers['2a'] = fn ($q) => $q->where('sd.PROD_CODE', $item->PROD_CODE)
                    ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('SCHEME_SLAB as ss')->whereColumn('ss.SCHEME_NO', 'sd.SCHEME_NO')->where('ss.ITEM_SIZE', $size));
            }
            $tiers['2b'] = fn ($q) => $q->where('sd.PROD_CODE', $item->PROD_CODE);
        }
        if ($item && !$blank($item->PROD_CATG)) {
            $tiers[3] = fn ($q) => $q->where('sd.PROD_CATG', $item->PROD_CATG);
        }
        if ($item && $size !== '' && !$blank($item->SIZE_UM)) {
            $tiers[4] = fn ($q) => $q->where('sd.ITEM_SIZE', 'LIKE', addcslashes($size, '%_\\') . '%')->where('sd.SIZE_UM', $item->SIZE_UM);
        }

        $today = date('Y-m-d');
        $pools = $blank($dealerCode) ? ['GLOBAL' => null] : ['DEALER' => $dealerCode, 'GLOBAL' => null];

        foreach ($pools as $source => $accCode) {
            foreach ($tiers as $tier => $apply) {
                $q = DB::table('SCHEME_DOMAIN as sd')
                    ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('CIRCULAR_MAST as cm')
                        ->whereColumn('cm.SCHEME_NO', 'sd.SCHEME_NO')
                        ->where('cm.from_date_condition', '<=', $today)
                        ->where('cm.to_date_condition', '>=', $today)
                        ->where(fn ($w) => $w->whereNull('cm.CANCELLEDBY')->orWhereIn('cm.CANCELLEDBY', ['', ' ', 'NULL'])))
                    ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('SCHEME_MAST as sm')->whereColumn('sm.SCHEME_NO', 'sd.SCHEME_NO'))
                    ->whereExists(fn ($e) => $e->select(DB::raw(1))->from('SCHEME_SLAB as sl')->whereColumn('sl.SCHEME_NO', 'sd.SCHEME_NO'));
                $q->whereExists(function ($e) use ($accCode) {
                    $e->select(DB::raw(1))->from('SCHEME_DOMAIN as acc')->whereColumn('acc.SCHEME_NO', 'sd.SCHEME_NO');
                    $accCode === null
                        ? $e->where(fn ($w) => $w->whereIn('acc.ACC_CODE', ['0', ''])->orWhereNull('acc.ACC_CODE'))
                        : $e->where('acc.ACC_CODE', $accCode);
                });
                $schemeNo = $apply($q)->orderByDesc('sd.id')->value('sd.SCHEME_NO');
                if ($schemeNo === null) {
                    continue;
                }

                $cols = ['id', 'BASE_FROM as base', 'FREE_ITEM1_QTY1 as free'];
                if ($tier === 1) {
                    $slabs = DB::table('SCHEME_SLAB')->where('SCHEME_NO', $schemeNo)->orderBy('id')->get($cols);
                } else {
                    $slabs = DB::table('SCHEME_SLAB')->where('SCHEME_NO', $schemeNo)->where('ITEM_SIZE', $size)->orderByDesc('id')->get($cols);
                    if ($slabs->isEmpty()) {
                        $slabs = DB::table('SCHEME_SLAB')->where('SCHEME_NO', $schemeNo)->orderByDesc('id')->get($cols);
                    }
                }

                return [
                    'scheme_no' => $schemeNo,
                    'source' => $source,
                    'tier' => (string) $tier,
                    'slabs' => $slabs->map(fn ($s) => ['id' => (int) $s->id, 'base' => $s->base, 'free' => $s->free])->all(),
                ];
            }
        }

        return ['scheme_no' => null, 'source' => null, 'tier' => null, 'slabs' => []];
    }

    private function legacySchemeOptionsFor(array $ctx, string $itemCode, int $term): array
    {
        $rateInfo = $this->legacyDealerRate($itemCode);
        if (!$rateInfo) {
            return ['rate' => null, 'scheme' => null, 'options' => []];
        }
        $scheme = $this->legacySchemeSlabs($itemCode, (string) $ctx['dealer_code']);
        $isBox = $rateInfo['um'] === 'BOX';
        $options = LegacySchemeOptions::build($scheme['slabs'], $term, $isBox, (float) $rateInfo['um_to_aum'], (float) $rateInfo['puda_size']);

        return ['rate' => $rateInfo, 'scheme' => $scheme, 'options' => $options];
    }

    private function legacyPricedLine(array $ctx, string $itemCode, int $term, float $title): array
    {
        $calc = $this->legacySchemeOptionsFor($ctx, $itemCode, $term);
        $rateInfo = $calc['rate'];
        if (!$rateInfo || $rateInfo['rate'] <= 0) {
            return ['error' => "Rate not configured for item {$itemCode}."];
        }
        if ($rateInfo['um'] === 'BOX' && $rateInfo['um_to_aum'] <= 0) {
            return ['error' => "Box size is not configured for item {$itemCode}."];
        }
        $option = LegacySchemeOptions::find($calc['options'], $title);
        if (!$option) {
            return ['error' => "Quantity {$title} is not a valid scheme option for item {$itemCode}. Please select the quantity again."];
        }

        $billed = $option['title'] - $option['free_qty'];
        return [
            'rate' => $rateInfo,
            'title' => $option['title'],
            'free' => $option['free_qty'],
            'total' => round($rateInfo['rate'] * $billed, 2),
        ];
    }

    private function dealerRate(array $ctx, $itemCode, $mode = 0)
    {
        $company_id = $ctx['company_id'];
        $rate = RateList::forDealer((int) $ctx['dealer_id'], (int) $company_id)->row((int) $itemCode);
        if (!$rate) return null;

        $login = DB::table('dealer_person_login')->where('company_id', $company_id)->where('dealer_id', $ctx['dealer_id'])->first();
        $qtyType = $login->order_quantity_type ?? 0;

        if ($company_id == 44) {
            $result = $rate->dealer_rate; $perCase = true;
        } elseif ($company_id == 206) {
            $result = $rate->dealer_pcs_rate; $perCase = false;
        } elseif ($qtyType == 1) {
            $result = ((int) $mode === 1) ? $rate->ss_case_rate : $rate->dealer_rate; $perCase = true;
        } else {
            $result = ((int) $mode === 1) ? $rate->ss_pcs_rate : $rate->dealer_pcs_rate; $perCase = false;
        }

        $mrp = $perCase ? $rate->mrp : ((float) ($rate->mrp_pcs ?? 0) > 0 ? $rate->mrp_pcs : $rate->mrp);
        $um = 'PCS';
        if (in_array($company_id, [243, 213, 331], true)) $um = $qtyType == 1 ? 'BOX' : 'PCS';

        return ['rate' => (float) $result, 'um' => $um, 'mrp' => (float) ($mrp ?? 0)];
    }

    private function dealerScheme(array $ctx, $productId, float $qty, float $rate): array
    {
        $none = ['rate' => $rate, 'percent' => 0.0];
        $dealer = $ctx['dealer'];
        if (empty($dealer->state_id) || $qty <= 0 || $rate <= 0) return $none;

        $today = date('Y-m-d');
        $q = DB::table('product_wise_scheme_plan_details')
            ->where('product_id', $productId)
            ->where('state_id', $dealer->state_id)
            ->where('company_id', $ctx['company_id'])
            ->whereDate('valid_from_date', '<=', $today)
            ->whereDate('valid_to_date', '>=', $today);

        if (!empty($dealer->ownership_type_id)) $q->where('ownership_type', $dealer->ownership_type_id);
        $s = $q->first();
        if (!$s) return $none;

        $first = (float) ($s->sale_value_range_first ?? 0);
        $last = trim((string) ($s->sale_value_range_last ?? '~'));
        if ($qty < $first || ($last !== '~' && $qty > (float) $last)) return $none;

        $value = (float) $s->value_amount_percentage;
        if ((int) $s->sale_unit === 5) {
            if ((int) $s->incentive_type === 1) return ['rate' => round($rate - ($rate * $value / 100), 2), 'percent' => 0.0];
            if ((int) $s->incentive_type === 2 && $value > 0) return ['rate' => $value, 'percent' => 0.0];
            return $none;
        }

        return (int) $s->incentive_type === 1 ? ['rate' => $rate, 'percent' => $value] : $none;
    }

    private function lineTotal(float $rate, float $qty, float $percent): float
    {
        $gross = $rate * $qty;
        return round($gross - ($gross * $percent / 100), 2);
    }

    private function resolveDivCode($dealer_id, $dealer_code)
    {
        $div_code_entry = DB::table('dealer')->where('id', $dealer_id)->first();
        $deiv_code_login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first();
        if ($dealer_code === '00001') return $div_code_entry->DIV_CODE ?? 'JH';
        $else = $deiv_code_login->div_code_main ?? '0';
        return $div_code_entry->DIV_CODE ?? $else;
    }

    /* ============================================================
     |  1) ORDER LISTING
     * ============================================================ */

    public function index(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyIndex($request, $ctx);
        }

        $dealer_id = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];
        $role_id = $ctx['role_id'];
        $dealer_code = $ctx['dealer_code'];

        if (empty($request->date_range_picker)) {
            $from_date = date('Y-m') . '-01';
            $to_date = date('Y-m-d');
        } else {
            $parts = explode(" -", $request->date_range_picker);
            $from_date = date('Y-m-d', strtotime(trim($parts[0])));
            $to_date = date('Y-m-d', strtotime(trim($parts[1])));
        }

        $order_no = $request->order_no;
        $extra = [
            'dl.name as distributor_name',
            DB::raw('SUM(demand_order_details.quantity) as total_qty'),
            DB::raw('ROUND(SUM(demand_order_details.total_rs * COALESCE(cp.gst_percent, 0) / 100), 2) as tax_value'),
        ];
        $div_code = $this->resolveDivCode($dealer_id, $dealer_code);

        $baseQ = DB::table('demand_order')
            ->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'demand_order_details.product_id')
            ->leftJoin('dealer as dl', 'dl.id', '=', 'demand_order.dealer_id')
            ->where('demand_order.company_id', $company_id)
            ->whereDate('demand_order.order_date', '>=', $from_date)
            ->whereDate('demand_order.order_date', '<=', $to_date)
            ->groupBy('demand_order.id');

        if (!empty($order_no)) {
            $baseQ->where(function ($q) use ($order_no) {
                $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")
                    ->when(Schema::hasColumn('demand_order', 'erp_order_no'), fn ($w) => $w->orWhere('demand_order.erp_order_no', 'LIKE', "%{$order_no}%"));
            });
        }

        if (in_array($role_id, [37, 823], true)) {
            if ($dealer_id == 1170) {
                $records = (clone $baseQ)->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->addSelect($extra)->where('demand_order.dealer_id', $dealer_id)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            } else {
                $records = (clone $baseQ)->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->addSelect($extra)->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->where('sale_div_code', $div_code)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            }
        } else {
            $records = (clone $baseQ)->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->addSelect($extra)->where(fn ($q) => $q->where('demand_order.dealer_id', $dealer_id)->orWhere('demand_order.created_person_id', $dealer_id))->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
        }

        if ($dealer_id != 1170 && $role_id == 37) {
            $depoQ = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->leftJoin('catalog_product as cp', 'cp.id', '=', 'demand_order_details.product_id')->leftJoin('dealer as dl', 'dl.id', '=', 'demand_order.dealer_id')->where('demand_order.company_id', $company_id)->whereDate('demand_order.order_date', '>=', $from_date)->whereDate('demand_order.order_date', '<=', $to_date)->groupBy('demand_order.id');
            if (!empty($order_no)) {
                $depoQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")->when(Schema::hasColumn('demand_order', 'erp_order_no'), fn ($w) => $w->orWhere('demand_order.erp_order_no', 'LIKE', "%{$order_no}%"));
                });
            }
            $records_depo = $depoQ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->addSelect($extra)->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->where('dealer_id', $dealer_id)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            $records = array_merge($records_depo, $records);
        }

        $erp_order_no = DB::table('purchase_order')->where('dealer_id', $dealer_id)->get()->groupBy('order_id');
        $depo_filter = [];
        $dealer_name = [];

        if ($role_id == 1) {
            $adminQ = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->leftJoin('catalog_product as cp', 'cp.id', '=', 'demand_order_details.product_id')->leftJoin('dealer as dl', 'dl.id', '=', 'demand_order.dealer_id')->whereDate('demand_order.order_date', '>=', $from_date)->whereDate('demand_order.order_date', '<=', $to_date)->groupBy('demand_order.id');
            $adminQ->select('demand_order.*', 'demand_order.id as order_id', 'demand_order.order_id as porder_id', 'dealer.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->addSelect($extra)->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->where('demand_order.company_id', $company_id);
            if (!empty($order_no)) {
                $adminQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', "%{$order_no}%")->when(Schema::hasColumn('demand_order', 'erp_order_no'), fn ($w) => $w->orWhere('demand_order.erp_order_no', 'LIKE', "%{$order_no}%"));
                });
            }
            if (!empty($request->depo_filter)) $adminQ->whereIn('demand_order.sale_div_code', (array) $request->depo_filter);
            if (!empty($request->distributor)) $adminQ->whereIn('demand_order.dealer_id', (array) $request->distributor);
            $records = $adminQ->get()->toArray();
            $depo_filter = DB::table('dealer')->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')->where('dealer_status', 1)->where('role_id', 37)->where('dealer.company_id', $company_id)->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as dealer_name"), 'div_code_main');
            $dealer_name = DB::table('dealer')->where('dealer_status', 1)->where('company_id', $company_id)->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'id');
        }

        return response()->json([
            'status' => true,
            'message' => 'Order list fetched',
            'data' => compact('records', 'erp_order_no', 'depo_filter', 'dealer_name') + [
                'role_id' => $role_id, 'dealer_id' => $dealer_id, 'company_id' => $company_id, 'dealer_code' => $dealer_code, 'from_date' => $from_date, 'to_date' => $to_date,
            ],
        ]);
    }

    /* ============================================================
     |  2) CREATE-DATA
     * ============================================================ */

    public function createData(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyCreateData($request, $ctx);
        }

        $dealer_id = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];
        $role_id = $ctx['role_id'];
        $dealer_code = $ctx['dealer_code'];
        $csa_id = $ctx['csa_id'];

        $dealer = DB::table('dealer')->where('id', $dealer_id)->first();
        $image_dyn = DB::table('image_mast')->where('img_locn', 'Order')->where('company_id', $company_id)->get();
        $div_code_master = DB::table('_div_code_master')->where('status', '1')->pluck('name', 'id');

        $record_product_id = DB::table('demand_order_cart')->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')->where('demand_order_cart.dealer_id', $dealer_id)->groupBy('demand_order_details_cart.product_id')->pluck('product_id')->toArray();

        $cart_details = DB::table('demand_order_details_cart')->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')->select(DB::raw("COUNT(demand_order_details_cart.product_id) as count"), DB::raw("SUM(total_rs-t1_rate-atd_rate+atd_rate) as sale_vale"))->where('demand_order_cart.dealer_id', $dealer_id)->first();

        $converstion_unit_item_code = [];

        $catalog_product_details = DB::table('catalog_product')->select('id', 'itemcode', 'name', 'catalog_id', 'quantity_per_case', 'gst_percent', DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"))->whereNotIn('id', $record_product_id)->where('status', '=', '1')->where('catalog_product.company_id', $company_id)->orderBy('itemcode', 'ASC')->get();

        $selectCsa = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->where('company_id', $company_id)->first();
        $div_code = $dealer->dealer_erp_div_code ?? ($selectCsa->div_code_main ?? '0');
        $dealer_array = [];
        if ($selectCsa) {
            $dealer_array = DB::table('dealer')->where('company_id', $company_id)->where('csa_id', $selectCsa->csa_id)->where('dealer_status', 1)->orderBy('id', 'ASC')->get()->toArray();
        }

        $checkSuper = DB::table('dealer')->where('id', $dealer_id)->first();
        $currMonth = date('Y-m');
        $currMonthOrder = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->select(DB::raw("SUM(rate*quantity) as demand_value"))->where('dealer_id', $dealer_id)->whereRaw("DATE_FORMAT(demand_order.order_date ,'%Y-%m')=?", [$currMonth])->first();

        return response()->json([
            'status' => true,
            'message' => 'Create data fetched',
            'data' => [
                'dealer_id' => $dealer_id, 'company_id' => $company_id, 'role_id' => $role_id, 'csa_id' => $csa_id, 'dealer_code' => $dealer_code, 'div_code' => $div_code, 'div_code_master' => $div_code_master, 'image_dyn' => $image_dyn, 'catalog_product_details' => $catalog_product_details, 'converstion_unit_item_code' => $converstion_unit_item_code, 'sale_value_cart' => $cart_details->sale_vale ?? '0', 'count_prod_cart' => $cart_details->count ?? '0', 'dealer_array' => $dealer_array, 'checkSuper' => $checkSuper, 'currMonthOrder' => $currMonthOrder, 'quantity_type' => $selectCsa->order_quantity_type ?? 0,
            ],
        ]);
    }

    /* ============================================================
     |  3) STORE (cart only)
     * ============================================================ */

    public function store(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyStore($request, $ctx);
        }

        $auth_id = $ctx['dealer_id'];
        $company_id = $ctx['company_id'];
        $role_id = $ctx['role_id'];
        $dealer_id = $ctx['dealer_id'];

        DB::beginTransaction();

        try {
            $orderDate = $request->order_date ?: date('Y-m-d');
            $dealer_id_cus = $this->allowedDealerId($ctx, $request->dealer_id_cus_select == '1' ? $dealer_id : $request->dealer_id_cus);

            if (!$dealer_id_cus) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'You cannot place an order for this distributor.'], 403);
            }

            if ($request->hasFile('uploadExcelData')) {
                return $this->importFromCsv($request, $orderDate, $dealer_id_cus, $ctx);
            }

            $item_code = array_values(array_filter((array) ($request->item_code ?? []), fn($v) => $v !== null && $v !== ''));
            $valid_ids = DB::table('catalog_product')->whereIn('id', $item_code)->where('company_id', $company_id)->where('status', 1)->pluck('id')->map(fn($v) => (string) $v)->toArray();
            $invalid = array_values(array_diff(array_map('strval', $item_code), $valid_ids));

            if (!empty($invalid)) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Invalid product id(s): ' . implode(', ', $invalid), 'data' => ['invalid_ids' => $invalid]], 422);
            }

            if ($role_id == 37) {
                $depo_dealer_status = 'DO'; $sale_div_code = $request->sale_div_code ?: 'JH';
            } elseif ($role_id == 1) {
                DB::rollBack();
                return response()->json(['status' => false, 'message' => 'Admin cannot place order here'], 403);
            } else {
                $depo_dealer_status = 'SO';
                $row = DB::table('dealer_person_login')->select('div_code_main as DIV_CODE')->where('dealer_id', $dealer_id_cus)->first();
                if (empty($row->DIV_CODE)) $row = DB::table('dealer')->where('id', $dealer_id_cus)->first();
                $sale_div_code = $row->DIV_CODE ?? 'JH';
            }

            $order_id = $this->newOrderId($auth_id);
            $check = DB::table('demand_order_cart')->where('dealer_id', $dealer_id_cus)->first();

            if ($check) {
                $order_id = $check->order_id;
                $uid = $check->id;
                $order_date = $check->order_date;
                if (filled($request->final_remarks)) {
                    DB::table('demand_order_cart')->where('id', $uid)->update(['order_remark' => mb_substr((string) $request->final_remarks, 0, 150)]);
                }
            } else {
                $insert = DB::table('demand_order_cart')->insertGetId([
                    'order_id' => $order_id, 'dealer_id' => $dealer_id_cus, 'created_date' => $orderDate . ' ' . date('H:i:s'), 'order_date' => $orderDate, 'created_person_id' => $auth_id, 'date_time' => $orderDate . ' ' . date('H:i:s'), 'company_id' => $company_id, 'sale_div_code' => $sale_div_code, 'csa_id' => $ctx['csa_id'], 'order_remark' => mb_substr((string) ($request->final_remarks ?? ''), 0, 150), 'total_value' => 0, 'vr_no_define' => $depo_dealer_status,
                ]);
                $uid = $insert;
            }

            if ($request->update_qty_status == '1') {
                $update_qty = (float) $request->update_qty;
                $line = DB::table('demand_order_details_cart')->where('product_id', $request->update_product_id)->where('order_id', $order_id)->first();

                if (!$line || $update_qty <= 0) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => $line ? 'Quantity must be greater than 0.' : 'Item not found in your cart.'], 422);
                }

                $base = $this->dealerRate($ctx, $line->product_id, $request->for_self_for_db);
                $baseRate = $base['rate'] ?? (float) $line->rate;
                $scheme = $this->dealerScheme($ctx, $line->product_id, $update_qty, $baseRate);
                $total = $this->lineTotal($scheme['rate'], $update_qty, $scheme['percent']);
                
                DB::table('demand_order_details_cart')->where('id', $line->id)->update([
                    'rate' => $scheme['rate'], 'quantity' => $update_qty, 'free_qty' => 0, 'total_rs' => $total, 'order_converstion_unit' => $line->order_unit === 'PCS' ? $update_qty : $line->order_converstion_unit, 't1_rate' => '0', 'atd_rate' => '0', 't1_rate_perc' => '0',
                ]);
            }

            // ---- Insert new rows (Handling duplicates gracefully) ----
            $raw_items = array_values((array) ($request->item_code ?? []));
            $qty = array_values((array) ($request->qty ?? []));
            $remarks = array_values((array) ($request->remarks ?? []));

            $rows = [];

            foreach ($raw_items as $k => $value) {
                $line_qty = (float) ($qty[$k] ?? 0);
                if ($value === null || $value === '' || $line_qty <= 0) continue;

                $base = $this->dealerRate($ctx, $value, $request->for_self_for_db);
                if (!$base || $base['rate'] <= 0) {
                    DB::rollBack();
                    return response()->json(['status' => false, 'message' => $base ? "Dealer rate is 0 for product {$value} in the rate list." : "Rate not found for product {$value}."], 422);
                }

                $scheme = $this->dealerScheme($ctx, $value, $line_qty, $base['rate']);
                $line_total = $this->lineTotal($scheme['rate'], $line_qty, $scheme['percent']);
                $order_conv = $base['um'] === 'PCS' ? $line_qty : 0;

                $existing = DB::table('demand_order_details_cart')->where('order_id', $order_id)->where('product_id', $value)->first();
                if ($existing) {
                    $newQty = (float)$existing->quantity + $line_qty;
                    $newTotal = $newQty * $scheme['rate'];
                    DB::table('demand_order_details_cart')->where('id', $existing->id)->update([
                        'quantity' => $newQty, 'total_rs' => $newTotal
                    ]);
                } else {
                    $rows[] = [
                        'order_id' => $order_id, 'product_id' => $value, 'rate' => $scheme['rate'], 'mrp' => $base['mrp'], 'quantity' => $line_qty, 'scheme_qty' => 0, 'free_qty' => 0, 'order_unit' => $base['um'], 'order_converstion_unit' => $order_conv, 'purchase_inv' => '', 'mfg_date' => '0000-00-00', 'expiry_date' => '0000-00-00', 'receive_date' => '0000-00-00', 'batch_no' => '', 'pr_rate' => '', 'gst' => '', 'cases' => '', 'total_rs' => $line_total, 'remarks' => mb_substr((string) ($remarks[$k] ?? ''), 0, 25), 'scheme_qty_with_free_qty' => '0', 't1_rate' => '0', 'atd_rate' => '0', 't1_rate_perc' => '0',
                    ];
                }
            }

            if ($rows) {
                DB::table('demand_order_details_cart')->insert($rows);
            }

            DB::table('demand_order_cart')->where('id', $uid)->update([
                'total_value' => DB::table('demand_order_details_cart')->where('order_id', $order_id)->sum('total_rs'),
            ]);

            DB::commit();

            $final_out = $this->buildCartPayload($dealer_id_cus, $uid, $company_id);

            return response()->json([
                'status' => true,
                'message' => 'Cart updated',
                'data' => ['uid' => $uid, 'order_id' => 'B-' . $uid, 'order_date' => $order_date ?? $orderDate, 'dealer_id_cus' => $dealer_id_cus, 'final_out' => $final_out],
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Cart update failed', 'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong'], 500);
        }
    }

    /** CSV variant of store() */
    private function importFromCsv(Request $request, $orderDate, $dealer_id_cus, array $ctx)
    {
        $file = $request->file('uploadExcelData');
        if (!$file || !($fh = fopen($file->getRealPath(), 'r'))) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Invalid CSV file'], 422);
        }

        $company_id = $ctx['company_id'];
        $order_id = $this->newOrderId($ctx['dealer_id']);
        $lines = []; $skipped = []; $seen = []; $line_no = 1;

        fgetcsv($fh, 1000, ",");

        while (($row = fgetcsv($fh, 1000, ",")) !== false) {
            $line_no++;
            if (count(array_filter($row, fn($c) => trim((string) $c) !== '')) === 0) continue;
            if (count($row) !== 3) { $skipped[] = "Row {$line_no}: expected 3 columns (SNO, Product Id, Quantity)."; continue; }

            $product_id = trim((string) $row[1]);
            $quantity = (float) str_replace(',', '', trim((string) $row[2]));
            $product = DB::table('catalog_product')->where('id', $product_id)->first(['name', 'company_id', 'status']);

            $problem = match (true) {
                !$product || (int) $product->company_id !== (int) $company_id => "product {$product_id} not found.",
                (int) $product->status !== 1 => "product {$product_id} ({$product->name}) is not active.",
                $quantity <= 0 => "quantity for product {$product_id} must be greater than 0.",
                isset($seen[$product_id]) => "product {$product_id} is already in row {$seen[$product_id]}.",
                default => null,
            };

            $base = null;
            if (!$problem) {
                $base = $this->dealerRate($ctx, $product_id);
                if (!$base || $base['rate'] <= 0) $problem = "rate not found for product {$product_id} ({$product->name}).";
            }

            if ($problem) { $skipped[] = "Row {$line_no}: {$problem}"; continue; }
            $seen[$product_id] = $line_no;

            $scheme = $this->dealerScheme($ctx, $product_id, $quantity, $base['rate']);
            $total = $this->lineTotal($scheme['rate'], $quantity, $scheme['percent']);

            $lines[] = [
                'order_id' => $order_id, 'product_id' => $product_id, 'rate' => $scheme['rate'], 'mrp' => $base['mrp'], 'quantity' => $quantity, 'scheme_qty' => 0, 'free_qty' => '0', 'order_unit' => $base['um'], 'order_converstion_unit' => $base['um'] === 'PCS' ? $quantity : 0, 'purchase_inv' => '', 'mfg_date' => '0000-00-00', 'expiry_date' => '0000-00-00', 'receive_date' => '0000-00-00', 'batch_no' => '', 'pr_rate' => '0', 'gst' => '0', 'cases' => '0', 'total_rs' => $total, 'remarks' => '', 'scheme_qty_with_free_qty' => '0', 't1_rate' => '0', 'atd_rate' => '0', 't1_rate_perc' => '0',
            ];
        }

        fclose($fh);

        if (!$lines) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => $skipped ? 'No item was ordered. ' . implode(' ', $skipped) : 'The file has no order rows.', 'skipped' => $skipped], 422);
        }

        DB::table('demand_order_details')->insert($lines);
        DB::table('purchase_order_details')->insert(array_map(fn($l) => ['id' => $order_id, 'order_id' => $order_id, 'product_id' => $l['product_id'], 'mrp' => $l['mrp'], 'rate' => $l['rate'], 'quantity' => $l['quantity'], 'scheme_qty' => 0, 'cases' => 0, 'pcs' => 0, 'pr_rate' => 0, 'company_id' => $company_id], $lines));
        DB::table('demand_order')->insert(['order_id' => $order_id, 'dealer_id' => $dealer_id_cus, 'company_id' => $company_id, 'created_date' => $orderDate . ' ' . date('H:i:s'), 'order_date' => $orderDate, 'created_person_id' => $ctx['dealer_id'], 'date_time' => $orderDate . ' ' . date('H:i:s'), 'sale_div_code' => 'JH', 'csa_id' => $ctx['csa_id'], 'order_remark' => '', 'total_value' => array_sum(array_column($lines, 'total_rs')), 'vr_no_define' => '']);
        DB::table('purchase_order')->insert(['id' => $order_id, 'order_id' => $order_id, 'dealer_id' => $dealer_id_cus, 'created_date' => $orderDate . ' ' . date('H:i:s'), 'created_person_id' => $ctx['dealer_id'], 'sale_date' => $orderDate, 'order_date' => $orderDate, 'receive_date' => null, 'date_time' => $orderDate . ' ' . date('H:i:s'), 'company_id' => $company_id, 'vehicle_id' => 11, 'dms_manual_order_status' => 0, 'dms_order_reason_id' => 2, 'd_palnt_id' => 0, 'dms_order_confirm_remaks' => 'NA']);

        DB::commit();
        $this->submitOrderToErp((string) $order_id, (int) $dealer_id_cus, (int) $company_id, $lines);

        return response()->json(['status' => true, 'message' => 'Order uploaded successfully (' . count($lines) . ' items).' . ($skipped ? ' Not ordered: ' . implode(' ', $skipped) : ''), 'skipped' => $skipped, 'data' => ['order_id' => $order_id]]);
    }

    /* ============================================================
     |  4) STORE-FINAL (cart → demand_order + purchase_order + ERP)
     * ============================================================ */

    public function storeFinal(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyStoreFinal($request, $ctx);
        }

        $auth_id = $ctx['dealer_id']; $company_id = $ctx['company_id']; $role_id = $ctx['role_id']; $csa_id = $ctx['csa_id'];
        $order_id_for_use = $request->order_id_for_use;
        $head = DB::table('demand_order_cart')->where('id', $order_id_for_use)->first();

        if (!$head || $this->allowedDealerId($ctx, $head->dealer_id) !== (int) $head->dealer_id) {
            return response()->json(['status' => false, 'message' => 'Cart not found'], 404);
        }

        $order_date = date('Y-m-d', strtotime($request->order_date ?: $head->order_date));
        $lines = DB::table('demand_order_details_cart')->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')->select('demand_order_details_cart.*')->where('demand_order_cart.id', $order_id_for_use)->groupBy('demand_order_details_cart.id')->get();
        $dealer_id = $head->dealer_id;
        $sale_div = $head->sale_div_code ?? '';

        if ($role_id == 37) { $vr_status = 'DO'; } elseif ($role_id == 1) { return response()->json(['status' => false, 'message' => 'Admin cannot finalize'], 403); } else { $vr_status = 'SO'; }

        $order_id = $this->newOrderId($auth_id);
        $detailRows = []; $poRows = []; $total_rs = [];

        foreach ($lines as $v) {
            $total_rs[] = $v->total_rs;
            $detailRows[] = [
                'order_id' => $order_id, 'product_id' => $v->product_id, 'rate' => $v->rate, 'mrp' => $v->mrp, 'quantity' => $v->quantity, 'scheme_qty' => $v->scheme_qty, 'free_qty' => $v->free_qty, 'order_unit' => $v->order_unit, 'order_converstion_unit' => $v->order_converstion_unit, 'purchase_inv' => $v->purchase_inv, 'mfg_date' => $v->mfg_date, 'expiry_date' => $v->expiry_date, 'receive_date' => $v->receive_date, 'batch_no' => $v->batch_no, 'pr_rate' => $v->pr_rate, 'gst' => $v->gst, 'cases' => $v->cases, 'total_rs' => $v->total_rs, 'remarks' => $v->remarks, 'scheme_qty_with_free_qty' => $v->scheme_qty_with_free_qty, 't1_rate' => $v->t1_rate, 'atd_rate' => $v->atd_rate, 't1_rate_perc' => $v->t1_rate_perc,
            ];
            $poRows[] = [
                'id' => $order_id, 'order_id' => $order_id, 'product_id' => $v->product_id, 'mrp' => $v->mrp, 'rate' => $v->rate, 'quantity' => $v->quantity, 'scheme_qty' => $v->scheme_qty, 'cases' => $v->cases, 'pcs' => $v->pcs ?? 0, 'pr_rate' => $v->pr_rate, 'company_id' => $company_id,
            ];
        }

        if (!$detailRows) return response()->json(['status' => false, 'message' => 'Empty cart'], 422);

        $available = DB::table('catalog_product')->whereIn('id', $lines->pluck('product_id')->all())->where('company_id', $company_id)->where('status', 1)->pluck('id')->map(fn($v) => (string) $v)->all();
        $unavailable = $lines->reject(fn($v) => in_array((string) $v->product_id, $available, true))->pluck('product_id')->all();

        if ($unavailable) {
            $names = DB::table('catalog_product')->whereIn('id', $unavailable)->pluck('name')->all() ?: $unavailable;
            return response()->json(['status' => false, 'message' => 'Remove items that are not available for your company before processing: ' . implode(', ', $names)], 422);
        }

        DB::beginTransaction();
        try {
            DB::table('demand_order_details')->insert($detailRows);
            DB::table('purchase_order_details')->insert($poRows);
            DB::table('demand_order')->insert(['order_id' => $order_id, 'dealer_id' => $dealer_id, 'created_date' => $order_date . ' ' . date('H:i:s'), 'order_date' => $order_date, 'created_person_id' => $auth_id, 'date_time' => $order_date . ' ' . date('H:i:s'), 'company_id' => $company_id, 'sale_div_code' => $sale_div, 'csa_id' => $csa_id, 'order_remark' => $head->order_remark ?? '', 'total_value' => array_sum($total_rs), 'vr_no_define' => $vr_status]);
            DB::table('purchase_order')->insert(['id' => $order_id, 'order_id' => $order_id, 'dealer_id' => $dealer_id, 'created_date' => $order_date . ' ' . date('H:i:s'), 'created_person_id' => $auth_id, 'sale_date' => $order_date, 'order_date' => $order_date, 'receive_date' => null, 'date_time' => $order_date . ' ' . date('H:i:s'), 'company_id' => $company_id, 'vehicle_id' => 11, 'dms_manual_order_status' => 0, 'dms_order_reason_id' => 2, 'd_palnt_id' => 0, 'dms_order_confirm_remaks' => 'NA']);

            $cart_order_id = DB::table('demand_order_cart')->where('id', $order_id_for_use)->value('order_id');
            if ($cart_order_id) DB::table('demand_order_details_cart')->where('order_id', $cart_order_id)->delete();
            DB::table('demand_order_cart')->where('id', $order_id_for_use)->delete();

            DB::commit();
            $this->submitOrderToErp((string) $order_id, (int) $dealer_id, (int) $company_id, $detailRows);
            return response()->json(['status' => true, 'message' => 'Order finalized', 'data' => ['order_id' => $order_id]]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Finalize failed', 'error' => config('app.debug') ? $e->getMessage() : 'Something went wrong'], 500);
        }
    }

    /* ============================================================
     |  5) CART MODAL DATA
     * ============================================================ */

    public function cartDataForModal(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyCartDataForModal($request, $ctx);
        }

        $company_id = $ctx['company_id'];
        $dealer_id_cus = $this->allowedDealerId($ctx, $request->dealer_id_cus);

        if (!$dealer_id_cus) return response()->json(['status' => false, 'message' => "You cannot view this distributor's cart."], 403);

        $head = DB::table('demand_order_cart')->join('dealer', 'dealer.id', '=', 'demand_order_cart.dealer_id')->select('dealer.*', 'demand_order_cart.id as ppid', 'demand_order_cart.order_remark as cart_remark')->where('demand_order_cart.dealer_id', $dealer_id_cus)->first();
        $uid = $head->ppid ?? '';
        $order_date = '';
        $final_out = [];

        if ($uid) $final_out = $this->buildCartPayload($dealer_id_cus, $uid, $company_id, $order_date);

        return response()->json([
            'status' => true, 'message' => 'Cart data',
            'data' => ['uid' => $uid, 'order_id' => 'B-' . $uid, 'order_date' => $order_date, 'party_name' => $head->name ?? '', 'order_remark' => $head->cart_remark ?? '', 'final_out' => $final_out, 'dealer_id_cus' => $dealer_id_cus],
        ]);
    }

    private function buildCartPayload($dealer_id_cus, $uid, $company_id, &$order_date = null)
    {
        $rows = DB::table('demand_order_cart')
            ->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
            ->leftJoin('catalog_product', 'catalog_product.id', '=', 'demand_order_details_cart.product_id')
            ->leftJoin('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            ->select(array_merge([
                'demand_order_cart.order_id     as cart_order_id',
                'demand_order_cart.order_date   as cart_order_date',
                'demand_order_details_cart.*',
                'demand_order_details_cart.id   as pid',
            ], $this->orderLineColumns()))
            ->where('demand_order_cart.dealer_id', $dealer_id_cus)
            ->where('demand_order_cart.id', $uid)
            ->orderBy('catalog_2.id')
            ->orderBy('demand_order_details_cart.id')
            ->get();

        $order_date = $rows->first()->cart_order_date ?? $order_date;

        // ✅ Wrap into [ {"ASV": [...]}, {"OTC": [...]} ] array shape
        $keyed = $this->groupOrderLines($rows);
        $final_out = [];
        foreach ($keyed as $group) {
            $final_out[] = [$group['name'] => $group['rows']];
        }
        return $final_out;
    }

    private function orderLineColumns(): array
    {
        return ['catalog_product.id as cp_id', 'catalog_product.name as cp_name', 'catalog_product.quantity_per_case as cp_qty_per_case', 'catalog_product.gst_percent as cp_gst', 'catalog_2.id as cat2_id', 'catalog_2.name as cat2_name'];
    }

    private function groupOrderLines($rows): array
{
    $final_out = [];
    foreach ($rows as $v) {
        $catId   = $v->cat2_id   ?: 'uncategorized';
        $catName = $v->cat2_name ?: 'Uncategorized';
        if (!isset($final_out[$catId])) $final_out[$catId] = ['name' => $catName, 'rows' => []];

        // Same convention as legacyBuildCartPayload:
        // `quantity` = TOTAL, `billed_qty` = BILLED, `free_qty` = FREE.
        $billed = (float) $v->quantity;
        $free   = (float) $v->free_qty;

        $final_out[$catId]['rows'][] = [
            'total_rs'               => $v->total_rs,
            'pid'                    => $v->pid,
            'order_id'               => $v->cart_order_id,
            'order_converstion_unit' => $v->order_converstion_unit,
            'ITEM_CODE'              => $v->cp_id ?? $v->product_id,
            'ITEM_NAME'              => $v->cp_name ?? ('Product #' . $v->product_id),
            'rate'                   => $v->rate,
            'order_unit'             => $v->order_unit,

            'quantity'               => $billed + $free,   // TOTAL
            'billed_qty'             => $billed,           // BILLED
            'free_qty'               => $free,             // FREE

            'quantity_per_case'      => $v->cp_qty_per_case,
            'gst_percent'            => $v->cp_gst ?? '0',
            'remarks'                => $v->remarks,
            't1_rate'                => $v->t1_rate,
            'atd_rate'               => $v->atd_rate,
             'billed_qty'        => $billed,
    'conversion_factor' => (float) ($v->cp_qty_per_case ?? 0), 
        ];
    }
    return $final_out;
}

    /* ============================================================
     |  6) DELETE CART ITEM
     * ============================================================ */

     public function deleteCartItem(Request $request)
    {
        $request->validate(['id' => 'required|integer', 'order_id' => 'required|integer', 'product_id' => 'required|integer']);
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyDeleteCartItem($request, $ctx);
        }

        $cart = DB::table('demand_order_cart')->where('id', $request->order_id)->first();
        if (!$cart || $this->allowedDealerId($ctx, $cart->dealer_id) !== (int) $cart->dealer_id) return response()->json(['status' => false, 'message' => 'Cart not found'], 404);

        DB::table('demand_order_details_cart')->where('product_id', $request->product_id)->where('id', $request->id)->where('order_id', $cart->order_id)->delete();
        DB::table('demand_order_cart')->where('id', $cart->id)->update(['total_value' => DB::table('demand_order_details_cart')->where('order_id', $cart->order_id)->sum('total_rs')]);

        // ✅ FIX: Rebuild the cart payload correctly using the existing helper method
        $order_date = null;
        $final_out = $this->buildCartPayload($cart->dealer_id, $request->order_id, $ctx['company_id'], $order_date);
        
        // Fetch the head again after updates
        $head = DB::table('demand_order_cart')->where('id', $request->order_id)->first();

        return response()->json([
            'status' => true, 
            'message' => 'Item removed', 
            'data' => [
                'uid' => $request->order_id, 
                'order_id' => 'B-' . $request->order_id, 
                'order_date' => $head->order_date ?? '', 
                'dealer_id_cus' => $head->dealer_id ?? $ctx['dealer_id'], 
                'final_out' => $final_out
            ]
        ]);
    }
    /* ============================================================
     |  7) EDIT
     * ============================================================ */

         public function edit(Request $request, $id)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyEdit($request, $ctx, $id);
        }

        $company_id = $ctx['company_id'];
        try { $uid = Crypt::decryptString($id); } catch (\Throwable $e) { $uid = (int) $id; }

        $order = DB::table('demand_order')->where('id', $uid)->first();
        if (!$this->canAccessOrder($ctx, $order)) return response()->json(['status' => false, 'message' => 'Order not found'], 404);

        $converstion_unit_item_code = DB::table('catalog_product')->where('company_id', $company_id)->groupBy('id')->pluck('quantity_per_case', 'id');
        $head = DB::table('dealer')->where('id', $order->dealer_id)->first();
        
        $rows = DB::table('demand_order_details')
            ->leftJoin('catalog_product', 'catalog_product.id', '=', 'demand_order_details.product_id')
            ->leftJoin('catalog_2', 'catalog_2.id', '=', 'catalog_product.catalog_id')
            ->select(array_merge([
                'demand_order_details.*', 
                'demand_order_details.id as pid', 
                'demand_order_details.order_id as cart_order_id'
            ], $this->orderLineColumns()))
            ->where('demand_order_details.order_id', (string) $order->order_id)
            ->orderBy('catalog_2.id')
            ->orderBy('demand_order_details.id')
            ->get();

        // ✅ FIX: Convert keyed object to legacy array format
        $keyed = $this->groupOrderLines($rows);
        $mktg_cat_array = array_map(fn($g) => $g['name'], $keyed);
        
        $final_out = [];
        foreach ($keyed as $group) {
            $final_out[] = [$group['name'] => $group['rows']];
        }

        $order_date = $order->order_date ?? '';
        $currMonth = date('Y-m');

        $checkSuper = DB::table('dealer')->where('dealer.company_id', $company_id)->where('id', $ctx['dealer_id'])->first();
        $currMonthOrder = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->select(DB::raw("SUM(rate*quantity) as demand_value"))->where('dealer_id', $ctx['dealer_id'])->whereRaw("DATE_FORMAT(demand_order.order_date ,'%Y-%m')=?", [$currMonth])->first();

        return response()->json([
            'status' => true, 
            'message' => 'Order detail',
            'data' => [
                'order_id' => 'B-' . $uid, 
                'party_name' => $head->name ?? '', 
                'order_remark' => $order->order_remark ?? '', 
                'order_date' => $order_date, 
                'mktg_cat_array' => $mktg_cat_array, 
                'final_out' => $final_out, 
                'converstion_unit_item_code' => $converstion_unit_item_code, 
                'checkSuper' => $checkSuper, 
                'currMonthOrder' => $currMonthOrder
            ],
        ]);
    }

    public function invoiceDetails(Request $request)
    {
        $ctx = $this->context($request);
        $from_date = $request->filled('from_date') ? date('Y-m-d', strtotime($request->from_date)) : date('Y-m-d');
        $to_date = $request->filled('to_date') ? date('Y-m-d', strtotime($request->to_date)) : date('Y-m-d');
        if ($from_date < '2021-04-01') $from_date = '2021-04-01';

        $q = DB::table('purchase_order')
            ->join('purchase_order_details', 'purchase_order_details.order_id', '=', 'purchase_order.order_id')
            ->select('purchase_order.invoice_no_p', DB::raw('MAX(purchase_order.sale_date) as sale_date'), DB::raw("GROUP_CONCAT(DISTINCT purchase_order_details.order_id ORDER BY purchase_order_details.order_id SEPARATOR ', ') as order_ids"), DB::raw("MAX(NULLIF(purchase_order.remarks, '')) as remarks"), DB::raw("MAX(NULLIF(NULLIF(purchase_order.pdf_name2, ''), '0')) as pdf"), DB::raw('SUM(purchase_order_details.inv_busy_amt) as amount'))
            ->where('purchase_order.dealer_id', $ctx['dealer_id'])->whereNotIn('purchase_order.invoice_no_p', ['0', ''])->whereDate('purchase_order.sale_date', '>=', $from_date)->whereDate('purchase_order.sale_date', '<=', $to_date);

        if ($request->filled('ref_no')) {
            $ref = '%' . trim((string) $request->ref_no) . '%';
            $q->where(function ($w) use ($ref) {
                $w->where('purchase_order.invoice_no_p', 'LIKE', $ref)->orWhere('purchase_order_details.order_id', 'LIKE', $ref);
            });
        }

        $rows = $q->groupBy('purchase_order.invoice_no_p')->orderBy('sale_date', 'DESC')->orderBy('purchase_order.invoice_no_p', 'ASC')->get();

        return response()->json(['status' => true, 'data' => ['from_date' => $from_date, 'to_date' => $to_date, 'rows' => $rows]]);
    }

    /* ============================================================
     |  10) PRODUCT RATE LOOKUP
     * ============================================================ */

    public function productRate(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyProductRate($request, $ctx);
        }

        $company_id = $ctx['company_id'];
        $item_code = $request->item_code;
        $dealer_id_cus = $this->allowedDealerId($ctx, $request->dealer_id_cus) ?? $ctx['dealer_id'];
        $catg = $request->catg;
        $item_codes_str = array_filter(explode(',', (string) $request->item_codes_str));

        $blocked = DB::table('demand_order_cart')->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')->where('dealer_id', $dealer_id_cus)->groupBy('product_id')->pluck('product_id');
        $productsQ = DB::table('catalog_product')->where('company_id', $company_id)->where('status', '=', '1')->whereNotIn('id', $item_codes_str);
        if (!empty($catg)) $productsQ->where('catalog_id', $catg);
        if ($blocked->isNotEmpty()) $productsQ->whereNotIn('id', $blocked);

        $products = $productsQ->groupBy('id')->orderBy('id', 'ASC')->get(['id', 'itemcode', 'name']);
        $item_mast = DB::table('catalog_product')->where('company_id', $company_id)->where('id', $item_code)->first();
        $base = $item_mast ? $this->dealerRate($ctx, $item_code, $request->for_self_for_db) : null;

        if (!$base) return response()->json(['status' => false, 'code' => 401, 'message' => 'Rate not found', 'data' => ['products' => $products]]);

        $qty = (float) $request->input('qty', 0);
        $scheme = $this->dealerScheme($ctx, $item_code, $qty, $base['rate']);

        return response()->json(['status' => true, 'code' => 200, 'message' => 'success', 'data' => ['result' => $base['rate'], 'item_mast_name' => $item_mast->name ?? '', 'item_aum_mast_array' => $products, 'selection_size_um' => $base['um'], 'case_size' => $item_mast->quantity_per_case ?? null, 'scheme_rate' => $scheme['rate'], 'scheme_percent' => $scheme['percent'], 'line_total' => $this->lineTotal($scheme['rate'], $qty, $scheme['percent'])]]);
    }

    /* ============================================================
     |  14) Dealer Dashboard
     * ============================================================ */

    public function dealerDashboard(Request $request)
    {
        $ctx = $this->context($request);
        if ($this->isLegacy($ctx)) {
            return $this->legacyDealerDashboard($request, $ctx);
        }

        $dealerId = (int) $ctx['dealer_id'];
        $domainUrl = (string) DB::table('company')->where('id', $ctx['company_id'])->value('domain_url');
        $banners = DB::table('image_mast')->where('img_locn', 'Dashboard')->where('company_id', $ctx['company_id'])->orderBy('id', 'DESC')->pluck('img')->map(fn($img) => ['url' => "https://baidyanathjhansi.msell.in/public/baidyanath_images/{$img}", 'video' => strtolower(pathinfo($img, PATHINFO_EXTENSION)) === 'mp4']);
        $entries = DB::table('dealer_ledger_busy_details')->where('dealer_id', $dealerId)->orderBy('trans_date')->orderBy('id')->get(['trans_date', 'vocuher_number', 'vocuher_type', 'narration', 'amount', 'op_bal']);
        $opening = round((float) $entries->whereNull('trans_date')->sum('op_bal'), 2);
        $rows = $entries->whereNotNull('trans_date')->values()->map(fn($r) => ['date' => $r->trans_date, 'voucher_no' => $r->vocuher_number, 'vch_type' => $r->vocuher_type, 'account' => $r->narration, 'amount' => (float) $r->amount]);
        $summary = DB::table('dealer_ledger_busy')->where('dealer_id', $dealerId)->orderByDesc('end_date')->first(['closing_balance', 'server_date_time']);

        return response()->json(['status' => true, 'message' => 'Dealer dashboard', 'data' => ['banners' => $banners, 'account' => ['name' => $ctx['dealer']->name ?? '', 'erp_code' => $ctx['dealer_code']], 'ledger' => ['opening_balance' => $opening, 'closing_balance' => round($summary ? (float) $summary->closing_balance : $opening + $rows->sum('amount'), 2), 'fetched_at' => $summary->server_date_time ?? null, 'rows' => $rows]]]);
    }

    public function orderFormat()
    {
        return response()->streamDownload(function () { echo "SNO,Product Id,Quantity\n"; }, 'orderFormat.csv', ['Content-Type' => 'text/csv']);
    }

    /* ============================================================
     | ============================================================
     |  LEGACY IMPLEMENTATIONS  (only when company_id === 405)
     | ============================================================
     | ============================================================ */

    private function legacyAuthId(array $ctx): string
    {
        return (string) ($ctx['user']->id ?? $ctx['dealer_id']);
    }

    /**
     * FIXED: Prevent BIGINT limit overflow (9223372036854775807)
     */
    private function legacyNewOrderId(array $ctx): string
    {
        $auth_id = $this->legacyAuthId($ctx);
        $id = date('YmdHis') . $auth_id;

        // If ID > 19 digits, shorten the year to fit in BIGINT
        return strlen($id) < 19 || (strlen($id) === 19 && strcmp($id, (string) PHP_INT_MAX) <= 0)
            ? $id
            : date('ymdHis') . $auth_id;
    }

    private function legacyDealerRate(string $itemCode): ?array
    {
        $itemCode = trim($itemCode);
        $display = DB::table('WWW_WEB_ITEM_DISPLAY')->where('ITEM_CODE', $itemCode)->where('ITEM_STATUS', '!=', 'C')->first(['ITEM_NAME', 'MIN_QTY', 'PUDA_SIZE']);
        if ($itemCode === '' || !$display) {
            return null;
        }

        $row = DB::table('ITEM_RATE_MAST')
            ->where('ITEM_CODE', $itemCode)
            ->where(fn ($q) => $q->whereNull('TO_DATE')->orWhereIn('TO_DATE', ['', 'NULL', '0000-00-00'])->orWhere('TO_DATE', '>=', date('Y-m-d')))
            ->orderByDesc('id')
            ->first(['WP', 'MRP']);
        if (!$row) {
            return null;
        }

        return [
            'rate' => (float) ($row->WP ?? 0),
            'mrp' => (float) ($row->MRP ?? 0),
            'um' => trim((string) $display->MIN_QTY) === 'B' ? 'BOX' : 'PCS',
            'um_to_aum' => (float) DB::table('ITEM_AUM_MAST')->where('ITEM_CODE', $itemCode)->value('UMTOAUM'),
            'puda_size' => (float) $display->PUDA_SIZE,
            'item_name' => (string) $display->ITEM_NAME,
        ];
    }


private function legacyBuildCartPayload(int $dealer_id, int $uid): array
{
    $records = DB::table('demand_order_cart')
        ->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
        ->leftJoin('WWW_WEB_ITEM_DISPLAY', 'WWW_WEB_ITEM_DISPLAY.ITEM_CODE', '=', 'demand_order_details_cart.product_id')
        ->leftJoin('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'demand_order_details_cart.product_id')   // ← NEW
        ->select(
            'demand_order_cart.*',
            'demand_order_details_cart.*',
            'demand_order_details_cart.id as pid',
            'WWW_WEB_ITEM_DISPLAY.ITEM_NAME',
            'WWW_WEB_ITEM_DISPLAY.MKTG_CATG',
            'ITEM_AUM_MAST.UMTOAUM as UM_TO_AUM'                        // ← NEW
        )
        ->where('demand_order_cart.dealer_id', $dealer_id)
        ->where('demand_order_cart.id', $uid)
        ->groupBy('demand_order_details_cart.id')
        ->get();

    $final_out_map = [];
    foreach ($records as $v) {
        $catKey = $v->MKTG_CATG ?? 'Uncategorized';
        if (!isset($final_out_map[$catKey])) $final_out_map[$catKey] = [];

        $total = (float) $v->quantity;
        $free  = (float) $v->free_qty;

        $final_out_map[$catKey][] = [
            'total_rs'               => $v->total_rs,
            'pid'                    => $v->pid,
            'order_id'               => $v->order_id,
            'order_converstion_unit' => $v->order_converstion_unit,
            'ITEM_CODE'              => $v->product_id,
            'ITEM_NAME'              => $v->ITEM_NAME ?? ('Product #' . $v->product_id),
            'rate'                   => $v->rate,
            'order_unit'             => $v->order_unit,
            'quantity'               => $total,
            'billed_qty'             => $total - $free,
            'free_qty'               => $free,
            'remarks'                => $v->remarks,
            't1_rate'                => $v->t1_rate,
            'atd_rate'               => $v->atd_rate,
            'conversion_factor'      => (float) ($v->UM_TO_AUM ?? 0),   // ← NEW
        ];
    }

    $final_out = [];
    foreach ($final_out_map as $catName => $rows) {
        $final_out[] = [$catName => $rows];
    }
    return $final_out;
}


    private function legacyIndex(Request $request, array $ctx)
    {
        $dealer_id   = (int) $ctx['dealer_id'];
        $role_id     = (int) $ctx['role_id'];
        $dealer_code = (string) $ctx['dealer_code'];
        $erp_company_id = self::LEGACY_ERP_COMPANY_ID;

        if (empty($request->date_range_picker)) {
            $from_date = date('Y-m') . '-01'; $to_date = date('Y-m-d');
        } else {
            $parts = explode(" -", $request->date_range_picker);
            $from_date = date('Y-m-d', strtotime(trim($parts[0]))); $to_date = date('Y-m-d', strtotime(trim($parts[1])));
        }

        $order_no = $request->order_no;
        $acc = DB::table('ACC_MAST')->where('ACC_CODE', $dealer_code)->first();
        $login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first();

        if ($dealer_code === '00001') {
            $div_code = !empty($acc->DIV_CODE) ? $acc->DIV_CODE : 'JH';
        } else {
            $div_code_else = !empty($login->div_code_main) ? $login->div_code_main : '0';
            $div_code = !empty($acc->DIV_CODE) ? $acc->DIV_CODE : $div_code_else;
        }

        $recordsQ = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")->groupBy('demand_order.id')->orderBy('demand_order.id', 'DESC');

        if (!empty($order_no)) {
            $recordsQ->where(function ($q) use ($order_no) {
                $q->where('demand_order.order_id', 'LIKE', '%' . $order_no . '%')->orWhere('erp_order_no', 'LIKE', '%' . $order_no . '%');
            });
        }

        if ($role_id === 37) {
            if ($dealer_id === 1170) {
                $records = $recordsQ->select('demand_order.*', 'demand_order.id as order_id', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->where('demand_order.dealer_id', $dealer_id)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            } else {
                $records = $recordsQ->select('demand_order.*', 'demand_order.id as order_id', 'ACC_MAST.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->join('ACC_MAST', 'ACC_MAST.ACC_CODE', '=', 'dealer.dealer_code')->where('sale_div_code', $div_code)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            }
        } else {
            $records = $recordsQ->select('demand_order.*', 'demand_order.id as order_id', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->where('demand_order.dealer_id', $dealer_id)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
        }

        if ($dealer_id !== 1170 && $role_id === 37) {
            $depoQ = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")->groupBy('demand_order.id')->orderBy('demand_order.id', 'DESC');
            if (!empty($order_no)) {
                $depoQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', '%' . $order_no . '%')->orWhere('erp_order_no', 'LIKE', '%' . $order_no . '%');
                });
            }
            $records_depo = $depoQ->select('demand_order.*', 'demand_order.id as order_id', 'ACC_MAST.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"))->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->join('ACC_MAST', 'ACC_MAST.ACC_CODE', '=', 'dealer.dealer_code')->where('dealer_id', $dealer_id)->orderBy('demand_order.order_date', 'DESC')->get()->toArray();
            $records = array_merge($records_depo, $records);
        }

        $depo_filter = []; $dealer_name = [];

        if ($role_id === 1) {
            $adminQ = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->join('ACC_MAST', 'ACC_MAST.ACC_CODE', '=', 'dealer.dealer_code')->whereRaw("date_format(demand_order.order_date,'%Y-%m-%d')>='$from_date' and date_format(demand_order.order_date,'%Y-%m-%d')<='$to_date'")->groupBy('demand_order.id')->orderBy('demand_order.id', 'DESC')->select('demand_order.*', 'dealer.dealer_code as dealer_code', 'demand_order.id as order_id', 'ACC_MAST.*', DB::raw("(SUM((total_rs-t1_rate-atd_rate)+atd_rate)) as total_value"));
            if (!empty($order_no)) {
                $adminQ->where(function ($q) use ($order_no) {
                    $q->where('demand_order.order_id', 'LIKE', '%' . $order_no . '%')->orWhere('erp_order_no', 'LIKE', '%' . $order_no . '%');
                });
            }
            if (!empty($request->depo_filter)) $adminQ->whereIn('demand_order.sale_div_code', (array) $request->depo_filter);
            if (!empty($request->distributor)) $adminQ->whereIn('demand_order.dealer_id', (array) $request->distributor);
            $records = $adminQ->get()->toArray();
            $depo_filter = DB::table('dealer')->join('dealer_person_login', 'dealer_person_login.dealer_id', '=', 'dealer.id')->where('dealer_status', 1)->where('role_id', 37)->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as dealer_name"), 'div_code_main');
            $dealer_name = DB::table('dealer')->where('dealer_status', 1)->pluck(DB::raw("CONCAT(dealer_code,' - ',name) as name"), 'id');
        }

        $erp_order_no = DB::table('purchase_order')->where('dealer_id', $dealer_id)->get()->groupBy('order_id');

        return response()->json(['status' => true, 'message' => 'Order list fetched (legacy)', 'data' => compact('records', 'erp_order_no', 'depo_filter', 'dealer_name') + ['role_id' => $role_id, 'dealer_id' => $dealer_id, 'company_id' => (int) $ctx['company_id'], 'erp_company_id' => $erp_company_id, 'dealer_code' => $dealer_code, 'from_date' => $from_date, 'to_date' => $to_date, 'legacy' => true]]);
    }

    private function legacyCreateData(Request $request, array $ctx)
    {
        $dealer_id = (int) $ctx['dealer_id']; $dealer_code = (string) $ctx['dealer_code']; $role_id = (int) $ctx['role_id']; $csa_id = (int) $ctx['csa_id']; $erp_company = self::LEGACY_ERP_COMPANY_ID;
        $acc = DB::table('ACC_MAST')->where('ACC_CODE', $dealer_code)->first(); $login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first();

        if ($dealer_code === '00001') {
            $div_code = !empty($acc->DIV_CODE) ? $acc->DIV_CODE : 'JH';
        } else {
            $div_code_else = !empty($login->div_code_main) ? $login->div_code_main : '0'; $div_code = !empty($acc->DIV_CODE) ? $acc->DIV_CODE : $div_code_else;
        }

        $domainUrl = 'baidyanathjhansi.msell.in';

$image_dyn = DB::table('image_mast')
    ->where('status', 1)
    ->where('img_locn', 'Order')
    ->where('company_id', $ctx['company_id'])
    ->orderBy('id', 'DESC')
    ->get()
    ->map(function ($img) {
        $filename = ltrim($img->img, '/');
        
        // Space ko %20 se replace karne ka sahi tarika
        $encoded  = str_replace(' ', '%20', $filename);
        
        return [
            // Ab yahan local URL banega
            'url' => url("/baidyanath_images/{$encoded}"),
            'video' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4',
            'img'   => $filename,   // backward compat
        ];
    });
        $div_code_master = DB::table('_div_code_master')->where('status', '1')->pluck('name', 'id');
        $fetch_year = DB::table('financial_year')->orderBy('id', 'DESC')->first();
        $from_year_t = !empty($fetch_year->from_year) ? $fetch_year->from_year : date('Y'); $to_year_t = !empty($fetch_year->to_year) ? $fetch_year->to_year : (date('Y') + 1);
        $from_sale_date = $from_year_t . "-04-01"; $to_sale_date = $to_year_t . "-03-31"; $from_date_month_sale = date('Y-m') . '-01'; $to_date_month_sale = date('Y-m-t');

        $record_product_id = DB::table('demand_order_cart')->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')->join('WWW_WEB_ITEM_DISPLAY', 'WWW_WEB_ITEM_DISPLAY.ITEM_CODE', '=', 'demand_order_details_cart.product_id')->where('demand_order_cart.dealer_id', $dealer_id)->groupBy('demand_order_details_cart.product_id')->pluck('product_id')->toArray();

        $cart_details = DB::table('demand_order_details_cart')->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')->select(DB::raw("COUNT(demand_order_details_cart.product_id) as count"), DB::raw("SUM(total_rs-t1_rate-atd_rate+atd_rate) as sale_vale"))->where('demand_order_cart.dealer_id', $dealer_id)->first();

        $catalog_product_details = DB::table('WWW_WEB_ITEM_DISPLAY')
            ->select('ITEM_CODE as id', 'ITEM_NAME', DB::raw('IFNULL(PUDA_SIZE, 1) as caseSize'), DB::raw("TRIM(IFNULL(MKTG_CATG, 'Uncategorized')) as mktg_catg"))
            ->whereNotIn('ITEM_CODE', $record_product_id)->where('ITEM_STATUS', '!=', 'C')->orderBy('ITEM_CODE', 'ASC')->get();

        $categories = DB::table('MKTG_CATG_MAST')->select('MKTG_CATG as id', 'MKTG_CATG_NAME as name')->orderBy('MKTG_CATG', 'ASC')->get();
        $mktg_cat_array = DB::table('MKTG_CATG_MAST')->orderBy('MKTG_CATG', 'ASC')->get();

        return response()->json(['status' => true, 'message' => 'Create data fetched (legacy)', 'data' => ['legacy' => true, 'dealer_id' => $dealer_id, 'company_id' => (int) $ctx['company_id'], 'erp_company_id' => $erp_company, 'role_id' => $role_id, 'csa_id' => $csa_id, 'dealer_code' => $dealer_code, 'div_code' => $div_code, 'div_code_master' => $div_code_master, 'image_dyn' => $image_dyn, 'catalog_product_details' => $catalog_product_details, 'categories' => $categories, 'mktg_cat_array' => $mktg_cat_array, 'converstion_unit_item_code' => DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE'), 'sale_value_cart' => !empty($cart_details->sale_vale) ? $cart_details->sale_vale : '0', 'count_prod_cart' => !empty($cart_details->count) ? $cart_details->count : '0', 'quantity_type' => !empty($login->order_quantity_type) ? $login->order_quantity_type : 0]]);
    }



private function legacyStore(Request $request, array $ctx)
{
    if ($request->hasFile('uploadExcelData')) {
        return response()->json([
            'status'  => false,
            'message' => 'CSV upload is not supported on this company. Please add items individually.',
        ], 422);
    }

    $auth_id     = $this->legacyAuthId($ctx);
    $dealer_id   = (int) $ctx['dealer_id'];
    $role_id     = (int) $ctx['role_id'];
    $csa_id      = (int) $ctx['csa_id'];
    $erp_company = self::LEGACY_ERP_COMPANY_ID;
    $fail        = fn (string $message, int $code = 422) => response()->json(['status' => false, 'message' => $message], $code);

    if ($role_id === 1) {
        return $fail('Admin cannot place order here', 403);
    }

    if ($role_id === 37) {
        $depo_dealer_status = 'DO';
        $sale_div_code = $request->sale_div_code ?: 'JH';
    } else {
        $depo_dealer_status = 'SO';
        $sale_div_code = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->value('div_code_main');
        if (empty($sale_div_code)) {
            $sale_div_code = DB::table('ACC_MAST')->where('ACC_CODE', $ctx['dealer_code'])->value('DIV_CODE') ?: 'JH';
        }
    }

    $item_codes = array_values((array) ($request->item_code ?? []));
    $qtys       = array_values((array) ($request->qty ?? []));
    $terms      = array_values((array) ($request->scheme_term ?? []));
    $remarks    = array_values((array) ($request->remarks ?? []));

    $check = DB::table('demand_order_cart')->where('dealer_id', $dealer_id)->first();
    $inCart = $check
        ? DB::table('demand_order_details_cart')->where('order_id', $check->order_id)->pluck('product_id')->map(fn ($v) => (string) $v)->all()
        : [];

    $lines = [];
    foreach ($item_codes as $k => $code) {
        $code = trim((string) $code);
        $title = (float) ($qtys[$k] ?? 0);
        if ($code === '' || $title <= 0) {
            continue;
        }
        if (in_array($code, $inCart, true) || isset($lines[$code])) {
            return $fail("Item {$code} is already in the order.");
        }
        $term = (int) ($terms[$k] ?? 0);
        $priced = $this->legacyPricedLine($ctx, $code, $term, $title);
        if (isset($priced['error'])) {
            return $fail($priced['error']);
        }
        $lines[$code] = $priced + ['remarks' => mb_substr(trim((string) ($remarks[$k] ?? '')), 0, 25)];
    }

    $updating = $request->update_qty_status == '1';
    if (!$updating && !$lines) {
        return $fail('Add at least one item with a quantity.');
    }

    DB::beginTransaction();
    try {
        if ($check) {
            $order_id   = $check->order_id;
            $uid        = $check->id;
            $order_date = $check->order_date;
            if (filled($request->final_remarks)) {
                DB::table('demand_order_cart')->where('id', $uid)->update(['order_remark' => mb_substr((string) $request->final_remarks, 0, 150)]);
            }
        } else {
            $order_id   = $this->legacyNewOrderId($ctx);
            $order_date = date('Y-m-d');
            $uid = DB::table('demand_order_cart')->insertGetId([
                'order_id'          => $order_id,
                'dealer_id'         => $dealer_id,
                'created_date'      => date('Y-m-d H:i:s'),
                'order_date'        => $order_date,
                'created_person_id' => $auth_id,
                'date_time'         => date('Y-m-d H:i:s'),
                'company_id'        => $erp_company,
                'sale_div_code'     => $sale_div_code,
                'csa_id'            => $csa_id,
                'order_remark'      => mb_substr((string) ($request->final_remarks ?? ''), 0, 150),
                'total_value'       => 0,
                'vr_no_define'      => $depo_dealer_status,
            ]);
        }

        if ($updating) {
            $line = DB::table('demand_order_details_cart')
                ->where('order_id', $order_id)
                ->where('product_id', (string) $request->update_product_id)
                ->first();
            if (!$line) {
                DB::rollBack();
                return $fail('Item not found in your cart.');
            }
            $priced = $this->legacyPricedLine($ctx, (string) $line->product_id, (int) $request->update_scheme_term, (float) $request->update_qty);
            if (isset($priced['error'])) {
                DB::rollBack();
                return $fail($priced['error']);
            }
            DB::table('demand_order_details_cart')->where('id', $line->id)->update($this->legacyCartLineValues($dealer_id, (string) $line->product_id, $priced));
        }

        $rows = [];
        foreach ($lines as $code => $priced) {
            $rows[] = [
                'order_id'     => $order_id,
                'product_id'   => $code,
                'scheme_qty'   => 0,
                'purchase_inv' => '',
                'mfg_date'     => '0000-00-00',
                'expiry_date'  => '0000-00-00',
                'receive_date' => '0000-00-00',
                'batch_no'     => '',
                'pr_rate'      => '',
                'gst'          => '',
                'cases'        => '',
                'remarks'      => $priced['remarks'],
            ] + $this->legacyCartLineValues($dealer_id, $code, $priced);
        }
        if ($rows) {
            DB::table('demand_order_details_cart')->insert($rows);
        }
        DB::table('demand_order_cart')->where('id', $uid)->update([
            'total_value' => DB::table('demand_order_details_cart')->where('order_id', $order_id)->sum('total_rs'),
        ]);

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('[legacy] quick order store failed', ['dealer_id' => $dealer_id, 'error' => $e->getMessage()]);
        return $fail('Cart update failed (legacy)', 500);
    }

    return response()->json([
        'status'  => true,
        'message' => 'Cart updated (legacy)',
        'data'    => [
            'legacy'        => true,
            'uid'           => $uid,
            'order_id'      => 'B-' . $uid,
            'order_date'    => $order_date,
            'dealer_id_cus' => $dealer_id,
            'final_out'     => $this->legacyBuildCartPayload($dealer_id, (int) $uid),
        ],
    ]);
}

private function legacyCartLineValues(int $dealerId, string $itemCode, array $priced): array
{
    [$atdRate, $tdDisc, $t1Pct] = $this->legacyCommonDiscount($dealerId, $itemCode, $priced['total']);
    $qty = LegacySchemeOptions::num($priced['title']);

    return [
        'rate'                     => $priced['rate']['rate'],
        'mrp'                      => $priced['rate']['mrp'],
        'quantity'                 => $qty,
        'free_qty'                 => (int) round($priced['free']),
        'order_unit'               => $priced['rate']['um'],
        'order_converstion_unit'   => $qty,
        'total_rs'                 => $priced['total'],
        'scheme_qty_with_free_qty' => (int) round($priced['title']),
        't1_rate'                  => $tdDisc,
        'atd_rate'                 => $atdRate,
        't1_rate_perc'             => $t1Pct,
    ];
}


   private function legacyStoreFinal(Request $request, array $ctx)
{
    $this->ensureBlockOrderDateColumn();

    $dealer_id   = (int) $ctx['dealer_id'];
    $role_id     = (int) $ctx['role_id'];
    $auth_id     = $this->legacyAuthId($ctx);
    $csa_id      = (int) $ctx['csa_id'];
    $erp_company = self::LEGACY_ERP_COMPANY_ID;

    $order_id_for_use = $request->order_id_for_use;
    $curr_date        = date('Y-m-d');

    // Block-date check
    if (Schema::hasColumn('dealer', 'block_order_date')) {
        $blocked = DB::table('dealer')
            ->where('id', $dealer_id)
            ->whereNotNull('block_order_date')
            ->whereRaw("date_format(block_order_date,'%Y-%m-%d') >= '$curr_date'")
            ->first();
        if (!empty($blocked->id)) {
            return response()->json([
                'status'  => false,
                'message' => 'Ordering is blocked for this dealer until ' . $blocked->block_order_date,
            ], 403);
        }
    }

    // Cart head
    $head = DB::table('demand_order_cart')->where('id', $order_id_for_use)->first();
    if (!$head || (int) $head->dealer_id !== $dealer_id) {
        return response()->json(['status' => false, 'message' => 'Cart not found'], 404);
    }

    // Cart lines
    $lines = DB::table('demand_order_details_cart')
        ->join('demand_order_cart', 'demand_order_cart.order_id', '=', 'demand_order_details_cart.order_id')
        ->select('demand_order_details_cart.*')
        ->where('demand_order_cart.id', $order_id_for_use)
        ->groupBy('demand_order_details_cart.id')
        ->get();

    if ($lines->isEmpty()) {
        return response()->json(['status' => false, 'message' => 'Empty cart'], 422);
    }

    $final_remarks = (string) ($head->order_remark ?? '');
    $sale_div_code = (string) ($head->sale_div_code ?? 'JH');

    if ($role_id === 37) {
        $depo_dealer_status = 'DO';
    } elseif ($role_id === 1) {
        return response()->json(['status' => false, 'message' => 'Admin cannot finalize'], 403);
    } else {
        $depo_dealer_status = 'SO';
    }

    $order_id   = $this->legacyNewOrderId($ctx);
    $detailRows = [];
    $total_rs   = [];

    foreach ($lines as $v) {
        $total_rs[] = $v->total_rs;

        // 🔧 FIX: recompute the tax fields from demand_order_details_cart.total_rs
        // (guards against carts created before the legacyStore fix).
        [$atdRate, $tdDisc, $t1Pct] = $this->legacyCommonDiscount(
            $dealer_id, (string) $v->product_id, (float) $v->total_rs
        );

        $detailRows[] = [
            'order_id'                 => (string) $order_id,
            'product_id'               => (string) $v->product_id,
            'rate'                     => (string) $v->rate,
            'mrp'                      => (string) $v->mrp,
            'quantity'                 => (string) (float) ($v->quantity ?: 0),

            'scheme_qty'               => (string) (float) ($v->scheme_qty ?: 0),
            'purchase_inv'             => (string) ($v->purchase_inv ?? ''),
            'mfg_date'                 => (string) ($v->mfg_date ?: '0000-00-00'),
            'expiry_date'              => (string) ($v->expiry_date ?: '0000-00-00'),
            'receive_date'             => (string) ($v->receive_date ?: '0000-00-00'),
            'batch_no'                 => (string) ($v->batch_no ?? ''),

            'pr_rate'                  => (float) ($v->pr_rate ?: 0),
            'gst'                      => (float) ($v->gst ?: 0),
            'cases'                    => (int)   ($v->cases ?: 0),

            'free_qty'                 => (int)   ($v->free_qty ?: 0),
            'total_rs'                 => (float) ($v->total_rs ?: 0),
            'scheme_qty_with_free_qty' => (int)   ($v->scheme_qty_with_free_qty ?: 0),

            'order_unit'               => (string) ($v->order_unit ?? 'PCS'),
            'order_converstion_unit'   => (string) ($v->order_converstion_unit ?: '0'),
            'remarks'                  => mb_substr((string) ($v->remarks ?? ''), 0, 200),
            't1_rate'                  => (string) $tdDisc,
            'atd_rate'                 => (string) $atdRate,
            't1_rate_perc'             => (string) $t1Pct,
        ];
    }

    DB::beginTransaction();
    try {
        DB::table('demand_order_details')->insert($detailRows);

        DB::table('demand_order')->insert([
            'order_id'          => $order_id,
            'dealer_id'         => $dealer_id,
            'created_date'      => date('Y-m-d H:i:s'),
            'order_date'        => date('Y-m-d'),
            'created_person_id' => $auth_id,
            'date_time'         => date('Y-m-d H:i:s'),
            'company_id'        => $erp_company,
            'sale_div_code'     => $sale_div_code,
            'csa_id'            => $csa_id,
            'order_remark'      => mb_substr($final_remarks, 0, 100),
            'total_value'       => array_sum($total_rs),
            'vr_no_define'      => $depo_dealer_status,
        ]);

        // Clear the cart
        $cart_order_id = DB::table('demand_order_cart')
            ->where('id', $order_id_for_use)
            ->value('order_id');

        if ($cart_order_id) {
            DB::table('demand_order_details_cart')->where('order_id', $cart_order_id)->delete();
        }
        DB::table('demand_order_cart')->where('id', $order_id_for_use)->delete();

        DB::commit();

        return response()->json([
            'status'  => true,
            'message' => 'Order finalized (legacy)',
            'data'    => [
                'legacy'   => true,
                'order_id' => $order_id,
            ],
        ]);
    } catch (\Throwable $e) {
        DB::rollBack();

        Log::error('[legacy] storeFinal failed', [
            'cart_id'   => $order_id_for_use,
            'order_id'  => $order_id,
            'dealer_id' => $dealer_id,
            'error'     => $e->getMessage(),
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'Finalize failed (legacy): ' . $e->getMessage(),
            'error'   => $e->getMessage(),
        ], 500);
    }
}

    private function legacyCartDataForModal(Request $request, array $ctx)
    {
        $dealer_id = (int) $ctx['dealer_id'];
        $head = DB::table('demand_order_cart')->join('dealer', 'dealer.id', '=', 'demand_order_cart.dealer_id')->select('dealer.*', 'demand_order_cart.id as ppid', 'demand_order_cart.order_remark as cart_remark')->where('demand_order_cart.dealer_id', $dealer_id)->first();
        $uid = !empty($head->ppid) ? (int) $head->ppid : 0; $order_date = ''; $final_out = [];

        if ($uid) { $final_out = $this->legacyBuildCartPayload($dealer_id, $uid); $order_date = (string) DB::table('demand_order_cart')->where('id', $uid)->value('order_date'); }

        return response()->json(['status' => true, 'message' => 'Cart data (legacy)', 'data' => ['legacy' => true, 'uid' => $uid ?: '', 'order_id' => 'B-' . $uid, 'order_date' => $order_date, 'party_name' => $head->name ?? '', 'order_remark' => $head->cart_remark ?? '', 'final_out' => $final_out, 'dealer_id_cus' => $dealer_id]]);
    }

    private function legacyDeleteCartItem(Request $request, array $ctx)
    {
        $pid = $request->id; $order_id = $request->order_id; $product_id = $request->product_id;
        $cart = DB::table('demand_order_cart')->where('id', $order_id)->where('dealer_id', (int) $ctx['dealer_id'])->first();
        if (!$cart) return response()->json(['status' => false, 'message' => 'Cart not found'], 404);

        DB::table('demand_order_details_cart')->where('order_id', $cart->order_id)->where('product_id', $product_id)->where('id', $pid)->delete();
        DB::table('demand_order_cart')->where('id', $cart->id)->update(['total_value' => DB::table('demand_order_details_cart')->where('order_id', $cart->order_id)->sum('total_rs')]);

        $final_out = $this->legacyBuildCartPayload((int) $cart->dealer_id, (int) $cart->id);
        return response()->json(['status' => true, 'message' => 'Item removed (legacy)', 'data' => ['legacy' => true, 'uid' => $order_id, 'order_id' => 'B-' . $order_id, 'order_date' => $cart->order_date ?? '', 'dealer_id_cus' => $cart->dealer_id ?? $ctx['dealer_id'], 'final_out' => $final_out]]);
    }

    private function legacyEdit(Request $request, array $ctx, $id)
    {
        try { $uid = Crypt::decryptString($id); } catch (\Throwable $e) { $uid = (int) $id; }
        $dealer_id = (int) $ctx['dealer_id']; $dealer_code = (string) $ctx['dealer_code'];
        $order_details = DB::table('demand_order')->join('dealer', 'dealer.id', '=', 'demand_order.dealer_id')->select('dealer.*', 'demand_order.order_remark as order_remark')->where('demand_order.id', $uid)->first();
        if (!$order_details) return response()->json(['status' => false, 'message' => 'Order not found'], 404);

        $dealer_details = DB::table('ACC_MAST')->where('ACC_CODE', $order_details->dealer_code)->first(); $div_code_entry = DB::table('ACC_MAST')->where('ACC_CODE', $dealer_code)->first();
        if ($dealer_code === '00001') { $div_code = !empty($div_code_entry->DIV_CODE) ? $div_code_entry->DIV_CODE : 'JH'; } else { $div_code = !empty($div_code_entry->DIV_CODE) ? $div_code_entry->DIV_CODE : '0'; }

        $mktg_cat_array = DB::table('MKTG_CATG_MAST')->orderBy('MKTG_CATG', 'ASC')->pluck('MKTG_CATG_NAME as v', 'MKTG_CATG as k');
        $final_out = []; $order_date = '';

        foreach ($mktg_cat_array as $key => $value) {
            $record = DB::table('demand_order')->join('demand_order_details', 'demand_order_details.order_id', '=', 'demand_order.order_id')->join('WWW_WEB_ITEM_DISPLAY', 'WWW_WEB_ITEM_DISPLAY.ITEM_CODE', '=', 'demand_order_details.product_id')->where('WWW_WEB_ITEM_DISPLAY.MKTG_CATG', $key)->where('demand_order.id', $uid)->groupBy('demand_order_details.id')->get()->toArray();
            $out_2 = [];
            foreach ($record as $v) {
                $order_date = $v->order_date;
                $out_2[$key][] = ['total_rs' => $v->total_rs, 'order_converstion_unit' => $v->order_converstion_unit, 'ITEM_CODE' => $v->ITEM_CODE, 'ITEM_NAME' => $v->ITEM_NAME, 'rate' => $v->rate, 'order_unit' => $v->order_unit, 'quantity' => $v->quantity, 'free_qty' => $v->free_qty, 'remarks' => $v->remarks, 't1_rate' => $v->t1_rate, 'atd_rate' => $v->atd_rate];
            }
            $final_out[] = $out_2;
        }

        return response()->json(['status' => true, 'message' => 'Order detail (legacy)', 'data' => ['legacy' => true, 'order_id' => 'B-' . $uid, 'party_name' => $dealer_details->ACC_NAME ?? '', 'order_remark' => $order_details->order_remark ?? '', 'order_date' => $order_date ?? '', 'div_code' => $div_code, 'mktg_cat_array' => $mktg_cat_array, 'final_out' => $final_out, 'converstion_unit_item_code' => DB::table('ITEM_AUM_MAST')->groupBy('ITEM_CODE')->pluck('UMTOAUM', 'ITEM_CODE'), 'item_aum_mast_array' => DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM as v', 'ITEM_CODE as k')]]);
    }



  private function legacyProductRate(Request $request, array $ctx)
{
    $dealer_id      = (int) $ctx['dealer_id'];
    $item_code      = trim((string) $request->item_code);
    $catg           = $request->catg;
    $item_codes_str = explode(',', (string) $request->item_codes_str);

    $blocked = DB::table('demand_order_cart')
        ->join('demand_order_details_cart', 'demand_order_details_cart.order_id', '=', 'demand_order_cart.order_id')
        ->where('dealer_id', $dealer_id)
        ->groupBy('product_id')
        ->pluck('product_id');

    $displayQ = DB::table('WWW_WEB_ITEM_DISPLAY')
        ->where('ITEM_STATUS', '!=', 'C')
        ->whereNotIn('ITEM_CODE', $item_codes_str);
    if (!empty($catg)) $displayQ->where('MKTG_CATG', $catg);
    if ($blocked->isNotEmpty()) $displayQ->whereNotIn('ITEM_CODE', $blocked);

    $item_aum_mast_array = $displayQ->groupBy('ITEM_CODE')
        ->orderBy('ITEM_CODE', 'ASC')
        ->pluck(DB::raw("CONCAT(ITEM_CODE,' - ',ITEM_NAME) AS ITEM_NAME"), 'ITEM_CODE as k');

    $calc = $this->legacySchemeOptionsFor($ctx, $item_code, (int) $request->input('qty', 0));
    $rateInfo = $calc['rate'];
    $error = !$rateInfo ? 'Rate not found for item ' . $item_code
        : ($rateInfo['rate'] <= 0 ? 'Rate is zero for item ' . $item_code
        : ($rateInfo['um'] === 'BOX' && $rateInfo['um_to_aum'] <= 0 ? 'Box size is not configured for item ' . $item_code : null));
    if ($error) {
        return response()->json([
            'status'  => false,
            'code'    => 401,
            'message' => $error,
            'data'    => ['legacy' => true, 'item_aum_mast_array' => $item_aum_mast_array],
        ]);
    }

    $scheme = $calc['scheme'];
    $slabsText = LegacySchemeOptions::slabsText($scheme['slabs']);

    return response()->json([
        'status'  => true,
        'code'    => 200,
        'message' => 'success',
        'data'    => [
            'legacy'              => true,
            'result'              => $rateInfo['rate'],
            'item_mast_name'      => $rateInfo['item_name'],
            'item_aum_mast_array' => $item_aum_mast_array,
            'selection_size_um'   => $rateInfo['um'],
            'case_size'           => $rateInfo['um_to_aum'],
            'puda_size'           => $rateInfo['puda_size'],
            'scheme_rate'         => $rateInfo['rate'],
            'scheme_percent'      => 0,
            'mrp'                 => $rateInfo['mrp'],
            'slabs'               => $slabsText,
            'size'                => $rateInfo['puda_size'],
            'prod'                => $this->itemProdCode($item_code),
            'scheme_slabs'        => [
                'master_text'    => $slabsText,
                'options'        => [],
                'suggestions'    => $calc['options'],
                'scheme_source'  => $scheme['source'],
                'matched_scheme' => $scheme['scheme_no'],
            ],
        ],
    ]);
}

    public function schemeAutocomplete(Request $request)
    {
        $ctx = $this->context($request);
        if (!$this->isLegacy($ctx)) {
            return response()->json(['status' => false, 'message' => 'This endpoint is only available for company 405.'], 403);
        }
        $request->validate(['item_code' => 'required|string|max:50', 'term' => 'required|integer|min:1|max:1000000']);

        $calc = $this->legacySchemeOptionsFor($ctx, trim((string) $request->item_code), (int) $request->term);
        if (!$calc['rate']) {
            return response()->json(['status' => false, 'message' => 'Rate not found for item ' . $request->item_code], 422);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'term'           => (int) $request->term,
                'result'         => $calc['options'],
                'slabs'          => LegacySchemeOptions::slabsText($calc['scheme']['slabs']),
                'scheme_source'  => $calc['scheme']['source'],
                'matched_scheme' => $calc['scheme']['scheme_no'],
            ],
        ]);
    }

private function itemProdCode(string $itemCode): array
{
    $row = DB::table('ITEM_MAST')->where('ITEM_CODE', $itemCode)->first(['PROD_CODE']);
    return $row && $row->PROD_CODE ? [$row->PROD_CODE] : [];
}

    private function legacyDealerDashboard(Request $request, array $ctx)
    {
        $dealer_id = (int) $ctx['dealer_id'];
        $dealer_code = (string) $ctx['dealer_code'];

        // 1. Division Code Calculation
        $div_code_entry = DB::table('ACC_MAST')->where('ACC_CODE', $dealer_code)->first();
        $deiv_code_login = DB::table('dealer_person_login')->where('dealer_id', $dealer_id)->first(); 
        $div_code_else = !empty($deiv_code_login->div_code_main) ? $deiv_code_login->div_code_main : '0';
        $div_code = !empty($div_code_entry->DIV_CODE) ? $div_code_entry->DIV_CODE : $div_code_else;

        // 2. Financial Year Dates
        $fetch_year = DB::table('financial_year')->orderBy('id','DESC')->first();
        $from_year_t = !empty($fetch_year->from_year) ? $fetch_year->from_year : date('Y');
        $to_year_t   = !empty($fetch_year->to_year) ? $fetch_year->to_year : (date('Y')+1);
        $from_year_d = !empty($fetch_year->from_year) ? $fetch_year->from_year : date('Y');
        $to_year_d   = !empty($fetch_year->to_year) ? $fetch_year->to_year : (date('Y')+1);

        $from_target_date = $from_year_t . "-04-01";
        $to_target_date   = $to_year_t . "-03-31";
        $from_sale_date   = $from_year_d . "-04-01";
        $to_sale_date     = $to_year_d . "-03-31";
        
        $from_date_month_sale = date('Y-m') . '-01';
        $to_date_month_sale   = date('Y-m-t');

        // 3. Orders & Lookups
        $paginate = !empty($request->perpage) ? $request->perpage : 10;
        $records = DB::table('demand_order')->where('dealer_id', $dealer_id)->paginate($paginate);
        
        $converstion_unit_item_code = DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE');
        $catalog_product_details = DB::table('WWW_WEB_ITEM_DISPLAY')
            ->select(DB::raw("CONCAT(ITEM_NAME,' (',ITEM_CODE,')') AS ITEM_NAME"))
            ->where('ITEM_STATUS','!=','C')->orderBy('ITEM_CODE','ASC')->get();
        $mktg_cat_array = DB::table('MKTG_CATG_MAST')->orderBy('MKTG_CATG','ASC')->get();

        // 4. ASV Specific Data
        $asv_wise_target_data = DB::table('DEALER_TARGET_MAST')
            ->where('DEALER_TARGET_MAST.PROD_CATG','ASV')
            ->where('DEALER_TARGET_MAST.FROM_DATE', '=', $from_target_date)
            ->where('DEALER_TARGET_MAST.TO_DATE', '=', $to_target_date)
            ->where('DEALER_TARGET_MAST.ACC_CODE', $dealer_code);
        $asv_wise_target = !empty($asv_wise_target_data->first()->TARGET_QTY) ? $asv_wise_target_data->first()->TARGET_QTY : 0;

        $asv_wise_sale_data = DB::table('ITEMTRAN_HEAD')
            ->join('ITEMTRAN_BODY', function($join){
                $join->on('ITEMTRAN_BODY.VRNO', '=', 'ITEMTRAN_HEAD.VRNO');
                $join->on('ITEMTRAN_BODY.DIV_CODE', '=', 'ITEMTRAN_HEAD.DIV_CODE');
            })
            ->join('ITEM_MAST','ITEM_MAST.ITEM_CODE','=','ITEMTRAN_BODY.ITEM_CODE')
            ->join('ITEM_AUM_MAST','ITEM_AUM_MAST.ITEM_CODE','=','ITEM_MAST.ITEM_CODE')
            ->join('PROD_CATG_MAST','PROD_CATG_MAST.PROD_CATG','=','ITEM_MAST.PROD_CATG')
            ->join('MKTG_CATG_MAST','MKTG_CATG_MAST.MKTG_CATG','=','PROD_CATG_MAST.MKTG_CATG')
            ->select(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED/ITEM_AUM_MAST.UMTOAUM) as QTYISSUED'))
            ->where('MKTG_CATG_MAST.MKTG_CATG', 'ASV')
            ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'") 
            ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
            ->first();

        $montha_sv_wise_sale_data = DB::table('ITEMTRAN_HEAD')
            ->join('ITEMTRAN_BODY', function($join){
                $join->on('ITEMTRAN_BODY.VRNO', '=', 'ITEMTRAN_HEAD.VRNO');
                $join->on('ITEMTRAN_BODY.DIV_CODE', '=', 'ITEMTRAN_HEAD.DIV_CODE');
            })
            ->join('ITEM_MAST','ITEM_MAST.ITEM_CODE','=','ITEMTRAN_BODY.ITEM_CODE')
            ->join('ITEM_AUM_MAST','ITEM_AUM_MAST.ITEM_CODE','=','ITEM_MAST.ITEM_CODE')
            ->join('PROD_CATG_MAST','PROD_CATG_MAST.PROD_CATG','=','ITEM_MAST.PROD_CATG')
            ->join('MKTG_CATG_MAST','MKTG_CATG_MAST.MKTG_CATG','=','PROD_CATG_MAST.MKTG_CATG')
            ->select(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED/ITEM_AUM_MAST.UMTOAUM) as QTYISSUED'))
            ->where('MKTG_CATG_MAST.MKTG_CATG', 'ASV')
            ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_date_month_sale' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_date_month_sale'") 
            ->groupBy('MKTG_CATG_MAST.MKTG_CATG')
            ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
            ->first();

        // 5. Category-wise Targets & Sales
        $categories = ["ASV", "CLA", "GEN", "GLD", "OTC", "OT2", "JPS", "FMC", "JP2", "GLD_FM"];
        $mktg_catg_wise_targets = [];
        $mktg_catg_wise_sales = [];
        $month_mktg_catg_wise_sales = [];

        foreach ($categories as $catg) {
            $target = DB::table('DEALER_TARGET_MAST')
                ->join('MKTG_CATG_MAST','MKTG_CATG_MAST.MKTG_CATG','=','DEALER_TARGET_MAST.MKTG_CATG')  
                ->where('DEALER_TARGET_MAST.MKTG_CATG', $catg)
                ->where('DEALER_TARGET_MAST.PROD_CATG', null)
                ->where('DEALER_TARGET_MAST.FROM_DATE_FILTER', $from_sale_date)
                ->where('DEALER_TARGET_MAST.TO_DATE_FILTER', $to_sale_date)
                ->where('DEALER_TARGET_MAST.ACC_CODE', $dealer_code)->first();
            $mktg_catg_wise_targets[$catg] = !empty($target->TARGET_AMT) ? $target->TARGET_AMT : 0;

            $sale = DB::table('ITEMTRAN_HEAD')
                ->join('ITEMTRAN_BODY', function($join){
                    $join->on('ITEMTRAN_BODY.VRNO', '=', 'ITEMTRAN_HEAD.VRNO');
                    $join->on('ITEMTRAN_BODY.DIV_CODE', '=', 'ITEMTRAN_HEAD.DIV_CODE');
                })
                ->join('ITEM_MAST','ITEM_MAST.ITEM_CODE','=','ITEMTRAN_BODY.ITEM_CODE')
                ->join('ITEM_AUM_MAST','ITEM_AUM_MAST.ITEM_CODE','=','ITEM_MAST.ITEM_CODE')
                ->join('PROD_MAST','PROD_MAST.PROD_CODE','=','ITEM_MAST.PROD_CODE')
                ->join('MKTG_CATG_MAST','MKTG_CATG_MAST.MKTG_CATG','=','PROD_MAST.MKTG_CATG')
                ->select(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'))
                ->where('MKTG_CATG_MAST.MKTG_CATG', $catg)
                ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'") 
                ->groupBy('MKTG_CATG_MAST.MKTG_CATG')
                ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)->first();
            $mktg_catg_wise_sales[$catg] = !empty($sale->VALISSUED) ? $sale->VALISSUED : 0;

            $month_sale = DB::table('ITEMTRAN_HEAD')
                ->join('ITEMTRAN_BODY', function($join){
                    $join->on('ITEMTRAN_BODY.VRNO', '=', 'ITEMTRAN_HEAD.VRNO');
                    $join->on('ITEMTRAN_BODY.DIV_CODE', '=', 'ITEMTRAN_HEAD.DIV_CODE');
                })
                ->join('ITEM_MAST','ITEM_MAST.ITEM_CODE','=','ITEMTRAN_BODY.ITEM_CODE')
                ->join('ITEM_AUM_MAST','ITEM_AUM_MAST.ITEM_CODE','=','ITEM_MAST.ITEM_CODE')
                ->join('PROD_MAST','PROD_MAST.PROD_CODE','=','ITEM_MAST.PROD_CODE')
                ->join('MKTG_CATG_MAST','MKTG_CATG_MAST.MKTG_CATG','=','PROD_MAST.MKTG_CATG')
                ->select(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'))
                ->where('ITEMTRAN_HEAD.ACC_CODE', $dealer_code)
                ->where('MKTG_CATG_MAST.MKTG_CATG', $catg)
                ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_date_month_sale' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_date_month_sale'") 
                ->groupBy('MKTG_CATG_MAST.MKTG_CATG')->first();
            $month_mktg_catg_wise_sales[$catg] = !empty($month_sale->VALISSUED) ? $month_sale->VALISSUED : 0;
        }

        // 6. Ledger Details & Banners
        $image_dyn = DB::table('image_mast')
            ->where('status', 1)
            ->where('img_locn', 'Dashboard')
            ->where('company_id', $ctx['company_id'])   
            ->orderBy('id', 'DESC')
            ->get();

        // ✅ FIX: Yahan ab local URL generate hoga production URL ki jagah
        $banners = $image_dyn->map(function ($img) {
            $filename = ltrim($img->img, '/');
            $encoded  = str_replace(' ', '%20', $filename);
            
            return [
                'url'   => url("/baidyanath_images/{$encoded}"), 
                'video' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4',
            ];
        });

        $entries = DB::table('dealer_ledger_busy_details')->where('dealer_id', $dealer_id)->orderBy('trans_date')->orderBy('id')->get(['trans_date', 'vocuher_number', 'vocuher_type', 'narration', 'amount', 'op_bal']);
        $opening = round((float) $entries->whereNull('trans_date')->sum('op_bal'), 2);
        $ledgerRows = $entries->whereNotNull('trans_date')->values()->map(fn($r) => ['date' => $r->trans_date, 'voucher_no' => $r->vocuher_number, 'vch_type' => $r->vocuher_type, 'account' => $r->narration, 'amount' => (float) $r->amount]);
        $summary = DB::table('dealer_ledger_busy')->where('dealer_id', $dealer_id)->orderByDesc('end_date')->first(['closing_balance', 'server_date_time']);

        // 7. Final JSON Response
        return response()->json([
            'status' => true, 
            'message' => 'Dealer dashboard (legacy)', 
            'data' => [
                'legacy' => true,
                // Dashboard UI specific data
                'banners' => $banners, 
                'account' => ['name' => $ctx['dealer']->name ?? '', 'erp_code' => $dealer_code], 
                'ledger' => [
                    'opening_balance' => $opening, 
                    'closing_balance' => round($summary ? (float) $summary->closing_balance : $opening + $ledgerRows->sum('amount'), 2), 
                    'fetched_at' => $summary->server_date_time ?? null, 
                    'rows' => $ledgerRows
                ],
                
                // Blade Controller specific data (Now available in API)
                'records' => $records,
                'catalog_product_details' => $catalog_product_details,
                'mktg_cat_array' => $mktg_cat_array,
                'converstion_unit_item_code' => $converstion_unit_item_code,
                'asv_wise_target' => $asv_wise_target,
                'asv_wise_sale_data' => $asv_wise_sale_data,
                'montha_sv_wise_sale_data' => $montha_sv_wise_sale_data,
                'mktg_catg_wise_targets' => $mktg_catg_wise_targets,
                'mktg_catg_wise_sales' => $mktg_catg_wise_sales,
                'month_mktg_catg_wise_sales' => $month_mktg_catg_wise_sales,
                
                // Standard mapped keys for older React UI
                'targets' => $mktg_catg_wise_targets, 
                'sales_value_ytd' => $mktg_catg_wise_sales, 
                'sales_value_month' => $month_mktg_catg_wise_sales
            ]
        ]);
    }


   public function otc(Request $request, string $mktgCatg = 'OTC')
{
    $ctx = $this->context($request);
    if (!$this->isLegacy($ctx) && !$request->has('force_legacy')) {
        return response()->json(['status' => false, 'message' => 'Report only available for legacy company (405).'], 403);
    }

    $dealer_id   = (int) $ctx['dealer_id'];
    $dealer_code = (string) $ctx['dealer_code'];

    $fetch_year  = DB::table('financial_year')->orderBy('id', 'DESC')->first();
    $from_year_t = !empty($fetch_year->from_year) ? $fetch_year->from_year : date('Y');
    $to_year_t   = !empty($fetch_year->to_year)   ? $fetch_year->to_year   : (date('Y') + 1);

    $from_target_date = "01-APR-" . date('y');
    $to_target_date   = "31-MAR-" . (date('y') + 1);
    $from_sale_date   = $from_year_t . '-04-01';
    $to_sale_date     = $to_year_t   . '-03-31';
    $from_date_month  = date('Y-m')  . '-01';
    $to_date_month    = date('Y-m-t');

    $curr_month = (int) date('m');
    $curr_year  = (int) date('Y');
    if ($curr_month >= 4 && $curr_month <= 6) {
        $quater_from_date = "{$curr_year}-04-01"; $quater_to_date = "{$curr_year}-06-30";
    } elseif ($curr_month >= 7 && $curr_month <= 9) {
        $quater_from_date = "{$curr_year}-07-01"; $quater_to_date = "{$curr_year}-09-30";
    } elseif ($curr_month >= 10 && $curr_month <= 12) {
        $quater_from_date = "{$curr_year}-10-01"; $quater_to_date = "{$curr_year}-12-31";
    } else {
        $prev_year = $curr_year - 1;
        $quater_from_date = "{$prev_year}-01-01"; $quater_to_date = "{$curr_year}-03-31";
    }

    $dealer_codes = [$dealer_code];

    // 1) sub-categories for this MKTG_CATG
    $prod_catg_mast_array = DB::table('PROD_CATG_MAST')
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← was 'OTC'
        ->orderBy('PROD_CATG_MAST.USER_SEQ', 'ASC')
        ->get();

    // 2) annual targets (per PROD_CATG, scoped to this MKTG_CATG)
    $prod_wise_target_mast_data = DB::table('DEALER_TARGET_MAST')
        ->leftJoin('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'DEALER_TARGET_MAST.PROD_CATG')
        ->select(
            DB::raw('SUM(DEALER_TARGET_MAST.TARGET_QTY) as TARGET_QTY'),
            DB::raw('SUM(DEALER_TARGET_MAST.TARGET_AMT) as TARGET_AMT'),
            'DEALER_TARGET_MAST.PROD_CATG as PROD_CATG'
        )
        ->where('DEALER_TARGET_MAST.FROM_DATE', '=', $from_target_date)
        ->where('DEALER_TARGET_MAST.TO_DATE',   '=', $to_target_date)
        ->whereIn('DEALER_TARGET_MAST.ACC_CODE', $dealer_codes)
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← ADD
        ->groupBy('DEALER_TARGET_MAST.PROD_CATG')
        ->orderBy('DEALER_TARGET_MAST.PROD_CATG', 'ASC')
        ->get()->toArray();

    // 3) annual QTY
    $prod_wise_sales_mast_data_qty = DB::table('ITEMTRAN_BODY')
        ->join('ITEMTRAN_HEAD', function ($join) {
            $join->on('ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO');
            $join->on('ITEMTRAN_HEAD.DIV_CODE', '=', 'ITEMTRAN_BODY.DIV_CODE');
        })
        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
        ->leftJoin('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
        ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'")
        ->whereIn('ITEMTRAN_HEAD.ACC_CODE', $dealer_codes)
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← ADD
        ->groupBy('ITEM_MAST.PROD_CATG')
        ->orderBy('ITEM_MAST.PROD_CATG', 'ASC')
        ->pluck(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / NULLIF(ITEM_AUM_MAST.UMTOAUM, 0)) as QTYISSUED'), 'ITEM_MAST.PROD_CATG as PROD_CATG');

    // 4) annual VALUE
    $prod_wise_sales_mast_data_val = DB::table('ITEMTRAN_BODY')
        ->join('ITEMTRAN_HEAD', function ($join) {
            $join->on('ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO');
            $join->on('ITEMTRAN_HEAD.DIV_CODE', '=', 'ITEMTRAN_BODY.DIV_CODE');
        })
        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
        ->leftJoin('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
        ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_sale_date' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_sale_date'")
        ->whereIn('ITEMTRAN_HEAD.ACC_CODE', $dealer_codes)
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← ADD
        ->groupBy('ITEM_MAST.PROD_CATG')
        ->orderBy('ITEM_MAST.PROD_CATG', 'ASC')
        ->pluck(DB::raw('SUM(ITEMTRAN_BODY.VALISSUED) as VALISSUED'), 'ITEM_MAST.PROD_CATG as PROD_CATG');

    // 5) month QTY
    $month_prod_wise_sales_mast_data_qty = DB::table('ITEMTRAN_BODY')
        ->join('ITEMTRAN_HEAD', function ($join) {
            $join->on('ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO');
            $join->on('ITEMTRAN_HEAD.DIV_CODE', '=', 'ITEMTRAN_BODY.DIV_CODE');
        })
        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
        ->leftJoin('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
        ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$from_date_month' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$to_date_month'")
        ->whereIn('ITEMTRAN_HEAD.ACC_CODE', $dealer_codes)
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← ADD
        ->groupBy('ITEM_MAST.PROD_CATG')
        ->orderBy('ITEM_MAST.PROD_CATG', 'ASC')
        ->pluck(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / NULLIF(ITEM_AUM_MAST.UMTOAUM, 0)) as QTYISSUED'), 'ITEM_MAST.PROD_CATG as PROD_CATG');

    // 6) quarter QTY
    $quat_prod_wise_sales_mast_data_qty = DB::table('ITEMTRAN_BODY')
        ->join('ITEMTRAN_HEAD', function ($join) {
            $join->on('ITEMTRAN_HEAD.VRNO', '=', 'ITEMTRAN_BODY.VRNO');
            $join->on('ITEMTRAN_HEAD.DIV_CODE', '=', 'ITEMTRAN_BODY.DIV_CODE');
        })
        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
        ->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE')
        ->leftJoin('PROD_CATG_MAST', 'PROD_CATG_MAST.PROD_CATG', '=', 'ITEM_MAST.PROD_CATG')
        ->whereRaw("date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')>='$quater_from_date' and date_format(ITEMTRAN_BODY.VRDATE_FILTER,'%Y-%m-%d')<='$quater_to_date'")
        ->whereIn('ITEMTRAN_HEAD.ACC_CODE', $dealer_codes)
        ->where('PROD_CATG_MAST.MKTG_CATG', $mktgCatg)              // ← ADD
        ->groupBy('ITEM_MAST.PROD_CATG')
        ->orderBy('ITEM_MAST.PROD_CATG', 'ASC')
        ->pluck(DB::raw('SUM(ITEMTRAN_BODY.QTYISSUED / NULLIF(ITEM_AUM_MAST.UMTOAUM, 0)) as QTYISSUED'), 'ITEM_MAST.PROD_CATG as PROD_CATG');

    return response()->json([
        'status'             => true,
        'mktg_catg'          => $mktgCatg,                            // ← new (echo for debugging)
        'product_categories' => $prod_catg_mast_array,
        'targets'            => $prod_wise_target_mast_data,
        'year_qty'           => $prod_wise_sales_mast_data_qty,
        'year_val'           => $prod_wise_sales_mast_data_val,
        'month_qty'          => $month_prod_wise_sales_mast_data_qty,
        'quarter_qty'        => $quat_prod_wise_sales_mast_data_qty,
        'header_target'      => array_sum(array_column($prod_wise_target_mast_data, 'TARGET_AMT')),
    ]);
}

   public function legacyCurrentStatus(Request $request)
{
    $ctx = $this->context($request);
    if (!$this->isLegacy($ctx) && !$request->has('force_legacy')) {
        return response()->json([
            'status'  => false,
            'message' => 'Report only available for legacy company (405).',
        ], 403);
    }

    $dealer_code  = (string) $ctx['dealer_code'];
    $dealer_codes = [$dealer_code];

    $fetch_year = DB::table('financial_year')->orderBy('id', 'DESC')->first();
    $from_year_t = !empty($fetch_year->from_year) ? $fetch_year->from_year : date('Y');
    $to_year_t   = !empty($fetch_year->to_year)   ? $fetch_year->to_year   : (date('Y') + 1);

    $from_sale_date  = $from_year_t . '-04-01';
    $to_sale_date    = $to_year_t   . '-03-31';
    $from_date_month = date('Y-m')  . '-01';
    $to_date_month   = date('Y-m-t');

    $categories = ['ASV', 'CLA', 'GEN', 'GLD', 'OTC', 'OT2', 'JPS', 'FMC', 'JP2', 'GLD_FM'];
    $detailMap  = [
        'OTC' => 'otc',
        'OT2' => 'ot2',
        'FMC' => 'fmcg',
        'JPS' => 'eth',
        'JP2' => 'jp2',
    ];

    $rows = [];

    foreach ($categories as $catg) {
        // Annual target (per MKTG_CATG, PROD_CATG null = top-level)
        $t = DB::table('DEALER_TARGET_MAST')
            ->where('MKTG_CATG', $catg)
            ->whereNull('PROD_CATG')
            ->where('FROM_DATE_FILTER', $from_sale_date)
            ->where('TO_DATE_FILTER', $to_sale_date)
            ->whereIn('ACC_CODE', $dealer_codes)
            ->selectRaw('SUM(TARGET_QTY) as qty, SUM(TARGET_AMT) as amt')
            ->first();

        $annualQty = $this->saleAggregate($catg, $dealer_codes, $from_sale_date, $to_sale_date, 'qty');
        $annualVal = $this->saleAggregate($catg, $dealer_codes, $from_sale_date, $to_sale_date, 'val');
        $monthQty  = $this->saleAggregate($catg, $dealer_codes, $from_date_month, $to_date_month, 'qty');
        $monthVal  = $this->saleAggregate($catg, $dealer_codes, $from_date_month, $to_date_month, 'val');

        $hasDetail = isset($detailMap[$catg]);
        $detailKey = $detailMap[$catg] ?? null;

        // QTY row
        $rows[] = [
            'category_code' => $catg,
            'category'      => $catg,
            'unit'          => 'QTY',
            'annual'        => [
                'target'   => (float) ($t->qty ?? 0),
                'achieved' => $annualQty,
            ],
            'current_month' => ['qty' => $monthQty, 'sales' => 0],
            'has_detail'    => $hasDetail,
            'detail_key'    => $detailKey,
        ];

        // VALUE row
        $rows[] = [
            'category_code' => $catg,
            'category'      => $catg,
            'unit'          => 'VALUE',
            'annual'        => [
                'target'   => (float) ($t->amt ?? 0),   // in lakhs, same as legacy
                'achieved' => $annualVal,               // in rupees
            ],
            'current_month' => ['qty' => 0, 'sales' => $monthVal],
            'has_detail'    => $hasDetail,
            'detail_key'    => $detailKey,
        ];
    }

    return response()->json([
        'status'            => true,
        'rows'              => $rows,
        'detail_categories' => array_values($detailMap),
    ]);
}

/** Aggregate ITEMTRAN sales for one MKTG_CATG over a date range. */
private function saleAggregate(
    string $mktgCatg,
    array  $dealerCodes,
    string $from,
    string $to,
    string $mode            // 'qty' | 'val'
): float {
    $q = DB::table('ITEMTRAN_HEAD')
        ->join('ITEMTRAN_BODY', function ($j) {
            $j->on('ITEMTRAN_BODY.VRNO', '=', 'ITEMTRAN_HEAD.VRNO');
            $j->on('ITEMTRAN_BODY.DIV_CODE', '=', 'ITEMTRAN_HEAD.DIV_CODE');
        })
        ->join('ITEM_MAST', 'ITEM_MAST.ITEM_CODE', '=', 'ITEMTRAN_BODY.ITEM_CODE')
        ->join('PROD_MAST', 'PROD_MAST.PROD_CODE', '=', 'ITEM_MAST.PROD_CODE')
        ->join('MKTG_CATG_MAST', 'MKTG_CATG_MAST.MKTG_CATG', '=', 'PROD_MAST.MKTG_CATG')
        ->where('MKTG_CATG_MAST.MKTG_CATG', $mktgCatg)
        ->whereIn('ITEMTRAN_HEAD.ACC_CODE', $dealerCodes)
        ->whereRaw(
            "date_format(ITEMTRAN_BODY.VRDATE_FILTER, '%Y-%m-%d') >= ?
             AND date_format(ITEMTRAN_BODY.VRDATE_FILTER, '%Y-%m-%d') <= ?",
            [$from, $to]
        );

    if ($mode === 'qty') {
        $q->join('ITEM_AUM_MAST', 'ITEM_AUM_MAST.ITEM_CODE', '=', 'ITEM_MAST.ITEM_CODE');
        return (float) $q->sum(DB::raw('ITEMTRAN_BODY.QTYISSUED / NULLIF(ITEM_AUM_MAST.UMTOAUM, 0)'));
    }
    return (float) $q->sum('ITEMTRAN_BODY.VALISSUED');
}
    public function ot2(Request $request)     { return $this->otc($request, 'OT2'); }
public function ethical(Request $request) { return $this->otc($request, 'JPS'); }   // "eth" link → JPS
public function jps(Request $request)     { return $this->otc($request, 'JPS'); }
public function fmcg(Request $request)    { return $this->otc($request, 'FMC'); }
public function jp2(Request $request)     { return $this->otc($request, 'JP2'); }
public function otc1(Request $request)    { return $this->otc($request, 'OTC'); }   // optional alias
    public function legacySendOrderToErp(Request $request)
    {
        $ctx = $this->context($request);
        if (!$this->isLegacy($ctx)) return response()->json(['status' => false, 'message' => 'This endpoint is only available for company 405.'], 403);

        $order_no = (array) $request->input('order_no', []); $checkbox_uncheck = (array) $request->input('checkbox_uncheck', []);

        if (!empty($checkbox_uncheck)) DB::table('demand_order')->whereIn('id', $checkbox_uncheck)->update(['send_order_erp_status' => 2, 'send_order_erp_status_cuurent' => 2, 'send_order_erp_date_time' => date('Y-m-d H:i:s')]);
        if (!empty($order_no)) DB::table('demand_order')->whereIn('id', $order_no)->update(['send_order_erp_status' => 1, 'send_order_erp_status_cuurent' => 1, 'send_order_erp_date_time' => date('Y-m-d H:i:s')]);

        return response()->json(['status' => true, 'message' => 'ERP status updated', 'data' => ['marked_sent' => count($order_no), 'marked_unsent' => count($checkbox_uncheck)]]);
    }
}