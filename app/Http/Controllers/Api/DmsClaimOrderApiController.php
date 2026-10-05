<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PDF;

class DmsClaimOrderApiController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Context helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function dealerContext(Request $request): object
    {
        $u = $request->user();
        abort_if(!$u, 401, 'Unauthenticated');

        $dealerId = $u->dealer_id ?? null;
        abort_if(!$dealerId, 403, 'Dealer-only endpoint.');

        return (object) [
            'auth_id'           => $dealerId,
            'dealer_id'         => $dealerId,
            'csa_id'            => $u->csa_id            ?? 0,
            'company_id'        => $u->company_id        ?? 0,
            'dealer_code'       => $u->dealer_code       ?? '0',
            'role_id'           => $u->role_id           ?? '0',
            'quantity_type'     => $u->quantity_type     ?? 0,
            'ownership_type_id' => $u->ownership_type_id ?? 0,
        ];
    }

    private function dealerLoginRow(int $dealerId)
    {
        return DB::table('dealer_person_login')->where('dealer_id', $dealerId)->first();
    }

    private function parseRange(?string $range): array
    {
        if (empty($range)) {
            $today = date('Y-m-d');
            return [$today, $today];
        }
        $parts = preg_split('/\s*-\s*/', trim($range));
        if (count($parts) < 2) abort(422, 'Invalid date_range_picker.');
        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    private function utf8Clean($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'utf8Clean'], $value);
        }
        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->map(fn ($v) => $this->utf8Clean($v));
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = $this->utf8Clean($v);
            }
            return $value;
        }
        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return $value;
    }

    /* ================================================================== */
    /*  1. INDEX — list dealer OR retailer claims                          */
    /* ================================================================== */

    public function index(Request $request)
    {
        $ctx = $this->dealerContext($request);
        [$to_date,$from_date] = $this->parseRange($request->input('date_range_picker'));

        $orderNo   = $request->input('order_no');
        $claimType = $request->input('claim_type', 2);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing for dealer {$ctx->dealer_id}");

        if ($claimType == 1) {
            $q = DB::table('dms_dealer_claim')
                ->join('dealer', 'dealer.id', '=', 'dms_dealer_claim.dealer_id')
                ->select('dealer.id as retailer_id', 'dealer.name as retailer_name', 'dms_dealer_claim.*')
                ->whereRaw(
                    "date_format(dms_dealer_claim.date,'%Y-%m-%d') >= ? and date_format(dms_dealer_claim.date,'%Y-%m-%d') <= ?",
                    [$from_date, $to_date]
                )
                ->where('dms_dealer_claim.company_id', $dealerData->company_id)
                ->where('dms_dealer_claim.dealer_id', $ctx->dealer_id)
                ->groupBy('dms_dealer_claim.id');

            if (!empty($orderNo)) {
                $q->where('dms_dealer_claim.order_id', 'LIKE', "%{$orderNo}%");
            }

            $records = $q->orderBy('dms_dealer_claim.date', 'DESC')->get();
        } else {
            $q = DB::table('dms_retailer_claim')
                ->join('retailer', 'retailer.id', '=', 'dms_retailer_claim.retailer_id')
                ->select('retailer.id as retailer_id', 'retailer.name as retailer_name', 'dms_retailer_claim.*')
                ->whereRaw(
                    "date_format(dms_retailer_claim.date,'%Y-%m-%d') >= ? and date_format(dms_retailer_claim.date,'%Y-%m-%d') <= ?",
                    [$from_date, $to_date]
                )
                ->where('dms_retailer_claim.company_id', $dealerData->company_id)
                ->where('dms_retailer_claim.dealer_id', $ctx->dealer_id)
                ->groupBy('dms_retailer_claim.id');

            if (!empty($orderNo)) {
                $q->where('dms_retailer_claim.order_id', 'LIKE', "%{$orderNo}%");
            }

            $records = $q->orderBy('dms_retailer_claim.date', 'DESC')->get();
        }

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'dealer_code_cus'   => $ctx->dealer_code,
                'dealer_id_cus'     => $ctx->dealer_id,
                'role_id'           => $ctx->role_id,
                'records'           => $records,
                'claim_type'        => $claimType,
                'ownership_type_id' => $ctx->ownership_type_id,
                'from_date'         => $from_date,
                'to_date'           => $to_date,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  2. CREATE — form data                                              */
    /* ================================================================== */

    public function create(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $divCodeEntry = DB::table('ACC_MAST')->where('ACC_CODE', $ctx->dealer_code)->first();
        $divCodeLogin = DB::table('dealer_person_login')->where('dealer_id', $ctx->dealer_id)->first();
        $divCodeElse  = $divCodeLogin->div_code_main ?? '0';
        $divCode      = $divCodeEntry->DIV_CODE ?? $divCodeElse;

        $imageDyn       = DB::table('image_mast')->where('img_locn', 'Order')->where('company_id', $ctx->company_id)->get();
        $divCodeMaster  = DB::table('_div_code_master')->where('status', 1)->pluck('name', 'id');
        $conversionUnit = DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE');

        $catalogProducts = DB::table('catalog_product')
            ->select(DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"))
            ->where('status', 1)
            ->where('catalog_product.company_id', $ctx->company_id)
            ->orderBy('itemcode', 'ASC')
            ->get();

        $dealerArray = DB::table('retailer')
            ->where('company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'catalog_product_details'    => $catalogProducts,
                'converstion_unit_item_code' => $conversionUnit,
                'image_dyn'                  => $imageDyn,
                'dealer_array'               => $dealerArray,
                'div_code_master'            => $divCodeMaster,
                'auth_id'                    => $ctx->dealer_id,
                'role_id_cus'                => $ctx->role_id,
                'quantity_type'              => $ctx->quantity_type,
                'company_id'                 => $ctx->company_id,
                'ownership_type_id'          => $ctx->ownership_type_id,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  3. STORE — create claim (dealer or retailer, multipart)            */
    /* ================================================================== */

    public function store(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'dealer_id_cus_select' => 'required|in:1,2',
            'item_code'            => 'required|array|min:1',
            'product_rate'         => 'required|array',
            'qty'                  => 'required|array',
        ]);

        $orderDate = $request->input('order_date', date('Y-m-d'));
        $orderId   = date('YmdHis') . $ctx->dealer_id;

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");

        $itemCode    = (array) $request->input('item_code');
        $productRate = (array) $request->input('product_rate');
        $qty         = (array) $request->input('qty');
        $remarks     = (array) $request->input('remarks');

        $isRetailerClaim = $request->input('dealer_id_cus_select') == '2';

        DB::beginTransaction();
        try {
            if ($isRetailerClaim) {
                $retailerId = $request->input('dealer_id_cus');

                DB::table('dms_retailer_claim')->insert([
                    'order_id'         => $orderId,
                    'company_id'       => $dealerData->company_id,
                    'dealer_id'        => $ctx->dealer_id,
                    'retailer_id'      => $retailerId,
                    'date'             => $orderDate,
                    'time'             => date('H:i:s'),
                    'server_date_time' => now(),
                    'claim_status'     => 0,
                ]);

                if ($request->hasFile('onlyforMt')) {
                    $file = $request->file('onlyforMt');
                    $rand = substr(str_shuffle('12345678901234567890'), 0, 2);
                    $name = date('YmdHis') . $rand . '.' . $file->getClientOriginalExtension();
                    $dir  = public_path('dgr_mt_images_claim');
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $file->move($dir, $name);

                    DB::table('dms_retailer_claim')
                        ->where('order_id', $orderId)
                        ->update(['image_name' => 'dgr_mt_images_claim/' . $name]);
                }

                foreach ($itemCode as $i => $pid) {
                    $batchRaw = $request->input("batchmfgmrp{$i}", '');
                    $ex       = explode('|', $batchRaw);

                    DB::table('dms_retailer_claim_details')->insert([
                        'order_id'   => $orderId,
                        'company_id' => $dealerData->company_id,
                        'product_id' => $pid,
                        'quantity'   => $qty[$i]         ?? 0,
                        'mfg_date'   => $ex[1]           ?? null,
                        'batch_no'   => $ex[0]           ?? '',
                        'rate'       => $productRate[$i] ?? 0,
                        'mrp'        => $ex[2]           ?? 0,
                        'reason'     => $remarks[$i]     ?? '',
                    ]);
                }
            } else {
                DB::table('dms_dealer_claim')->insert([
                    'order_id'         => $orderId,
                    'company_id'       => $dealerData->company_id,
                    'dealer_id'        => $ctx->dealer_id,
                    'date'             => $orderDate,
                    'time'             => date('H:i:s'),
                    'server_date_time' => now(),
                    'claim_status'     => 0,
                ]);

                if ($request->hasFile('onlyforMt')) {
                    $file = $request->file('onlyforMt');
                    $rand = substr(str_shuffle('12345678901234567890'), 0, 2);
                    $name = date('YmdHis') . $rand . '.' . $file->getClientOriginalExtension();
                    $dir  = public_path('dgr_mt_images_claim');
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $file->move($dir, $name);

                    DB::table('dms_dealer_claim')
                        ->where('order_id', $orderId)
                        ->update(['image_name' => 'dgr_mt_images_claim/' . $name]);
                }

                foreach ($itemCode as $i => $pid) {
                    $batchRaw = $request->input("batchmfgmrp{$i}", '');
                    $ex       = explode('|', $batchRaw);

                    DB::table('dms_dealer_claim_details')->insert([
                        'order_id'   => $orderId,
                        'company_id' => $dealerData->company_id,
                        'product_id' => $pid,
                        'quantity'   => $qty[$i]         ?? 0,
                        'mfg_date'   => $ex[1]           ?? '0',
                        'batch_no'   => $ex[0]           ?? '0',
                        'rate'       => $productRate[$i] ?? 0,
                        'mrp'        => $ex[2]           ?? '0',
                        'reason'     => $remarks[$i]     ?? '',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'code'     => 200,
                'message'  => 'success',
                'order_id' => $orderId,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================== */
    /*  4. EDIT                                                            */
    /* ================================================================== */

    public function edit(Request $request, $id)
    {
        $ctx = $this->dealerContext($request);

        try {
            $uid = Crypt::decryptString($id);
        } catch (\Throwable $e) {
            abort_unless(is_numeric($id), 422, 'Invalid id');
            $uid = $id;
        }

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");
        $company_id = $dealerData->company_id;

        $getRetailer  = DB::table('retailer')->where('company_id', $company_id)->pluck('name', 'id');
        $productNames = DB::table('catalog_product')->where('company_id', $company_id)->pluck('name', 'id');

        $currentStock = DB::table('stock')
            ->where('dealer_id', $ctx->dealer_id)
            ->where('batch_no', '!=', '')
            ->where('mfg', '!=', '0000-00-00')
            ->whereNotNull('mfg')
            ->pluck('qty', DB::raw("CONCAT(product_id,batch_no,mfg,mrp) as concat"));

        $getData = DB::table('dms_retailer_claim')
            ->join('dms_retailer_claim_details', 'dms_retailer_claim_details.order_id', '=', 'dms_retailer_claim.order_id')
            ->where('dms_retailer_claim.company_id', $company_id)
            ->where('dms_retailer_claim_details.company_id', $company_id)
            ->where('dms_retailer_claim_details.order_id', $uid)
            ->groupBy('dms_retailer_claim_details.id')
            ->get();

        $firstCard = [];
        $secondCardDetails = [];
        foreach ($getData as $v) {
            $firstCard = [
                'order_id'      => $v->order_id,
                'order_date'    => $v->date,
                'order_time'    => $v->time,
                'retailer_name' => $getRetailer[$v->retailer_id] ?? '',
            ];

            $key = $v->product_id . $v->batch_no . $v->mfg_date . $v->mrp;

            $secondCardDetails[] = [
                'product_id'              => $v->product_id,
                'product_name'            => $productNames[$v->product_id] ?? '',
                'available_salable_stock' => $currentStock[$key] ?? 0,
                'damage_request_quantity' => $v->quantity,
                'mfg_date'                => $v->mfg_date,
                'batch_no'                => $v->batch_no,
                'rate'                    => $v->rate,
                'mrp'                     => $v->mrp,
                'reason'                  => $v->reason,
            ];
        }

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'order_id'          => $uid,
                'firstCard'         => (object) $firstCard,
                'secondCardDetails' => $secondCardDetails,
                'encrypt_id'        => $id,
                'company_id'        => $ctx->company_id,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  5. CLAIM PRODUCT RATES                                             */
    /* ================================================================== */

    public function claimProductRates(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'item_code'      => 'required',
            'item_codes_str' => 'required|string',
        ]);

        $itemCode     = $request->input('item_code');
        $itemCodesStr = explode(',', $request->input('item_codes_str'));
        $catg         = $request->input('catg');
        $forSelfForDb = $request->input('for_self_for_db');
        $nonsalableSalableStatus = $request->input('nonsalable_salable_status', '');

        $dealerData    = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");
        $dealerDetails = DB::table('dealer')->where('id', $ctx->dealer_id)->first();

        $returnRate = DB::table('product_rate_list')
            ->where('product_id', $itemCode)
            ->where('state_id', $dealerData->state_id)
            ->where('company_id', $ctx->company_id)
            ->first();

        if (empty($returnRate)) {
            $returnRate = DB::table('product_rate_list')
                ->where('product_id', $itemCode)
                ->where('company_id', $ctx->company_id)
                ->first();
        }

        // item dropdown
        if ($forSelfForDb == 1) {
            $itemsQ = DB::table('catalog_product')
                ->join('stock', 'stock.product_id', '=', 'catalog_product.id')
                ->where('stock.nonsalable_damage', '!=', 0)
                ->where('catalog_product.company_id', $ctx->company_id)
                ->where('stock.dealer_id', $ctx->dealer_id)
                ->where('catalog_product.status', 1)
                ->where('stock.batch_no', '!=', '')
                ->where('stock.mfg', '!=', '0000-00-00')
                ->whereNotNull('stock.mfg')
                ->whereNotIn('catalog_product.id', $itemCodesStr);

            if (!empty($catg)) $itemsQ->where('catalog_product.catalog_id', $catg);

            $itemAumMastArray = $itemsQ
                ->groupBy('catalog_product.id')
                ->orderBy('catalog_product.id', 'ASC')
                ->pluck(
                    DB::raw($ctx->company_id == 213
                        ? "CONCAT(catalog_product.name) AS ITEM_NAME"
                        : "CONCAT(catalog_product.itemcode,' - ',catalog_product.name) AS ITEM_NAME"),
                    'catalog_product.id'
                );

        } elseif (in_array($nonsalableSalableStatus, [0, 1, 2], true)) {
            $itemsQ = DB::table('catalog_product')
                ->join('stock', 'stock.product_id', '=', 'catalog_product.id')
                ->where('stock.nonsalable_damage', 0)
                ->where('catalog_product.company_id', $ctx->company_id)
                ->where('stock.dealer_id', $ctx->dealer_id)
                ->where('catalog_product.status', 1)
                ->whereNotIn('catalog_product.id', $itemCodesStr);

            if (!empty($catg)) $itemsQ->where('catalog_product.catalog_id', $catg);

            $itemAumMastArray = $itemsQ
                ->groupBy('catalog_product.id')
                ->orderBy('catalog_product.id', 'ASC')
                ->pluck(
                    DB::raw($ctx->company_id == 213
                        ? "CONCAT(catalog_product.name) AS ITEM_NAME"
                        : "CONCAT(catalog_product.itemcode,' - ',catalog_product.name) AS ITEM_NAME"),
                    'catalog_product.id'
                );

        } else {
            $itemsQ = DB::table('catalog_product')
                ->where('company_id', $ctx->company_id)
                ->where('catalog_product.status', 1)
                ->whereNotIn('id', $itemCodesStr);

            if (!empty($catg)) $itemsQ->where('catalog_id', $catg);

            $itemAumMastArray = $itemsQ
                ->groupBy('id')
                ->orderBy('id', 'ASC')
                ->pluck(
                    DB::raw($ctx->company_id == 213
                        ? "CONCAT(name) AS ITEM_NAME"
                        : "CONCAT(itemcode,' - ',name) AS ITEM_NAME"),
                    'id'
                );
        }

        // get_rate_list_details
        if (!empty($dealerDetails->template_id)) {
            $getRateListDetails = DB::table('product_rate_list_template')
                ->where('template_type', $dealerDetails->template_id)
                ->where('product_id', $itemCode)
                ->where('company_id', $ctx->company_id)
                ->first();
        } else {
            $getRateListDetails = DB::table('product_rate_list')
                ->where('template_type', $dealerDetails->template_id)
                ->where('product_id', $itemCode)
                ->where('state_id', $dealerData->state_id)
                ->where('company_id', $ctx->company_id)
                ->first();
        }

        $itemMastName = DB::table('catalog_product')
            ->where('company_id', $ctx->company_id)
            ->where('id', $itemCode)
            ->orderBy('itemcode', 'ASC')
            ->first();

        $dealerLogin = DB::table('dealer_person_login')
            ->where('company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->first();

        // stock details
        if ($forSelfForDb == 1) {
            $stockDetails = DB::table('stock')
                ->where('dealer_id', $ctx->dealer_id)
                ->where('product_id', $itemCode)
                ->where('batch_no', '!=', '')
                ->where('mfg', '!=', '0000-00-00')
                ->whereNotNull('mfg')
                ->where('nonsalable_damage', '!=', 0)
                ->get();
        } else {
            $stockDetails = DB::table('stock')
                ->where('dealer_id', $ctx->dealer_id)
                ->where('product_id', $itemCode)
                ->where('batch_no', '!=', '')
                ->where('nonsalable_damage', 0)
                ->where('mfg', '!=', '0000-00-00')
                ->whereNotNull('mfg')
                ->get();
        }

        if (!empty($returnRate) && $stockDetails->isNotEmpty()) {
            if ($ctx->company_id == 44) {
                $rate = $returnRate->dealer_rate;
            } elseif ($ctx->company_id == 206) {
                $rate = $returnRate->dealer_pcs_rate;
            } elseif ($forSelfForDb == 1) {
                $rate = $returnRate->dealer_pcs_rate;
            } else {
                $rate = $getRateListDetails->retailer_pcs_rate ?? $returnRate->retailer_pcs_rate;
            }

            if ($ctx->company_id == 243) {
                $sizeUm = $dealerLogin->quantity_type == 1 ? 'BOX' : 'PCS';
            } else {
                $sizeUm = 'PCS';
            }

            return response()->json($this->utf8Clean([
                'code'                 => 200,
                'message'              => 'success',
                'result'               => $rate,
                'stockDetails'         => $stockDetails,
                'for_self_for_db'      => $forSelfForDb,
                'case_rate'            => $returnRate->dealer_rate,
                'case_mrp'             => $returnRate->mrp,
                'qty_per_case'         => $itemMastName->quantity_per_case ?? null,
                'item_mast_name'       => $itemMastName->name ?? '',
                'item_code'            => $itemCode,
                'item_aum_mast_array'  => $itemAumMastArray,
                'get_rate_list_details'=> $getRateListDetails,
                'selection_size_um'    => $sizeUm,
            ]));
        }

        return response()->json($this->utf8Clean([
            'code'                 => 401,
            'message'              => 'unauthorized request',
            'result'               => '',
            'selection_size_um'    => '',
            'stockDetails'         => '',
            'item_aum_mast_array'  => $itemAumMastArray,
            'get_rate_list_details'=> [],
        ]));
    }

    /* ================================================================== */
    /*  6. UPDATE — fulfill retailer claim                                 */
    /* ================================================================== */

    public function update(Request $request, $id)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'order_id'                => 'required',
            'product_id'              => 'required|array',
            'damage_request_quantity' => 'required|array',
            'rate'                    => 'required|array',
            'mfg_date'                => 'required|array',
            'batch_no'                => 'required|array',
            'mrp'                     => 'required|array',
        ]);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");
        $company_id = $dealerData->company_id;

        $orderId = $request->input('order_id');

        DB::beginTransaction();
        try {
            DB::table('dms_retailer_claim')
                ->where('order_id', $orderId)
                ->where('company_id', $company_id)
                ->update([
                    'claim_status'     => 1,
                    'claim_fulfill_on' => now(),
                ]);

            $productIds = (array) $request->input('product_id');
            $damageQtys = (array) $request->input('damage_request_quantity');
            $rates      = (array) $request->input('rate');
            $mfgDates   = (array) $request->input('mfg_date');
            $batchNos   = (array) $request->input('batch_no');
            $mrps       = (array) $request->input('mrp');

            foreach ($productIds as $i => $pid) {
                $stock = DB::table('stock')
                    ->where('dealer_id', $ctx->dealer_id)
                    ->where('product_id', $pid)
                    ->where('mfg', $mfgDates[$i])
                    ->where('mrp', $mrps[$i])
                    ->where('batch_no', $batchNos[$i])
                    ->first();

                if (!$stock) continue;

                DB::table('dms_retailer_claim_details')
                    ->where('order_id', $orderId)
                    ->where('product_id', $pid)
                    ->where('company_id', $company_id)
                    ->where('mfg_date', $mfgDates[$i])
                    ->where('batch_no', $batchNos[$i])
                    ->where('mrp', $mrps[$i])
                    ->update([
                        'claim_fulfill_qty'  => $damageQtys[$i],
                        'claim_fulfill_rate' => $rates[$i],
                    ]);

                DB::table('stock')
                    ->where('id', $stock->id)
                    ->update([
                        'qty'              => $stock->qty - $damageQtys[$i],
                        'update_date_time' => now(),
                    ]);
            }

            DB::commit();
            return response()->json(['code' => 200, 'message' => 'success']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================== */
    /*  7. PDF — retailer claim                                            */
    /* ================================================================== */

    public function pdfGenerationRetailerClaim(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['order_id' => 'required']);

        $orderId    = $request->input('order_id');
        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");
        $company_id = $dealerData->company_id;

        $mainData = DB::table('dms_retailer_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_retailer_claim.dealer_id')
            ->join('retailer', 'retailer.id', '=', 'dms_retailer_claim.retailer_id')
            ->select(
                'dms_retailer_claim.order_id',
                'dealer.name as dealer_name',
                'dealer.address',
                'dms_retailer_claim.claim_fulfill_on',
                'retailer.name as retailer_name',
                'retailer.track_address',
                'retailer.landline',
                'retailer.other_numbers',
                'return_ch_no'
            )
            ->where('dms_retailer_claim.order_id', $orderId)
            ->where('dms_retailer_claim.company_id', $company_id)
            ->first();

        $oldDataPreview = [];
        if (!empty($mainData->return_ch_no)) {
            $oldDataPreview = DB::table('damage_order')
                ->join('damage_order_details', 'damage_order_details.ch_id', '=', 'damage_order.id')
                ->join('catalog_product', 'catalog_product.id', '=', 'damage_order_details.product_id')
                ->select('damage_order_details.*', 'catalog_product.name as product_name', 'damage_order.ch_no as invoice_no')
                ->where('damage_order.id', $mainData->return_ch_no)
                ->groupBy('damage_order_details.id')
                ->get();
        }

        $detailsData = DB::table('dms_retailer_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_retailer_claim_details.product_id')
            ->select('catalog_product.name as product_name', 'dms_retailer_claim_details.*')
            ->where('dms_retailer_claim_details.order_id', $orderId)
            ->where('dms_retailer_claim_details.company_id', $company_id)
            ->groupBy('dms_retailer_claim_details.id')
            ->get();

        $companyDetails = DB::table('company')->where('id', $company_id)->first();

        $pdfDir = public_path('pdf');
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $pdfName = $orderId . '.pdf';
        $pdf = PDF::loadView('pdf.pdfRetailerClaim', [
            'company_details' => $companyDetails,
            'main_data'       => $mainData,
            'details_data'    => $detailsData,
            'old_data_preview'=> $oldDataPreview,
        ]);
        $pdf->setPaper([0, 0, 1240, 1748]);
        $pdf->save($pdfDir . '/' . $pdfName);

        return response()->json([
            'code'     => 200,
            'message'  => 'success',
            'pdf_name' => $pdfName,
            'pdf_url'  => url('pdf/' . $pdfName),
        ]);
    }

    /* ================================================================== */
    /*  8. Non-saleable qty                                                */
    /* ================================================================== */

    public function getNonSaleableQty(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['concatData' => 'required|string']);

        $ex          = explode('|', $request->input('concatData'));
        $product_id  = $ex[0] ?? null;
        $batch_no    = $ex[1] ?? null;
        $mfg         = $ex[2] ?? null;
        $mrp         = $ex[3] ?? null;
        $dynamic_row = $ex[4] ?? null;

        $row = DB::table('stock')
            ->where('product_id', $product_id)
            ->where('mfg', $mfg)
            ->where('batch_no', $batch_no)
            ->where('mrp', $mrp)
            ->where('dealer_id', $ctx->dealer_id)
            ->first();

        if (!empty($row)) {
            return response()->json($this->utf8Clean([
                'code'                => 200,
                'message'             => 'success',
                'getNonSaleableStock' => $row,
                'dynamic_row'         => $dynamic_row,
            ]));
        }

        return response()->json([
            'code'                => 401,
            'message'             => 'Request Not Found!!',
            'getNonSaleableStock' => '',
            'dynamic_row'         => $dynamic_row,
        ]);
    }

    /* ================================================================== */
    /*  9. processClaimPreview                                             */
    /* ================================================================== */

    public function processClaimPreview(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['checkedVals' => 'required|string']);

        $orderArray = explode(',', $request->input('checkedVals'));
        $dealerId   = $ctx->dealer_id;

        $detailsData = DB::table('dms_retailer_claim_details')
            ->join('dms_retailer_claim', 'dms_retailer_claim.order_id', '=', 'dms_retailer_claim_details.order_id')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_retailer_claim_details.product_id')
            ->select('dms_retailer_claim_details.*', 'catalog_product.name as product_name', DB::raw("SUM(claim_fulfill_qty) as claim_fulfill_qty"))
            ->where('dms_retailer_claim.saleable_non_saleable', 2)
            ->groupBy('mfg_date', 'mrp', 'batch_no', 'product_id')
            ->whereIn('dms_retailer_claim_details.order_id', $orderArray)
            ->get();

        $itemArr = $detailsData->pluck('product_id')->all();

        $getNonSalableStock = DB::table('stock')
            ->where('dealer_id', $dealerId)
            ->whereIn('product_id', $itemArr)
            ->where('nonsalable_damage', 0)
            ->groupBy('product_id', 'mfg', 'mrp', 'batch_no')
            ->pluck(DB::raw("SUM(qty)"), DB::raw("CONCAT(product_id,mfg,mrp,batch_no) as q"));

        return response()->json($this->utf8Clean([
            'code'                 => 200,
            'message'              => 'success',
            'details_data'         => $detailsData,
            'get_non_salable_stock'=> $getNonSalableStock,
        ]));
    }

    /* ================================================================== */
    /*  10. save_sra_generate_pdf                                          */
    /* ================================================================== */

    public function saveSraGeneratePdf(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'order_id'           => 'required|string',
            'product_id'         => 'required|array',
            'claim_fulfill_qty'  => 'required|array',
            'claim_fulfill_rate' => 'required|array',
            'batch_no'           => 'required|array',
            'mfg_date'           => 'required|array',
            'mrp'                => 'required|array',
        ]);

        $orderArray = $request->input('order_id');
        $orderId    = date('YmdHis') . $ctx->dealer_id;
        $dealerId   = $ctx->dealer_id;

        DB::beginTransaction();
        try {
            $dealerData = $this->dealerLoginRow($dealerId);
            abort_if(!$dealerData, 422, "dealer_person_login missing");

            DB::table('dms_dealer_claim')->insert([
                'order_id'         => $orderId,
                'company_id'       => $dealerData->company_id,
                'dealer_id'        => $dealerId,
                'date'             => date('Y-m-d'),
                'time'             => date('H:i:s'),
                'order_id_string'  => $orderArray,
                'server_date_time' => now(),
                'claim_status'     => 0,
            ]);

            $productIds = (array) $request->input('product_id');
            $qtys       = (array) $request->input('claim_fulfill_qty');
            $rates      = (array) $request->input('claim_fulfill_rate');
            $batchNos   = (array) $request->input('batch_no');
            $mfgDates   = (array) $request->input('mfg_date');
            $mrps       = (array) $request->input('mrp');

            $rows = [];
            foreach ($qtys as $i => $q) {
                $rows[] = [
                    'order_id'   => $orderId,
                    'company_id' => $dealerData->company_id,
                    'product_id' => $productIds[$i],
                    'quantity'   => $q,
                    'mfg_date'   => $mfgDates[$i],
                    'batch_no'   => $batchNos[$i],
                    'rate'       => $rates[$i],
                    'mrp'        => $mrps[$i],
                    'reason'     => '',
                ];
            }

            if (!empty($rows)) {
                DB::table('dms_dealer_claim_details')->insert($rows);
            }

            DB::table('dms_retailer_claim')
                ->whereIn('order_id', explode(',', $orderArray))
                ->update(['sra_status' => 1]);

            DB::commit();

            return response()->json([
                'code'     => 200,
                'message'  => 'success',
                'order_id' => $orderId,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================== */
    /*  11. sra_report                                                     */
    /* ================================================================== */

    public function sraReport(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $getData = DB::table('dms_dealer_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_dealer_claim.dealer_id')
            ->select('dms_dealer_claim.*', 'dealer.name as dealer_name')
            ->where('dms_dealer_claim.company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->whereNotNull('order_id_string')
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean($getData),
        ]);
    }

    /* ================================================================== */
    /*  12. generate_sra_pdf — returns PDF download URL                    */
    /* ================================================================== */

    public function generateSraPdf(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['pid' => 'required|integer']);

        $pid        = $request->input('pid');
        $company_id = $ctx->company_id;

        $getData = DB::table('dms_dealer_claim')->where('id', $pid)->first();
        abort_if(!$getData, 404, 'Claim not found');

        $orderId    = $getData->order_id_string;
        $orderArray = explode(',', $orderId);

        $dealerData    = $this->dealerLoginRow($ctx->dealer_id);
        $dealerDetails = DB::table('dealer')->where('dealer.id', $ctx->dealer_id)->first();
        $companyDetails= DB::table('company')->where('id', $company_id)->first();

        $mainData = DB::table('dms_retailer_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $ctx->dealer_id)
            ->groupBy('order_id')
            ->get();

        $crno = [];
        $retailerCount = [];
        $newDate = '';
        foreach ($mainData as $mv) {
            $crno[] = $mv->id;
            $retailerCount[$mv->retailer_id] = $mv->retailer_id;
            $newDate = $mv->claim_fulfill_on;
        }

        $crno       = 'SRA-' . implode('-', $crno);
        $orderNoPdf = date('YmdHis') . $ctx->dealer_id;
        $pdfName    = date('YmdHis') . $ctx->dealer_id . '.pdf';

        $detailsData = DB::table('dms_retailer_claim_details')->whereIn('order_id', $orderArray)->get();

        $tv = [];
        $orderWiseSum = [];
        foreach ($detailsData as $dv) {
            $amt = $dv->claim_fulfill_rate * $dv->claim_fulfill_qty;
            $tv[] = $amt;
            $orderWiseSum[$dv->order_id][] = $amt;
        }
        $finalAmount = round(array_sum($tv), 2);

        $pdfDir = public_path('dms_sra');
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $pdf = PDF::loadView('pdf.pdfSra', [
            'company_details' => $companyDetails,
            'main_data'       => $mainData,
            'new_date'        => $newDate,
            'details_data'    => $detailsData,
            'dealerDetails'   => $dealerDetails,
            'orderNoPdf'      => $orderNoPdf,
            'crno'            => $crno,
            'final_amount'    => $finalAmount,
            'retailerCount'   => $retailerCount,
            'orderWiseSum'    => $orderWiseSum,
        ]);
        $pdf->setPaper([0, 0, 1240, 1748]);
        $pdf->save($pdfDir . '/' . $pdfName);

        return response()->json([
            'code'     => 200,
            'message'  => 'success',
            'pdf_name' => $pdfName,
            'pdf_url'  => url('dms_sra/' . $pdfName),
        ]);
    }

    /* ================================================================== */
    /*  13. dealer_generate_sra_pdf                                        */
    /* ================================================================== */

    public function dealerGenerateSraPdf(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['pid' => 'required|string']);

        $orderId    = $request->input('pid');
        $company_id = $ctx->company_id;

        $getData = DB::table('dms_dealer_claim')->where('order_id', $orderId)->first();
        abort_if(!$getData, 404, 'Claim not found');

        $orderArray = [$orderId];
        $dealerId   = $getData->dealer_id;

        $dealerData    = $this->dealerLoginRow($dealerId);
        $dealerDetails = DB::table('dealer')->where('dealer.id', $dealerId)->first();
        $companyDetails= DB::table('company')->where('id', $company_id)->first();

        $mainData = DB::table('dms_dealer_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $dealerId)
            ->groupBy('order_id')
            ->get();

        $crno = [];
        $retailerCount = [];
        $crnoStr = 'SRA-' . implode('-', $crno);
        $orderNoPdf = date('YmdHis') . $dealerId;
        $pdfName    = date('YmdHis') . $dealerId . '.pdf';

        $detailsData = DB::table('dms_dealer_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_dealer_claim_details.product_id')
            ->select('catalog_product.name as product_name', 'dms_dealer_claim_details.*')
            ->whereIn('order_id', $orderArray)
            ->get();

        $tv = [];
        $orderWiseSum = [];
        foreach ($detailsData as $dv) {
            $amt = $dv->rate * $dv->quantity;
            $tv[] = $amt;
            $orderWiseSum[$dv->order_id][] = $amt;
        }
        $finalAmount = round(array_sum($tv), 2);

        $pdfDir = public_path('dms_sra');
        if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        $pdf = PDF::loadView('pdf.pdfSraDealer', [
            'company_details' => $companyDetails,
            'main_data'       => $mainData,
            'details_data'    => $detailsData,
            'dealerDetails'   => $dealerDetails,
            'orderNoPdf'      => $orderNoPdf,
            'crno'            => $crnoStr,
            'final_amount'    => $finalAmount,
            'retailerCount'   => $retailerCount,
            'orderWiseSum'    => $orderWiseSum,
        ]);
        $pdf->setPaper([0, 0, 1240, 1748]);
        $pdf->save($pdfDir . '/' . $pdfName);

        return response()->json([
            'code'     => 200,
            'message'  => 'success',
            'pdf_name' => $pdfName,
            'pdf_url'  => url('dms_sra/' . $pdfName),
        ]);
    }

    /* ================================================================== */
    /*  14. takeActionGenerateSra — returns 801 to redirect                */
    /* ================================================================== */

    public function takeActionGenerateSra(Request $request)
    {
        $this->dealerContext($request);

        return response()->json([
            'code'     => 801,
            'pdf_name' => 'processClaimPreview',
            'message'  => 'success',
        ]);
    }

    /* ================================================================== */
    /*  15. new_Claim_details                                              */
    /* ================================================================== */

    public function newClaimDetails(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $divCodeEntry = DB::table('ACC_MAST')->where('ACC_CODE', $ctx->dealer_code)->first();
        $divCodeLogin = DB::table('dealer_person_login')->where('dealer_id', $ctx->dealer_id)->first();
        $divCodeElse  = $divCodeLogin->div_code_main ?? '0';
        $divCode      = $divCodeEntry->DIV_CODE ?? $divCodeElse;

        $imageDyn       = DB::table('image_mast')->where('img_locn', 'Order')->where('company_id', $ctx->company_id)->get();
        $divCodeMaster  = DB::table('_div_code_master')->where('status', 1)->pluck('name', 'id');
        $conversionUnit = DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE');

        $catalogProducts = DB::table('catalog_product')
            ->select(DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"))
            ->where('status', 1)
            ->where('catalog_product.company_id', $ctx->company_id)
            ->orderBy('itemcode', 'ASC')
            ->get();

        $dealerArray = DB::table('retailer')
            ->where('company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'catalog_product_details'    => $catalogProducts,
                'converstion_unit_item_code' => $conversionUnit,
                'image_dyn'                  => $imageDyn,
                'dealer_array'               => $dealerArray,
                'div_code_master'            => $divCodeMaster,
                'auth_id'                    => $ctx->dealer_id,
                'role_id_cus'                => $ctx->role_id,
                'quantity_type'              => $ctx->quantity_type,
                'company_id'                 => $ctx->company_id,
                'ownership_type_id'          => $ctx->ownership_type_id,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  16. createMultipleBatch                                            */
    /* ================================================================== */

    public function createMultipleBatch(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $divCodeEntry = DB::table('ACC_MAST')->where('ACC_CODE', $ctx->dealer_code)->first();
        $divCodeLogin = DB::table('dealer_person_login')->where('dealer_id', $ctx->dealer_id)->first();
        $divCodeElse  = $divCodeLogin->div_code_main ?? '0';
        $divCode      = $divCodeEntry->DIV_CODE ?? $divCodeElse;

        $imageDyn       = DB::table('image_mast')->where('img_locn', 'Order')->where('company_id', $ctx->company_id)->get();
        $divCodeMaster  = DB::table('_div_code_master')->where('status', 1)->pluck('name', 'id');
        $conversionUnit = DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE');

        $catalogProducts = DB::table('catalog_product')
            ->select(DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"))
            ->where('status', 1)
            ->where('catalog_product.company_id', $ctx->company_id)
            ->orderBy('itemcode', 'ASC')
            ->get();

        $dataGet = [];
        $invoiceId = $request->input('invoice_id');
        if (!empty($invoiceId)) {
            $dataGet = DB::table('damage_order')
                ->join('damage_order_details', 'damage_order_details.ch_id', '=', 'damage_order.id')
                ->join('catalog_product', 'catalog_product.id', '=', 'damage_order_details.product_id')
                ->select('damage_order_details.*', 'catalog_product.name as product_name', 'damage_order.ch_no as invoice_no')
                ->where('damage_order.id', $invoiceId)
                ->groupBy('damage_order_details.id')
                ->get();
        }

        $dealerArray = DB::table('retailer')
            ->where('company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->orderBy('id', 'ASC')
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'catalog_product_details'    => $catalogProducts,
                'converstion_unit_item_code' => $conversionUnit,
                'data_get'                   => $dataGet,
                'image_dyn'                  => $imageDyn,
                'dealer_array'               => $dealerArray,
                'div_code_master'            => $divCodeMaster,
                'auth_id'                    => $ctx->dealer_id,
                'role_id_cus'                => $ctx->role_id,
                'quantity_type'              => $ctx->quantity_type,
                'company_id'                 => $ctx->company_id,
                'ownership_type_id'          => $ctx->ownership_type_id,
                'invoice_id'                 => $invoiceId ?? '',
            ]),
        ]);
    }

    /* ================================================================== */
    /*  17. multipleBatchClaim — creates claim with images                 */
    /* ================================================================== */

    public function multipleBatchClaim(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'dealer_id_cus_select' => 'required|in:1,2',
            'item_code'            => 'required|array|min:1',
            'product_rate'         => 'required|array',
            'qty'                  => 'required|array',
        ]);

        $orderDate = $request->input('order_date', date('Y-m-d'));
        $orderId   = date('YmdHis') . $ctx->dealer_id;
        $dealerId  = $ctx->dealer_id;

        $dealerData = $this->dealerLoginRow($dealerId);
        abort_if(!$dealerData, 422, "dealer_person_login missing");

        $itemCode    = (array) $request->input('item_code');
        $productRate = (array) $request->input('product_rate');
        $qty         = (array) $request->input('qty');
        $remarks     = (array) $request->input('remarks');

        DB::beginTransaction();
        try {
            if ($request->input('dealer_id_cus_select') == '2') {
                $retailerId = $request->input('dealer_id_cus');

                $saleableNonSaleable = 0;
                if (!empty($request->input('return_ch_no')) && !empty($request->input('request_retailer'))) {
                    $DamageHead = DB::table('damage_order')->where('id', $request->input('return_ch_no'))->first();
                    $saleableNonSaleable = $DamageHead->damage_order_type_id ?? 2;
                }

                DB::table('dms_retailer_claim')->insert([
                    'order_id'              => $orderId,
                    'company_id'            => $dealerData->company_id,
                    'dealer_id'             => $dealerId,
                    'retailer_id'           => $retailerId,
                    'date'                  => $orderDate,
                    'time'                  => date('H:i:s'),
                    'server_date_time'      => now(),
                    'claim_status'          => 0,
                    'return_ch_no'          => $request->input('return_ch_no', null),
                    'saleable_non_saleable' => $saleableNonSaleable,
                ]);

                $this->handleMultipleImages($request, 'dms_retailer_claim', $orderId, $dealerId);

                foreach ($itemCode as $i => $pid) {
                    $rate   = $productRate[$i] ?? 0;
                    $reason = $remarks[$i] ?? 0;
                    if (empty($qty[$i])) continue;

                    foreach ($qty[$i] as $batchmfgmrp => $quantity) {
                        if (empty($quantity)) continue;
                        $ex = explode('|', $batchmfgmrp);

                        DB::table('dms_retailer_claim_details')->insert([
                            'order_id'   => $orderId,
                            'company_id' => $dealerData->company_id,
                            'product_id' => $pid,
                            'quantity'   => $quantity,
                            'mfg_date'   => $ex[1] ?? null,
                            'batch_no'   => $ex[0] ?? '',
                            'rate'       => $rate,
                            'mrp'        => $ex[2] ?? '',
                            'reason'     => $reason,
                        ]);
                    }
                }
            } else {
                DB::table('dms_dealer_claim')->insert([
                    'order_id'         => $orderId,
                    'company_id'       => $dealerData->company_id,
                    'dealer_id'        => $dealerId,
                    'date'             => $orderDate,
                    'time'             => date('H:i:s'),
                    'server_date_time' => now(),
                    'claim_status'     => 0,
                ]);

                $this->handleMultipleImages($request, 'dms_dealer_claim', $orderId, $dealerId);

                foreach ($itemCode as $i => $pid) {
                    $rate   = $productRate[$i] ?? 0;
                    $reason = $remarks[$i] ?? 0;
                    if (empty($qty[$i])) continue;

                    foreach ($qty[$i] as $batchmfgmrp => $quantity) {
                        if (empty($quantity)) continue;
                        $ex = explode('|', $batchmfgmrp);

                        DB::table('dms_dealer_claim_details')->insert([
                            'order_id'   => $orderId,
                            'company_id' => $dealerData->company_id,
                            'product_id' => $pid,
                            'quantity'   => $quantity,
                            'mfg_date'   => $ex[1] ?? '0',
                            'batch_no'   => $ex[0] ?? '0',
                            'rate'       => $rate,
                            'mrp'        => $ex[2] ?? '0',
                            'reason'     => $reason,
                        ]);

                        $stockQ = DB::table('stock')
                            ->where('product_id', $pid)
                            ->where('dealer_id', $dealerId)
                            ->where('batch_no', $ex[0] ?? '')
                            ->where('mrp', $ex[2] ?? '')
                            ->where('mfg', date('Y-m-d', strtotime($ex[1] ?? '')))
                            ->first();

                        if ($stockQ) {
                            DB::table('stock')
                                ->where('id', $stockQ->id)
                                ->update([
                                    'nonsalable_damage' => ($stockQ->nonsalable_damage ?? 0) - $quantity,
                                ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'code'     => 200,
                'message'  => 'success',
                'order_id' => $orderId,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    private function handleMultipleImages(Request $request, string $table, string $orderId, int $dealerId)
    {
        if (!$request->hasFile('onlyforMt')) return;

        $files = $request->file('onlyforMt');
        $dir = public_path('dgr_mt_images_claim');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $names = [];
        foreach ($files as $file) {
            $rand = substr(str_shuffle('12345678901234567890'), 0, 2);
            $name = date('YmdHis') . $rand . $dealerId . '.' . $file->getClientOriginalExtension();
            $file->move($dir, $name);
            $names[] = '/dgr_mt_images_claim/' . $name;
        }

        $update = [
            'image_name'  => $names[0] ?? '',
            'image_name1' => $names[1] ?? '',
            'image_name2' => $names[2] ?? '',
            'image_name3' => $names[3] ?? '',
            'image_name4' => $names[4] ?? '',
            'server_date_time' => now(),
        ];

        DB::table($table)->where('order_id', $orderId)->update($update);
    }

    /* ================================================================== */
    /*  18. schemeDetails                                                  */
    /* ================================================================== */

    public function schemeDetails(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate(['order_id' => 'required']);

        $orderId    = $request->input('order_id');
        $company_id = $ctx->company_id;

        $detailsData = DB::table('dms_dealer_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_dealer_claim_details.product_id')
            ->select('catalog_product.name as product_name', 'dms_dealer_claim_details.*')
            ->where('dms_dealer_claim_details.order_id', $orderId)
            ->where('dms_dealer_claim_details.company_id', $company_id)
            ->groupBy('dms_dealer_claim_details.id')
            ->get();

        return response()->json([
            'details' => $this->utf8Clean($detailsData),
        ]);
    }

    /* ================================================================== */
    /*  19. stock_check_dms                                                */
    /* ================================================================== */

    public function stockCheckDms(Request $request)
    {
        $ctx = $this->dealerContext($request);
        $request->validate([
            'item_code' => 'required',
            'qty'       => 'required',
        ]);

        $itemId = $request->input('item_code');

        $details = DB::table('stock')
            ->where('dealer_id', $ctx->dealer_id)
            ->where('product_id', $itemId)
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean($details),
        ]);
    }
}