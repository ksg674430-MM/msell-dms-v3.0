<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Reports\Concerns\ReportFilterTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimReportController extends Controller
{
    use ReportFilterTrait;

    public function display(Request $request)
    {
        ['dealer_id' => $dealer, 'company_id' => $company] = $this->scope($request);
        [$from, $to] = $this->dateRange($request);

        $docs = array_map(fn ($i) => "claim.doc_$i", range(1, 17));

        $rows = DB::table('dms_retailer_images_amount_claim as claim')
            ->join('challan_order as co', 'claim.order_id', '=', 'co.id')
            ->join('dealer as d', 'claim.dealer_id', '=', 'd.id')
            ->leftJoin('retailer as r', 'claim.retailer_id', '=', 'r.id')
            ->leftJoin('_retailer_outlet_category as roc', 'r.class', '=', 'roc.id')
            ->leftJoin('person as p', 'co.ch_user_id', '=', 'p.id')
            ->leftJoin('location_6 as l6', 'd.town_id', '=', 'l6.id')
            ->where('claim.company_id', $company)
            ->where('claim.dealer_id', $dealer)
            ->where('claim.date_time', '>=', $from)
            ->where('claim.date_time', '<', Carbon::parse($to)->addDay()->toDateString())
            ->orderByDesc('claim.date_time')
            ->select(array_merge([
                'claim.id', 'claim.date_time as submission_date', 'claim.contract_date', 'claim.start_date', 'claim.display_period', 'claim.claim_amount',
                'co.id as ch_id', 'co.ch_no as invoice_no', 'co.ch_date as invoice_date', 'co.amount as invoice_amount',
                'd.name as dealer_name', 'l6.name as town_name',
                'r.name as retailer_name', 'roc.outlet_category as retailer_category',
                DB::raw("CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) as tsm_name"),
            ], $docs))
            ->get();

        foreach ($rows as $row) {
            $images = [];
            foreach (range(1, 17) as $i) {
                foreach (explode(',', (string) $row->{"doc_$i"}) as $url) {
                    if (($url = trim($url)) === '') continue;
                    $images[] = str_contains($url, '/') ? $url : url('myupload/claim/' . $url);
                }
                unset($row->{"doc_$i"});
            }
            $row->images = $images;
        }

        return $this->ok($rows);
    }
}
