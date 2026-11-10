<!DOCTYPE html>
<html lang="en">
   


    <table border="1" cellspacing="0" cellpadding="0" height="30px" width="100%">
        <tr>
         <td colspan="12" style="text-align: center;"> <h2>{{$company_details->title}}</h2></td>
        </tr>

        <tr>
        <td colspan="12" style="text-align:center;"><h3>Credit Note</h3></td>
        </tr>

        <tr >
            <td colspan="4">
                <strong style="text-align:left;">  
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> Bill To : {{$dealerDetails->name}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> Ship To : {{$dealerDetails->name}}
                </strong>
            </td>
        </tr>


        <tr >
            <td colspan="4">
                <strong style="text-align:left;">  State Code : {{!empty($stateArr[$csaDetails->state_id])?$stateArr[$csaDetails->state_id]->state_abbriviation:''}}
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> 
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> 
                </strong>
            </td>
        </tr>

         <tr >
            <td colspan="4">
                <strong style="text-align:left;">  PinCode : 
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> Contact No : {{!empty($dealerDetails->landline)?$dealerDetails->landline:$dealerDetails->other_numbers}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> Contact No : {{!empty($dealerDetails->landline)?$dealerDetails->landline:$dealerDetails->other_numbers}}
                </strong>
            </td>
        </tr>


        <tr >
            <td colspan="4">
                <strong style="text-align:left;">  PAN : {{!empty($csaDetails->gst_no)?$csaDetails->gst_no:''}}
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> State Code : {{!empty($stateArr[$dealerDetails->state_id])?$stateArr[$dealerDetails->state_id]->state_abbriviation:''}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> State Code : {{!empty($stateArr[$dealerDetails->state_id])?$stateArr[$dealerDetails->state_id]->state_abbriviation:''}}
                </strong>
            </td>
        </tr>


        <tr >
            <td colspan="4">
                <strong style="text-align:left;">  
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> Pin Code : {{!empty($dealerDetails->pin_no)?$dealerDetails->pin_no:''}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> Pin Code : {{!empty($dealerDetails->pin_no)?$dealerDetails->pin_no:''}}
                </strong>
            </td>
        </tr>

         <tr >
            <td colspan="4">
                <strong style="text-align:left;">  
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> Place Of Supply (POS) : {{!empty($dealerDetails->address)?$dealerDetails->address:''}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> Place Of Supply (POS) : {{!empty($dealerDetails->address)?$dealerDetails->address:''}}
                </strong>
            </td>
        </tr>

         <tr>
            <td colspan="4">
                <strong style="text-align:left;">   GSTIN No : {{!empty($csaDetails->gst_no)?$csaDetails->gst_no:''}}
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> GSTIN No : {{!empty($dealerDetails->tin_no)?$dealerDetails->tin_no:''}}
                </strong>
            </td>

            <td colspan="4">
                <strong style="text-align:left;"> 
                </strong>
            </td>
        </tr>
         <tr>
            <td colspan="12">
                <strong style="text-align:left;">   Credit Note No. : {{$crno}}
                </strong>
                <br>
                <br>

                <strong style="text-align:left;">   Date. : {{date('d/m/Y')}}
                </strong>
                <br>
                <br>

                <strong style="text-align:left;">   Invoice ref. no : {{$orderNoPdf}}
                </strong>

            </td>
         </tr>

         <tr>
              <td colspan="2">
                <strong style="text-align:left;"> Particulars
                </strong>
            </td>
            <td colspan="2">
                <strong style="text-align:left;"> Basic
                </strong>
            </td>
            <td colspan="2">
                <strong style="text-align:left;"> GST %
                </strong>
            </td>
            <td colspan="2">
                <strong style="text-align:left;"> Total Tax
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> Total Amount
                </strong>
            </td>
         </tr>


           <tr>
              <td colspan="8">
                <strong style="text-align:right;"> Total
                </strong>
            </td>
            <td colspan="4">
                <strong style="text-align:left;"> {{ROUND($final_amount,2)}}
                </strong>
            </td>
         </tr>

         <tr>
        <td colspan="12">
            <strong style="text-align:left;"> Remarks: BEING AMOUT CREDIT DGR HO AUDIT JULY 21
            </strong>
        </td>
             
         </tr>

          <tr>
            <td colspan="2">
                <strong style="text-align:left;"> Tax
                </strong>
            </td>
             <td colspan="2">
                <strong style="text-align:left;"> Taxable Amt
                </strong>
            </td>
             <td colspan="2">
                <strong style="text-align:left;"> Tax Amount
                </strong>
            </td>
             
         </tr>

          <tr>
            <td colspan="2">
                <strong style="text-align:left;"> Total Tax
                </strong>
            </td>
             <td colspan="2">
                <strong style="text-align:left;"> 
                </strong>
            </td>
             <td colspan="2">
                <strong style="text-align:left;"> 
                </strong>
            </td>
             
         </tr>
         <tr>
             <td colspan="12">
                  <strong style="text-align:left;"> Amount in Words : {{$final_amount_conver}}
                  </strong>

                  <br>
                  <br>

                  <strong>IRN : </strong> 
                   <br>
                  <br> <br>
                  <br> <br>
                  <br>

                  <strong>Supply Type : </strong> NONE 
                  <br>
                  <br>

                   <strong>Eway Bill No : </strong> 
                  <br>
                  <br>

                   <strong>Document Type : </strong> NONE 
                  <br>
                  <br>

                   <strong>IS Service : </strong> N 
                  <br>
                  <br>

                   <strong>Acknowledgement Number : </strong>  
                  <br>
                  <br>

                   <strong>Acknowledgement Date : </strong>  
                  <br>
                  <br>
                  <br>
                  <br><br>
                  <br><br>
                  <br><br>
                  <br><br>
                  <br><br>
                  <br>



             </td>

         </tr>

         <tr>
             <td colspan="8">
                 <strong>Subject to Ahmedabad Jurisdiction.</strong><br>
                 Goods sold on principal to principal basis and will not be taken back after delivery. Interest @ 18% will be
                charged on accounts not settled within 15 days, if any. No responsibility for leakage and breakage in transit.<br>
                Claims for the same to be submitted to carriers.<br>
                Recommended storage conditions should be followed; which is, humidity less than 40% and temperature
                20.25 C.<br>
                REGD OFFICE: 905,Atlanta Towers, Near Sears Towers, Gulbai Tekra, Ahmedabad - 380 015.<br>
                <strong> CIN:</strong> U15499GJ2002PTC040538 <strong> Tel No.: </strong>+91 79 26449603 <strong>Fax:</strong> +91 79 26449610<br>
                <strong>E mail: customercare@rasnainternational.com Website: <a href="https://rasnainternational.com">www.rasnainternational.com</a></strong>
             </td>

             <td colspan="8">

                For: 
                <br>
                <br>
                <br>
                <br>
                <br>
                <br>


                Authorised Signatory

             </td>
         </tr>

    </table>


<style type="text/css">
    span {
  content: "\20B9";
} 
    #searchlistdiv {
   width: 400px;
   margin: 0 auto; 
}
</style>