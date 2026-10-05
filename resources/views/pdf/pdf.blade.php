<!DOCTYPE html>
<html lang="en">
    <h3 style="text-align: center;"> Order</h3>
<div class="searchlistdiv watermark" id="searchlistdiv"     style="width: 60%; border: solid 1px #000; align-content: center;"> 
   
    <table border="1" cellspacing="0" cellpadding="0" height="30px" width="100%">
        <tr >
            <td width="50%" align="left">
            <p  ><strong> Invoice To, <br>
                {{$data_query[0]->retailer_name}}<br>
                </strong><br>
                <strong>VERAVAL<br>
                MO.{{$data_query[0]->retailer_no}}.</strong>
            </p>
            </td>
            <td width="50%" align="left">
                <p   ><strong> Voucher No, <br>
                    {{$data_query[0]->order_id}}<br>
                    </strong><br>
                    <strong>Dispatch Through<br>
                    {{!empty($data_query[0]->dispatch_through)?$data_query[0]->dispatch_through:''}}</strong>
                </p>
            </td>
            <td width="50%" align="left">
                <p ><strong> Order Date, <br>
                    {{$data_query[0]->sale_date}}<br>
                    </strong><br>
                    <strong>Destination<br>
                    {{!empty($data_query[0]->destination)?$data_query[0]->destination:''}}.</strong>
                </p>
            </td>

        </tr>
    </table>
    <?php
        $total = '0';
        $totalqty = '0';
        $totalrate = '0';
        $discount = '0';
        $remarks = '0';
    ?>
    <table border="1" cellspacing="0" cellpadding="0"  width="100%">
        <thead>
        <tr>
            <th style="text-align: center;">S.No</th>
            <th style="text-align: center;">Product Name</th>
            <th style="text-align: center;">Quantity</th>
            <th style="text-align: center;">Rate</th>
            <th style="text-align: center;">Unit</th>
            <th style="text-align: center;">Amount</th>
        </tr>
        </thead>
       
        <tbody >
            @if(!empty($data_query))
            @foreach($data_query as $key => $value)

            <tr>
                <td style="width:40px;  text-align: center;">{{$key+1}}</td>
                <td style="width:100px; text-align: center;">{{$value->product_name}}</td>
                <td style="width:70px; text-align: center;">{{$value->final_secondary_qty}}</td>
                <td style="width:70px; text-align: center;">{{$value->final_secondary_rate}}</td>
                <td style="width:70px; text-align: center;">{{$value->primary_unit}}</td>
                <td style="width:70px; text-align: center;">{{$value->final_secondary_qty*$value->final_secondary_rate}}</td>
            </tr>
                <?php 
                    $total += $value->final_secondary_rate*$value->final_secondary_qty; 
                    $totalqty+=$value->final_secondary_qty;
                    $totalrate+=$value->final_secondary_rate;
                    $discount=$value->discount;
                    $remarks=$value->remarks;

                  
                ?>

            
            @endforeach
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td style="width:40px;"></td>
                <td style="width:100px; text-align: left;"><b>Total</b></td>
                <td style="width:100px;text-align: center;"><b>{{$totalqty}}</b></td>
                <td style="width:70px;text-align: center;"><b>{{$totalrate}}</b></td>
                <td style="width:80px;"></td>
                <td style="width:80px;text-align: right;"><b>{{$total}}</b></td>

                
            </tr>
            <tr>
                <td style="width:40px;"></td>
                <td style="width:100px;"></td>
                <td style="width:100px;"></td>
                <td style="width:70px;"></td>
                <td style="width:80px;text-align: left;"><b>Discount</b></td>
                <td style="width:80px;text-align: right;"><b>{{$discount}}</b></td>

                
            </tr>
            <tr>
                <td style="width:40px;"></td>
                <td style="width:100px;"></td>
                <td style="width:100px;"></td>
                <td style="width:70px;"></td>
                <td style="width:80px;text-align: left;"><b>Grand Total</b></td>
                <td style="width:80px;text-align: right;"><b>{{$total-$discount}}</b></td>

                
            </tr>
            
            
        </tfoot>
    </table>
    <table border="1" cellspacing="0" cellpadding="0" height="70px" width="100%">
        <tr>
                <td>Remarks : {{$remarks}} <br><br></td>
            </tr>
            <tr>
                <td>Amount Chargeable (in words) E. & O.E <br><br><br>

                </td>
            </tr>
            <tr>
                <td style="text-align: right;"><b>For {{$coampany_details->title}}</b> <br>Authorised Signature
                    
                </td>
            </tr>
    </table>



</div>


<style type="text/css">
    span {
  content: "\20B9";
} 
    #searchlistdiv {
   width: 400px;
   margin: 0 auto; 
}
</style>