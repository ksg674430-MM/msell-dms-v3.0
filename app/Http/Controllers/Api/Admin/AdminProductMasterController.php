<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\RequiresAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminProductMasterController extends Controller
{
    use RequiresAdmin;

    private const MASTERS = [
        'prod' => [
            'table'  => 'PROD_CATG_MAST',
            'fields' => ['PROD_CATG', 'PROD_CATG_NAME', 'PROD_CATG_HINDI_NAME', 'USER_SEQ', 'PARENT_CODE', 'CODE_LEVEL', 'USER_CODE', 'LASTUPDATE', 'FLAG', 'MKTG_CATG'],
            'unique' => ['PROD_CATG', 'MKTG_CATG'],
            'order'  => 'PROD_CATG, USER_SEQ, id',
        ],
        'mktg' => [
            'table'  => 'MKTG_CATG_MAST',
            'fields' => ['MKTG_CATG', 'MKTG_CATG_NAME', 'MKTG_CATG_HINDI_NAME', 'PARENT_CODE', 'CODE_LEVEL', 'USER_CODE', 'LASTUPDATE', 'FLAG', 'USER_SEQ'],
            'unique' => ['MKTG_CATG'],
            'order'  => 'id',
        ],
    ];

    public function items(Request $request): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        return response()->json([
            'status' => true,
            'rows'   => DB::select(
                "SELECT cp.id, cp.name, cp.itemcode, cp.hsn_code, cp.gst_percent AS tax, COALESCE(pc.PROD_CATG_NAME, im.PROD_CATG, '') AS category
                   FROM catalog_product cp
                   LEFT JOIN ITEM_MAST im ON im.ITEM_CODE = cp.itemcode
                   LEFT JOIN PROD_CATG_MAST pc ON pc.PROD_CATG = im.PROD_CATG
                  WHERE cp.company_id = ? AND cp.status = 1
                  ORDER BY cp.created_at DESC, cp.id DESC",
                [$ctx->company_id]
            ),
        ]);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();

        $v = Validator::make($request->all(), [
            'name'     => ['required', 'string', 'max:200'],
            'itemcode' => ['required', 'string', 'max:50'],
            'hsn_code' => ['required', 'string', 'max:20'],
            'tax'      => ['required', 'numeric', 'min:0', 'max:100'],
        ], ['tax.numeric' => 'Tax must be a number.']);
        if ($v->fails()) return $this->invalid($v);

        $updated = DB::table('catalog_product')->where('id', $id)->where('company_id', $ctx->company_id)->where('status', 1)->update([
            'name'        => trim($request->input('name')),
            'itemcode'    => trim($request->input('itemcode')),
            'hsn_code'    => trim($request->input('hsn_code')),
            'gst_percent' => (float) $request->input('tax'),
            'updated_at'  => now(),
            'updated_by'  => $ctx->dp_id,
        ]);
        $row = DB::selectOne(
            "SELECT cp.id, cp.name, cp.itemcode, cp.hsn_code, cp.gst_percent AS tax, COALESCE(pc.PROD_CATG_NAME, im.PROD_CATG, '') AS category
               FROM catalog_product cp
               LEFT JOIN ITEM_MAST im ON im.ITEM_CODE = cp.itemcode
               LEFT JOIN PROD_CATG_MAST pc ON pc.PROD_CATG = im.PROD_CATG
              WHERE cp.id = ? AND cp.company_id = ? AND cp.status = 1",
            [$id, $ctx->company_id]
        );
        if (!$row) return response()->json(['status' => false, 'message' => 'Item not found.'], 404);

        return response()->json(['status' => true, 'message' => $updated ? 'Item updated.' : 'No changes to save.', 'row' => $row]);
    }

    public function masters(Request $request, string $type): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();
        $m = self::MASTERS[$type];

        return response()->json([
            'status' => true,
            'fields' => $m['fields'],
            'rows'   => DB::select("SELECT id, " . implode(', ', $m['fields']) . ", server_date_time FROM {$m['table']} ORDER BY {$m['order']}"),
        ]);
    }

    public function saveMaster(Request $request, string $type, ?int $id = null): JsonResponse
    {
        if (!$ctx = $this->admin($request)) return $this->adminDenied();
        if (!$ctx->erp) return $this->erpOnly();
        $m = self::MASTERS[$type];

        $rules = array_fill_keys($m['fields'], ['nullable', 'string', 'max:200']);
        $rules[$m['fields'][0]] = ['required', 'string', 'max:200'];
        $rules[$m['fields'][1]] = ['required', 'string', 'max:200'];
        if ($type === 'prod') $rules['MKTG_CATG'] = ['required', 'string', 'max:200'];
        $v = Validator::make($request->all(), $rules, [
            "{$m['fields'][0]}.required" => 'Enter the code.',
            "{$m['fields'][1]}.required" => 'Enter the name.',
            'MKTG_CATG.required' => 'Select the MKTG CATG.',
        ]);
        if ($v->fails()) return $this->invalid($v);

        $data = [];
        foreach ($m['fields'] as $f) $data[$f] = trim((string) $request->input($f, ''));

        $dup = DB::table($m['table']);
        foreach ($m['unique'] as $u) $dup->where($u, $data[$u]);
        if ($id) $dup->where('id', '<>', $id);
        if ($dup->exists()) return response()->json(['status' => false, 'message' => 'This code already exists.'], 422);

        if ($type === 'prod' && !DB::table('MKTG_CATG_MAST')->where('MKTG_CATG', $data['MKTG_CATG'])->exists()) {
            return response()->json(['status' => false, 'message' => 'Select a valid MKTG CATG.'], 422);
        }

        $data['server_date_time'] = now();
        if ($id) {
            if (!DB::table($m['table'])->where('id', $id)->exists()) return response()->json(['status' => false, 'message' => 'Record not found.'], 404);
            DB::table($m['table'])->where('id', $id)->update($data);
            return response()->json(['status' => true, 'message' => $m['table'] . ' updated successfully.']);
        }
        DB::table($m['table'])->insert($data);
        return response()->json(['status' => true, 'message' => $m['table'] . ' created successfully.'], 201);
    }

    private function invalid($v): JsonResponse
    {
        return response()->json(['status' => false, 'message' => $v->errors()->first(), 'errors' => $v->errors()], 422);
    }
}
