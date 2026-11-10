<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Gstr1Service
{
    public const STATES = [
        '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh',
        '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
        '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur',
        '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal',
        '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
        '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh',
        '97' => 'Other Territory', '99' => 'Centre Jurisdiction',
    ];

    public const B2CL_LIMIT_FROM = '2024-08-01';

    public function generate(int $dealerId, string $from, string $to): array
    {
        $seller = $this->seller($dealerId);
        $outside = DB::table('challan_order')
            ->where('ch_dealer_id', $dealerId)
            ->where(fn ($q) => $q->where('ch_date', '<', $from)->orWhere('ch_date', '>=', date('Y-m-d', strtotime($to . ' +1 day'))))
            ->pluck('ch_no')->all();
        return ['seller' => $seller, 'period' => ['from' => $from, 'to' => $to]] + $this->build($this->lines($dealerId, $from, $to), $seller['state'], $outside, $seller['company_id'] === 213 ? 0 : $seller['state_id']);
    }

    public function seller(int $dealerId): array
    {
        $dealer = DB::table('dealer')
            ->leftJoin('location_3 as l3', 'l3.id', '=', 'dealer.state_id')
            ->where('dealer.id', $dealerId)
            ->first(['dealer.name', 'dealer.tin_no', 'l3.state_code', 'dealer.state_id', 'dealer.company_id']);

        $gstin = self::normaliseGstin($dealer->tin_no ?? '');
        $valid = self::isValidGstin($gstin);
        $state = $valid ? substr($gstin, 0, 2) : self::stateCode($dealer->state_code ?? '');

        return ['name' => $dealer->name ?? '', 'gstin' => $valid ? $gstin : '', 'gstin_raw' => $gstin, 'state' => $state, 'state_id' => (int) ($dealer->state_id ?? 0), 'company_id' => (int) ($dealer->company_id ?? 0)];
    }

    public function lines(int $dealerId, string $from, string $to): array
    {
        return DB::table('challan_order as co')
            ->leftJoin('challan_order_details as cod', 'cod.ch_id', '=', 'co.id')
            ->leftJoin('catalog_product as cp', 'cp.id', '=', 'cod.product_id')
            ->leftJoin('retailer as r', 'r.id', '=', DB::raw('CAST(co.ch_retailer_id AS UNSIGNED)'))
            ->leftJoin('location_view as lv', 'lv.l7_id', '=', 'r.location_id')
            ->leftJoin('location_3 as l3', 'l3.id', '=', 'lv.l3_id')
            ->leftJoin('location_3 as l3r', 'l3r.id', '=', 'r.state_id_retailer')
            ->where('co.ch_dealer_id', $dealerId)
            ->where('co.ch_date', '>=', $from)
            ->where('co.ch_date', '<', date('Y-m-d', strtotime($to . ' +1 day')))
            ->orderBy('co.ch_date')->orderBy('co.ch_no')->orderBy('cod.id')
            ->get([
                'co.id as invoice_id', 'co.ch_no', DB::raw('DATE(co.ch_date) as ch_date'), 'co.amount', 'co.amount_round',
                'co.discount_amt', 'co.dispatch_status', 'co.crdr_status', 'r.id as retailer_id', 'r.name as retailer_name', 'r.tin_no',
                DB::raw('COALESCE(l3.state_code, l3r.state_code) as retailer_state'), 'lv.l3_id as retailer_state_id',
                'cod.id as line_id', 'cp.name as product_name',
                DB::raw("COALESCE(NULLIF(TRIM(cod.hsn_code), ''), TRIM(cp.hsn_code)) as hsn"),
                'cod.tax as rate', 'cod.qty', 'cod.free_qty', 'cod.taxable_amt as line_total', 'cod.vat_amt',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function build(array $lines, string $sellerState, array $outside = [], int $billingStateId = 0): array
    {
        $invoices = [];
        foreach ($lines as $l) {
            $invoices[$l['invoice_id']]['head'] ??= $l;
            if ($l['line_id'] !== null) $invoices[$l['invoice_id']]['lines'][] = $l;
        }

        $b2b = $b2cl = $b2cs = $hsn = $b2csDetail = $exempDetail = $hsnDetail = $issues = [];
        $cdnr = $cdnur = $uninvolved = [];
        $noteHsn = ['b2b' => [], 'b2c' => []];
        $exemp = [];
        foreach (['INTRB2B' => 'Inter-State supplies to registered persons', 'INTRAB2B' => 'Intra-State supplies to registered persons', 'INTRB2C' => 'Inter-State supplies to unregistered persons', 'INTRAB2C' => 'Intra-State supplies to unregistered persons'] as $type => $label) {
            $exemp[$type] = ['sply_ty' => $type, 'description' => $label, 'nil_rated' => 0.0, 'exempt' => 0.0, 'non_gst' => 0.0];
        }
        $docs = [];
        $seen = [];

        foreach ($invoices as $inv) {
            $h = $inv['head'];
            $docs[] = ['ch_no' => (string) $h['ch_no'], 'cancelled' => (int) $h['dispatch_status'] === 3];
            if ((int) $h['dispatch_status'] === 3) continue;

            $issue = function (string $type, string $message) use (&$issues, $h) {
                $issues[] = ['type' => $type, 'invoice_no' => $h['ch_no'], 'date' => self::dmy($h['ch_date']), 'party' => $h['retailer_name'] ?? '', 'message' => $message];
            };
            $rows = $inv['lines'] ?? [];
            if (isset($seen[$h['ch_no']])) $issue('Duplicate number', "Invoice number is used by more than one active invoice (also on {$seen[$h['ch_no']]}); both are included. Cancel the extra copy if it was saved twice.");
            $seen[$h['ch_no']] ??= self::dmy($h['ch_date']);
            if (!$rows) {
                $issue('No items', 'Invoice has no line items; it is excluded from all sections.');
                continue;
            }

            $gstin = self::normaliseGstin($h['tin_no'] ?? '');
            $registered = self::isValidGstin($gstin);
            if ($h['retailer_id'] === null) $issue('Retailer missing', 'Retailer record not found; treated as unregistered.');
            elseif ($gstin !== '' && $gstin !== '0' && $gstin !== 'URP' && !$registered) $issue('Invalid GSTIN', "GSTIN '{$gstin}' is invalid; treated as unregistered.");

            $pos = $registered ? substr($gstin, 0, 2) : self::stateCode($h['retailer_state'] ?? '');
            if ($pos === '') {
                $pos = $sellerState;
                $issue('POS defaulted', 'Retailer state not found; place of supply defaulted to the distributor state.');
            }
            $inter = $pos !== $sellerState;
            $billedState = (int) ($h['retailer_state_id'] ?? 0);
            if ($billingStateId > 0 && $billedState > 0 && ($billedState !== $billingStateId) !== $inter) {
                $issue('GST type mismatch', sprintf(
                    'Invoice was billed with %s (from the retailer\'s beat state), but place of supply %s makes it %s in GSTR-1. Correct the retailer\'s %s, then fix this invoice.',
                    $inter ? 'CGST + SGST' : 'IGST', self::posLabel($pos), $inter ? 'IGST' : 'CGST + SGST', $registered ? 'GSTIN or beat' : 'beat'
                ));
            }
            $posLabel = self::posLabel($pos);
            $value = self::invoiceValue($h);
            $date = self::dmy($h['ch_date']);
            $limit = $h['ch_date'] >= self::B2CL_LIMIT_FROM ? 100000 : 250000;
            $large = $inter && $value > $limit;
            $note = [1 => 'C', 2 => 'D'][(int) ($h['crdr_status'] ?? 0)] ?? null;
            $sign = $note === 'C' ? -1 : 1;
            $kind = $registered ? 'b2b' : 'b2c';
            $docType = $note === null ? 'Invoice' : ($note === 'C' ? 'Credit Note' : 'Debit Note');
            $section = $note === null
                ? ($registered ? 'b2b' : ($large ? 'b2cl' : 'b2cs'))
                : ($registered ? 'cdnr' : ($large ? 'cdnur' : 'b2cs_note'));

            $byRate = [];
            $rateHsn = [];
            $lineSum = 0.0;
            foreach ($rows as $l) {
                $rate = round((float) $l['rate'], 2);
                [$taxable, $tax, $total, $ok] = self::resolveLine((float) $l['line_total'], (float) $l['vat_amt'], $rate);
                $lineSum += $total;
                $code = (string) $l['hsn'];

                if (!preg_match('/^\d{4}(\d{2}){0,2}$/', $code)) $issue('HSN invalid', "HSN '{$code}' for {$l['product_name']} must be 4, 6 or 8 digits.");
                if (!$ok && $rate > 0) $issue('GST recomputed', sprintf('%s: stored GST %.2f is not %.2f%% of amount %.2f; reported taxable %.2f and GST %.2f from the line total. Correct the invoice.', $l['product_name'], $l['vat_amt'], $rate, $l['line_total'], $taxable, $tax));
                if (!$ok && $rate == 0) $issue('Tax mismatch', sprintf('%s: GST %.2f charged on a 0%% item.', $l['product_name'], $l['vat_amt']));
                $split = self::split($tax, $inter);
                if ($taxable < 0) $issue('Negative value', "{$l['product_name']} has a negative taxable value.");

                $qty = ((float) $l['qty'] + (float) $l['free_qty']) * $sign;
                $key = "{$kind}|{$code}|{$rate}";
                $hsn[$key] ??= ['kind' => $kind, 'hsn' => $code, 'rate' => $rate, 'qty' => 0.0, 'value' => 0.0, 'taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0, 'note_value' => 0.0, 'note_taxable' => 0.0];
                $hsn[$key]['qty'] += $qty;
                $hsn[$key]['value'] += $total * $sign;
                $hsn[$key]['taxable'] += $taxable * $sign;
                foreach ($split as $k => $v) $hsn[$key][$k] += $v * $sign;

                $dk = "{$h['invoice_id']}|{$key}";
                $hsnDetail[$dk] ??= ['kind' => $kind, 'doc_type' => $docType, 'invoice_no' => $h['ch_no'], 'date' => $date, 'party' => $h['retailer_name'] ?? '', 'gstin' => $registered ? $gstin : '', 'hsn' => $code, 'rate' => $rate, 'qty' => 0.0, 'value' => 0.0, 'taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0];
                $hsnDetail[$dk]['qty'] += $qty;
                $hsnDetail[$dk]['value'] += $total * $sign;
                $hsnDetail[$dk]['taxable'] += $taxable * $sign;
                foreach ($split as $k => $v) $hsnDetail[$dk][$k] += $v * $sign;

                if ($note !== null) {
                    $hsn[$key]['note_value'] -= $total * $sign;
                    $hsn[$key]['note_taxable'] -= $taxable * $sign;
                    $nk = "{$code}|{$rate}";
                    $noteHsn[$kind][$nk] ??= ['hsn' => $code, 'rate' => $rate, 'value' => 0.0, 'taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0];
                    $noteHsn[$kind][$nk]['value'] -= $total * $sign;
                    $noteHsn[$kind][$nk]['taxable'] -= $taxable * $sign;
                    foreach ($split as $k => $v) $noteHsn[$kind][$nk][$k] -= $v * $sign;
                    if ($rate == 0 && $section === 'b2cs_note') $exemp[($inter ? 'INTR' : 'INTRA') . 'B2C']['exempt'] += $taxable * $sign;
                } elseif ($rate == 0) {
                    $type = ($inter ? 'INTR' : 'INTRA') . ($registered ? 'B2B' : 'B2C');
                    $exemp[$type]['exempt'] += $taxable;
                    $exempDetail[] = ['invoice_no' => $h['ch_no'], 'date' => $date, 'party' => $h['retailer_name'] ?? '', 'gstin' => $registered ? $gstin : '', 'pos' => $posLabel, 'hsn' => $code, 'category' => $exemp[$type]['description'], 'taxable' => round($taxable, 2), 'value' => round($total, 2)];
                    continue;
                }
                $byRate[(string) $rate] ??= ['taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0];
                $byRate[(string) $rate]['taxable'] += $taxable;
                foreach ($split as $k => $v) $byRate[(string) $rate][$k] += $v;
                $rateHsn[(string) $rate][$code] = true;
            }

            if (abs($lineSum - (float) $h['discount_amt'] - (float) $h['amount']) > 1) {
                $issue('Value mismatch', sprintf('Invoice amount %.2f differs from item total %.2f less discount %.2f.', $h['amount'], $lineSum, $h['discount_amt']));
            }

            foreach ($byRate as $rate => $t) {
                $row = ['invoice_no' => $h['ch_no'], 'date' => $date, 'value' => $value, 'pos' => $posLabel, 'inter' => $inter, 'rate' => (float) $rate] + self::finish($t);
                if ($note !== null) {
                    $row = ['note_type' => $note, 'name' => $h['retailer_name'] ?? ''] + $row + ['tax_amount' => round($row['igst'] + $row['cgst'] + $row['sgst'], 2)];
                    if ($section === 'cdnr') {
                        $cdnr[] = ['gstin' => $gstin, 'hsn' => implode(', ', array_keys($rateHsn[$rate]))] + $row;
                    } elseif ($section === 'cdnur') {
                        $cdnur[] = ['ur_type' => 'B2CL'] + $row;
                    } else {
                        $uninvolved[] = ['gstin' => '', 'source' => (float) $rate == 0 ? 'Exempt adjustment' : 'B2CS adjustment'] + $row;
                        if ((float) $rate != 0) {
                            $bk = "{$pos}|{$rate}";
                            $b2cs[$bk] ??= ['type' => 'OE', 'pos' => $posLabel, 'sply_ty' => $inter ? 'INTER' : 'INTRA', 'rate' => (float) $rate, 'taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0, 'invoices' => 0];
                            foreach ($t as $k => $v) $b2cs[$bk][$k] += $v * $sign;
                        }
                    }
                    continue;
                }
                if ($section === 'b2b') {
                    $b2b[] = ['gstin' => $gstin, 'name' => $h['retailer_name'] ?? ''] + $row;
                } elseif ($section === 'b2cl') {
                    $b2cl[] = $row;
                } else {
                    $bk = "{$pos}|{$rate}";
                    $b2cs[$bk] ??= ['type' => 'OE', 'pos' => $posLabel, 'sply_ty' => $inter ? 'INTER' : 'INTRA', 'rate' => (float) $rate, 'taxable' => 0.0, 'igst' => 0.0, 'intra' => 0.0, 'invoices' => 0];
                    $b2cs[$bk]['invoices']++;
                    foreach ($t as $k => $v) $b2cs[$bk][$k] += $v;
                    $b2csDetail[] = ['party' => $h['retailer_name'] ?? ''] + $row;
                }
            }
        }

        $b2cs = array_map(fn ($r) => self::finish($r), array_values($b2cs));
        usort($b2cs, fn ($a, $b) => [$a['pos'], $a['rate']] <=> [$b['pos'], $b['rate']]);
        foreach ($b2cs as $r) {
            if ($r['taxable'] < 0) $issues[] = ['type' => 'Negative B2CS', 'invoice_no' => '', 'date' => '', 'party' => '', 'message' => sprintf('Credit notes for %s at %s%% exceed this period\'s sales (net taxable %.2f). The portal may reject a negative B2CS value.', $r['pos'], $r['rate'], $r['taxable'])];
        }
        $noteHsn = array_map(fn ($rows) => array_map(fn ($r) => self::finish($r), array_values($rows)), $noteHsn);
        $hsn = array_map(fn ($r) => self::finish($r), array_values($hsn));
        usort($hsn, fn ($a, $b) => [$a['kind'], $a['hsn'], $a['rate']] <=> [$b['kind'], $b['hsn'], $b['rate']]);
        $exemp = array_map(fn ($r) => self::round2($r), array_values($exemp));
        $hsnDetail = array_map(fn ($r) => self::finish($r), array_values($hsnDetail));
        $docs = self::docSummary($docs, $outside);
        foreach ($docs as $d) {
            if ($d['missing'] > 0) $issues[] = ['type' => 'Missing numbers', 'invoice_no' => "{$d['from']} – {$d['to']}", 'date' => '', 'party' => '', 'message' => "{$d['missing']} invoice number(s) in this range do not exist (deleted); reported as cancelled."];
        }

        return [
            'b2b' => $b2b, 'b2cl' => $b2cl, 'b2cs' => $b2cs, 'exemp' => $exemp, 'cdnr' => $cdnr, 'cdnur' => $cdnur,
            'hsn_b2b' => array_values(array_filter($hsn, fn ($r) => $r['kind'] === 'b2b')),
            'hsn_b2c' => array_values(array_filter($hsn, fn ($r) => $r['kind'] === 'b2c')),
            'docs' => $docs,
            'details' => ['b2cs' => $b2csDetail, 'exemp' => $exempDetail, 'hsn' => $hsnDetail, 'uninvolved' => $uninvolved, 'hsn_cdnr' => $noteHsn['b2b'], 'hsn_cdnur' => $noteHsn['b2c']],
            'issues' => $issues,
            'summary' => self::summary($b2b, $b2cl, $b2cs, $exemp, $cdnr, $cdnur, $hsn, $docs, $b2csDetail, $exempDetail),
        ];
    }

    public static function docSummary(array $docs, array $outside = []): array
    {
        $outside = array_flip($outside);
        $series = [];
        foreach ($docs as $d) {
            $no = $d['ch_no'];
            if (!preg_match('/^(.*?)(\d+)$/', $no, $m)) {
                $series["#{$no}"] = ['prefix' => $no, 'width' => 0, 'nums' => [0 => $d['cancelled']]];
                continue;
            }
            $key = $m[1] . '|' . strlen($m[2]);
            $series[$key] ??= ['prefix' => $m[1], 'width' => strlen($m[2]), 'nums' => []];
            $n = (int) $m[2];
            $series[$key]['nums'][$n] = ($series[$key]['nums'][$n] ?? true) && $d['cancelled'];
        }
        ksort($series);

        $out = [];
        foreach ($series as $s) {
            $nums = $s['nums'];
            ksort($nums);
            $format = fn ($n) => $s['width'] ? $s['prefix'] . str_pad((string) $n, $s['width'], '0', STR_PAD_LEFT) : $s['prefix'];
            $min = array_key_first($nums);
            $max = array_key_last($nums);
            $range = null;
            $flush = function () use (&$range, &$out, $s) {
                if ($range) $out[] = ['document_type' => 'Invoices for outward supply', 'series' => $s['prefix'], 'from' => $range['from'], 'to' => $range['to'], 'total' => $range['total'], 'cancelled' => $range['cancelled'], 'issued' => $range['total'] - $range['cancelled'], 'missing' => $range['missing']];
                $range = null;
            };
            $steps = $max - $min > 100000 ? array_keys($nums) : range($min, $max);
            $pendingMissing = 0;
            foreach ($steps as $n) {
                if (array_key_exists($n, $nums)) {
                    $range ??= ['from' => $format($n), 'to' => $format($n), 'total' => 0, 'cancelled' => 0, 'missing' => 0];
                    $range['to'] = $format($n);
                    $range['total'] += 1 + $pendingMissing;
                    $range['cancelled'] += ($nums[$n] ? 1 : 0) + $pendingMissing;
                    $range['missing'] += $pendingMissing;
                    $pendingMissing = 0;
                } elseif (isset($outside[$format($n)])) {
                    $pendingMissing = 0;
                    $flush();
                } elseif ($range) {
                    $pendingMissing++;
                }
            }
            $flush();
        }
        return $out;
    }

    private static function summary(array $b2b, array $b2cl, array $b2cs, array $exemp, array $cdnr, array $cdnur, array $hsn, array $docs, array $b2csDetail, array $exempDetail): array
    {
        $sum = function (array $rows, array $keys) {
            $t = array_fill_keys($keys, 0.0);
            foreach ($rows as $r) foreach ($keys as $k) $t[$k] += (float) ($r[$k] ?? 0);
            return self::round2($t);
        };
        $tax = ['taxable', 'igst', 'cgst', 'sgst'];
        $signed = fn (array $rows) => array_map(function ($r) use ($tax) {
            if ($r['note_type'] === 'C') foreach ($tax as $k) $r[$k] = -$r[$k];
            return $r;
        }, $rows);
        $invoices = fn (array $rows) => array_unique(array_column($rows, 'invoice_no'));
        $invoiceCount = fn (array $rows) => count($invoices($rows));
        $sections = [
            'b2b' => ['label' => 'B2B Invoices', 'count' => $invoiceCount($b2b)] + $sum($b2b, $tax),
            'b2cl' => ['label' => 'B2C Large Invoices', 'count' => $invoiceCount($b2cl)] + $sum($b2cl, $tax),
            'b2cs' => ['label' => 'B2C Small', 'count' => $invoiceCount($b2csDetail)] + $sum($b2cs, $tax),
            'cdnr' => ['label' => 'Registered Credit/Debit Notes', 'count' => $invoiceCount($cdnr)] + $sum($signed($cdnr), $tax),
            'cdnur' => ['label' => 'Unregistered Credit/Debit Notes', 'count' => $invoiceCount($cdnur)] + $sum($signed($cdnur), $tax),
            'exemp' => ['label' => 'Nil Rated / Exempt / Non-GST', 'count' => $invoiceCount($exempDetail), 'taxable' => round(array_sum(array_map(fn ($r) => $r['exempt'] + $r['nil_rated'] + $r['non_gst'], $exemp)), 2), 'igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0],
        ];
        $supplies = ['count' => count(array_unique(array_merge($invoices($b2b), $invoices($b2cl), $invoices($b2csDetail), $invoices($exempDetail))))] + $sum(array_values($sections), $tax);
        $supplies['value'] = round($supplies['taxable'] + $supplies['igst'] + $supplies['cgst'] + $supplies['sgst'], 2);
        $hsnTotal = $sum($hsn, array_merge($tax, ['value']));
        $diff = [];
        foreach (array_merge($tax, ['value']) as $k) $diff[$k] = round($supplies[$k] - $hsnTotal[$k], 2);

        return [
            'sections' => $sections,
            'supplies' => $supplies,
            'hsn' => $hsnTotal,
            'hsn_notes' => ['value' => $sum($hsn, ['note_value'])['note_value'], 'taxable' => $sum($hsn, ['note_taxable'])['note_taxable']],
            'difference' => $diff,
            'matched' => max(array_map('abs', $diff)) <= 1,
            'documents' => ['total' => array_sum(array_column($docs, 'total')), 'cancelled' => array_sum(array_column($docs, 'cancelled')), 'issued' => array_sum(array_column($docs, 'issued'))],
        ];
    }

    public function portalJson(array $data, string $to): array
    {
        $gstin = $data['seller']['gstin'];
        $item = function (array $r, int $num) {
            $det = ['txval' => $r['taxable'], 'rt' => $r['rate']];
            if ($r['inter']) $det['iamt'] = $r['igst'];
            else [$det['camt'], $det['samt']] = [$r['cgst'], $r['sgst']];
            $det['csamt'] = 0;
            return ['num' => $num, 'itm_det' => $det];
        };

        $b2b = [];
        foreach ($data['b2b'] as $r) {
            $inv = &$b2b[$r['gstin']]['inv'][$r['invoice_no']];
            $inv ??= ['inum' => $r['invoice_no'], 'idt' => $r['date'], 'val' => $r['value'], 'pos' => self::code($r['pos']), 'rchrg' => 'N', 'inv_typ' => 'R', 'itms' => []];
            $inv['itms'][] = $item($r, count($inv['itms']) + 1);
            unset($inv);
        }
        $b2b = array_map(fn ($ctin, $c) => ['ctin' => $ctin, 'inv' => array_values($c['inv'])], array_keys($b2b), $b2b);

        $b2cl = [];
        foreach ($data['b2cl'] as $r) {
            $inv = &$b2cl[self::code($r['pos'])][$r['invoice_no']];
            $inv ??= ['inum' => $r['invoice_no'], 'idt' => $r['date'], 'val' => $r['value'], 'itms' => []];
            $inv['itms'][] = $item($r, count($inv['itms']) + 1);
            unset($inv);
        }
        $b2cl = array_map(fn ($pos, $invs) => ['pos' => (string) $pos, 'inv' => array_values($invs)], array_keys($b2cl), $b2cl);

        $b2cs = array_map(function ($r) {
            $e = ['sply_ty' => $r['sply_ty'], 'rt' => $r['rate'], 'typ' => 'OE', 'pos' => self::code($r['pos']), 'txval' => $r['taxable']];
            if ($r['sply_ty'] === 'INTER') $e['iamt'] = $r['igst'];
            else [$e['camt'], $e['samt']] = [$r['cgst'], $r['sgst']];
            $e['csamt'] = 0;
            return $e;
        }, $data['b2cs']);

        $notes = function (array $rows, callable $head) use ($item) {
            $out = [];
            foreach ($rows as $r) {
                $nt = &$out[$r['invoice_no']];
                $nt ??= $head($r) + ['itms' => []];
                $nt['itms'][] = $item($r, count($nt['itms']) + 1);
                unset($nt);
            }
            return array_values($out);
        };
        $cdnr = [];
        foreach ($notes($data['cdnr'] ?? [], fn ($r) => ['ntty' => $r['note_type'], 'nt_num' => $r['invoice_no'], 'nt_dt' => $r['date'], 'val' => $r['value'], 'pos' => self::code($r['pos']), 'rchrg' => 'N', 'inv_typ' => 'R', 'gstin' => $r['gstin']]) as $nt) {
            $ctin = $nt['gstin'];
            unset($nt['gstin']);
            $cdnr[$ctin]['nt'][] = $nt;
        }
        $cdnr = array_map(fn ($ctin, $c) => ['ctin' => $ctin, 'nt' => $c['nt']], array_keys($cdnr), $cdnr);
        $cdnur = $notes($data['cdnur'] ?? [], fn ($r) => ['typ' => $r['ur_type'], 'ntty' => $r['note_type'], 'nt_num' => $r['invoice_no'], 'nt_dt' => $r['date'], 'val' => $r['value'], 'pos' => self::code($r['pos'])]);

        $nil = array_values(array_map(fn ($r) => ['sply_ty' => $r['sply_ty'], 'expt_amt' => $r['exempt'], 'nil_amt' => $r['nil_rated'], 'ngsup_amt' => $r['non_gst']], array_filter($data['exemp'], fn ($r) => $r['exempt'] + $r['nil_rated'] + $r['non_gst'] != 0)));

        $hsn = fn (array $rows) => array_map(fn ($r, $i) => [
            'num' => $i + 1, 'hsn_sc' => $r['hsn'], 'desc' => '', 'user_desc' => '', 'uqc' => 'NOS', 'qty' => round($r['qty'], 2),
            'rt' => $r['rate'], 'txval' => $r['taxable'], 'iamt' => $r['igst'], 'camt' => $r['cgst'], 'samt' => $r['sgst'], 'csamt' => 0,
        ], $rows, array_keys($rows));

        $json = ['gstin' => $gstin, 'fp' => date('mY', strtotime($to)), 'version' => 'GST3.2.4', 'hash' => 'hash'];
        if ($b2b) $json['b2b'] = $b2b;
        if ($b2cl) $json['b2cl'] = $b2cl;
        if ($b2cs) $json['b2cs'] = $b2cs;
        if ($cdnr) $json['cdnr'] = $cdnr;
        if ($cdnur) $json['cdnur'] = $cdnur;
        if ($nil) $json['nil'] = ['inv' => $nil];
        $json['hsn'] = ['hsn_b2b' => $hsn($data['hsn_b2b']), 'hsn_b2c' => $hsn($data['hsn_b2c'])];
        if ($data['docs']) {
            $json['doc_issue'] = ['doc_det' => [[
                'doc_num' => 1,
                'doc_typ' => 'Invoices for outward supply',
                'docs' => array_map(fn ($d, $i) => ['num' => $i + 1, 'from' => $d['from'], 'to' => $d['to'], 'totnum' => $d['total'], 'cancel' => $d['cancelled'], 'net_issue' => $d['issued']], $data['docs'], array_keys($data['docs'])),
            ]]];
        }
        return $json;
    }

    public static function periodError(string $from, string $to): ?string
    {
        if ($from > $to) return 'From date cannot be after To date.';
        $f = strtotime($from);
        $t = strtotime($to);
        if (date('Y-m-d', $f) !== date('Y-m-01', $f) || date('Y-m-d', $t) !== date('Y-m-t', $t)) return 'GSTR-1 JSON needs a full return period: from the 1st to the last day of the month (or quarter).';
        $months = (date('Y', $t) - date('Y', $f)) * 12 + date('n', $t) - date('n', $f) + 1;
        if ($months === 1) return null;
        if ($months === 3 && date('n', $t) % 3 === 0) return null;
        return 'GSTR-1 JSON can be generated for a single month or one calendar quarter only.';
    }

    public static function resolveLine(float $amount, float $tax, float $rate): array
    {
        if (abs($tax - ($amount - $tax) * $rate / 100) <= 1) return [$amount - $tax, $tax, $amount, true];
        if (abs($tax - $amount * $rate / 100) <= 1) return [$amount, $tax, $amount + $tax, true];
        if ($rate == 0) return [$amount - $tax, $tax, $amount, false];
        $taxable = round($amount / (1 + $rate / 100), 2);
        return [$taxable, round($amount - $taxable, 2), $amount, false];
    }

    public static function split(float $tax, bool $inter): array
    {
        return $inter ? ['igst' => $tax, 'intra' => 0.0] : ['igst' => 0.0, 'intra' => $tax];
    }

    public static function invoiceValue(array $h): float
    {
        $amount = (float) $h['amount'];
        $rounded = (float) $h['amount_round'];
        return round($rounded == 0 || abs($rounded - $amount) > 100 ? round($amount) : $rounded, 2);
    }

    public static function normaliseGstin(?string $gstin): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $gstin));
    }

    public static function isValidGstin(string $gstin): bool
    {
        if (!preg_match('/^\d{2}[A-Z0-9]{10}[1-9A-Z]Z[0-9A-Z]$/', $gstin) || !isset(self::STATES[substr($gstin, 0, 2)])) return false;
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $p = strpos($chars, $gstin[$i]) * ($i % 2 ? 2 : 1);
            $sum += intdiv($p, 36) + $p % 36;
        }
        return $chars[(36 - $sum % 36) % 36] === $gstin[14];
    }

    public static function stateCode($code): string
    {
        $code = trim((string) $code);
        if ($code === '' || !ctype_digit($code)) return '';
        $code = str_pad($code, 2, '0', STR_PAD_LEFT);
        return isset(self::STATES[$code]) ? $code : '';
    }

    public static function posLabel(string $code): string
    {
        return $code . '-' . (self::STATES[$code] ?? '');
    }

    private static function code(string $pos): string
    {
        return substr($pos, 0, 2);
    }

    private static function dmy(string $date): string
    {
        return date('d-m-Y', strtotime($date));
    }

    public static function finish(array $row): array
    {
        $half = round(($row['intra'] ?? 0) / 2, 2);
        unset($row['intra']);
        return self::round2(array_merge($row, ['cgst' => $half, 'sgst' => $half]));
    }

    private static function round2(array $row): array
    {
        foreach ($row as $k => $v) if (is_float($v)) $row[$k] = round($v, 2) + 0.0;
        return $row;
    }
}
