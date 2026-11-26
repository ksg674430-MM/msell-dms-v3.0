<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #000; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #000; padding: 2px 3px; vertical-align: top; }
    th { font-weight: bold; text-align: center; }
    .r { text-align: right; white-space: nowrap; }
    .b { font-weight: bold; }
    .nb { border: none; }
    .title { font-size: 12px; }
    .gap { height: 6px; border: none; }
    p { margin: 0; }
</style>
</head>
<body>
@php
    $m = fn ($v) => number_format((float) $v, 2, '.', '');
    $pct = fn ($amt, $p) => (float) $amt ? number_format((float) $amt, 2, '.', '') . ' (' . (float) $p . '%)' : '0.00';
    $p = $inv['party'];
    $address = $p ? trim(implode(', ', array_filter([$p->ADD1, $p->ADD2]))) . ' CITY: ' . $p->CITY_CODE . ' DISTRICT: ' . $p->DISTRICT_CODE . ' STATE: ' . $p->STATE_CODE : '';
    $meta = [
        ['Invoice No.', $inv['invoice_no'], 'G.R. No.', $inv['gr_no']],
        ['Invoice Date', $inv['invoice_date'], 'G.R. Date', $inv['gr_date']],
        ['Order No.', $inv['order_no'], 'Gate Pass No.', ''],
        ['Order Date', $inv['invoice_date'], 'G. Pass Date', ''],
        ['Marka No.', $inv['marka_no'], 'Total Carton(s)', $inv['cartons']],
    ];
@endphp
<table>
    <tr><th colspan="3" class="title">TAX INVOICE</th></tr>
    <tr>
        <td style="width:30%">
            <p class="b">Shree Baidyanath Ayurved Bhawan Pvt. Ltd.</p>
            <p>172, GUSAIN PURA, JHANSI</p>
            <p>CITY: JHANSI, DISTRICT: JHANSI</p>
            <p>State: UTTAR PRADESH (Code-09) PIN: 284002</p>
            <p>GSTIN: 09AAECS5408D1ZI</p>
            <p>PAN Number: AAECS5408D</p>
            <p>Phone No.: (0510)-2333871,72,73</p>
            <p>Email: contact@baidyanath.co.in</p>
            <p>Ayurvedic Drug Mfg. License No. A-1800/89</p>
            <p>REGISTERED OFFICE : 1, GUPTA LANE, KOLKATA 700 006</p>
            <p>CIN: U24233WB1947PTC015374</p>
        </td>
        <td style="width:35%; padding:0">
            <table>
                @foreach($meta as $row)
                    <tr>
                        <td class="b">{{ $row[0] }}</td><td>{{ $row[1] ?: '—' }}</td>
                        <td class="b">{{ $row[2] }}</td><td>{{ $row[3] ?: '—' }}</td>
                    </tr>
                @endforeach
                @foreach(['Transport', 'Vehicle No.', 'Remark'] as $label)
                    <tr><td class="b">{{ $label }}</td><td colspan="3">—</td></tr>
                @endforeach
            </table>
        </td>
        <td>
            @foreach(['Details of Receiver (Bill To)', 'Details of Consignee (Shipped To)'] as $title)
                <p class="b">{{ $title }}</p>
                @if($p)
                    @if($p->STNO)<p>GSTIN: {{ $p->STNO }}</p>@endif
                    <p>{{ $p->ACC_NAME }} (Code: {{ $p->ACC_CODE }})</p>
                    <p>{{ $address }}</p>
                @else
                    <p>—</p>
                @endif
                <br>
            @endforeach
        </td>
    </tr>
</table>

<div class="gap"></div>
<table>
    <thead>
        <tr>
            <th rowspan="2">Slno</th><th rowspan="2">Item Details</th><th rowspan="2">Size</th><th rowspan="2">HSN Code</th>
            <th rowspan="2">M.R.P</th><th rowspan="2">Rate</th><th colspan="2">Qty In Piece(s)</th>
            <th rowspan="2">Value</th><th rowspan="2">Trade Disc.</th><th rowspan="2">Scheme Disc.</th><th rowspan="2">Special Disc.</th>
            <th rowspan="2">Cash Disc.</th><th rowspan="2">Addl Trade Disc.</th><th rowspan="2">Taxable Amt</th>
            <th rowspan="2">CGST</th><th rowspan="2">SGST</th><th rowspan="2">IGST</th>
        </tr>
        <tr><th>Sold</th><th>Disc.</th></tr>
    </thead>
    <tbody>
        @foreach($inv['items'] as $n => $i)
            <tr>
                <td>{{ $n + 1 }}</td>
                <td>{{ $i['item_code'] }} : {{ $i['item_name'] }}<br>Batch No.: {{ $i['batch_no'] ?: '—' }}, Mfg Dt.: {{ $i['mfg_date'] ?: '—' }}</td>
                <td>{{ $i['size'] }}</td>
                <td>{{ $i['hsn_code'] }}</td>
                <td class="r">{{ $m($i['mrp']) }}</td>
                <td class="r">{{ $m($i['rate']) }}</td>
                <td class="r">{{ (float) $i['sold'] }}</td>
                <td class="r">{{ (float) $i['free'] }}</td>
                <td class="r">{{ $m($i['value']) }}</td>
                <td class="r">{{ $m($i['trade_disc']) }} ({{ (float) $i['trade_rate'] }}%)</td>
                <td class="r">{{ $m($i['scheme_disc']) }}</td>
                <td class="r">{{ $m($i['special_disc']) }}</td>
                <td class="r">{{ $m($i['cash_disc']) }}</td>
                <td class="r">{{ $m($i['addl_trade_disc']) }}</td>
                <td class="r">{{ $m($i['taxable']) }}</td>
                <td class="r">{{ $pct($i['cgst'], $i['cgst_percent']) }}</td>
                <td class="r">{{ $pct($i['sgst'], $i['sgst_percent']) }}</td>
                <td class="r">{{ $pct($i['igst'], $i['gst_percent']) }}</td>
            </tr>
        @endforeach
        <tr class="b">
            <td colspan="8">Total</td>
            @foreach(['value', 'trade_disc', 'scheme_disc', 'special_disc', 'cash_disc', 'addl_trade_disc', 'taxable', 'cgst', 'sgst', 'igst'] as $k)
                <td class="r">{{ $m($totals[$k]) }}</td>
            @endforeach
        </tr>
        <tr>
            <td colspan="2" class="b">Net Payable Amount</td>
            <td colspan="10" class="b">{{ $words }}</td>
            <td colspan="3" class="b">Grand Total</td>
            <td colspan="3" class="r b">{{ $m($grand) }}</td>
        </tr>
    </tbody>
</table>

<div class="gap"></div>
<table class="nb">
    <tr>
        <td style="width:60%; padding:0; border:none">
            <table>
                <tr><th colspan="7">Tax Details</th></tr>
                <tr><th>GST Slab</th><th>HSN Code</th><th>Taxable Amount</th><th>GST Amount</th><th>CGST Amount</th><th>SGST Amount</th><th>IGST Amount</th></tr>
                @foreach($gst as $g)
                    <tr>
                        <td class="r">{{ (float) $g['gst'] }}%</td>
                        <td>{{ $g['hsn'] ?: '—' }}</td>
                        <td class="r">{{ $m($g['taxable']) }}</td>
                        <td class="r">{{ $m($g['cgst'] + $g['sgst'] + $g['igst']) }}</td>
                        <td class="r">{{ $m($g['cgst']) }}</td>
                        <td class="r">{{ $m($g['sgst']) }}</td>
                        <td class="r">{{ $m($g['igst']) }}</td>
                    </tr>
                @endforeach
                <tr class="b">
                    <td colspan="2">Total</td>
                    <td class="r">{{ $m($totals['taxable']) }}</td>
                    <td class="r">{{ $m($totals['cgst'] + $totals['sgst'] + $totals['igst']) }}</td>
                    <td class="r">{{ $m($totals['cgst']) }}</td>
                    <td class="r">{{ $m($totals['sgst']) }}</td>
                    <td class="r">{{ $m($totals['igst']) }}</td>
                </tr>
            </table>
        </td>
        <td style="width:2%; border:none"></td>
        <td style="border:1px solid #000; text-align:right; padding:4px">
            <p class="b">For Shree Baidyanath Ayurved Bhawan Pvt. Ltd.</p>
            <br><br><br>
            <p>Authorised Signatory</p>
        </td>
    </tr>
</table>

<div class="gap"></div>
<table>
    <tr>
        <td>
            <p class="b">Terms &amp; conditions</p>
            <p>1. Subject to exclusive jurisdiction of JHANSI (U.P.) courts and our business terms agreed and accepted by you.</p>
            <p>2. Interest will be charged @18% PA from the date of invoice, If payment received by us after due date.</p>
            <p>3. The risk &amp; rewards associated with goods shall be deemed to be transferred to the customer with the dispatch of goods.</p>
            <p>4. You hereby accept GST charged &amp; deposited by company herein. It is your liability if variation in GST charged/deposited or excess GST input credit claimed by you.</p>
            <p>5. By accepting this invoice, you also acknowledge &amp; accept our standard business terms &amp; conditions.</p>
        </td>
    </tr>
</table>
</body>
</html>
