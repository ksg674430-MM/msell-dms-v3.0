<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PDF;

class DmsDgrCreditNoteApiController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Dealer context resolution                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Return the dealer context for the current request. Pulled off the
     * authenticated user (Sanctum). If your User model doesn't carry these
     * columns, put them on the token abilities or in a `dealer_tokens` table
     * and hydrate here instead.
     */
    private function dealerContext(Request $request): object
    {
        $u = $request->user();

        abort_if(!$u, 401, 'Unauthenticated');

        $dealerId = $u->dealer_id ?? null;
        abort_if(!$dealerId, 403, 'This endpoint is dealer-only.');

        return (object) [
            'auth_id'       => $dealerId,
            'dealer_id'     => $dealerId,
            'csa_id'        => $u->csa_id        ?? 0,
            'company_id'    => $u->company_id    ?? 0,
            'dealer_code'   => $u->dealer_code   ?? '0',
            'role_id'       => $u->role_id       ?? '0',
            'quantity_type' => $u->quantity_type ?? 0,
        ];
    }

    /**
     * Convenience: the dealer row from `dealer_person_login` (used everywhere).
     */
    private function dealerLoginRow(int $dealerId)
    {
        return DB::table('dealer_person_login')->where('dealer_id', $dealerId)->first();
    }

    /**
     * Parse "YYYY-MM-DD - YYYY-MM-DD"; default to current month.
     */
    private function parseRange(?string $range): array
    {
        if (empty($range)) {
            return [date('Y-m') . '-01', date('Y-m-d')];
        }
        $parts = preg_split('/\s*-\s*/', trim($range));
        if (count($parts) < 2) {
            abort(422, 'Invalid date_range_picker.');
        }
        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    /* ================================================================== */
    /*  1. INDEX — dealer-wise claim list (Retailer amount claim)          */
    /* ================================================================== */

    public function index(Request $request)
    {
        $ctx = $this->dealerContext($request);
        [$to_date,$from_date] = $this->parseRange($request->input('date_range_picker'));
        // dd($this->parseRange($request->input('date_range_picker')));

        $order_no  = $request->input('order_no');
        $dealer_id = $request->input('dealer_id', 0);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $assignedDealerFilter = DB::table('dealer')
            ->where('company_id', $dealerData->company_id)
            ->where('csa_id', $dealerData->csa_id)
            ->pluck('name', 'id');

        $q = DB::table('dms_dealer_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_dealer_claim.dealer_id')
            ->join('dms_dealer_claim_level_approval', 'dms_dealer_claim_level_approval.order_id', '=', 'dms_dealer_claim.order_id')
            ->select('dealer.id as dealer_id', 'dealer.name as dealer_name', 'dms_dealer_claim.*')
            ->whereRaw("date_format(dms_dealer_claim.date,'%Y-%m-%d') >= ? and date_format(dms_dealer_claim.date,'%Y-%m-%d') <= ?", [$from_date, $to_date])
            ->where('dms_dealer_claim.company_id', $dealerData->company_id)
            ->where('dms_dealer_claim_level_approval.level_sequence', 3)
            ->where('dealer.id', $dealer_id)
            ->groupBy('dms_dealer_claim.order_id');

        if (!empty($order_no)) {
            $q->where('dms_dealer_claim.order_id', 'LIKE', "%{$order_no}%");
        }
        $records = $q->orderBy('dms_dealer_claim.date', 'DESC')->get();
        // dd($records);

        $orderIds = $records->pluck('order_id')->all();
        // dd($orderIds);

        $getClaimAmount = DB::table('dms_dealer_claim_details')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->pluck(DB::raw("ROUND(SUM(rate*quantity),2)"), 'order_id');

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'dealer_code_cus'       => $ctx->dealer_code,
                'dealer_id_cus'         => $ctx->dealer_id,
                'role_id'               => $ctx->role_id,
                'records'               => $records,
                'getClaimAmount'        => $getClaimAmount,
                'assignedDealerFilter'  => $assignedDealerFilter,
                'dealer_id'             => $dealer_id,
                'from_date'             => $from_date,
                'to_date'               => $to_date,
            ],
        ]);
    }

    /* ================================================================== */
    /*  2. CREATE — form data for a new claim                              */
    /* ================================================================== */

    public function create(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $divCodeEntry  = DB::table('ACC_MAST')->where('ACC_CODE', $ctx->dealer_code)->first();
        $divCodeLogin  = DB::table('dealer_person_login')->where('dealer_id', $ctx->dealer_id)->first();
        $divCodeElse   = $divCodeLogin->div_code_main ?? '0';
        $divCode       = $divCodeEntry->DIV_CODE ?? $divCodeElse;

        $imageDyn        = DB::table('image_mast')->where('img_locn', 'Order')->where('company_id', $ctx->company_id)->get();
        $divCodeMaster   = DB::table('_div_code_master')->where('status', 1)->pluck('name', 'id');
        $conversionUnit  = DB::table('ITEM_AUM_MAST')->pluck('UMTOAUM', 'ITEM_CODE');

        $catalogProducts = DB::table('catalog_product')
            ->select(DB::raw("CONCAT(name,' (',itemcode,')') AS ITEM_NAME"), 'id')
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
            'data'    => [
                'catalog_product_details'     => $catalogProducts,
                'converstion_unit_item_code'  => $conversionUnit,
                'image_dyn'                   => $imageDyn,
                'dealer_array'                => $dealerArray,
                'div_code_master'             => $divCodeMaster,
                'div_code'                    => $divCode,
                'auth_id'                     => $ctx->dealer_id,
                'role_id_cus'                 => $ctx->role_id,
                'quantity_type'               => $ctx->quantity_type,
                'company_id'                  => $ctx->company_id,
            ],
        ]);
    }

    /* ================================================================== */
    /*  3. STORE — create a claim (dealer or retailer)                     */
    /* ================================================================== */

    public function store(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'dealer_id_cus_select'      => 'required|in:1,2',
            'item_code'                 => 'required|array|min:1',
            'item_code.*'               => 'required',
            'qty'                       => 'required|array',
            'product_rate'              => 'required|array',
        ]);

        $dealerId = $ctx->dealer_id;
        $dealerData = $this->dealerLoginRow($dealerId);

        $orderDate = $request->input('order_date', date('Y-m-d'));
        $orderId   = date('YmdHis') . $dealerId;

        $itemCode    = (array) $request->input('item_code', []);
        $productRate = (array) $request->input('product_rate', []);
        $qty         = (array) $request->input('qty', []);
        $remarks     = (array) $request->input('remarks', []);

        $isRetailerClaim = $request->input('dealer_id_cus_select') == '2';

        DB::beginTransaction();
        try {
            if ($isRetailerClaim) {
                $retailerId = $request->input('dealer_id_cus');

                DB::table('dms_retailer_claim')->insert([
                    'order_id'         => $orderId,
                    'company_id'       => $dealerData->company_id,
                    'dealer_id'        => $dealerId,
                    'retailer_id'      => $retailerId,
                    'date'             => $orderDate,
                    'time'             => date('H:i:s'),
                    'server_date_time' => now(),
                    'claim_status'     => 0,
                ]);

                foreach ($itemCode as $i => $pid) {
                    $batchRaw = $request->input("batchmfgmrp{$i}", '');
                    $ex       = explode('|', $batchRaw);

                    DB::table('dms_retailer_claim_details')->insert([
                        'order_id'   => $orderId,
                        'company_id' => $dealerData->company_id,
                        'product_id' => $pid,
                        'quantity'   => $qty[$i]          ?? 0,
                        'mfg_date'   => $ex[1]            ?? null,
                        'batch_no'   => $ex[0]            ?? '',
                        'rate'       => $productRate[$i]  ?? 0,
                        'mrp'        => $ex[2]            ?? 0,
                        'reason'     => $remarks[$i]      ?? '',
                    ]);
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

                foreach ($itemCode as $i => $pid) {
                    $batchRaw = $request->input("batchmfgmrp{$i}", '');
                    $ex       = explode('|', $batchRaw);

                    DB::table('dms_dealer_claim_details')->insert([
                        'order_id'   => $orderId,
                        'company_id' => $dealerData->company_id,
                        'product_id' => $pid,
                        'quantity'   => $qty[$i]          ?? 0,
                        'mfg_date'   => $ex[1]            ?? null,
                        'batch_no'   => $ex[0]            ?? '',
                        'rate'       => $productRate[$i]  ?? 0,
                        'mrp'        => $ex[2]            ?? 0,
                        'reason'     => $remarks[$i]      ?? '',
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'code'     => 200,
                'message'  => 'Claim created',
                'order_id' => $orderId,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'code'    => 500,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /* ================================================================== */
    /*  4. EDIT — data for a single claim                                  */
    /* ================================================================== */

    public function edit(Request $request, $id)
    {
        $ctx = $this->dealerContext($request);
        $uid = Crypt::decryptString($id);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        $company_id = $dealerData->company_id;

        $getRetailer  = DB::table('retailer')->where('company_id', $company_id)->pluck('name', 'id');
        $productNames = DB::table('catalog_product')->where('company_id', $company_id)->pluck('name', 'id');

        $currentStock = DB::table('stock')
            ->where('dealer_id', $ctx->dealer_id)
            ->where('batch_no', '!=', '')
            ->where('mfg', '!=', '0000-00-00')
            ->where('mfg', '!=', '')
            ->pluck('qty', DB::raw("CONCAT(product_id,batch_no,mfg,mrp) as concat"));

        $getData = DB::table('dms_retailer_claim')
            ->join('dms_retailer_claim_details', 'dms_retailer_claim_details.order_id', '=', 'dms_retailer_claim.order_id')
            ->where('dms_retailer_claim.company_id', $company_id)
            ->where('dms_retailer_claim_details.company_id', $company_id)
            ->where('dms_retailer_claim_details.order_id', $uid)
            ->groupBy('dms_retailer_claim_details.id')
            ->get();

        $firstCard        = [];
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
                'product_id'                => $v->product_id,
                'product_name'              => $productNames[$v->product_id] ?? '',
                'available_salable_stock'   => $currentStock[$key] ?? 0,
                'damage_request_quantity'   => $v->quantity,
                'mfg_date'                  => $v->mfg_date,
                'batch_no'                  => $v->batch_no,
                'rate'                      => $v->rate,
                'mrp'                       => $v->mrp,
                'reason'                    => $v->reason,
            ];
        }

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'order_id'            => $uid,
                'firstCard'           => (object) $firstCard,
                'secondCardDetails'   => $secondCardDetails,
                'encrypt_id'          => $id,
                'company_id'          => $ctx->company_id,
            ],
        ]);
    }

    /* ================================================================== */
    /*  5. claim_product_rates                                             */
    /* ================================================================== */

    public function claimProductRates(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'item_code'      => 'required',
            'item_codes_str' => 'required|string',
        ]);

        $itemCode       = $request->input('item_code');
        $itemCodesStr   = explode(',', $request->input('item_codes_str'));
        $catg           = $request->input('catg');
        $forSelfForDb   = $request->input('for_self_for_db');

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $returnRate = DB::table('product_rate_list')
            ->where('product_id', $itemCode)
            ->where('state_id', $dealerData->state_id)
            ->where('company_id', $ctx->company_id)
            ->first()
            ?: DB::table('product_rate_list')
                ->where('product_id', $itemCode)
                ->where('company_id', $ctx->company_id)
                ->first();

        // product dropdown
        $itemsQ = DB::table('catalog_product')
    ->select('catalog_product.id', 'catalog_product.itemcode', 'catalog_product.name')
    ->where('catalog_product.company_id', $ctx->company_id)
    ->where('catalog_product.status', 1)
    ->whereNotIn('catalog_product.id', $itemCodesStr);

if ($forSelfForDb == 1) {
    $itemsQ->join('stock', 'stock.product_id', '=', 'catalog_product.id')
        ->where('stock.nonsalable_damage', '!=', 0)
        ->where('stock.dealer_id', $ctx->dealer_id)
        ->where('stock.batch_no', '!=', '')
        ->where('stock.mfg', '!=', '0000-00-00')
        ->where('stock.mfg', '!=', '');
}

if (!empty($catg)) {
    $itemsQ->where('catalog_product.catalog_id', $catg);
}

$itemAumMastArray = $itemsQ
    ->groupBy('catalog_product.id')
    ->orderBy('catalog_product.id', 'ASC')
    ->pluck(
        DB::raw("CONCAT(catalog_product.itemcode,' - ',catalog_product.name) AS ITEM_NAME"),
        'catalog_product.id'
    );
        $itemMastName = DB::table('catalog_product')
            ->where('company_id', $ctx->company_id)
            ->where('id', $itemCode)
            ->orderBy('itemcode', 'ASC')
            ->first();

        $dealerLogin = DB::table('dealer_person_login')
            ->where('company_id', $ctx->company_id)
            ->where('dealer_id', $ctx->dealer_id)
            ->first();

        $stockQ = DB::table('stock')
            ->where('dealer_id', $ctx->dealer_id)
            ->where('product_id', $itemCode)
            ->where('batch_no', '!=', '')
            ->where('mfg', '!=', '0000-00-00')
            ->where('mfg', '!=', '');

        if ($forSelfForDb == 1) {
            $stockQ->where('nonsalable_damage', '!=', 0);
        }

        $stockDetails = $stockQ->get();

        if (!empty($returnRate) && $stockDetails->isNotEmpty()) {

            if ($ctx->company_id == 44) {
                $rate = $returnRate->dealer_rate;
            } elseif ($ctx->company_id == 206) {
                $rate = $returnRate->dealer_pcs_rate;
            } elseif ($forSelfForDb == 1) {
                $rate = $returnRate->dealer_pcs_rate;
            } else {
                $rate = $returnRate->retailer_pcs_rate;
            }

            if ($ctx->company_id == 243) {
                $sizeUm = $dealerLogin->quantity_type == 1 ? 'BOX' : 'PCS';
            } else {
                $sizeUm = 'PCS';
            }

            return response()->json([
                'code'                 => 200,
                'message'              => 'success',
                'result'               => $rate,
                'stockDetails'         => $stockDetails,
                'for_self_for_db'      => $forSelfForDb,
                'item_mast_name'       => $itemMastName->name ?? '',
                'item_code'            => $itemCode,
                'item_aum_mast_array'  => $itemAumMastArray,
                'selection_size_um'    => $sizeUm,
            ]);
        }

        return response()->json([
            'code'                 => 401,
            'message'              => 'unauthorized request',
            'result'               => '',
            'selection_size_um'    => '',
            'stockDetails'         => '',
            'item_aum_mast_array'  => $itemAumMastArray,
        ]);
    }

    /* ================================================================== */
    /*  6. UPDATE — fulfill retailer claim                                 */
    /* ================================================================== */

    public function update(Request $request, $id)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'order_id'                  => 'required',
            'product_id'                => 'required|array',
            'damage_request_quantity'   => 'required|array',
            'rate'                      => 'required|array',
            'mfg_date'                  => 'required|array',
            'batch_no'                  => 'required|array',
            'mrp'                       => 'required|array',
        ]);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
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

                $newSaleable    = $stock->qty - $damageQtys[$i];
                $newNonSaleable = $stock->nonsalable_damage + $damageQtys[$i];

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
                    ->where('dealer_id', $ctx->dealer_id)
                    ->where('product_id', $pid)
                    ->where('mfg', $mfgDates[$i])
                    ->where('mrp', $mrps[$i])
                    ->where('batch_no', $batchNos[$i])
                    ->update([
                        'qty'                => $newSaleable,
                        'nonsalable_damage'  => $newNonSaleable,
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
    /*  7. PDF Generation — retailer claim                                 */
    /* ================================================================== */

    public function pdfGenerationRetailerClaim(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate(['order_id' => 'required']);

        $orderId    = $request->input('order_id');
        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
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
                'retailer.other_numbers'
            )
            ->where('dms_retailer_claim.order_id', $orderId)
            ->where('dms_retailer_claim.company_id', $company_id)
            ->first();

        $detailsData = DB::table('dms_retailer_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_retailer_claim_details.product_id')
            ->select('catalog_product.name as product_name', 'dms_retailer_claim_details.*')
            ->where('dms_retailer_claim_details.order_id', $orderId)
            ->where('dms_retailer_claim_details.company_id', $company_id)
            ->groupBy('dms_retailer_claim_details.id')
            ->get();

        $companyDetails = DB::table('company')->where('id', $company_id)->first();

        $customPaper = [0, 0, 1240, 1748];
        $pdfName = $orderId . '.pdf';

        $pdf = PDF::loadView('pdf.pdfRetailerClaim', [
            'company_details' => $companyDetails,
            'main_data'       => $mainData,
            'details_data'    => $detailsData,
        ]);
        $pdf->setPaper($customPaper);
        $pdf->save(public_path('pdf/' . $pdfName));

        if (!empty($companyDetails)) {
            return response()->json([
                'code'      => 200,
                'message'   => 'success',
                'pdf_name'  => $pdfName,
                'pdf_url'   => url('pdf/' . $pdfName),
            ]);
        }

        return response()->json([
            'code'     => 401,
            'message'  => 'unauthorized request',
            'pdf_name' => '',
        ], 401);
    }

    /* ================================================================== */
    /*  8. getNonSalealeQty                                                */
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
    return response()->json([
        'code'                 => 200,
        'message'              => 'success',
        'getNonSaleableStock'  => $row,
        'dynamic_row'          => $dynamic_row,
    ]);
}

return response()->json([
    'code'                 => 401,
    'message'              => 'Request Not Found!!',
    'getNonSaleableStock'  => '',
    'dynamic_row'          => $dynamic_row,
]);
    }

    /* ================================================================== */
    /*  9. takeActionGenerateCreditNote (dealer claims)                    */
    /* ================================================================== */

    public function takeActionGenerateCreditNote(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'checkedVals'          => 'required|array|min:1',
            'filtered_dealer_id'   => 'required|integer',
        ]);

        $orderArray = $request->input('checkedVals');
        $dealerId   = $request->input('filtered_dealer_id');

        $dealerData     = $this->dealerLoginRow($ctx->dealer_id);
        $company_id     = $dealerData->company_id;
        $companyDetails = DB::table('company')->where('id', $company_id)->first();

        $stateArr = DB::table('location_3')->where('company_id', $company_id)->get()->keyBy('id');
        $csaDetails     = DB::table('csa')->where('c_id', $ctx->csa_id)->first();
        $dealerDetails  = DB::table('dealer')->where('company_id', $company_id)->where('id', $dealerId)->first();

        $mainData = DB::table('dms_dealer_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $dealerId)
            ->groupBy('order_id')
            ->get();

        $crnoRaw = $mainData->pluck('id')->all();
        $crno    = 'DMC-JPR-' . implode('-', $crnoRaw);

        $orderNoPdf = date('YmdHis') . $ctx->dealer_id;
        $pdfName    = date('YmdHis') . $ctx->dealer_id . '.pdf';

        $detailsData = DB::table('dms_dealer_claim_details')->whereIn('order_id', $orderArray)->get();

        $tv = $detailsData->map(fn ($d) => $d->rate * $d->quantity)->all();
        $finalAmount        = round(array_sum($tv), 2);
        $finalAmountConvert = $this->getIndianCurrency($finalAmount);

        DB::table('dms_dealer_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $dealerId)
            ->update([
                'claim_status'         => 1,
                'claim_fulfill_on'     => now(),
                'credit_note_order_id' => $orderNoPdf,
                'credit_note_pdf'      => $pdfName,
                'credit_note_no'       => $crno,
            ]);

        $pdf = PDF::loadView('pdf.pdfDgrCreditNote', [
            'company_details'     => $companyDetails,
            'main_data'           => $mainData,
            'details_data'        => $detailsData,
            'dealerDetails'       => $dealerDetails,
            'stateArr'            => $stateArr,
            'csaDetails'          => $csaDetails,
            'orderNoPdf'          => $orderNoPdf,
            'crno'                => $crno,
            'final_amount'        => $finalAmount,
            'final_amount_conver' => $finalAmountConvert,
        ]);
        $pdf->setPaper([0, 0, 1240, 1748]);
        $pdf->save(public_path('dms_pdf/' . $pdfName));

        return response()->json([
            'code'     => 200,
            'message'  => 'success',
            'pdf_name' => $pdfName,
            'pdf_url'  => url('dms_pdf/' . $pdfName),
        ]);
    }

    /* ================================================================== */
    /*  10. CreditNoteMtImages — retailer image/amount claim list          */
    /* ================================================================== */

    public function creditNoteMtImages(Request $request)
    {
        $ctx = $this->dealerContext($request);
        [$from_date, $to_date] = $this->parseRange($request->input('date_range_picker'));

        $orderNo  = $request->input('order_no');
        $dealerId = $request->input('dealer_id', 0);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $assignedDealerFilter = DB::table('dealer')
            ->where('company_id', $dealerData->company_id)
            ->where('csa_id', $dealerData->csa_id)
            ->pluck('name', 'id');

        $q = DB::table('dms_retailer_images_amount_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_retailer_images_amount_claim.dealer_id')
            ->join('retailer', 'retailer.id', '=', 'dms_retailer_images_amount_claim.retailer_id')
            ->join('dms_retailer_images_amount_claim_level_approval', 'dms_retailer_images_amount_claim_level_approval.order_id', '=', 'dms_retailer_images_amount_claim.order_id')
            ->select(
                'retailer.name as retailer_name',
                'dealer.id as dealer_id',
                'dealer.name as dealer_name',
                'dms_retailer_images_amount_claim.*'
            )
            ->whereRaw("date_format(dms_retailer_images_amount_claim.date_time,'%Y-%m-%d') >= ? and date_format(dms_retailer_images_amount_claim.date_time,'%Y-%m-%d') <= ?", [$from_date, $to_date])
            ->where('dms_retailer_images_amount_claim.company_id', $dealerData->company_id)
            ->where('dms_retailer_images_amount_claim_level_approval.level_sequence', 3)
            ->where('dealer.id', $dealerId)
            ->groupBy('dms_retailer_images_amount_claim.order_id');

        if (!empty($orderNo)) {
            $q->where('dms_retailer_images_amount_claim.order_id', 'LIKE', "%{$orderNo}%");
        }

        $records = $q->orderBy('dms_retailer_images_amount_claim.date_time', 'DESC')->get();
        $orderIds = $records->pluck('order_id')->all();

        $getClaimAmount = DB::table('dms_retailer_images_amount_claim')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->pluck('claim_amount', 'order_id');

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'dealer_code_cus'      => $ctx->dealer_code,
                'dealer_id_cus'        => $ctx->dealer_id,
                'role_id'              => $ctx->role_id,
                'records'              => $records,
                'getClaimAmount'       => $getClaimAmount,
                'assignedDealerFilter' => $assignedDealerFilter,
                'dealer_id'            => $dealerId,
            ],
        ]);
    }

    /* ================================================================== */
    /*  11. mtRetailerClaimDetailsDMS                                      */
    /* ================================================================== */

    public function mtRetailerClaimDetailsDms(Request $request)
    {
        $this->dealerContext($request);

        $request->validate(['orderid' => 'required']);

        $main = DB::table('dms_retailer_images_amount_claim')
            ->where('order_id', $request->input('orderid'))
            ->first();

        if (!$main) {
            return response()->json(['code' => 500, 'message' => 'Failure!!', 'data' => '']);
        }

        $sending = (array) $main;
        // strip anything not needed
        $allowed = array_merge(
            ['id', 'order_id', 'company_id', 'dealer_id', 'retailer_id', 'date_time', 'claim_amount'],
            array_map(fn ($i) => "doc_{$i}", range(1, 17))
        );
        $sending = array_intersect_key($sending, array_flip($allowed));

        return response()->json([
            'code'            => 200,
            'message'         => 'Success!!',
            'mainSendingArray' => $sending,
        ]);
    }

    /* ================================================================== */
    /*  12. mtRetailerClaimUpdateDMS (multipart upload)                    */
    /* ================================================================== */

    public function mtRetailerClaimUpdateDms(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'orderid'               => 'required',
            'updated_claim_amount'  => 'required|numeric',
        ]);

        $orderId       = $request->input('orderid');
        $updatedAmount = $request->input('updated_claim_amount');

        $orig = DB::table('dms_retailer_images_amount_claim')->where('order_id', $orderId)->first();
        abort_if(!$orig, 404, 'Claim not found');

        // Log the previous record
        $logArray = [];
        foreach ((array) $orig as $k => $v) {
            if ($k === 'id') continue;
            $logArray[$k] = $v;
        }
        $logArray['updated_by_csa']    = $ctx->csa_id;
        $logArray['updated_by_csa_on'] = now();

        DB::table('dms_retailer_images_amount_claim_log')->insert($logArray);

        DB::table('dms_retailer_images_amount_claim')
            ->where('order_id', $orderId)
            ->update(['claim_amount' => $updatedAmount]);

        // Handle optional document uploads (doc_1..doc_17)
        $uploadedFiles = [];
        for ($i = 1; $i <= 17; $i++) {
            $field = "updated_document_array{$i}";
            if ($request->hasFile($field)) {
                $file     = $request->file($field);
                $rand     = substr(str_shuffle('12345678901234567890'), 0, 2);
                $fileName = date('YmdHis') . $rand . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('mt_images_claim'), $fileName);

                DB::table('dms_retailer_images_amount_claim')
                    ->where('order_id', $orderId)
                    ->update(["doc_{$i}" => 'mt_images_claim/' . $fileName]);

                $uploadedFiles["doc_{$i}"] = 'mt_images_claim/' . $fileName;
            }
        }

        return response()->json([
            'code'           => 200,
            'message'        => 'success',
            'uploaded_files' => $uploadedFiles,
        ]);
    }

    /* ================================================================== */
    /*  13. takeActionGenerateCreditNoteDMS                                */
    /* ================================================================== */

public function takeActionGenerateCreditNoteDms(Request $request)
{
    $ctx = $this->dealerContext($request);

    $request->validate([
        'checkedVals'        => 'required|array|min:1',
        'filtered_dealer_id' => 'required|integer',
    ]);

    $orderArray = $request->input('checkedVals');
    $dealerId   = (int) $request->input('filtered_dealer_id');

    // ------------------------------------------------------------------
    // 1. Resolve context
    // ------------------------------------------------------------------
    $dealerData = $this->dealerLoginRow($ctx->dealer_id);
    abort_if(!$dealerData, 422, "dealer_person_login missing for dealer {$ctx->dealer_id}");

    $company_id     = $dealerData->company_id;
    $companyDetails = DB::table('company')->where('id', $company_id)->first();
    abort_if(!$companyDetails, 422, "Company {$company_id} not found");

    $stateArr = DB::table('location_3')
        ->where('company_id', $company_id)
        ->get()
        ->keyBy('id');

    // ------------------------------------------------------------------
    // 2. Dealer row first (csa_id lives here)
    // ------------------------------------------------------------------
    $dealerDetails = DB::table('dealer')
        ->where('company_id', $company_id)
        ->where('id', $dealerId)
        ->first();

    abort_if(!$dealerDetails, 422, "Dealer {$dealerId} not found for company {$company_id}");

    // ------------------------------------------------------------------
    // 3. CSA
    // ------------------------------------------------------------------
    $csaId = $dealerDetails->csa_id ?: ($dealerData->csa_id ?? 0);
    abort_if(empty($csaId), 422, "No CSA linked to dealer {$dealerId}");

    $csaDetails = DB::table('csa')->where('c_id', $csaId)->first();
    abort_if(!$csaDetails, 422, "CSA {$csaId} not found in csa table");

    // ------------------------------------------------------------------
    // 4. Fetch claims (same filters as the legacy function)
    // ------------------------------------------------------------------
    $mainData = DB::table('dms_retailer_images_amount_claim')
        ->where('company_id', $company_id)
        ->whereIn('order_id', $orderArray)
        ->where('dealer_id', $dealerId)
        ->groupBy('order_id')
        ->get();

    // Legacy behavior: if nothing matched, still return a 200 with an empty PDF result.
    // (Comment the block below if you'd rather 422 instead.)
    if ($mainData->isEmpty()) {
        return response()->json([
            'code'     => 200,
            'message'  => 'No matching claim orders — nothing to process',
            'pdf_name' => '',
            'pdf_url'  => '',
        ]);
    }

    // ------------------------------------------------------------------
    // 5. Totals
    // ------------------------------------------------------------------
    $crnoRaw            = $mainData->pluck('id')->all();
    $finalAmount        = round($mainData->sum('claim_amount'), 2);
    $finalAmountConvert = $this->getIndianCurrency($finalAmount);

    $crno       = 'DMC-JPR-' . implode('-', $crnoRaw);
    $orderNoPdf = date('YmdHis') . $ctx->dealer_id;
    $pdfName    = date('YmdHis') . $ctx->dealer_id . '.pdf';

    // ------------------------------------------------------------------
    // 6. Mark claims fulfilled
    // ------------------------------------------------------------------
    DB::table('dms_retailer_images_amount_claim')
        ->where('company_id', $company_id)
        ->whereIn('order_id', $orderArray)
        ->where('dealer_id', $dealerId)
        ->update([
            'claim_status'         => 1,
            'claim_fulfill_on'     => now(),
            'credit_note_order_id' => $orderNoPdf,
            'credit_note_pdf'      => $pdfName,
            'credit_note_no'       => $crno,
        ]);

    // ------------------------------------------------------------------
    // 7. Ensure output dir exists, then build PDF
    // ------------------------------------------------------------------
    $pdfDir = public_path('dms_pdf');
    if (!is_dir($pdfDir)) {
        mkdir($pdfDir, 0755, true);
    }

    $pdf = PDF::loadView('pdf.pdfDgrCreditNote', [
        'company_details'     => $companyDetails,
        'main_data'           => $mainData,
        'dealerDetails'       => $dealerDetails,
        'stateArr'            => $stateArr,
        'csaDetails'          => $csaDetails,
        'orderNoPdf'          => $orderNoPdf,
        'crno'                => $crno,
        'final_amount'        => $finalAmount,
        'final_amount_conver' => $finalAmountConvert,
    ]);
    $pdf->setPaper([0, 0, 1240, 1748]);
    $pdf->save($pdfDir . '/' . $pdfName);

    return response()->json([
        'code'     => 200,
        'message'  => 'success',
        'pdf_name' => $pdfName,
        'pdf_url'  => url('dms_pdf/' . $pdfName),
    ]);
}
    /* ================================================================== */
    /*  14. takeActionOnProcessChallanClaim                                */
    /* ================================================================== */

    public function takeActionOnProcessChallanClaim(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate(['checkedVals' => 'required|array|min:1']);

        $loopData   = $request->input('checkedVals');
        $dealerId   = $ctx->dealer_id;
        $orderId    = date('YmdHis') . $dealerId;

        $dealerData = $this->dealerLoginRow($dealerId);
        $company_id = $dealerData->company_id;

        DB::beginTransaction();
        try {
            DB::table('dms_challan_claim')->insert([
                'order_id'   => $orderId,
                'company_id' => $company_id,
                'dealer_id'  => $dealerId,
                'date_time'  => now(),
            ]);

            $rows = [];
            foreach ($loopData as $lvalue) {
                $ex = explode('|', $lvalue);
                $rows[] = [
                    'order_id'      => $orderId,
                    'challan_id'    => $ex[0] ?? null,
                    'product_id'    => $ex[1] ?? null,
                    'challan_rate'  => $ex[2] ?? 0,
                    'challan_qty'   => $ex[3] ?? 0,
                    'purchase_rate' => $ex[4] ?? 0,
                    'claim_amount'  => $ex[5] ?? 0,
                ];
            }

            DB::table('dms_challan_claim_details')->insert($rows);
            DB::commit();

            return response()->json([
                'code'      => 200,
                'message'   => 'success',
                'order_id'  => $orderId,
                'loop_data' => $loopData,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /* ================================================================== */
    /*  15. CreditNoteChallan — challan claim list                         */
    /* ================================================================== */

    public function creditNoteChallan(Request $request)
    {
        $ctx = $this->dealerContext($request);
        [$from_date, $to_date] = $this->parseRange($request->input('date_range_picker'));

        $orderNo  = $request->input('order_no');
        $dealerId = $request->input('dealer_id', 0);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        $assignedDealerFilter = DB::table('dealer')
            ->where('company_id', $dealerData->company_id)
            ->where('csa_id', $dealerData->csa_id)
            ->pluck('name', 'id');

        $q = DB::table('dms_challan_claim')
            ->join('dealer', 'dealer.id', '=', 'dms_challan_claim.dealer_id')
            ->join('dms_challan_claim_level_approval', 'dms_challan_claim_level_approval.order_id', '=', 'dms_challan_claim.order_id')
            ->select('dealer.id as dealer_id', 'dealer.name as dealer_name', 'dms_challan_claim.*')
            ->whereRaw("date_format(dms_challan_claim.date_time,'%Y-%m-%d') >= ? and date_format(dms_challan_claim.date_time,'%Y-%m-%d') <= ?", [$from_date, $to_date])
            ->where('dms_challan_claim.company_id', $dealerData->company_id)
            ->where('dms_challan_claim_level_approval.level_sequence', 3)
            ->where('dealer.id', $dealerId)
            ->groupBy('dms_challan_claim.order_id');

        if (!empty($orderNo)) {
            $q->where('dms_challan_claim.order_id', 'LIKE', "%{$orderNo}%");
        }

        $records  = $q->orderBy('dms_challan_claim.date_time', 'DESC')->get();
        $orderIds = $records->pluck('order_id')->all();

        $getClaimAmount = DB::table('dms_challan_claim_details')
            ->whereIn('order_id', $orderIds)
            ->groupBy('order_id')
            ->pluck(DB::raw('SUM(claim_amount)'), 'order_id');

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'dealer_code_cus'      => $ctx->dealer_code,
                'dealer_id_cus'        => $ctx->dealer_id,
                'role_id'              => $ctx->role_id,
                'records'              => $records,
                'getClaimAmount'       => $getClaimAmount,
                'assignedDealerFilter' => $assignedDealerFilter,
                'dealer_id'            => $dealerId,
            ],
        ]);
    }

    /* ================================================================== */
    /*  16. mtChallanClaimDetailsSS                                        */
    /* ================================================================== */

    public function mtChallanClaimDetailsSs(Request $request)
    {
        $this->dealerContext($request);
        $request->validate(['orderid' => 'required']);

        $mainData = DB::table('dms_challan_claim_details')
            ->join('catalog_product', 'catalog_product.id', '=', 'dms_challan_claim_details.product_id')
            ->select('catalog_product.name as product_name', 'dms_challan_claim_details.*')
            ->where('dms_challan_claim_details.order_id', $request->input('orderid'))
            ->get();

        if ($mainData->isNotEmpty()) {
            return response()->json(['code' => 200, 'message' => 'Success!!', 'main_data' => $mainData]);
        }

        return response()->json(['code' => 500, 'message' => 'Failure!!', 'main_data' => '']);
    }

    /* ================================================================== */
    /*  17. mtChallanClaimUpdateSS                                         */
    /* ================================================================== */

    public function mtChallanClaimUpdateSs(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'id'            => 'required|array',
            'claim_amount'  => 'required|array',
        ]);

        $ids          = (array) $request->input('id');
        $claimAmounts = (array) $request->input('claim_amount');

        foreach ($ids as $i => $rowId) {
            $existing = DB::table('dms_challan_claim_details')->where('id', $rowId)->first();
            if (!$existing) continue;

            DB::table('dms_challan_claim_details_log')->insert([
                'order_id'          => $existing->order_id,
                'challan_id'        => $existing->challan_id,
                'product_id'        => $existing->product_id,
                'challan_rate'      => $existing->challan_rate,
                'challan_qty'       => $existing->challan_qty,
                'purchase_rate'     => $existing->purchase_rate,
                'claim_amount'      => $existing->claim_amount,
                'updated_by_csa'    => $ctx->csa_id,
                'updated_by_csa_on' => now(),
            ]);

            DB::table('dms_challan_claim_details')
                ->where('id', $rowId)
                ->update(['claim_amount' => $claimAmounts[$i] ?? 0]);
        }

        return response()->json(['code' => 200, 'message' => 'success']);
    }

    /* ================================================================== */
    /*  18. takeActionGenerateChallanCreditNote                            */
    /* ================================================================== */

    public function takeActionGenerateChallanCreditNote(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $request->validate([
            'checkedVals'        => 'required|array|min:1',
            'filtered_dealer_id' => 'required|integer',
        ]);

        $orderArray = $request->input('checkedVals');
        $dealerId   = $request->input('filtered_dealer_id');

        $dealerData     = $this->dealerLoginRow($ctx->dealer_id);
        $company_id     = $dealerData->company_id;
        $companyDetails = DB::table('company')->where('id', $company_id)->first();

        $stateArr      = DB::table('location_3')->where('company_id', $company_id)->get()->keyBy('id');
        $csaDetails    = DB::table('csa')->where('c_id', $ctx->csa_id)->first();
        $dealerDetails = DB::table('dealer')->where('company_id', $company_id)->where('id', $dealerId)->first();

        $mainData = DB::table('dms_challan_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $dealerId)
            ->groupBy('order_id')
            ->get();

        $crnoRaw = $mainData->pluck('id')->all();
        $crno    = 'CHL-' . implode('-', $crnoRaw);

        $detailsAll = DB::table('dms_challan_claim_details')->whereIn('order_id', $orderArray)->get();
        $finalAmount = round($detailsAll->sum('claim_amount'), 2);
        $finalAmountConvert = $this->getIndianCurrency($finalAmount);

        $orderNoPdf = date('YmdHis') . $ctx->dealer_id;
        $pdfName    = date('YmdHis') . $ctx->dealer_id . '.pdf';

        DB::table('dms_challan_claim')
            ->where('company_id', $company_id)
            ->whereIn('order_id', $orderArray)
            ->where('dealer_id', $dealerId)
            ->update([
                'claim_status'         => 1,
                'claim_fulfill_on'     => now(),
                'credit_note_order_id' => $orderNoPdf,
                'credit_note_pdf'      => $pdfName,
                'credit_note_no'       => $crno,
            ]);

        $pdf = PDF::loadView('pdf.pdfDgrCreditNote', [
            'company_details'     => $companyDetails,
            'main_data'           => $mainData,
            'dealerDetails'       => $dealerDetails,
            'stateArr'            => $stateArr,
            'csaDetails'          => $csaDetails,
            'orderNoPdf'          => $orderNoPdf,
            'crno'                => $crno,
            'final_amount'        => $finalAmount,
            'final_amount_conver' => $finalAmountConvert,
        ]);
        $pdf->setPaper([0, 0, 1240, 1748]);
        $pdf->save(public_path('dms_pdf/' . $pdfName));

        return response()->json([
            'code'     => 200,
            'message'  => 'success',
            'pdf_name' => $pdfName,
            'pdf_url'  => url('dms_pdf/' . $pdfName),
        ]);
    }

    /* ================================================================== */
    /*  19. getRasnaSchemeQty                                              */
    /* ================================================================== */

    public function getRasnaSchemeQty(Request $request)
    {
        $this->dealerContext($request);

        $request->validate([
            'productid' => 'required',
            'qty'       => 'required|numeric',
            'rate'      => 'required|numeric',
            'stateid'   => 'required',
            'companyid' => 'required',
        ]);

        $productId = $request->input('productid');
        $qty       = $request->input('qty');
        $rate      = $request->input('rate');
        $stateId   = $request->input('stateid');
        $companyId = $request->input('companyid');
        $curDate   = date('Y-m-d');

        $schemes = DB::table('product_wise_scheme_plan_details')
            ->where('company_id', $companyId)
            ->whereRaw("DATE_FORMAT(valid_from_date,'%Y-%m-%d') <= ? AND DATE_FORMAT(valid_to_date,'%Y-%m-%d') >= ?", [$curDate, $curDate])
            ->where('state_id', $stateId)
            ->where('product_id', $productId)
            ->get();

        $schemePercent = 0;
        foreach ($schemes as $s) {
            if ($s->sale_value_range_last == '~') {
                if ($qty >= $s->sale_value_range_first) {
                    $schemePercent = $s->value_amount_percentage;
                }
            } else {
                if ($qty >= $s->sale_value_range_first && $qty <= $s->sale_value_range_last) {
                    $schemePercent = $s->value_amount_percentage;
                }
            }
        }

        $schemeValue   = round(($rate * $qty) * ($schemePercent / 100), 2);
        $taxableAmount = round(($rate * $qty) - $schemeValue, 2);

        return response()->json([
            'code'             => 200,
            'scheme_percent'   => $schemePercent,
            'scheme_value'     => $schemeValue,
            'taxable_amount'   => $taxableAmount,
        ]);
    }

    /* ================================================================== */
    /*  Helper: amount in words                                            */
    /* ================================================================== */

    private function getIndianCurrency(float $number): string
    {
        $decimal = round($number - ($no = floor($number)), 2) * 100;
        $hundred = null;
        $digits_length = strlen($no);
        $i = 0;
        $str = [];
        $words = [
            0 => '', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
            11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen',
            15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
            19 => 'nineteen', 20 => 'twenty', 30 => 'thirty', 40 => 'forty',
            50 => 'fifty', 60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety',
        ];
        $digits = ['', 'hundred', 'thousand', 'lakh', 'crore'];

        while ($i < $digits_length) {
            $divider = ($i == 2) ? 10 : 100;
            $number  = floor($no % $divider);
            $no      = floor($no / $divider);
            $i      += $divider == 10 ? 1 : 2;

            if ($number) {
                $plural  = (($counter = count($str)) && $number > 9) ? 's' : null;
                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                $str[] = ($number < 21)
                    ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred
                    : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
            } else {
                $str[] = null;
            }
        }
        $rupees = implode('', array_reverse($str));
        $paise  = ($decimal > 0) ? "and " . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
        return ($rupees ? $rupees . 'Rupees ' : '') . $paise;
    }
}