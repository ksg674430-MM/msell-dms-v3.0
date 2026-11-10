<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PDF;

class MtImagesClaimApiController extends Controller
{
    private const SAMPLING_MAX_IMAGES = 17;

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
            'auth_id'       => $dealerId,
            'dealer_id'     => $dealerId,
            'csa_id'        => $u->csa_id        ?? 0,
            'company_id'    => $u->company_id    ?? 0,
            'dealer_code'   => $u->dealer_code   ?? '0',
            'role_id'       => $u->role_id       ?? '0',
            'quantity_type' => $u->quantity_type ?? 0,
        ];
    }

    private function dealerLoginRow(int $dealerId)
    {
        return DB::table('dealer_person_login')->where('dealer_id', $dealerId)->first();
    }

    private function parseRange(?string $range): array
    {
        if (empty($range)) {
            return [date('Y-m') . '-01', date('Y-m-d')];
        }
        $parts = preg_split('/\s*-\s*/', trim($range));
        if (count($parts) < 2) abort(422, 'Invalid date_range_picker.');
        return [
            date('Y-m-d', strtotime(trim($parts[0]))),
            date('Y-m-d', strtotime(trim($parts[1]))),
        ];
    }

    /**
     * Recursively scrub invalid UTF-8 sequences so response()->json() never dies
     * on legacy latin1 / binary columns.
     */
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
    /*  1. INDEX — list MT images claims                                   */
    /* ================================================================== */

    // public function index(Request $request)
    // {
        // $ctx = $this->dealerContext($request);
        // [$from_date, $to_date] = $this->parseRange($request->input('date_range_picker'));

        // $orderNo = $request->input('order_no');

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing for dealer {$ctx->dealer_id}");

        // $q = DB::table('dms_retailer_images_amount_claim')
            // ->join('retailer', 'retailer.id', '=', 'dms_retailer_images_amount_claim.retailer_id')
            // ->select('retailer.id as retailer_id', 'retailer.name as retailer_name', 'dms_retailer_images_amount_claim.*')
            // ->whereRaw(
                // "date_format(dms_retailer_images_amount_claim.date_time,'%Y-%m-%d') >= ? and date_format(dms_retailer_images_amount_claim.date_time,'%Y-%m-%d') <= ?",
                // [$from_date, $to_date]
            // )
            // ->where('dms_retailer_images_amount_claim.company_id', $dealerData->company_id)
            // ->where('retailer.company_id', $dealerData->company_id)
            // ->where('dms_retailer_images_amount_claim.dealer_id', $ctx->dealer_id)
            // ->groupBy('dms_retailer_images_amount_claim.order_id');

        // if (!empty($orderNo)) {
            // $q->where('dms_retailer_images_amount_claim.order_id', 'LIKE', "%{$orderNo}%");
        // }

        // $records = $q->orderBy('dms_retailer_images_amount_claim.date_time', 'DESC')->get();

        // return response()->json([
            // 'code'    => 200,
            // 'message' => 'success',
            // 'data'    => $this->utf8Clean([
                // 'dealer_code_cus' => $ctx->dealer_code,
                // 'dealer_id_cus'   => $ctx->dealer_id,
                // 'role_id'         => $ctx->role_id,
                // 'records'         => $records,
                // 'from_date'       => $from_date,
                // 'to_date'         => $to_date,
            // ]),
        // ]);
    // }

    /* ================================================================== */
    /*  2. CREATE — form data                                              */
    /* ================================================================== */

    // public function create(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);

        // $retailerArray = DB::table('retailer')
            // ->join('location_7', 'location_7.id', '=', 'retailer.location_id')
            // ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'location_7.id')
            // ->select('retailer.id', 'retailer.name')
            // ->where('retailer.company_id', $ctx->company_id)
            // ->where('dealer_location_rate_list.company_id', $ctx->company_id)
            // ->where('location_7.company_id', $ctx->company_id)
            // ->where('dealer_location_rate_list.dealer_id', $ctx->dealer_id)
            // ->where('retailer_status', 1)
            // ->groupBy('retailer.id', 'retailer.name')
            // ->orderBy('retailer.name', 'ASC')
            // ->get();

        // return response()->json([
            // 'code'    => 200,
            // 'message' => 'success',
            // 'data'    => $this->utf8Clean([
                // 'dealerData'     => $dealerData,
                // 'auth_id'        => $ctx->dealer_id,
                // 'role_id_cus'    => $ctx->role_id,
                // 'quantity_type'  => $ctx->quantity_type,
                // 'company_id'     => $ctx->company_id,
                // 'retailer_array' => $retailerArray,
            // ]),
        // ]);
    // }

    /* ================================================================== */
    /*  3. STORE — create MT claim (multipart)                             */
    /* ================================================================== */

    // public function store(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $request->validate([
            // 'retailer_id'  => 'required|integer',
            // 'claim_amount' => 'required|numeric|min:0',
        // ]);

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");

        // $orderId = date('YmdHis') . $ctx->dealer_id;

        // DB::beginTransaction();
        // try {
            // DB::table('dms_retailer_images_amount_claim')->insert([
                // 'order_id'     => $orderId,
                // 'company_id'   => $dealerData->company_id,
                // 'dealer_id'    => $ctx->dealer_id,
                // 'retailer_id'  => $request->input('retailer_id'),
                // 'claim_amount' => $request->input('claim_amount'),
                // 'date_time'    => now(),
            // ]);

            // $pdfDir = public_path('mt_images_claim');
            // if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

            // $uploaded = [];
            // for ($i = 1; $i <= 17; $i++) {
                // $field = "document_array{$i}";
                // if ($request->hasFile($field)) {
                    // $file = $request->file($field);
                    // $rand = substr(str_shuffle('12345678901234567890'), 0, 2);
                    // $name = date('YmdHis') . $rand . '.' . $file->getClientOriginalExtension();
                    // $file->move($pdfDir, $name);

                    // DB::table('dms_retailer_images_amount_claim')
                        // ->where('order_id', $orderId)
                        // ->update(["doc_{$i}" => 'mt_images_claim/' . $name]);

                    // $uploaded["doc_{$i}"] = 'mt_images_claim/' . $name;
                // }
            // }

            // DB::commit();

            // return response()->json([
                // 'code'           => 200,
                // 'message'        => 'Successfully Submitted!!',
                // 'order_id'       => $orderId,
                // 'uploaded_files' => $uploaded,
            // ], 201);

        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        // }
    // }

    /* ================================================================== */
    /*  4. EDIT — data for one claim                                       */
    /* ================================================================== */

    // public function edit(Request $request, $id)
    // {
        // $ctx = $this->dealerContext($request);

        // try {
            // $uid = Crypt::decryptString($id);
        // } catch (\Throwable $e) {
            // abort_unless(is_numeric($id), 422, 'Invalid id');
            // $uid = $id;
        // }

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");
        // $company_id = $dealerData->company_id;

        // $getRetailer  = DB::table('retailer')->where('company_id', $company_id)->pluck('name', 'id');
        // $productNames = DB::table('catalog_product')->where('company_id', $company_id)->pluck('name', 'id');

        // $currentStock = DB::table('stock')
            // ->where('dealer_id', $ctx->dealer_id)
            // ->where('batch_no', '!=', '')
            // ->where('mfg', '!=', '0000-00-00')
            // ->where('mfg', '!=', '')
            // ->pluck('qty', DB::raw("CONCAT(product_id,batch_no,mfg,mrp) as concat"));

        // $getData = DB::table('dms_retailer_claim')
            // ->join('dms_retailer_claim_details', 'dms_retailer_claim_details.order_id', '=', 'dms_retailer_claim.order_id')
            // ->where('dms_retailer_claim.company_id', $company_id)
            // ->where('dms_retailer_claim_details.company_id', $company_id)
            // ->where('dms_retailer_claim_details.order_id', $uid)
            // ->groupBy('dms_retailer_claim_details.id')
            // ->get();

        // $firstCard = [];
        // $secondCardDetails = [];
        // foreach ($getData as $v) {
            // $firstCard = [
                // 'order_id'      => $v->order_id,
                // 'order_date'    => $v->date,
                // 'order_time'    => $v->time,
                // 'retailer_name' => $getRetailer[$v->retailer_id] ?? '',
            // ];

            // $key = $v->product_id . $v->batch_no . $v->mfg_date . $v->mrp;

            // $secondCardDetails[] = [
                // 'product_id'              => $v->product_id,
                // 'product_name'            => $productNames[$v->product_id] ?? '',
                // 'available_salable_stock' => $currentStock[$key] ?? 0,
                // 'damage_request_quantity' => $v->quantity,
                // 'mfg_date'                => $v->mfg_date,
                // 'batch_no'                => $v->batch_no,
                // 'rate'                    => $v->rate,
                // 'mrp'                     => $v->mrp,
                // 'reason'                  => $v->reason,
            // ];
        // }

        // return response()->json([
            // 'code'    => 200,
            // 'message' => 'success',
            // 'data'    => $this->utf8Clean([
                // 'order_id'          => $uid,
                // 'firstCard'         => (object) $firstCard,
                // 'secondCardDetails' => $secondCardDetails,
                // 'encrypt_id'        => $id,
                // 'company_id'        => $ctx->company_id,
            // ]),
        // ]);
    // }

    /* ================================================================== */
    /*  5. CLAIM PRODUCT RATES                                             */
    /* ================================================================== */

    // public function claimProductRates(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $request->validate([
            // 'item_code'      => 'required',
            // 'item_codes_str' => 'required|string',
        // ]);

        // $itemCode     = $request->input('item_code');
        // $itemCodesStr = explode(',', $request->input('item_codes_str'));
        // $catg         = $request->input('catg');
        // $forSelfForDb = $request->input('for_self_for_db');

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");

        // $dealerDetails = DB::table('dealer')->where('id', $ctx->dealer_id)->first();

        // // Optional template-based lookup
        // if (!empty($dealerDetails->template_id)) {
            // $getRateListDetails = DB::table('product_rate_list_template')
                // ->where('template_type', $dealerDetails->template_id)
                // ->where('product_id', $itemCode)
                // ->where('company_id', $ctx->company_id)
                // ->first();
        // } else {
            // $getRateListDetails = DB::table('product_rate_list')
                // ->where('template_type', $dealerDetails->template_id)
                // ->where('product_id', $itemCode)
                // ->where('state_id', $dealerData->state_id)
                // ->where('company_id', $ctx->company_id)
                // ->first();
        // }

        // $returnRate = DB::table('product_rate_list')
            // ->where('product_id', $itemCode)
            // ->where('state_id', $dealerData->state_id)
            // ->where('company_id', $ctx->company_id)
            // ->first();

        // if (empty($returnRate)) {
            // $returnRate = DB::table('product_rate_list')
                // ->where('product_id', $itemCode)
                // ->where('company_id', $ctx->company_id)
                // ->first();
        // }

        // // -------- item dropdown (FULLY QUALIFIED column names to avoid ambiguity) --------
        // $itemsQ = DB::table('catalog_product')
            // ->where('catalog_product.company_id', $ctx->company_id)
            // ->where('catalog_product.status', 1)
            // ->whereNotIn('catalog_product.id', $itemCodesStr);

        // if ($forSelfForDb == 1) {
            // $itemsQ->join('stock', 'stock.product_id', '=', 'catalog_product.id')
                // ->where('stock.nonsalable_damage', '!=', 0)
                // ->where('stock.dealer_id', $ctx->dealer_id)
                // ->where('stock.batch_no', '!=', '')
                // ->where('stock.mfg', '!=', '0000-00-00')
                // ->where('stock.mfg', '!=', '');
        // }

        // if (!empty($catg)) {
            // $itemsQ->where('catalog_product.catalog_id', $catg);
        // }

        // $itemAumMastArray = $itemsQ
            // ->groupBy('catalog_product.id')
            // ->orderBy('catalog_product.id', 'ASC')
            // ->pluck(
                // DB::raw("CONCAT(catalog_product.itemcode,' - ',catalog_product.name) AS ITEM_NAME"),
                // 'catalog_product.id'
            // );

        // $itemMastName = DB::table('catalog_product')
            // ->where('company_id', $ctx->company_id)
            // ->where('id', $itemCode)
            // ->orderBy('itemcode', 'ASC')
            // ->first();

        // $dealerLogin = DB::table('dealer_person_login')
            // ->where('company_id', $ctx->company_id)
            // ->where('dealer_id', $ctx->dealer_id)
            // ->first();

        // // -------- stock details --------
        // $stockQ = DB::table('stock')
            // ->where('dealer_id', $ctx->dealer_id)
            // ->where('product_id', $itemCode)
            // ->where('batch_no', '!=', '')
            // ->where('mfg', '!=', '0000-00-00')
            // ->where('mfg', '!=', '');

        // if ($forSelfForDb == 1) {
            // $stockQ->where('nonsalable_damage', '!=', 0);
        // }

        // $stockDetails = $stockQ->get();

        // if (!empty($returnRate) && $stockDetails->isNotEmpty()) {

            // if ($ctx->company_id == 44) {
                // $rate = $returnRate->dealer_rate;
            // } elseif ($ctx->company_id == 206) {
                // $rate = $returnRate->dealer_pcs_rate;
            // } elseif ($forSelfForDb == 1) {
                // $rate = $returnRate->dealer_pcs_rate;
            // } else {
                // $rate = $returnRate->retailer_pcs_rate;
            // }

            // if ($ctx->company_id == 243) {
                // $sizeUm = $dealerLogin->quantity_type == 1 ? 'BOX' : 'PCS';
            // } else {
                // $sizeUm = 'PCS';
            // }

            // return response()->json($this->utf8Clean([
                // 'code'                 => 200,
                // 'message'              => 'success',
                // 'result'               => $rate,
                // 'stockDetails'         => $stockDetails,
                // 'for_self_for_db'      => $forSelfForDb,
                // 'item_mast_name'       => $itemMastName->name ?? '',
                // 'item_code'            => $itemCode,
                // 'item_aum_mast_array'  => $itemAumMastArray,
                // 'get_rate_list_details'=> $getRateListDetails,
                // 'selection_size_um'    => $sizeUm,
            // ]));
        // }

        // return response()->json($this->utf8Clean([
            // 'code'                 => 401,
            // 'message'              => 'unauthorized request',
            // 'result'               => '',
            // 'selection_size_um'    => '',
            // 'stockDetails'         => '',
            // 'item_aum_mast_array'  => $itemAumMastArray,
            // 'get_rate_list_details'=> [],
        // ]));
    // }

    /* ================================================================== */
    /*  6. UPDATE — fulfill retailer claim                                 */
    /* ================================================================== */

    // public function update(Request $request, $id)
    // {
        // $ctx = $this->dealerContext($request);

        // $request->validate([
            // 'order_id'                => 'required',
            // 'product_id'              => 'required|array',
            // 'damage_request_quantity' => 'required|array',
            // 'rate'                    => 'required|array',
            // 'mfg_date'                => 'required|array',
            // 'batch_no'                => 'required|array',
            // 'mrp'                     => 'required|array',
        // ]);

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");
        // $company_id = $dealerData->company_id;

        // $orderId = $request->input('order_id');

        // DB::beginTransaction();
        // try {
            // DB::table('dms_retailer_claim')
                // ->where('order_id', $orderId)
                // ->where('company_id', $company_id)
                // ->update([
                    // 'claim_status'     => 1,
                    // 'claim_fulfill_on' => now(),
                // ]);

            // $productIds = (array) $request->input('product_id');
            // $damageQtys = (array) $request->input('damage_request_quantity');
            // $rates      = (array) $request->input('rate');
            // $mfgDates   = (array) $request->input('mfg_date');
            // $batchNos   = (array) $request->input('batch_no');
            // $mrps       = (array) $request->input('mrp');

            // foreach ($productIds as $i => $pid) {
                // $stock = DB::table('stock')
                    // ->where('dealer_id', $ctx->dealer_id)
                    // ->where('product_id', $pid)
                    // ->where('mfg', $mfgDates[$i])
                    // ->where('mrp', $mrps[$i])
                    // ->where('batch_no', $batchNos[$i])
                    // ->first();

                // if (!$stock) continue;

                // $newSaleable    = $stock->qty - $damageQtys[$i];
                // $newNonSaleable = $stock->nonsalable_damage + $damageQtys[$i];

                // DB::table('dms_retailer_claim_details')
                    // ->where('order_id', $orderId)
                    // ->where('product_id', $pid)
                    // ->where('company_id', $company_id)
                    // ->where('mfg_date', $mfgDates[$i])
                    // ->where('batch_no', $batchNos[$i])
                    // ->where('mrp', $mrps[$i])
                    // ->update([
                        // 'claim_fulfill_qty'  => $damageQtys[$i],
                        // 'claim_fulfill_rate' => $rates[$i],
                    // ]);

                // DB::table('stock')
                    // ->where('dealer_id', $ctx->dealer_id)
                    // ->where('product_id', $pid)
                    // ->where('mfg', $mfgDates[$i])
                    // ->where('mrp', $mrps[$i])
                    // ->where('batch_no', $batchNos[$i])
                    // ->update([
                        // 'qty'               => $newSaleable,
                        // 'nonsalable_damage' => $newNonSaleable,
                    // ]);
            // }

            // DB::commit();
            // return response()->json(['code' => 200, 'message' => 'success']);

        // } catch (\Throwable $e) {
            // DB::rollBack();
            // return response()->json(['code' => 500, 'message' => $e->getMessage()], 500);
        // }
    // }

    /* ================================================================== */
    /*  7. PDF — retailer claim                                            */
    /* ================================================================== */

    // public function pdfGenerationRetailerClaim(Request $request)
    // {
        // $ctx = $this->dealerContext($request);
        // $request->validate(['order_id' => 'required']);

        // $orderId    = $request->input('order_id');
        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");
        // $company_id = $dealerData->company_id;

        // $mainData = DB::table('dms_retailer_claim')
            // ->join('dealer', 'dealer.id', '=', 'dms_retailer_claim.dealer_id')
            // ->join('retailer', 'retailer.id', '=', 'dms_retailer_claim.retailer_id')
            // ->select(
                // 'dms_retailer_claim.order_id',
                // 'dealer.name as dealer_name',
                // 'dealer.address',
                // 'dms_retailer_claim.claim_fulfill_on',
                // 'retailer.name as retailer_name',
                // 'retailer.track_address',
                // 'retailer.landline',
                // 'retailer.other_numbers'
            // )
            // ->where('dms_retailer_claim.order_id', $orderId)
            // ->where('dms_retailer_claim.company_id', $company_id)
            // ->first();

        // $detailsData = DB::table('dms_retailer_claim_details')
            // ->join('catalog_product', 'catalog_product.id', '=', 'dms_retailer_claim_details.product_id')
            // ->select('catalog_product.name as product_name', 'dms_retailer_claim_details.*')
            // ->where('dms_retailer_claim_details.order_id', $orderId)
            // ->where('dms_retailer_claim_details.company_id', $company_id)
            // ->groupBy('dms_retailer_claim_details.id')
            // ->get();

        // $companyDetails = DB::table('company')->where('id', $company_id)->first();

        // $pdfDir = public_path('pdf');
        // if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        // $pdfName = $orderId . '.pdf';
        // $pdf = PDF::loadView('pdf.pdfRetailerClaim', [
            // 'company_details' => $companyDetails,
            // 'main_data'       => $mainData,
            // 'details_data'    => $detailsData,
        // ]);
        // $pdf->setPaper([0, 0, 1240, 1748]);
        // $pdf->save($pdfDir . '/' . $pdfName);

        // return response()->json([
            // 'code'     => 200,
            // 'message'  => 'success',
            // 'pdf_name' => $pdfName,
            // 'pdf_url'  => url('pdf/' . $pdfName),
        // ]);
    // }

    /* ================================================================== */
    /*  8. Non-saleable qty                                                */
    /* ================================================================== */

    // public function getNonSaleableQty(Request $request)
    // {
        // $ctx = $this->dealerContext($request);
        // $request->validate(['concatData' => 'required|string']);

        // $ex          = explode('|', $request->input('concatData'));
        // $product_id  = $ex[0] ?? null;
        // $batch_no    = $ex[1] ?? null;
        // $mfg         = $ex[2] ?? null;
        // $mrp         = $ex[3] ?? null;
        // $dynamic_row = $ex[4] ?? null;

        // $row = DB::table('stock')
            // ->where('product_id', $product_id)
            // ->where('mfg', $mfg)
            // ->where('batch_no', $batch_no)
            // ->where('mrp', $mrp)
            // ->where('dealer_id', $ctx->dealer_id)
            // ->first();

        // if (!empty($row)) {
            // return response()->json($this->utf8Clean([
                // 'code'                => 200,
                // 'message'             => 'success',
                // 'getNonSaleableStock' => $row,
                // 'dynamic_row'         => $dynamic_row,
            // ]));
        // }

        // return response()->json([
            // 'code'                => 401,
            // 'message'             => 'Request Not Found!!',
            // 'getNonSaleableStock' => '',
            // 'dynamic_row'         => $dynamic_row,
        // ]);
    // }

    /* ================================================================== */
    /*  9. takeActionGenerateCreditNote (dealer claims)                    */
    /* ================================================================== */

    // public function takeActionGenerateCreditNote(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $request->validate([
            // 'checkedVals'        => 'required|array|min:1',
            // 'filtered_dealer_id' => 'required|integer',
        // ]);

        // $orderArray = $request->input('checkedVals');
        // $dealerId   = (int) $request->input('filtered_dealer_id');

        // $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        // abort_if(!$dealerData, 422, "dealer_person_login missing");
        // $company_id = $dealerData->company_id;

        // $companyDetails = DB::table('company')->where('id', $company_id)->first();
        // abort_if(!$companyDetails, 422, "Company {$company_id} not found");

        // $stateArr = DB::table('location_3')->where('company_id', $company_id)->get()->keyBy('id');

        // // Load dealer first, then csa from it
        // $dealerDetails = DB::table('dealer')
            // ->where('company_id', $company_id)
            // ->where('id', $dealerId)
            // ->first();
        // abort_if(!$dealerDetails, 422, "Dealer {$dealerId} not found for company {$company_id}");

        // $csaId = $dealerDetails->csa_id ?: ($dealerData->csa_id ?? 0);
        // abort_if(empty($csaId), 422, "No CSA linked to dealer {$dealerId}");

        // $csaDetails = DB::table('csa')->where('c_id', $csaId)->first();
        // abort_if(!$csaDetails, 422, "CSA {$csaId} not found");

        // $mainData = DB::table('dms_dealer_claim')
            // ->where('company_id', $company_id)
            // ->whereIn('order_id', $orderArray)
            // ->where('dealer_id', $dealerId)
            // ->groupBy('order_id')
            // ->get();

        // if ($mainData->isEmpty()) {
            // return response()->json([
                // 'code'     => 200,
                // 'message'  => 'No matching claim orders — nothing to process',
                // 'pdf_name' => '',
                // 'pdf_url'  => '',
            // ]);
        // }

        // $crnoRaw     = $mainData->pluck('id')->all();
        // $crno        = 'DMC-JPR-' . implode('-', $crnoRaw);
        // $orderNoPdf  = date('YmdHis') . $ctx->dealer_id;
        // $pdfName     = date('YmdHis') . $ctx->dealer_id . '.pdf';

        // $detailsData = DB::table('dms_dealer_claim_details')->whereIn('order_id', $orderArray)->get();
        // $finalAmount        = round($detailsData->sum(fn ($d) => $d->rate * $d->quantity), 2);
        // $finalAmountConvert = $this->getIndianCurrency($finalAmount);

        // DB::table('dms_dealer_claim')
            // ->where('company_id', $company_id)
            // ->whereIn('order_id', $orderArray)
            // ->where('dealer_id', $dealerId)
            // ->update([
                // 'claim_status'         => 1,
                // 'claim_fulfill_on'     => now(),
                // 'credit_note_order_id' => $orderNoPdf,
                // 'credit_note_pdf'      => $pdfName,
                // 'credit_note_no'       => $crno,
            // ]);

        // $pdfDir = public_path('dms_pdf');
        // if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

        // $pdf = PDF::loadView('pdf.pdfDgrCreditNote', [
            // 'company_details'     => $companyDetails,
            // 'main_data'           => $mainData,
            // 'details_data'        => $detailsData,
            // 'dealerDetails'       => $dealerDetails,
            // 'stateArr'            => $stateArr,
            // 'csaDetails'          => $csaDetails,
            // 'orderNoPdf'          => $orderNoPdf,
            // 'crno'                => $crno,
            // 'final_amount'        => $finalAmount,
            // 'final_amount_conver' => $finalAmountConvert,
        // ]);
        // $pdf->setPaper([0, 0, 1240, 1748]);
        // $pdf->save($pdfDir . '/' . $pdfName);

        // return response()->json([
            // 'code'     => 200,
            // 'message'  => 'success',
            // 'pdf_name' => $pdfName,
            // 'pdf_url'  => url('dms_pdf/' . $pdfName),
        // ]);
    // }

    /* ================================================================== */
    /*  10. getIndianCurrency                                              */
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

    /* ================================================================== */
    /*  11. NEW MT IMAGES (samplingclaim) — form data                      */
    /* ================================================================== */

    public function newMtImages(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        $dealerName = DB::table('dealer')->where('id', $ctx->dealer_id)->first();

        $retailerArray = DB::table('retailer')
            ->join('location_7', 'location_7.id', '=', 'retailer.location_id')
            ->join('dealer_location_rate_list', 'dealer_location_rate_list.location_id', '=', 'location_7.id')
            ->select('retailer.id', 'retailer.name')
            ->where('retailer.company_id', $ctx->company_id)
            ->where('dealer_location_rate_list.company_id', $ctx->company_id)
            ->where('location_7.company_id', $ctx->company_id)
            ->where('dealer_location_rate_list.dealer_id', $ctx->dealer_id)
            ->where('retailer_status', 1)
            ->groupBy('retailer.id', 'retailer.name')
            ->orderBy('retailer.name', 'ASC')
            ->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'dealerData'     => $dealerData,
                'dealer_Name'    => $dealerName,
                'auth_id'        => $ctx->dealer_id,
                'role_id_cus'    => $ctx->role_id,
                'quantity_type'  => $ctx->quantity_type,
                'company_id'     => $ctx->company_id,
                'retailer_array' => $retailerArray,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  12. STORE NEW IMAGES CLAIM (samplingclaim) — multipart             */
    /* ================================================================== */

    public function storingNewImagesClaim(Request $request)
    {
        $ctx = $this->dealerContext($request);

        $itemCodes = (array) $request->input('item_code');
        $rules = [
            'retailer_id' => ['required', Rule::exists('retailer', 'id')->where('company_id', $ctx->company_id)],
            'item_code'   => 'required|array|min:1',
        ];
        $names = ['retailer_id' => 'retailer'];
        foreach (array_keys($itemCodes) as $i => $key) {
            $row = 'Item ' . ($i + 1);
            $rules["item_code.$key"]          = 'required|integer|distinct';
            $rules["product_rate.$key"]       = 'required|numeric|min:0';
            $rules["qty.$key"]                = 'required|numeric|gt:0';
            $rules["discount_percent.$key"]   = 'nullable|numeric|between:0,100';
            $rules["sampling_qty.$key"]       = 'required|numeric|gt:0';
            $rules["invoice_no.$key"]         = 'nullable|string|max:100';
            $rules["remarks.$key"]            = 'nullable|string|max:25';
            $rules["document_array.$key"]     = 'required|array|min:1|max:' . self::SAMPLING_MAX_IMAGES;
            $rules["document_array.$key.*"]   = 'required|file|image|max:10240';
            $names += [
                "item_code.$key"        => "$row item",
                "product_rate.$key"     => "$row rate per case",
                "qty.$key"              => "$row Qty (CASE)",
                "discount_percent.$key" => "$row Disc %",
                "sampling_qty.$key"     => "$row Mkt Sampling in Pcs",
                "invoice_no.$key"       => "$row invoice no",
                "remarks.$key"          => "$row reason",
                "document_array.$key"   => "$row images",
                "document_array.$key.*" => "$row image",
            ];
        }
        Validator::make($request->all(), $rules, [], $names)->validate();

        $retailerInBeat = DB::table('retailer')
            ->join('dealer_location_rate_list as dlr', 'dlr.location_id', '=', 'retailer.location_id')
            ->where('retailer.id', $request->input('retailer_id'))
            ->where('retailer.company_id', $ctx->company_id)
            ->where('dlr.dealer_id', $ctx->dealer_id)
            ->where('dlr.company_id', $ctx->company_id)
            ->exists();
        abort_unless($retailerInBeat, 422, 'Select a retailer from your own beats.');

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");

        $packs = DB::table('catalog_product')
            ->where('company_id', $ctx->company_id)
            ->whereIn('id', $itemCodes)
            ->pluck('quantity_per_case', 'id');

        $now = now('Asia/Kolkata');
        $rows = [];
        foreach ($itemCodes as $i => $itemCode) {
            $perCase = (float) ($packs[$itemCode] ?? 0);
            abort_if($perCase <= 0, 422, 'Item ' . (count($rows) + 1) . ': product not found or its pack size is missing.');

            $qtyCase    = (float) $request->input("qty.$i");
            $rate       = (float) $request->input("product_rate.$i");
            $discPct    = (float) $request->input("discount_percent.$i", 0);
            $samplingQt = (float) $request->input("sampling_qty.$i");
            $gross      = round($rate * $qtyCase, 2);
            $qtyPcs     = $qtyCase * $perCase;
            $discValue  = round($gross * $discPct / 100, 2);
            $basic      = round($gross - $discValue, 2);

            $rows[$i] = [
                'product_id'       => $itemCode,
                'mrp'              => $request->input("mrp.$i"),
                'rate_case'        => $rate,
                'qty_case'         => $qtyCase,
                'qty_pcs'          => $qtyPcs,
                'discount_percent' => $discPct,
                'discount_value'   => $discValue,
                'gross_value'      => $gross,
                'basic_value'      => $basic,
                'size_um'          => $request->input("unit_configuration.$i"),
                'invoice_no'       => $request->input("invoice_no.$i"),
                'sampling_qty'     => $samplingQt,
                'claim_amount'     => round($basic / $qtyPcs * $samplingQt, 2),
                'remarks'          => $request->input("remarks.$i"),
                'date_time'        => $now,
            ];
        }
        $totalClaim = round(array_sum(array_column($rows, 'claim_amount')), 2);
        $orderId = $now->format('YmdHis') . $ctx->dealer_id;

        $uploadDir = public_path('mt_images_claim');
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $moved = [];
        $uploadedAll = [];
        DB::beginTransaction();
        try {
            $samplingId = DB::table('samplingclaim')->insertGetId([
                'order_id'     => $orderId,
                'company_id'   => $dealerData->company_id,
                'dealer_id'    => $ctx->dealer_id,
                'retailer_id'  => $request->input('retailer_id'),
                'claim_amount' => $totalClaim,
                'date_time'    => $now,
            ]);

            foreach ($rows as $key => $temp) {
                $temp['sampling_id'] = $samplingId;
                foreach (array_values((array) $request->file("document_array.$key", [])) as $n => $file) {
                    $name = $now->format('YmdHis') . '_' . Str::uuid() . '.' . $file->extension();
                    $file->move($uploadDir, $name);
                    $moved[] = $uploadDir . DIRECTORY_SEPARATOR . $name;

                    $column = 'doc_' . ($n + 1);
                    $temp[$column] = 'mt_images_claim/' . $name;
                    $uploadedAll[$key][$column] = 'mt_images_claim/' . $name;
                }

                DB::table('samplingclaim_details')->insert($temp);
            }

            DB::commit();

            return response()->json([
                'code'           => 200,
                'message'        => 'Successfully Submitted',
                'order_id'       => $orderId,
                'claim_amount'   => $totalClaim,
                'uploaded_files' => $uploadedAll,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($moved as $path) @unlink($path);
            return response()->json(['code' => 500, 'message' => safe_error_message($e)], 500);
        }
    }

    /* ================================================================== */
    /*  13. NEW MT IMAGES INDEX                                            */
    /* ================================================================== */

    public function newMtImagesIndex(Request $request)
    {
        $ctx = $this->dealerContext($request);
        [$from_date, $to_date] = $this->parseRange($request->input('date_range_picker'));

        $orderNo = $request->input('order_no');

        $dealerData = $this->dealerLoginRow($ctx->dealer_id);
        abort_if(!$dealerData, 422, "dealer_person_login missing");

        $q = DB::table('samplingclaim')
            ->join('retailer', 'retailer.id', '=', 'samplingclaim.retailer_id')
            ->select('retailer.id as retailer_id', 'retailer.name as retailer_name', 'samplingclaim.*')
            ->whereRaw(
                "date_format(samplingclaim.date_time,'%Y-%m-%d') >= ? and date_format(samplingclaim.date_time,'%Y-%m-%d') <= ?",
                [$from_date, $to_date]
            )
            ->where('samplingclaim.company_id', $dealerData->company_id)
            ->where('retailer.company_id', $dealerData->company_id)
            ->where('samplingclaim.dealer_id', $ctx->dealer_id)
            ->groupBy('samplingclaim.order_id');

        if (!empty($orderNo)) {
            $q->where('samplingclaim.order_id', 'LIKE', "%{$orderNo}%");
        }

        $records = $q->orderBy('samplingclaim.date_time', 'DESC')->get();

        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => $this->utf8Clean([
                'dealer_code_cus' => $ctx->dealer_code,
                'dealer_id_cus'   => $ctx->dealer_id,
                'role_id'         => $ctx->role_id,
                'records'         => $records,
            ]),
        ]);
    }

    /* ================================================================== */
    /*  14. DGR Claim details (invoice + existing claim)                   */
    /* ================================================================== */

    // public function getDgrClaimDetails(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $request->validate(['order_id' => 'required']);

        // $orderId    = $request->input('order_id');
        // $companyId  = $ctx->company_id;
        // $dealerId   = $ctx->dealer_id;

        // $invoice = DB::table('challan_order as co')
            // ->leftJoin('retailer as r', 'co.ch_retailer_id', '=', 'r.id')
            // ->leftJoin('_retailer_outlet_category as roc', 'roc.id', '=', 'r.class')
            // ->leftJoin('dealer as d', 'co.ch_dealer_id', '=', 'd.id')
            // ->leftJoin('person as p', 'co.ch_user_id', '=', 'p.id')
            // ->leftJoin('location_7 as l7', 'r.location_id', '=', 'l7.id')
            // ->leftJoin('location_6 as l6', 'd.town_id', '=', 'l6.id')
            // ->where('co.id', $orderId)
            // ->select(
                // 'co.id as order_id',
                // 'co.ch_no as invoice_no',
                // DB::raw("DATE_FORMAT(co.ch_date, '%d-%m-%Y') as invoice_date"),
                // 'co.amount as invoice_amount',
                // 'co.discount_amt',
                // 'co.ch_retailer_id as retailer_id',
                // 'r.name as retailer_name',
                // 'r.address as retailer_address',
                // 'r.contact_per_name as retailer_contact',
                // 'd.id as dealer_id',
                // 'd.name as dealer_name',
                // 'd.address as dealer_address',
                // 'co.ch_user_id as user_id',
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                // 'l7.name as beat_name',
                // 'l6.name as town_name',
                // 'roc.outlet_category as retailer_category'
            // )
            // ->first();

        // if (!$invoice) {
            // return response()->json(['code' => 404, 'message' => 'Invoice not found'], 404);
        // }

        // $claim = DB::table('dms_retailer_images_amount_claim')
            // ->where('order_id', $orderId)
            // ->where('company_id', $companyId)
            // ->where('dealer_id', $invoice->dealer_id)
            // ->first();

        // $responseData = (array) $invoice;

        // if ($claim) {
            // $responseData = array_merge($responseData, (array) $claim);
            // $responseData['claim_exists']        = true;
            // $responseData['claim_status']        = $claim->claim_status;
            // $responseData['claim_status_label']  = $this->getClaimStatusLabel($claim->claim_status);
        // } else {
            // $responseData['claim_exists']       = false;
            // $responseData['claim_status']       = 0;
            // $responseData['claim_status_label'] = 'Not Submitted';
            // $responseData['claim_amount']       = 0;
            // for ($i = 1; $i <= 17; $i++) {
                // $responseData["doc_{$i}"] = '';
            // }
            // $responseData['contract_date']  = '';
            // $responseData['start_date']     = '';
            // $responseData['display_period'] = '';
            // $responseData['dms_product']    = '';
            // $responseData['date_time']      = '';
        // }

        // return response()->json([
            // 'code' => 200,
            // 'data' => $this->utf8Clean($responseData),
        // ]);
    // }

    /* ================================================================== */
    /*  15. SUBMIT DGR CLAIM (multipart)                                   */
    /* ================================================================== */

    // public function submitDgrClaim(Request $request)
    // {
        // $ctx = $this->dealerContext($request);

        // $validator = Validator::make($request->all(), [
            // 'order_id'       => 'required',
            // 'claim_amount'   => 'required|numeric|min:0',
            // 'dms_product'    => 'required|string',
            // 'doc_1'          => 'nullable|image|max:5120',
            // 'doc_2'          => 'nullable|array',
            // 'doc_2.*'        => 'nullable|image|max:5120',
            // 'doc_3'          => 'nullable|image|max:5120',
            // 'doc_4'          => 'nullable|image|max:5120',
            // 'contract_date'  => 'nullable|date',
            // 'start_date'     => 'nullable|date',
            // 'display_period' => 'nullable|string|max:255',
        // ]);

        // if ($validator->fails()) {
            // return response()->json([
                // 'code'    => 400,
                // 'message' => 'Validation Error',
                // 'errors'  => $validator->errors(),
            // ], 422);
        // }

        // $orderId       = $request->input('order_id');
        // $companyId     = $request->input('company_id', $ctx->company_id);
        // $userId        = $request->user()->id ?? 0;
        // $claimAmount   = $request->input('claim_amount', 0);
        // $dmsProduct    = $request->input('dms_product', '');
        // $contractDate  = $request->input('contract_date', '');
        // $startDate     = $request->input('start_date', '');
        // $displayPeriod = $request->input('display_period', '');
        // $dateTime      = now();

        // $invoice = DB::table('challan_order')->where('id', $orderId)
            // ->select('ch_retailer_id', 'ch_dealer_id')->first();

        // $retailerId = $invoice->ch_retailer_id ?? 0;
        // $dealerId   = $invoice->ch_dealer_id   ?? $ctx->dealer_id;

        // if (empty($orderId) || empty($companyId) || empty($dealerId)) {
            // return response()->json(['code' => 400, 'message' => 'Missing required fields'], 400);
        // }

        // $docPaths = array_fill(1, 17, '');
        // $uploadDir = 'dgr_claims/' . $orderId;
        // if (!Storage::disk('public')->exists($uploadDir)) {
            // Storage::disk('public')->makeDirectory($uploadDir, 0777, true);
        // }

        // // doc_1 — single image
        // if ($request->hasFile('doc_1')) {
            // $file = $request->file('doc_1');
            // $name = 'doc_1_' . time() . '.' . $file->getClientOriginalExtension();
            // $path = $file->storeAs($uploadDir, $name, 'public');
            // $docPaths[1] = Storage::url($path);
        // }

        // // doc_2 — multiple images
        // if ($request->hasFile('doc_2')) {
            // $images = [];
            // foreach ($request->file('doc_2') as $i => $file) {
                // if ($file->isValid()) {
                    // $name = 'doc_2_' . $i . '_' . time() . '.' . $file->getClientOriginalExtension();
                    // $path = $file->storeAs($uploadDir, $name, 'public');
                    // $images[] = Storage::url($path);
                // }
            // }
            // $docPaths[2] = implode(',', $images);
        // }

        // foreach (['doc_3', 'doc_4'] as $idx => $field) {
            // $num = $idx + 3;
            // if ($request->hasFile($field)) {
                // $file = $request->file($field);
                // $name = $field . '_' . time() . '.' . $file->getClientOriginalExtension();
                // $path = $file->storeAs($uploadDir, $name, 'public');
                // $docPaths[$num] = Storage::url($path);
            // }
        // }

        // $docPaths[5] = $dmsProduct;
        // $docPaths[6] = $contractDate;
        // $docPaths[7] = $startDate;
        // $docPaths[8] = $displayPeriod;
        // $docPaths[9] = $dateTime->format('Y-m-d H:i:s');

        // $claimData = [
            // 'claim_amount' => $claimAmount,
            // 'claim_status' => 0,
            // 'date_time'    => $dateTime,
        // ];
        // for ($i = 1; $i <= 17; $i++) {
            // $claimData["doc_{$i}"] = $docPaths[$i];
        // }

        // $existing = DB::table('dms_retailer_images_amount_claim')
            // ->where('order_id', $orderId)
            // ->where('company_id', $companyId)
            // ->where('dealer_id', $dealerId)
            // ->first();

        // if ($existing) {
            // DB::table('dms_retailer_images_amount_claim')->where('id', $existing->id)->update($claimData);

            // $logData = $claimData;
            // $logData['order_id']          = $orderId;
            // $logData['company_id']        = $companyId;
            // $logData['dealer_id']         = $dealerId;
            // $logData['retailer_id']       = $retailerId;
            // $logData['updated_by_user']   = $userId;
            // $logData['updated_by_user_on']= $dateTime;
            // DB::table('dms_retailer_images_amount_claim_log')->insert($logData);

            // $message = 'DGR Claim updated successfully!';
            // $claimId = $existing->id;
        // } else {
            // $claimData['order_id']    = $orderId;
            // $claimData['company_id']  = $companyId;
            // $claimData['dealer_id']   = $dealerId;
            // $claimData['retailer_id'] = $retailerId;

            // $claimId = DB::table('dms_retailer_images_amount_claim')->insertGetId($claimData);

            // $logData = $claimData;
            // $logData['updated_by_user']    = $userId;
            // $logData['updated_by_user_on'] = $dateTime;
            // DB::table('dms_retailer_images_amount_claim_log')->insert($logData);

            // DB::table('dms_retailer_images_amount_claim_level_approval')->insert([
                // 'company_id'     => $companyId,
                // 'order_id'       => $orderId,
                // 'level_id'       => 1,
                // 'level_sequence' => 1,
                // 'approved_by'    => 0,
                // 'approved_on'    => $dateTime,
            // ]);

            // $message = 'DGR Claim submitted successfully!';
        // }

        // // Optional: flip challan flag if column exists
        // $columns = DB::select("SHOW COLUMNS FROM challan_order LIKE 'dgr_claim_status'");
        // if (!empty($columns)) {
            // DB::table('challan_order')->where('id', $orderId)->update(['dgr_claim_status' => 1]);
        // }

        // return response()->json([
            // 'code'     => 200,
            // 'message'  => $message,
            // 'claim_id' => $claimId,
        // ]);
    // }

    /* ================================================================== */
    /*  16. GET INVOICE DETAILS ONLY                                       */
    /* ================================================================== */

    // public function getInvoiceDetails(Request $request)
    // {
        // $this->dealerContext($request);

        // $request->validate(['order_id' => 'required']);
        // $orderId = $request->input('order_id');

        // $invoice = DB::table('challan_order as co')
            // ->leftJoin('retailer as r', 'co.ch_retailer_id', '=', 'r.id')
            // ->leftJoin('dealer as d', 'co.ch_dealer_id', '=', 'd.id')
            // ->leftJoin('person as p', 'co.ch_user_id', '=', 'p.id')
            // ->leftJoin('location_7 as l7', 'r.location_id', '=', 'l7.id')
            // ->where('co.id', $orderId)
            // ->select(
                // 'co.id as order_id',
                // 'co.ch_no as invoice_no',
                // DB::raw("DATE_FORMAT(co.ch_date, '%d-%m-%Y') as invoice_date"),
                // 'co.amount as invoice_amount',
                // 'co.discount_amt',
                // 'co.ch_retailer_id as retailer_id',
                // 'r.name as retailer_name',
                // 'r.class as retailer_category',
                // 'r.address as retailer_address',
                // 'r.contact_per_name as retailer_contact',
                // 'd.name as dealer_name',
                // 'co.ch_user_id as user_id',
                // DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as user_name"),
                // 'l7.name as beat_name'
            // )
            // ->first();

        // if (!$invoice) {
            // return response()->json(['code' => 404, 'message' => 'Invoice not found'], 404);
        // }

        // return response()->json([
            // 'code' => 200,
            // 'data' => $this->utf8Clean($invoice),
        // ]);
    // }

    /* ================================================================== */
    /*  HELPER — status label                                              */
    /* ================================================================== */

    private function getClaimStatusLabel($status): string
    {
        $labels = [
            0 => 'Pending',
            1 => 'Approved',
            2 => 'Rejected',
            3 => 'Cancelled',
        ];
        return $labels[$status] ?? 'Unknown';
    }
}