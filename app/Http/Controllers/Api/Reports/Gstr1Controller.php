<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use App\Http\Controllers\Controller;
use App\Services\Gstr1Service;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Gstr1Controller extends Controller
{
    use ReportFilterTrait;

    public function __construct(private Gstr1Service $gstr1)
    {
    }

    public function index(Request $request)
    {
        $request->validate(['from_date' => 'nullable|date_format:Y-m-d', 'to_date' => 'nullable|date_format:Y-m-d']);
        [$from, $to] = $this->dateRange($request);
        if ($from > $to) return $this->fail('From date cannot be after To date.');

        ['dealer_id' => $dealer] = $this->scope($request);
        $data = $this->gstr1->generate($dealer, $from, $to);
        if ($data['seller']['state'] === '') return $this->fail('Distributor GSTIN and state are missing, so place of supply cannot be determined.');

        return $this->ok($data, ['from' => $from, 'to' => $to]);
    }

    public function excel(Request $request)
    {
        $request->validate(['from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d']);
        [$from, $to] = $this->dateRange($request);
        if ($from > $to) return $this->fail('From date cannot be after To date.');

        ['dealer_id' => $dealer] = $this->scope($request);
        $data = $this->gstr1->generate($dealer, $from, $to);
        if ($data['seller']['state'] === '') return $this->fail('Distributor GSTIN and state are missing, so place of supply cannot be determined.');

        $template = storage_path('app/template/gstr1_template.xlsx');
        if (!is_file($template)) return $this->fail('GSTR-1 template file is missing on the server.');

        ini_set('memory_limit', '512M');
        set_time_limit(120);
        $book = IOFactory::load($template);
        $date = fn ($dmy) => \DateTime::createFromFormat('d-m-Y', $dmy)->format('d-M-Y');
        $sheets = [
            'b2b,sez,de' => array_map(fn ($r) => [$r['gstin'], $r['name'], $r['invoice_no'], $date($r['date']), $r['value'], $r['pos'], 'N', '', 'Regular B2B', '', $r['rate'], $r['taxable'], 0], $data['b2b']),
            'b2cl' => array_map(fn ($r) => [$r['invoice_no'], $date($r['date']), $r['value'], $r['pos'], '', $r['rate'], $r['taxable'], 0, ''], $data['b2cl']),
            'b2cs' => array_map(fn ($r) => [$r['type'], $r['pos'], '', $r['rate'], $r['taxable'], 0, ''], $data['b2cs']),
            'exemp' => array_map(fn ($r) => [$r['description'], $r['nil_rated'], $r['exempt'], $r['non_gst']], $data['exemp']),
            'hsn(b2b)' => array_map(fn ($r) => [$r['hsn'], '', 'NOS-NUMBERS', $r['qty'], $r['value'], $r['rate'], $r['taxable'], $r['igst'], $r['cgst'], $r['sgst'], 0], $data['hsn_b2b']),
            'hsn(b2c)' => array_map(fn ($r) => [$r['hsn'], '', 'NOS-NUMBERS', $r['qty'], $r['value'], $r['rate'], $r['taxable'], $r['igst'], $r['cgst'], $r['sgst'], 0], $data['hsn_b2c']),
            'docs' => array_map(fn ($r) => [$r['document_type'], $r['from'], $r['to'], $r['total'], $r['cancelled']], $data['docs']),
        ];
        foreach ($sheets as $name => $rows) {
            if ($rows && $sheet = $book->getSheetByName($name)) $sheet->fromArray($rows, null, 'A5', true);
        }

        $name = 'GSTR1_' . ($data['seller']['gstin'] ?: $dealer) . "_{$from}_{$to}.xlsx";
        return response()->streamDownload(function () use ($book) {
            while (ob_get_level() > 0) ob_end_clean();
            $writer = IOFactory::createWriter($book, 'Xlsx');
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function json(Request $request)
    {
        $request->validate(['from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d']);
        [$from, $to] = $this->dateRange($request);
        if ($error = Gstr1Service::periodError($from, $to)) return $this->fail($error);

        ['dealer_id' => $dealer] = $this->scope($request);
        $data = $this->gstr1->generate($dealer, $from, $to);
        if ($data['seller']['gstin'] === '') return $this->fail("Distributor GSTIN '{$data['seller']['gstin_raw']}' is missing or invalid. Update it before generating the return file.");

        return $this->ok($this->gstr1->portalJson($data, $to), [
            'filename' => 'returns_' . date('dmY') . '_R1_' . $data['seller']['gstin'] . '_offline.json',
            'issues' => count($data['issues']),
        ]);
    }
}
