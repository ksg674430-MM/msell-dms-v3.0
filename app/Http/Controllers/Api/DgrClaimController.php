<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DgrClaimController extends Controller
{
    /**
     * GET /api/dgr-claims
     * List of DGR image/amount claims for the dealer.
     */
    use App\Support\FilterBuilder;

public function query(Request $request): JsonResponse
{
    $user = $request->user();

    $fb = FilterBuilder::make('dgr_claim', $request)
        ->hardScope('c.dealer_id = ?', [(int) $user->dealer_id]);

    if ((int) $user->company_id > 0) {
        $fb->hardScope('c.company_id = ?', [(int) $user->company_id]);
    }

    if (!$fb->isValid()) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid filter.',
            'errors'  => $fb->errors(),
        ], 422);
    }

    $result = $fb->run();

    return response()->json([
        'status' => true,
        'data'   => $result['rows'],
        'meta'   => $result['meta'],
    ]);
}
    public function index(Request $request): JsonResponse
    {
        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $from = $request->input('from_date', date('Y-m-01'));
        $to   = $request->input('to_date',   date('Y-m-d'));

        $where    = [
            "c.company_id = ?",
            "c.dealer_id = ?",
            "DATE_FORMAT(c.date_time,'%Y-%m-%d') >= ?",
            "DATE_FORMAT(c.date_time,'%Y-%m-%d') <= ?",
        ];
        $bindings = [$companyId, $dealerId, $from, $to];

        if ($chNo = $request->input('ch_no')) {
            $where[]    = "c.order_id LIKE ?";
            $bindings[] = "%{$chNo}%";
        }

        $whereSql = implode(' AND ', $where);

        $rows = DB::select(
            "SELECT c.id, c.order_id, c.company_id, c.dealer_id, c.retailer_id,
                    c.date_time, c.claim_amount, c.claim_status,
                    c.claim_fulfill_on, c.credit_note_no, c.credit_note_pdf,
                    r.name AS retailer_name,
                    d.name AS dealer_name
               FROM dms_retailer_images_amount_claim c
               LEFT JOIN retailer r ON r.id = c.retailer_id
               LEFT JOIN dealer  d ON d.id = c.dealer_id
              WHERE {$whereSql}
              ORDER BY c.date_time DESC",
            $bindings
        );

        return response()->json([
            'status' => true,
            'rows'   => $rows,
            'labels' => $this->statusLabels(),
        ]);
    }

    /**
     * GET /api/dgr-claims/check?challan_id=X
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['challan_id' => ['required', 'string']]);

        $user      = $request->user();
        $dealerId  = (int) $user->dealer_id;
        $companyId = (int) ($user->company_id ?? 0);

        $claim = DB::selectOne(
            "SELECT id, claim_status FROM dms_retailer_images_amount_claim
              WHERE order_id = ? AND dealer_id = ? AND company_id = ?
              LIMIT 1",
            [$request->input('challan_id'), $dealerId, $companyId]
        );

        return response()->json([
            'status'       => true,
            'claim_exists' => $claim !== null,
            'claim_id'     => $claim->id ?? null,
            'claim_status' => $claim->claim_status ?? null,
        ]);
    }

    /**
     * GET /api/dgr-claims/details?challan_id=X
     * Pre-fill the modal — merges challan info + existing claim if any.
     */
    public function details(Request $request): JsonResponse
    {
        $request->validate(['challan_id' => ['required', 'string']]);

        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);
        $dealerId  = (int) $user->dealer_id;
        $challanId = $request->input('challan_id');

        $invoice = DB::selectOne(
            "SELECT co.id AS order_id, co.ch_no AS invoice_no,
                    DATE_FORMAT(co.ch_date, '%d-%m-%Y') AS invoice_date,
                    co.amount AS invoice_amount, co.discount_amt,
                    co.ch_retailer_id AS retailer_id,
                    r.name AS retailer_name, r.address AS retailer_address,
                    r.contact_per_name AS retailer_contact,
                    r.class AS retailer_class_id,
                    roc.outlet_category AS retailer_category,
                    d.id AS dealer_id, d.name AS dealer_name, d.address AS dealer_address,
                    d.ownership_type_id,
                    co.ch_user_id AS user_id,
                    CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) AS user_name,
                    l7.name AS beat_name,
                    l6.name AS town_name
               FROM challan_order co
               LEFT JOIN retailer r ON r.id = co.ch_retailer_id
               LEFT JOIN _retailer_outlet_category roc ON roc.id = r.class
               LEFT JOIN dealer d ON d.id = co.ch_dealer_id
               LEFT JOIN person p ON p.id = co.ch_user_id
               LEFT JOIN location_7 l7 ON l7.id = r.location_id
               LEFT JOIN location_6 l6 ON l6.id = d.town_id
              WHERE co.id = ?
              LIMIT 1",
            [$challanId]
        );

        if (!$invoice) {
            return response()->json(['status' => false, 'message' => 'Invoice not found.'], 404);
        }

        $claim = DB::selectOne(
            "SELECT * FROM dms_retailer_images_amount_claim
              WHERE order_id = ? AND company_id = ? AND dealer_id = ?
              LIMIT 1",
            [$challanId, $companyId, $invoice->dealer_id]
        );

        $ownership = (int) ($invoice->ownership_type_id ?? 0);
        $claimType = $this->ownershipType($ownership);

        $data = (array) $invoice;
        $data['claim_type'] = $claimType; // 'GT' | 'MT' | 'OTHER'

        if ($claim) {
            $data = array_merge($data, (array) $claim);
            $data['claim_exists']       = true;
            $data['claim_status_label'] = $this->statusLabel((int) $claim->claim_status);
        } else {
            $data['claim_exists']       = false;
            $data['claim_status']       = 0;
            $data['claim_status_label'] = 'Not Submitted';
            for ($i = 1; $i <= 17; $i++) $data["doc_{$i}"] = '';
            $data['contract_date']  = '';
            $data['start_date']     = '';
            $data['display_period'] = '';
            $data['dms_product']    = '';
            $data['claim_amount']   = 0;
            $data['date_time']      = '';
        }

        return response()->json(['status' => true, 'data' => $data]);
    }

    /**
     * POST /api/dgr-claims
     * Submit a claim. Multipart with file uploads.
     */
    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);
        $userId    = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;

        $request->validate([
            'order_id'       => ['required', 'string'],
            'ownership_type' => ['required', 'integer'],
            'claim_amount'   => ['nullable', 'numeric'],
            'dms_product'    => ['nullable', 'string', 'max:100'],
        ]);

        $orderId       = $request->input('order_id');
        $ownershipType = (int) $request->input('ownership_type');
        $claimType     = $this->ownershipType($ownershipType);

        if ($claimType === 'OTHER') {
            return response()->json(['status' => false, 'message' => 'Ownership type not supported (not GT or MT).'], 400);
        }

        $invoice = DB::selectOne(
            "SELECT ch_retailer_id, ch_dealer_id FROM challan_order WHERE id = ? LIMIT 1",
            [$orderId]
        );
        if (!$invoice) {
            return response()->json(['status' => false, 'message' => 'Challan not found.'], 404);
        }

        $retailerId = (string) ($invoice->ch_retailer_id ?? 0);
        $dealerId   = (int) ($invoice->ch_dealer_id ?: $dealerId);

        // Existing claim?
        $existing = DB::selectOne(
            "SELECT id, claim_status FROM dms_retailer_images_amount_claim
              WHERE order_id = ? AND company_id = ? AND dealer_id = ?
              LIMIT 1",
            [$orderId, $companyId, $dealerId]
        );
        if ($existing && (int) $existing->claim_status !== 0) {
            return response()->json([
                'status'  => false,
                'message' => 'Claim already processed (' . $this->statusLabel((int) $existing->claim_status) . ').',
            ], 409);
        }

        $doc = $this->uploadDocs($request, $claimType, $orderId);

        // GT validation
        if ($claimType === 'GT') {
            if (empty($doc['doc_1']) || empty($doc['doc_2'])) {
                return response()->json([
                    'status'  => false,
                    'message' => 'GT requires Contract Form image and at least one Shop Display image.',
                ], 422);
            }
        }
        // MT validation
        if ($claimType === 'MT') {
            $required = ['doc_1', 'doc_2', 'doc_5', 'doc_6', 'doc_7', 'doc_8'];
            foreach ($required as $key) {
                if (empty($doc[$key])) {
                    return response()->json([
                        'status'  => false,
                        'message' => "MT requires all mandatory images (missing: {$key}).",
                    ], 422);
                }
            }
        }

        // Contract dates
        if ($claimType === 'MT') {
            $contractDate  = $request->input('contract_date_mt');
            $startDate     = $request->input('start_date_mt');
            $displayPeriod = (string) $request->input('display_period_mt', '');
        } else {
            $contractDate  = $request->input('contract_date');
            $startDate     = $request->input('start_date');
            $displayPeriod = (string) $request->input('display_period', '');
        }

        $claimAmount = (string) ($request->input('claim_amount') ?? '0');
        $dmsProduct  = (string) ($request->input('dms_product', ''));
        $dateTime    = now()->format('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            $base = [
                'order_id'       => $orderId,
                'company_id'     => $companyId,
                'dealer_id'      => $dealerId,
                'retailer_id'    => $retailerId,
                'date_time'      => $dateTime,
                'contract_date'  => $contractDate ?: null,
                'start_date'     => $startDate ?: null,
                'display_period' => $displayPeriod,
                'dms_product'    => $dmsProduct,
                'claim_amount'   => $claimAmount,
                'claim_status'   => 0,
            ] + $doc;

            if ($existing) {
                DB::table('dms_retailer_images_amount_claim')
                    ->where('id', $existing->id)
                    ->update($base);
                $claimId = $existing->id;
                $message = 'DGR Claim updated successfully.';
            } else {
                $claimId = DB::table('dms_retailer_images_amount_claim')->insertGetId($base);
                $message = 'DGR Claim submitted successfully.';

                // Log
                DB::table('dms_retailer_images_amount_claim_log')->insert([
                    'company_id'   => $companyId,
                    'order_id'     => $orderId,
                    'level_id'     => 1,
                    'level_sequence' => 1,
                    'approved_by'  => $userId,
                    'approved_on'  => $dateTime,
                ]);
            }

            DB::commit();
            return response()->json([
                'status'   => true,
                'message'  => $message,
                'claim_id' => $claimId,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Save failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/dgr-claims/process
     * Bulk process — creates dms_challan_claim + details.
     * Body: { "challan_ids": ["3929...", "..."] }
     * (Replaces legacy takeActionOnProcessChallanClaim with checkedVals CSV)
     */
    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'challan_ids' => ['required', 'array', 'min:1'],
        ]);

        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);
        $userId    = (int) $user->getKey();
        $dealerId  = (int) $user->dealer_id;

        $orderId   = date('YmdHis') . $dealerId;
        $dateTime  = now()->format('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            DB::table('dms_challan_claim')->insert([
                'order_id'   => $orderId,
                'company_id' => $companyId,
                'dealer_id'  => $dealerId,
                'date_time'  => $dateTime,
                'claim_status' => 0,
                'credit_note_pdf' => '',
                'credit_note_no'  => '',
            ]);

            $rows = [];
            foreach ($request->input('challan_ids') as $chId) {
                $rows[] = [
                    'order_id'     => $orderId,
                    'challan_id'   => $chId,
                    'product_id'   => 0,
                    'challan_rate' => '0',
                    'challan_qty'  => 0,
                    'purchase_rate'=> '0',
                    'claim_amount' => '0',
                ];
            }

            DB::table('dms_challan_claim_details')->insert($rows);

            DB::commit();
            return response()->json([
                'status'   => true,
                'message'  => 'Claim processed successfully.',
                'order_id' => $orderId,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Process failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/dgr-claims/approve
     * Bulk approve — flips claim_status and generates credit-note reference.
     * Body: { "order_ids": ["...", "..."] }
     */
    public function approve(Request $request): JsonResponse
    {
        $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
        ]);

        $user      = $request->user();
        $companyId = (int) ($user->company_id ?? 0);
        $dealerId  = (int) $user->dealer_id;

        $orderIds = $request->input('order_ids');
        $dateTime = now()->format('Y-m-d H:i:s');

        // Build credit-note identifiers
        $ids = DB::table('dms_retailer_images_amount_claim')
            ->whereIn('order_id', $orderIds)
            ->where('company_id', $companyId)
            ->where('dealer_id', $dealerId)
            ->pluck('id')
            ->toArray();

        if (empty($ids)) {
            return response()->json(['status' => false, 'message' => 'No matching claims.'], 404);
        }

        $crNo      = 'DMC-JPR-' . implode('-', $ids);
        $orderNo   = date('YmdHis') . $dealerId;
        $pdfName   = date('YmdHis') . $dealerId . '.pdf';

        DB::beginTransaction();
        try {
            DB::table('dms_retailer_images_amount_claim')
                ->whereIn('order_id', $orderIds)
                ->where('company_id', $companyId)
                ->where('dealer_id', $dealerId)
                ->update([
                    'claim_status'         => 1,
                    'claim_fulfill_on'     => $dateTime,
                    'credit_note_order_id' => $orderNo,
                    'credit_note_pdf'      => $pdfName,
                    'credit_note_no'       => $crNo,
                ]);

            DB::commit();
            return response()->json([
                'status'          => true,
                'message'         => 'Claim(s) approved.',
                'credit_note_no'  => $crNo,
                'credit_note_pdf' => $pdfName,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Approve failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* ================= helpers ================= */

    private function uploadDocs(Request $request, string $type, string $orderId): array
    {
        $doc = array_fill_keys(array_map(fn($i) => "doc_{$i}", range(1, 17)), '');
        $dir = public_path('myuploads/dgr_claims');
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $put = function ($file, $prefix) use ($dir) {
            $name = $prefix . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($dir, $name);
            return 'myuploads/dgr_claims/' . $name;
        };

        // Common
        $doc['doc_1'] = $request->hasFile('contract_image')      ? $put($request->file('contract_image'), 'contract')       : '';
        $doc['doc_2'] = $this->uploadMulti($request, 'shop_display_images', 'shop_display', $dir);
        $doc['doc_3'] = $request->hasFile('other_image_1')       ? $put($request->file('other_image_1'), 'other1')          : '';
        $doc['doc_4'] = $request->hasFile('other_image_2')       ? $put($request->file('other_image_2'), 'other2')          : '';

        if ($type === 'MT') {
            $doc['doc_1'] = $request->hasFile('claim_letter_image_mt')  ? $put($request->file('claim_letter_image_mt'), 'claim_letter') : '';
            $doc['doc_2'] = $this->uploadMulti($request, 'shop_display_images_mt', 'shop_display_mt', $dir);
            $doc['doc_3'] = $request->hasFile('other_image_1_mt')        ? $put($request->file('other_image_1_mt'), 'other1_mt')          : '';
            $doc['doc_4'] = $request->hasFile('other_image_2_mt')        ? $put($request->file('other_image_2_mt'), 'other2_mt')          : '';
            $doc['doc_5'] = $this->uploadMulti($request, 'dn_from_chain_mt', 'dn_chain', $dir);
            $doc['doc_6'] = $this->uploadMulti($request, 'dn_db_rasna_mt',  'dn_db_rasna', $dir);
            $doc['doc_7'] = $request->hasFile('visibility_approval_mt')  ? $put($request->file('visibility_approval_mt'), 'vis_approval') : '';
            $doc['doc_8'] = $request->hasFile('visibility_verification_mt') ? $put($request->file('visibility_verification_mt'), 'vis_verify') : '';
        }

        return $doc;
    }

    private function uploadMulti(Request $request, string $field, string $prefix, string $dir): string
    {
        if (!$request->hasFile($field)) return '';
        $urls = [];
        foreach ((array) $request->file($field) as $i => $file) {
            $name = $prefix . '_' . $i . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move($dir, $name);
            $urls[] = 'myuploads/dgr_claims/' . $name;
        }
        return implode(',', $urls);
    }

    private function ownershipType(int $id): string
    {
        $gt = [244, 245, 256, 258, 266];
        $mt = [249, 255, 257, 259, 260, 262, 309];
        if (in_array($id, $gt, true)) return 'GT';
        if (in_array($id, $mt, true)) return 'MT';
        return 'OTHER';
    }

    private function statusLabel(int $s): string
    {
        return [0 => 'Pending', 1 => 'Approved', 2 => 'Rejected', 3 => 'Cancelled'][$s] ?? 'Unknown';
    }

    private function statusLabels(): array
    {
        return [0 => 'Pending', 1 => 'Approved', 2 => 'Rejected', 3 => 'Cancelled'];
    }
}