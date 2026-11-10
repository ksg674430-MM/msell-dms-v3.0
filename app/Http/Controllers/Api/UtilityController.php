<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UtilityController extends Controller
{
    private function ctx(Request $request): array
    {
        $u = $request->user();
        $d = DB::table('dealer')->where('id', $u->dealer_id ?? 0)->first();
        return [
            'dealer_id'  => (int) ($u->dealer_id ?? 0),
            'company_id' => (int) ($d->company_id ?? ($u->company_id ?? 0)),
        ];
    }

    /* was dms_return_case_size */
    public function caseSize(Request $request): \Illuminate\Http\JsonResponse
    {
        if (empty($request->item_code)) {
            return response()->json(['status' => false, 'message' => 'item_code required'], 422);
        }
        $row = DB::table('catalog_product')->where('id', $request->item_code)->first();
        if (! $row) return response()->json(['status' => false, 'message' => 'Product not found'], 404);

        return response()->json([
            'status'  => true,
            'message' => 'Case size',
            'data'    => ['qty_per_case' => $row->quantity_per_case],
        ]);
    }

    /* was upload_dms_challan_format (stream CSV) */
    public function challanFormat()
    {
        return response()->streamDownload(function () {
            echo "Date,Order Id,Retailer Id,Retailer Name,User Id,User Name,Product Id,Product Name,Quantity,Rate,Direct Invoice(1)/Through SFA(0)\n";
        }, 'DMSchallanFormat.csv', ['Content-Type' => 'text/csv']);
    }

    /* was dms_import_export — just return context meta */
    public function importExportMeta(Request $request): \Illuminate\Http\JsonResponse
    {
        $c = $this->ctx($request);
        return response()->json([
            'status'  => true,
            'message' => 'Import / Export meta',
            'data'    => [
                'company_id' => $c['company_id'],
                'dealer_id'  => $c['dealer_id'],
                'formats'    => [
                    'order'   => url('/api/quick-order/format'),
                    'challan' => url('/api/utility/challan-format'),
                ],
            ],
        ]);
    }

    /* was autocomplete_search_url — fixed the buggy dd() + returns JSON */
    public function moduleAutocomplete(Request $request): \Illuminate\Http\JsonResponse
    {
        $c   = $this->ctx($request);
        $term = $request->get('term', '');
        $data = [];

        $parent = DB::table('web_module')
            ->join('modules_bucket', 'modules_bucket.id', '=', 'web_module.module_id')
            ->select('web_module.title')
            ->where('web_module.company_id', $c['company_id'])
            ->where('web_module.title', 'LIKE', "%{$term}%")
            ->get();

        $sub = DB::table('sub_web_module')
            ->join('sub_web_module_bucket', 'sub_web_module_bucket.id', '=', 'sub_web_module.sub_module_id')
            ->select('sub_web_module.title')
            ->where('sub_web_module.company_id', $c['company_id'])
            ->where('sub_web_module.title', 'LIKE', "%{$term}%")
            ->get();

        $subsub = DB::table('sub_sub_web_module')
            ->join('sub_sub_web_module_bucket', 'sub_sub_web_module_bucket.id', '=', 'sub_sub_web_module.sub_sub_module_id')
            ->select('sub_sub_web_module.title')
            ->where('sub_sub_web_module.company_id', $c['company_id'])
            ->where('sub_sub_web_module.title', 'LIKE', "%{$term}%")
            ->get();

        foreach ($sub as $r)     $data[] = ['value' => $r->title, 'title' => $r->title];
        foreach ($subsub as $r)  $data[] = ['value' => $r->title, 'title' => $r->title];
        if (empty($sub) && empty($subsub)) {
            foreach ($parent as $r) $data[] = ['value' => $r->title, 'title' => $r->title];
        }

        // dedupe
        $data = array_values(array_reduce($data, function ($carry, $item) {
            $carry[$item['value']] = $item;
            return $carry;
        }, []));

        return response()->json([
            'status'  => true,
            'message' => 'Autocomplete results',
            'data'    => $data,
        ]);
    }
}