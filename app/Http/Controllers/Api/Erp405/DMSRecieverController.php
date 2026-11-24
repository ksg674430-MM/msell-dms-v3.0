<?php

namespace App\Http\Controllers\Api\Erp405;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DMSRecieverController extends Controller
{
    public function parent_receive_function(Request $request)
    {

        $json_decode_data = json_decode($request->response);

        $return_array = array();

        if(!empty($json_decode_data->response->dealer_f_out) && COUNT($json_decode_data->response->dealer_f_out)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->dealer_f_out as $key => $value) {
                $check = DB::table('ACC_MAST')
                        ->where('ACC_MAST.ACC_CODE', $value->ACC_CODE)
                        ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ACC_MAST')
                                ->where('ACC_MAST.ACC_CODE', $value->ACC_CODE)
                                ->delete();
                }

                $ACC_MAST_array[] = [

                    'ACC_CODE' => !empty($value->ACC_CODE)?$value->ACC_CODE:'',
                    'TITLE' => !empty($value->TITLE)?$value->TITLE:'',
                    'ACC_NAME' => !empty($value->ACC_NAME)?$value->ACC_NAME:'',
                    'PARENT_CODE' => !empty($value->PARENT_CODE)?$value->PARENT_CODE:'',
                    'CODE_LEVEL' => !empty($value->CODE_LEVEL)?$value->CODE_LEVEL:'',
                    'ACC_CLASS_CODE' => !empty($value->ACC_CLASS_CODE)?$value->ACC_CLASS_CODE:'',
                    'ACC_STATUS' => !empty($value->ACC_STATUS)?$value->ACC_STATUS:'',
                    'SUPPLIER_TYPE' => !empty($value->SUPPLIER_TYPE)?$value->SUPPLIER_TYPE:'',
                    'BILLTRACK' => !empty($value->BILLTRACK)?$value->BILLTRACK:'',
                    'CBJQTY_FLAG' => !empty($value->CBJQTY_FLAG)?$value->CBJQTY_FLAG:'',
                    'ENTITY_CODE' => !empty($value->ENTITY_CODE)?$value->ENTITY_CODE:'',
                    'PLANT_CODE' => !empty($value->PLANT_CODE)?$value->PLANT_CODE:'',
                    'DIV_CODE' => !empty($value->DIV_CODE)?$value->DIV_CODE:'',
                    'CENAME' => !empty($value->CENAME)?$value->CENAME:'',
                    'ADD1' => !empty($value->ADD1)?$value->ADD1:'',
                    'ADD2' => !empty($value->ADD2)?$value->ADD2:'',
                    'ADD3' => !empty($value->ADD3)?$value->ADD3:'',
                    'CITY_CODE' => !empty($value->CITY_CODE)?$value->CITY_CODE:'',
                    'DISTRICT_CODE' => !empty($value->DISTRICT_CODE)?$value->DISTRICT_CODE:'',
                    'STATE_CODE' => !empty($value->STATE_CODE)?$value->STATE_CODE:'',
                    'HQ_CODE' => !empty($value->HQ_CODE)?$value->HQ_CODE:'',
                    'COUNTRY' => !empty($value->COUNTRY)?$value->COUNTRY:'',
                    'BOOKING_STATION' => !empty($value->BOOKING_STATION)?$value->BOOKING_STATION:'',
                    'TRANSPORT_MODE' => !empty($value->TRANSPORT_MODE)?$value->TRANSPORT_MODE:'',
                    'STDCODE' => !empty($value->STDCODE)?$value->STDCODE:'',
                    'PHONE' => !empty($value->PHONE)?$value->PHONE:'',
                    'FAX' => !empty($value->FAX)?$value->FAX:'',
                    'MOBILE' => !empty($value->MOBILE)?$value->MOBILE:'',
                    'PAGER' => !empty($value->PAGER)?$value->PAGER:'',
                    'EMAIL' => !empty($value->EMAIL)?$value->EMAIL:'',
                    'PANNO' => !empty($value->PANNO)?$value->PANNO:'',
                    'STNO' => !empty($value->STNO)?$value->STNO:'',
                    'CSTNO' => !empty($value->CSTNO)?$value->CSTNO:'',
                    'TDS_CODE' => !empty($value->TDS_CODE)?$value->TDS_CODE:'',
                    'STAX_CODE' => !empty($value->STAX_CODE)?$value->STAX_CODE:'',
                    'ETAX_CODE' => !empty($value->ETAX_CODE)?$value->ETAX_CODE:'',
                    'GPDAYS' => !empty($value->GPDAYS)?$value->GPDAYS:'',
                    'INTRATE' => !empty($value->INTRATE)?$value->INTRATE:'',
                    'CREDIT_LIMIT' => !empty($value->CREDIT_LIMIT)?$value->CREDIT_LIMIT:'',
                    'HUNDI_MAX_LIMIT' => !empty($value->HUNDI_MAX_LIMIT)?$value->HUNDI_MAX_LIMIT:'',
                    'HUNDI_MIN_LIMIT' => !empty($value->HUNDI_MIN_LIMIT)?$value->HUNDI_MIN_LIMIT:'',
                    'HUNDI_LIMIT' => !empty($value->HUNDI_LIMIT)?$value->HUNDI_LIMIT:'',
                    'HUNDI_DAYS' => !empty($value->HUNDI_DAYS)?$value->HUNDI_DAYS:'',
                    'DRUG_LICENO' => !empty($value->DRUG_LICENO)?$value->DRUG_LICENO:'',
                    'NDLCNO' => !empty($value->NDLCNO)?$value->NDLCNO:'',
                    'HOLIDAY' => !empty($value->HOLIDAY)?$value->HOLIDAY:'',
                    'BANK_CODE' => !empty($value->BANK_CODE)?$value->BANK_CODE:'',
                    'SPOSTR' => !empty($value->SPOSTR)?$value->SPOSTR:'',
                    'ECCNO' => !empty($value->ECCNO)?$value->ECCNO:'',
                    'RANGENO' => !empty($value->RANGENO)?$value->RANGENO:'',
                    'RANGE_NAME' => !empty($value->RANGE_NAME)?$value->RANGE_NAME:'',
                    'RANGEADD1' => !empty($value->RANGEADD1)?$value->RANGEADD1:'',
                    'RANGEADD2' => !empty($value->RANGEADD2)?$value->RANGEADD2:'',
                    'DIVISION' => !empty($value->DIVISION)?$value->DIVISION:'',
                    'COLLECTOR' => !empty($value->COLLECTOR)?$value->COLLECTOR:'',
                    'USER_CODE' => !empty($value->USER_CODE)?$value->USER_CODE:'',
                    'LASTUPDATE' => !empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'OLDACC_CODE' => !empty($value->OLDACC_CODE)?$value->OLDACC_CODE:'',
                    'COST_CODE' => !empty($value->COST_CODE)?$value->COST_CODE:'',
                    'BS_CODE' => !empty($value->BS_CODE)?$value->BS_CODE:'',
                    'SEARCH_TEXT' => !empty($value->SEARCH_TEXT)?$value->SEARCH_TEXT:'',
                    'FLAG' => !empty($value->FLAG)?$value->FLAG:'',
                    'REBOOK_FLAG' => !empty($value->REBOOK_FLAG)?$value->REBOOK_FLAG:'',
                    'CLEAN_CREDIT_LIMIT' => !empty($value->CLEAN_CREDIT_LIMIT)?$value->CLEAN_CREDIT_LIMIT:'',
                    'PAYMENT_MODE' => !empty($value->PAYMENT_MODE)?$value->PAYMENT_MODE:'',
                    'BANK_BRANCH' => !empty($value->BANK_BRANCH)?$value->BANK_BRANCH:'',
                    'BANK_ADD1' => !empty($value->BANK_ADD1)?$value->BANK_ADD1:'',
                    'BANK_ADD2' => !empty($value->BANK_ADD2)?$value->BANK_ADD2:'',
                    'BANK_ADD3' => !empty($value->BANK_ADD3)?$value->BANK_ADD3:'',
                    'BANK_PIN' => !empty($value->BANK_PIN)?$value->BANK_PIN:'',
                    'BAL_PRINT_STATUS' => !empty($value->BAL_PRINT_STATUS)?$value->BAL_PRINT_STATUS:'',
                    'ACC_TYPE' => !empty($value->ACC_TYPE)?$value->ACC_TYPE:'',
                    'DEPOT_FLAG' => !empty($value->DEPOT_FLAG)?$value->DEPOT_FLAG:'',
                    'DIV_STR' => !empty($value->DIV_STR)?$value->DIV_STR:'',
                    'FHNAME' => !empty($value->FHNAME)?$value->FHNAME:'',
                    'EMP_CODE' => !empty($value->EMP_CODE)?$value->EMP_CODE:'',
                    'ACC_SCH' => !empty($value->ACC_SCH)?$value->ACC_SCH:'',
                    'SLAB1_CDRATE' => !empty($value->SLAB1_CDRATE)?$value->SLAB1_CDRATE:'',
                    'SLAB1_CDDAYS' => !empty($value->SLAB1_CDDAYS)?$value->SLAB1_CDDAYS:'',
                    'SLAB2_CDRATE' => !empty($value->SLAB2_CDRATE)?$value->SLAB2_CDRATE:'',
                    'SLAB2_CDDAYS' => !empty($value->SLAB2_CDDAYS)?$value->SLAB2_CDDAYS:'',
                    'SLAB3_CDRATE' => !empty($value->SLAB3_CDRATE)?$value->SLAB3_CDRATE:'',
                    'SLAB3_CDDAYS' => !empty($value->SLAB3_CDDAYS)?$value->SLAB3_CDDAYS:'',
                    'SLAB4_CDRATE' => !empty($value->SLAB4_CDRATE)?$value->SLAB4_CDRATE:'',
                    'SLAB4_CDDAYS' => !empty($value->SLAB4_CDDAYS)?$value->SLAB4_CDDAYS:'',
                    'server_date_time'=>date('Y-m-d H:i:s')
                ];
            }
            $collected = collect($ACC_MAST_array);
            $chunked_array = $collected->chunk(700);
            foreach ($chunked_array as  $value) {

                $ACC_MAST_display = DB::table('ACC_MAST')
                                    ->insert($value->toArray());

                if($ACC_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ACC_CODE']= $r_v['ACC_CODE'];
                        $ACC_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ACC_MAST_display)
            {
                DB::commit();
                $ready_to_send['ACC_MAST']=$ACC_MAST_arr;
                $return_array['ACC_MAST'] = 'ACC_MAST table total records '.COUNT($ACC_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ACC_MAST'] = 'Error in ACC_MAST table';

            }
        }

        if(!empty($json_decode_data->response->www_web_item_display) && COUNT($json_decode_data->response->www_web_item_display)>0)
        {
            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->www_web_item_display as $key => $value) {
                $check = DB::table('WWW_WEB_ITEM_DISPLAY')
                        ->where('WWW_WEB_ITEM_DISPLAY.ITEM_CODE', $value->ITEM_CODE)

                        ->get();
                if(count($check)>0){
                   $delete_row = DB::table('WWW_WEB_ITEM_DISPLAY')
                                ->where('WWW_WEB_ITEM_DISPLAY.ITEM_CODE', $value->ITEM_CODE)

                                ->delete();
                }

                $WWW_WEB_ITEM_DISPLAY_array[] = [

                    'ITEM_CODE'=> $value->ITEM_CODE,
                    'ITEM_NAME'=> $value->ITEM_NAME,
                    'MKTG_CATG'=> $value->MKTG_CATG,
                    'ORDER_SL'=> $value->ORDER_SL,
                    'ITEM_STATUS'=> $value->ITEM_STATUS,
                    'PRICE_CATG'=> $value->PRICE_CATG,
                    'PUDA_SIZE'=> $value->PUDA_SIZE,
                    'PCS_IN_BOX'=> $value->PCS_IN_BOX,
                    'MIN_QTY_PCS'=> $value->MIN_QTY_PCS,
                    'MIN_QTY'=> $value->MIN_QTY,
                    'PUDA_SIZE_O'=> $value->PUDA_SIZE_O,
                    'MIN_QTY_PCS_O'=> $value->MIN_QTY_PCS_O,
                    'entity_code'=> !empty($value->entity_code)?$value->entity_code:'',
                    'server_date_time'=>date('Y-m-d H:i:s'),
                    'updated_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($WWW_WEB_ITEM_DISPLAY_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $WWW_WEB_ITEM_DISPLAY_display = DB::table('WWW_WEB_ITEM_DISPLAY')
                                    ->insert($value->toArray());

                if($WWW_WEB_ITEM_DISPLAY_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ITEM_CODE']= $r_v['ITEM_CODE'];
                        $out['MKTG_CATG']= $r_v['MKTG_CATG'];
                        $WWW_WEB_ITEM_DISPLAY_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($WWW_WEB_ITEM_DISPLAY_display)
            {
                DB::commit();
                $ready_to_send['WWW_WEB_ITEM_DISPLAY']=$WWW_WEB_ITEM_DISPLAY_arr;
                $return_array['WWW_WEB_ITEM_DISPLAY'] = 'WWW_WEB_ITEM_DISPLAY table total records '.COUNT($WWW_WEB_ITEM_DISPLAY_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in WWW_WEB_ITEM_DISPLAY table';

            }

        }

        if(!empty($json_decode_data->response->circular_f_out) && COUNT($json_decode_data->response->circular_f_out)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->circular_f_out as $key => $value) {
                $check = DB::table('CIRCULAR_MAST')
                                ->where('CIRCULAR_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('CIRCULAR_MAST.DIV_CODE', $value->DIV_CODE)
                                ->where('CIRCULAR_MAST.ACC_YEAR', $value->ACC_YEAR)
                                ->where('CIRCULAR_MAST.CIRCULAR_NO', $value->CIRCULAR_NO)
                                ->where('CIRCULAR_MAST.SCHEME_NO', $value->SCHEME_NO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('CIRCULAR_MAST')
                                ->where('CIRCULAR_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('CIRCULAR_MAST.DIV_CODE', $value->DIV_CODE)
                                ->where('CIRCULAR_MAST.ACC_YEAR', $value->ACC_YEAR)
                                ->where('CIRCULAR_MAST.CIRCULAR_NO', $value->CIRCULAR_NO)
                                ->where('CIRCULAR_MAST.SCHEME_NO', $value->SCHEME_NO)
                                ->delete();
                }

                $CIRCULAR_MAST_array[] = [

                    'ENTITY_CODE'=> $value->ENTITY_CODE,
                    'PLANT_CODE'=> $value->PLANT_CODE,
                    'DIV_CODE'=> $value->DIV_CODE,
                    'ACC_YEAR'=> $value->ACC_YEAR,
                    'CIRCULAR_NO'=> $value->CIRCULAR_NO,
                    'CIRCULAR_DATE'=> $value->CIRCULAR_DATE,
                    'FROM_DATE'=> $value->FROM_DATE,
                    'FROM_DATE_CONDITION'=> date('Y-m-d',strtotime($value->FROM_DATE)),
                    'TO_DATE'=> $value->TO_DATE,
                    'TO_DATE_CONDITION'=> date('Y-m-d',strtotime($value->TO_DATE)),
                    'EFFECTIVE_DATE'=> $value->EFFECTIVE_DATE,
                    'SCHEME_NO'=> $value->SCHEME_NO,
                    'REFE_CIRCULAR_NO'=> $value->REFE_CIRCULAR_NO,
                    'LASTUPDATE'=> $value->LASTUPDATE,
                    'USER_CODE'=> $value->USER_CODE,
                    'FLAG'=> $value->FLAG,
                    'APPROVEDBY'=> $value->APPROVEDBY,
                    'APPROVEDDATE'=> $value->APPROVEDDATE,
                    'CANCELLEDBY'=> $value->CANCELLEDBY,
                    'CANCELLEDDATE'=> $value->CANCELLEDDATE,
                    'REFE_SCHEME_NO'=> $value->REFE_SCHEME_NO,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];
            }

            $collected = collect($CIRCULAR_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {
                $CIRCULAR_MAST_display = DB::table('CIRCULAR_MAST')
                                    ->insert($value->toArray());

                if($CIRCULAR_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE']= $r_v['ENTITY_CODE'];
                        $out['DIV_CODE']= $r_v['DIV_CODE'];
                        $out['ACC_YEAR']= $r_v['ACC_YEAR'];
                        $out['CIRCULAR_NO']= $r_v['CIRCULAR_NO'];
                        $out['SCHEME_NO']= $r_v['SCHEME_NO'];
                        $CIRCULAR_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();
                }

            }

            if($CIRCULAR_MAST_display)
            {
                DB::commit();
                $ready_to_send['CIRCULAR_MAST']=$CIRCULAR_MAST_arr;
                $return_array['CIRCULAR_MAST'] = 'CIRCULAR_MAST table total records '.COUNT($CIRCULAR_MAST_array).' inserted succesfully';

                $return_array[] = 'CIRCULAR_MAST table inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in CIRCULAR_MAST table';

            }

        }

        if(!empty($json_decode_data->response->scheme_mast_f_out) && COUNT($json_decode_data->response->scheme_mast_f_out)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->scheme_mast_f_out as $key => $value) {
                $check = DB::table('SCHEME_MAST')
                                ->where('SCHEME_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_MAST.SCHEME_NO', $value->SCHEME_NO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('SCHEME_MAST')
                                ->where('SCHEME_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_MAST.SCHEME_NO', $value->SCHEME_NO)
                                ->delete();
                }

                $scheme_mast_array[] = [

                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'TCODE' => $value->TCODE,
                    'SCHEME_NO' => $value->SCHEME_NO,
                    'SCHEME_TYPE' => $value->SCHEME_TYPE,
                    'SCHEME_FOR' => $value->SCHEME_FOR,
                    'SCHEME_CATG' => $value->SCHEME_CATG,
                    'DEPOT_DOMAIN' => $value->DEPOT_DOMAIN,
                    'HQ_DOMAIN' => $value->HQ_DOMAIN,
                    'CITY_DOMAIN' => $value->CITY_DOMAIN,
                    'DEALER_DOMAIN' => $value->DEALER_DOMAIN,
                    'PROD_CATG_DOMAIN' => $value->PROD_CATG_DOMAIN,
                    'PROD_CODE_DOMAIN' => $value->PROD_CODE_DOMAIN,
                    'ITEM_CODE_DOMAIN' => $value->ITEM_CODE_DOMAIN,
                    'ITEM_SIZE_DOMAIN' => $value->ITEM_SIZE_DOMAIN,
                    'COMBINED_FLAG' => $value->COMBINED_FLAG,
                    'LOT_RATE_FLAG' => $value->LOT_RATE_FLAG,
                    'NPOR_FLAG' => $value->NPOR_FLAG,
                    'FLAG' => $value->FLAG,
                    'SLAB_UNIT' => $value->SLAB_UNIT,
                    'CALCULATION_FLAG' => $value->CALCULATION_FLAG,
                    'NPOR_DISC' => $value->NPOR_DISC,
                    'server_date_time'=>date('Y-m-d H:i:s')
                ];
            }

            $collected = collect($scheme_mast_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {
                $SCHEME_MAST_display = DB::table('SCHEME_MAST')
                                    ->insert($value->toArray());

                if($SCHEME_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE']= $r_v['ENTITY_CODE'];
                        $out['SCHEME_NO']= $r_v['SCHEME_NO'];
                        $SCHEME_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($SCHEME_MAST_display)
            {
                DB::commit();
                $ready_to_send['SCHEME_MAST']=$SCHEME_MAST_arr;
                $return_array['SCHEME_MAST'] = 'SCHEME_MAST table total records '.COUNT($scheme_mast_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in SCHEME_MAST table';

            }

        }

        if(!empty($json_decode_data->response->scheme_domain) && COUNT($json_decode_data->response->scheme_domain)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->scheme_domain as $key => $value) {
                $check = DB::table('SCHEME_DOMAIN')
                                ->where('SCHEME_DOMAIN.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_DOMAIN.SCHEME_NO', $value->SCHEME_NO)
                                ->where('SCHEME_DOMAIN.DOMAIN_NO', $value->DOMAIN_NO)
                                ->where('SCHEME_DOMAIN.DOMAIN_SLNO', $value->DOMAIN_SLNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('SCHEME_DOMAIN')
                                ->where('SCHEME_DOMAIN.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_DOMAIN.SCHEME_NO', $value->SCHEME_NO)
                                ->where('SCHEME_DOMAIN.DOMAIN_NO', $value->DOMAIN_NO)
                                ->where('SCHEME_DOMAIN.DOMAIN_SLNO', $value->DOMAIN_SLNO)
                                ->delete();
                }

                 $scheme_domain_array[] = [

                    'ENTITY_CODE'=> !empty($value->ENTITY_CODE)?$value->ENTITY_CODE:'',
                    'SCHEME_NO'=> !empty($value->SCHEME_NO)?$value->SCHEME_NO:'',
                    'DOMAIN_NO'=> !empty($value->DOMAIN_NO)?$value->DOMAIN_NO:'',
                    'DOMAIN_SLNO'=> !empty($value->DOMAIN_SLNO)?$value->DOMAIN_SLNO:'',
                    'DIV_CODE'=> !empty($value->DIV_CODE)?$value->DIV_CODE:'',
                    'HQ_CODE'=> !empty($value->HQ_CODE)?$value->HQ_CODE:'',
                    'CITY_CODE'=> !empty($value->CITY_CODE)?$value->CITY_CODE:'',
                    'ACC_CODE'=> !empty($value->ACC_CODE)?$value->ACC_CODE:'',
                    'PROD_CATG'=> !empty($value->PROD_CATG)?$value->PROD_CATG:'',
                    'PROD_CODE'=> !empty($value->PROD_CODE)?$value->PROD_CODE:'',
                    'ITEM_CODE'=> !empty($value->ITEM_CODE)?$value->ITEM_CODE:'',
                    'ITEM_SIZE'=> !empty($value->ITEM_SIZE)?$value->ITEM_SIZE:'',
                    'SIZE_UM'=> !empty($value->SIZE_UM)?$value->SIZE_UM:'',
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];
            }

            $collected = collect($scheme_domain_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {
                $SCHEME_DOMAIN_display = DB::table('SCHEME_DOMAIN')
                                    ->insert($value->toArray());

                if($SCHEME_DOMAIN_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE']= $r_v['ENTITY_CODE'];
                        $out['SCHEME_NO']= $r_v['SCHEME_NO'];
                        $out['DOMAIN_NO']= $r_v['DOMAIN_NO'];
                        $out['DOMAIN_SLNO']= $r_v['DOMAIN_SLNO'];
                        $SCHEME_DOMAIN_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($SCHEME_DOMAIN_display)
            {
                DB::commit();
                $ready_to_send['SCHEME_DOMAIN']=$SCHEME_DOMAIN_arr;
                $return_array['SCHEME_DOMAIN'] = 'SCHEME_DOMAIN table total records '.COUNT($scheme_domain_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in SCHEME_DOMAIN table';

            }

        }

        if(!empty($json_decode_data->response->scheme_slab) && COUNT($json_decode_data->response->scheme_slab)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->scheme_slab as $key => $value) {
                $check = DB::table('SCHEME_SLAB')
                                ->where('SCHEME_SLAB.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_SLAB.SCHEME_NO', $value->SCHEME_NO)
                                ->where('SCHEME_SLAB.SLNO', $value->SLNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('SCHEME_SLAB')
                                ->where('SCHEME_SLAB.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('SCHEME_SLAB.SCHEME_NO', $value->SCHEME_NO)
                                ->where('SCHEME_SLAB.SLNO', $value->SLNO)
                                ->delete();
                }

                $scheme_slab_array[] = [

                    'ENTITY_CODE'=> $value->ENTITY_CODE,
                    'SCHEME_NO'=> $value->SCHEME_NO,
                    'DOMAIN_NO'=> $value->DOMAIN_NO,
                    'DOMAIN_SLNO'=> $value->DOMAIN_SLNO,
                    'SLNO'=> $value->SLNO,
                    'MINQTY'=> $value->MINQTY,
                    'MINAMT'=> $value->MINAMT,
                    'BASE_FROM'=> $value->BASE_FROM,
                    'BASE_TO'=> $value->BASE_TO,
                    'IRATE'=> $value->IRATE,
                    'FREE_ITEM1_CH1'=> $value->FREE_ITEM1_CH1,
                    'FREE_ITEM1_QTY1'=> $value->FREE_ITEM1_QTY1,
                    'FREE_ITEM1_CH2'=> $value->FREE_ITEM1_CH2,
                    'FREE_ITEM1_QTY2'=> $value->FREE_ITEM1_QTY2,
                    'FREE_ITEM1_CRAMT'=> $value->FREE_ITEM1_CRAMT,
                    'FREE_ITEM2_CH1'=> $value->FREE_ITEM2_CH1,
                    'FREE_ITEM2_QTY1'=> $value->FREE_ITEM2_QTY1,
                    'FREE_ITEM2_CH2'=> $value->FREE_ITEM2_CH2,
                    'FREE_ITEM2_QTY2'=> $value->FREE_ITEM2_QTY2,
                    'FREE_ITEM2_CRAMT'=> $value->FREE_ITEM2_CRAMT,
                    'FREE_ITEM3_CH1'=> $value->FREE_ITEM3_CH1,
                    'FREE_ITEM3_QTY1'=> $value->FREE_ITEM3_QTY1,
                    'FREE_ITEM3_CH2'=> $value->FREE_ITEM3_CH2,
                    'FREE_ITEM3_QTY2'=> $value->FREE_ITEM3_QTY2,
                    'FREE_ITEM3_CRAMT'=> $value->FREE_ITEM3_CRAMT,
                    'FROM_DATE'=> $value->FROM_DATE,
                    'TO_DATE'=> $value->TO_DATE,
                    'SITE_DATE'=> $value->SITE_DATE,
                    'SITE_DAYS'=> $value->SITE_DAYS,
                    'RETIRE1_DAYS'=> $value->RETIRE1_DAYS,
                    'RETIRE2_DAYS'=> $value->RETIRE2_DAYS,
                    'RETIRE3_DAYS'=> $value->RETIRE3_DAYS,
                    'RETIRE4_DAYS'=> $value->RETIRE4_DAYS,
                    'RETIRE5_DAYS'=> $value->RETIRE5_DAYS,
                    'RETIRE6_DAYS'=> $value->RETIRE6_DAYS,
                    'CD1_RATE'=> $value->CD1_RATE,
                    'CD2_RATE'=> $value->CD2_RATE,
                    'CD3_RATE'=> $value->CD3_RATE,
                    'CD4_RATE'=> $value->CD4_RATE,
                    'CD5_RATE'=> $value->CD5_RATE,
                    'CD6_RATE'=> $value->CD6_RATE,
                    'ITEM_SIZE'=> $value->ITEM_SIZE,
                    'SIZE_UM'=> $value->SIZE_UM,
                    'TARGET_OF'=> $value->TARGET_OF,
                    'SCHEME_CATG'=> $value->SCHEME_CATG,
                    'MINTARGET'=> $value->MINTARGET,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];
            }

            $collected = collect($scheme_slab_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $SCHEME_SLAB_display = DB::table('SCHEME_SLAB')
                                    ->insert($value->toArray());

                if($SCHEME_SLAB_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['SCHEME_NO'] = $r_v['SCHEME_NO'];
                        $out['SLNO'] = $r_v['SLNO'];
                        $SCHEME_SLAB_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($SCHEME_SLAB_display)
            {
                DB::commit();
                $ready_to_send['SCHEME_SLAB']=$SCHEME_SLAB_arr;
                $return_array['SCHEME_SLAB'] = 'ACC_MAST table total records '.COUNT($scheme_slab_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in SCHEME_SLAB table';

            }
        }

        if(!empty($json_decode_data->response->item_mast) && COUNT($json_decode_data->response->item_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->item_mast as $key => $value) {
                $check = DB::table('ITEM_MAST')
                                ->where('ITEM_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ITEM_MAST')
                                ->where('ITEM_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->delete();
                }

                $ITEM_MAST_array[] = [

                    'ITEM_CODE' => $value->ITEM_CODE,
                    'ITEM_NAME' => $value->ITEM_NAME,
                    'SHORT_NAME' => !empty($value->SHORT_NAME)?$value->SHORT_NAME:'',
                    'ITEM_HINDI_NAME' => $value->ITEM_HINDI_NAME,
                    'ITEM_DETAIL' => $value->ITEM_DETAIL,
                    'PARENT_CODE' => $value->PARENT_CODE,
                    'CODE_LEVEL' => $value->CODE_LEVEL,
                    'ITEM_SIZE' => $value->ITEM_SIZE,
                    'ITEM_NATURE' => $value->ITEM_NATURE,
                    'PATENT_FLAG' => $value->PATENT_FLAG,
                    'AGMARK_FLAG' => $value->AGMARK_FLAG,
                    'COSTLY_FLAG' => $value->COSTLY_FLAG,
                    'IP_FLAG' => $value->IP_FLAG,
                    'EXCISABLE_FLAG' => $value->EXCISABLE_FLAG,
                    'EXPIRY_FLAG' => $value->EXPIRY_FLAG,
                    'BATCH_FLAG' => $value->BATCH_FLAG,
                    'QC_FLAG' => $value->QC_FLAG,
                    'GODOWN_CODE' => $value->GODOWN_CODE,
                    'HARVEST_CODE' => $value->HARVEST_CODE,
                    'BREAKAGE_FLAG' => $value->BREAKAGE_FLAG,
                    'PLANT_CODE' => $value->PLANT_CODE,
                    'PROD_CODE' => $value->PROD_CODE,
                    'ITEM_CLASS' => $value->ITEM_CLASS,
                    'PROD_CATG' => $value->PROD_CATG,
                    'UM' => $value->UM,
                    'MIN_SIZE' => $value->MIN_SIZE,
                    'QTYDECIMAL' => $value->QTYDECIMAL,
                    'RATE' => $value->RATE,
                    'BINCARDNO' => $value->BINCARDNO,
                    'BEST_BEFORE' => $value->BEST_BEFORE,
                    'EQPT_CODE' => $value->EQPT_CODE,
                    'MAKE_CODE' => $value->MAKE_CODE,
                    'DEPT_CODE' => $value->DEPT_CODE,
                    'EQITEM_CODE' => $value->EQITEM_CODE,
                    'OLDITEM_CODE' => $value->OLDITEM_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'USER_CODE' => $value->USER_CODE,
                    'FLAG' => $value->FLAG,
                    'TOOLS_FLAG' => $value->TOOLS_FLAG,
                    'SEARCH_TEXT' => $value->SEARCH_TEXT,
                    'SAMPLE_FLAG' => $value->SAMPLE_FLAG,
                    'PRICE_CATG' => $value->PRICE_CATG,
                    'NGPICODE' => $value->NGPICODE,
                    'NGPMRP' => $value->NGPMRP,
                    'NGPWP' => $value->NGPWP,
                    'NGPSIZE' => $value->NGPSIZE,
                    'SIZE_UM' => $value->SIZE_UM,
                    'NGP_PRICECATG' => $value->NGP_PRICECATG,
                    'ITEM_STATUS' => $value->ITEM_STATUS,
                    'ITEM_CATG' => $value->ITEM_CATG,
                    'TABLETSEC' => $value->TABLETSEC,
                    'BP_FLAG' => $value->BP_FLAG,
                    'ITEM_GROUP' => $value->ITEM_GROUP,
                    'WEIGHT' => $value->WEIGHT,
                    'WEIGHT_UM' => $value->WEIGHT_UM,
                    'SUPPLY_BEFORE' => $value->SUPPLY_BEFORE,
                    'HSN_CODE' => $value->HSN_CODE,
                    'BARCODE' => $value->BARCODE,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($ITEM_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $ITEM_MAST_display = DB::table('ITEM_MAST')
                                    ->insert($value->toArray());

                if($ITEM_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ITEM_CODE']= $r_v['ITEM_CODE'];
                        $ITEM_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ITEM_MAST_display)
            {
                DB::commit();
                $ready_to_send['ITEM_MAST']=$ITEM_MAST_arr;
                $return_array['ITEM_MAST'] = 'ITEM_MAST table total records '.COUNT($ITEM_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in ITEM_MAST table';

            }

        }

        if(!empty($json_decode_data->response->prod_mast) && COUNT($json_decode_data->response->prod_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->prod_mast as $key => $value) {
                $check = DB::table('PROD_MAST')
                                ->where('PROD_MAST.PROD_CODE', $value->PROD_CODE)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('PROD_MAST')
                                ->where('PROD_MAST.PROD_CODE', $value->PROD_CODE)
                                ->delete();
                }

                $PROD_MAST_array[] = [

                    'PROD_CODE' => $value->PROD_CODE,
                    'PROD_NAME' => $value->PROD_NAME,
                    'PROD_HINDI_NAME' => $value->PROD_HINDI_NAME,
                    'MKTG_STATUS' => $value->MKTG_STATUS,
                    'PARENT_CODE' => $value->PARENT_CODE,
                    'CODE_LEVEL' => $value->CODE_LEVEL,
                    'PROD_CATG' => $value->PROD_CATG,
                    'MKTG_CATG' => $value->MKTG_CATG,
                    'BILL_CATG' => $value->BILL_CATG,
                    'PRICE_LIST_CATG' => $value->PRICE_LIST_CATG,
                    'USER_CODE' => $value->USER_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'FLAG' => $value->FLAG,
                    'RETURN_DEDUCT_RATE' => $value->RETURN_DEDUCT_RATE,
                    'NEW_PROD_CODE' => $value->NEW_PROD_CODE,
                    'USER_SEQ' => $value->USER_SEQ,
                    'TARIFF_CODE' => $value->TARIFF_CODE,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($PROD_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $PROD_MAST_display = DB::table('PROD_MAST')
                                    ->insert($value->toArray());

                if($PROD_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['PROD_CODE']= $r_v['PROD_CODE'];
                        $PROD_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($PROD_MAST_display)
            {
                DB::commit();
                $ready_to_send['PROD_MAST']=$PROD_MAST_arr;
                $return_array['PROD_MAST'] = 'PROD_MAST table total records '.COUNT($PROD_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in PROD_MAST table';

            }
        }

        if(!empty($json_decode_data->response->mktg_catg_mast) && COUNT($json_decode_data->response->mktg_catg_mast)>0)
        {

            DB::beginTransaction();
            $check = [];

           foreach ($json_decode_data->response->mktg_catg_mast as $key => $value) {
                $check = DB::table('MKTG_CATG_MAST')
                                ->where('MKTG_CATG_MAST.MKTG_CATG', $value->MKTG_CATG)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('MKTG_CATG_MAST')
                                ->where('MKTG_CATG_MAST.MKTG_CATG', $value->MKTG_CATG)
                                ->delete();
                }

                $MKTG_CATG_MAST_array[] = [

                    'MKTG_CATG' => $value->MKTG_CATG,
                    'MKTG_CATG_NAME' => $value->MKTG_CATG_NAME,
                    'MKTG_CATG_HINDI_NAME' => $value->MKTG_CATG_HINDI_NAME,
                    'PARENT_CODE' => $value->PARENT_CODE,
                    'CODE_LEVEL' => $value->CODE_LEVEL,
                    'USER_CODE' => $value->USER_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'FLAG' => $value->FLAG,
                    'USER_SEQ' => $value->USER_SEQ,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($MKTG_CATG_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $MKTG_CATG_MAST_display = DB::table('MKTG_CATG_MAST')
                                    ->insert($value->toArray());
                if($MKTG_CATG_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['MKTG_CATG']= $r_v['MKTG_CATG'];
                        $MKTG_CATG_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($MKTG_CATG_MAST_display)
            {
                DB::commit();
                $ready_to_send['MKTG_CATG_MAST']=$MKTG_CATG_MAST_arr;
                $return_array['MKTG_CATG_MAST'] = 'MKTG_CATG_MAST table total records '.COUNT($MKTG_CATG_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in MKTG_CATG_MAST table';

            }
        }

        if(!empty($json_decode_data->response->prod_catg_mast) && COUNT($json_decode_data->response->prod_catg_mast)>0)
        {

            DB::beginTransaction();
            $check = [];

            foreach ($json_decode_data->response->prod_catg_mast as $key => $value) {
                $check = DB::table('PROD_CATG_MAST')
                                ->where('PROD_CATG_MAST.PROD_CATG', $value->PROD_CATG)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('PROD_CATG_MAST')
                                ->where('PROD_CATG_MAST.PROD_CATG', $value->PROD_CATG)
                                ->delete();
                }

                $PROD_CATG_MAST_array[] = [

                    'PROD_CATG' => $value->PROD_CATG,
                    'PROD_CATG_NAME' => $value->PROD_CATG_NAME,
                    'PROD_CATG_HINDI_NAME' => $value->PROD_CATG_HINDI_NAME,
                    'USER_SEQ' => $value->USER_SEQ,
                    'PARENT_CODE' => $value->PARENT_CODE,
                    'CODE_LEVEL' => $value->CODE_LEVEL,
                    'USER_CODE' => $value->USER_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'FLAG' => $value->FLAG,
                    'MKTG_CATG' => $value->MKTG_CATG,
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($PROD_CATG_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $PROD_CATG_MAST_display = DB::table('PROD_CATG_MAST')
                                    ->insert($value->toArray());
                if($PROD_CATG_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['PROD_CATG']= $r_v['PROD_CATG'];
                        $PROD_CATG_MAST_arr[] = $out;

                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($PROD_CATG_MAST_display)
            {
                DB::commit();
                $ready_to_send['PROD_CATG_MAST']=$PROD_CATG_MAST_arr;
                $return_array['PROD_CATG_MAST'] = 'PROD_CATG_MAST table total records '.COUNT($PROD_CATG_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in PROD_CATG_MAST table';

            }
        }

        if(!empty($json_decode_data->response->item_rate_mast) && COUNT($json_decode_data->response->item_rate_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->item_rate_mast as $key => $value) {
                $check = DB::table('ITEM_RATE_MAST')
                                ->where('ITEM_RATE_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEM_RATE_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('ITEM_RATE_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->where('ITEM_RATE_MAST.MRP', $value->MRP)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ITEM_RATE_MAST')
                                ->where('ITEM_RATE_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEM_RATE_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('ITEM_RATE_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->where('ITEM_RATE_MAST.MRP', $value->MRP)
                                ->delete();
                }

                $ITEM_RATE_MAST_array[] = [

                    'ENTITY_CODE' => !empty($value->ENTITY_CODE)?$value->ENTITY_CODE:'',
                    'FROM_DATE' => ($value->FROM_DATE),
                    'TO_DATE' => ($value->TO_DATE),
                    'EFFECTIVE_DATE' => ($value->EFFECTIVE_DATE),
                    'ITEM_CODE' => !empty($value->ITEM_CODE)?$value->ITEM_CODE:'',
                    'MRP' => !empty($value->MRP)?$value->MRP:'',
                    'WP' => !empty($value->WP)?$value->WP:'',
                    'MIN_QTY' => !empty($value->MIN_QTY)?$value->MIN_QTY:'',
                    'SLAB1_WP' => !empty($value->SLAB1_WP)?$value->SLAB1_WP:'',
                    'SLAB1_QTY' => !empty($value->SLAB1_QTY)?$value->SLAB1_QTY:'',
                    'SLAB2_WP' => !empty($value->SLAB2_WP)?$value->SLAB2_WP:'',
                    'SLAB2_QTY' => !empty($value->SLAB2_QTY)?$value->SLAB2_QTY:'',
                    'SLAB3_WP' => !empty($value->SLAB3_WP)?$value->SLAB3_WP:'',
                    'SLAB3_QTY' => !empty($value->SLAB3_QTY)?$value->SLAB3_QTY:'',
                    'SLAB4_WP' => !empty($value->SLAB4_WP)?$value->SLAB4_WP:'',
                    'SLAB4_QTY' => !empty($value->SLAB4_QTY)?$value->SLAB4_QTY:'',
                    'SLAB5_WP' => !empty($value->SLAB5_WP)?$value->SLAB5_WP:'',
                    'SLAB5_QTY' => !empty($value->SLAB5_QTY)?$value->SLAB5_QTY:'',
                    'SLAB6_WP' => !empty($value->SLAB6_WP)?$value->SLAB6_WP:'',
                    'SLAB6_QTY' => !empty($value->SLAB6_QTY)?$value->SLAB6_QTY:'',
                    'LASTUPDATE' => !empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'USER_CODE' => !empty($value->USER_CODE)?$value->USER_CODE:'',
                    'FLAG' => !empty($value->FLAG)?$value->FLAG:'',
                    'EXCISE_AMT' => !empty($value->EXCISE_AMT)?$value->EXCISE_AMT:'',
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($ITEM_RATE_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $ITEM_RATE_MAST_display = DB::table('ITEM_RATE_MAST')
                                    ->insert($value->toArray());
                if($ITEM_RATE_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE']= $r_v['ENTITY_CODE'];
                        $out['FROM_DATE']= $r_v['FROM_DATE'];
                        $out['ITEM_CODE']= $r_v['ITEM_CODE'];
                        $out['MRP']= $r_v['MRP'];
                        $ITEM_RATE_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ITEM_RATE_MAST_display)
            {
                DB::commit();
                $ready_to_send['ITEM_RATE_MAST']=$ITEM_RATE_MAST_arr;
                $return_array['ITEM_RATE_MAST'] = 'ITEM_RATE_MAST table total records '.COUNT($ITEM_RATE_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in ITEM_RATE_MAST table';

            }
        }

        if(!empty($json_decode_data->response->dealer_target_mast) && COUNT($json_decode_data->response->dealer_target_mast)>0)
        {

            DB::beginTransaction();

            foreach ($json_decode_data->response->dealer_target_mast as $key => $value) {
                $check = DB::table('DEALER_TARGET_MAST')
                                ->where('DEALER_TARGET_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('DEALER_TARGET_MAST.ACC_CODE', $value->ACC_CODE)
                                ->where('DEALER_TARGET_MAST.PROD_CATG', $value->PROD_CATG)
                                ->where('DEALER_TARGET_MAST.MKTG_CATG', $value->MKTG_CATG)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('DEALER_TARGET_MAST')
                                ->where('DEALER_TARGET_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('DEALER_TARGET_MAST.ACC_CODE', $value->ACC_CODE)
                                ->where('DEALER_TARGET_MAST.PROD_CATG', $value->PROD_CATG)
                                ->where('DEALER_TARGET_MAST.MKTG_CATG', $value->MKTG_CATG)
                                ->delete();
                }

                $DEALER_TARGET_MAST_array[$value->FROM_DATE.$value->ACC_CODE.$value->PROD_CATG.$value->MKTG_CATG] = [

                    'FROM_DATE' => !empty($value->FROM_DATE)?$value->FROM_DATE:'',
                    'TO_DATE' => !empty($value->TO_DATE)?$value->TO_DATE:'',
                    'ACC_CODE' => !empty($value->ACC_CODE)?$value->ACC_CODE:NULL,
                    'PROD_CATG' => !empty($value->PROD_CATG)?$value->PROD_CATG:NULL,
                    'UM' => !empty($value->UM)?$value->UM:'',
                    'TARGET_QTY' => !empty($value->TARGET_QTY)?$value->TARGET_QTY:'',
                    'TARGET_AMT' => !empty($value->TARGET_AMT)?$value->TARGET_AMT:'',
                    'LASTUPDATE' => !empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'USER_CODE' => !empty($value->USER_CODE)?$value->USER_CODE:'',
                    'FLAG' => !empty($value->FLAG)?$value->FLAG:'',
                    'MKTG_CATG' => !empty($value->MKTG_CATG)?$value->MKTG_CATG:'',

                    'FROM_DATE_FILTER'=>date('Y-m-d',strtotime($value->FROM_DATE)),
                    'TO_DATE_FILTER'=>date('Y-m-d',strtotime($value->TO_DATE)),

                    'server_date_time'=>date('Y-m-d H:i:s')

                ];
            }

            $collected = collect($DEALER_TARGET_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $DEALER_TARGET_MAST_display = DB::table('DEALER_TARGET_MAST')
                                    ->insert($value->toArray());

                if($DEALER_TARGET_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['FROM_DATE'] = $r_v['FROM_DATE'];
                        $out['ACC_CODE'] = $r_v['ACC_CODE'];
                        $out['PROD_CATG'] = $r_v['PROD_CATG'];
                        $out['MKTG_CATG'] = $r_v['MKTG_CATG'];
                        $DEALER_TARGET_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($DEALER_TARGET_MAST_display)
            {
                DB::commit();
                $ready_to_send['DEALER_TARGET_MAST']=$DEALER_TARGET_MAST_arr;
                $return_array['DEALER_TARGET_MAST'] = 'DEALER_TARGET_MAST table total records '.COUNT($DEALER_TARGET_MAST_array).' inserted succesfully';

                $return_array[] = 'DEALER_TARGET_MAST table inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in DEALER_TARGET_MAST table';

            }
        }

        if(!empty($json_decode_data->response->item_aum_mast) && COUNT($json_decode_data->response->item_aum_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->item_aum_mast as $key => $value) {
                $check = DB::table('ITEM_AUM_MAST')
                                ->where('ITEM_AUM_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->where('ITEM_AUM_MAST.AUM', $value->AUM)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ITEM_AUM_MAST')
                                ->where('ITEM_AUM_MAST.ITEM_CODE', $value->ITEM_CODE)
                                ->where('ITEM_AUM_MAST.AUM', $value->AUM)
                                ->delete();
                }

                $ITEM_AUM_MAST_array[] = [

                    'ITEM_CODE' => !empty($value->ITEM_CODE)?$value->ITEM_CODE:'',
                    'AUM' => !empty($value->AUM)?$value->AUM:'',
                    'AUM_LEVEL' => !empty($value->AUM_LEVEL)?$value->AUM_LEVEL:'',
                    'AQDECIMAL' => !empty($value->AQDECIMAL)?$value->AQDECIMAL:'',
                    'UMTOAUM' => !empty($value->UMTOAUM)?$value->UMTOAUM:'',
                    'LASTUPDATE' => !empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'USER_CODE' => !empty($value->USER_CODE)?$value->USER_CODE:'',
                    'FLAG' => !empty($value->FLAG)?$value->FLAG:'',
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($ITEM_AUM_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $ITEM_AUM_MAST_display = DB::table('ITEM_AUM_MAST')
                                    ->insert($value->toArray());

                if($ITEM_AUM_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ITEM_CODE']= $r_v['ITEM_CODE'];
                        $out['AUM']= $r_v['AUM'];
                        $ITEM_AUM_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ITEM_AUM_MAST_display)
            {
                DB::commit();
                $ready_to_send['ITEM_AUM_MAST']=$ITEM_AUM_MAST_arr;
                $return_array['ITEM_AUM_MAST'] = 'ITEM_AUM_MAST table total records '.COUNT($ITEM_AUM_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in ITEM_AUM_MAST table';

            }
        }

        if(!empty($json_decode_data->response->dealer_billtax_mast) && COUNT($json_decode_data->response->dealer_billtax_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->dealer_billtax_mast as $key => $value) {
                $check = DB::table('DEALER_BILLTAX_MAST')
                                ->where('DEALER_BILLTAX_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('DEALER_BILLTAX_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('DEALER_BILLTAX_MAST.TO_DATE', $value->TO_DATE)
                                ->where('DEALER_BILLTAX_MAST.ACC_CODE', $value->ACC_CODE)
                                ->where('DEALER_BILLTAX_MAST.PRICE_LIST_CATG', $value->PRICE_LIST_CATG)
                                ->where('DEALER_BILLTAX_MAST.PROD_CATG', $value->PROD_CATG)
                                ->where('DEALER_BILLTAX_MAST.PROD_CODE', $value->PROD_CODE)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('DEALER_BILLTAX_MAST')
                                ->where('DEALER_BILLTAX_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('DEALER_BILLTAX_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('DEALER_BILLTAX_MAST.TO_DATE', $value->TO_DATE)
                                ->where('DEALER_BILLTAX_MAST.ACC_CODE', $value->ACC_CODE)
                                ->where('DEALER_BILLTAX_MAST.PRICE_LIST_CATG', $value->PRICE_LIST_CATG)
                                ->where('DEALER_BILLTAX_MAST.PROD_CATG', $value->PROD_CATG)
                                ->where('DEALER_BILLTAX_MAST.PROD_CODE', $value->PROD_CODE)
                                ->delete();
                }

                $concat_string = $value->ENTITY_CODE.$value->FROM_DATE.$value->TO_DATE.$value->ACC_CODE.$value->PRICE_LIST_CATG.$value->PROD_CATG.$value->PROD_CODE;
                $DEALER_BILLTAX_MAST_array[] = [
                    'ENTITY_CODE'=>!empty($value->ENTITY_CODE)?$value->ENTITY_CODE:NULL,
                    'FROM_DATE'=>!empty($value->FROM_DATE)?$value->FROM_DATE:NULL,
                    'TO_DATE'=>!empty($value->TO_DATE)?$value->TO_DATE:NULL,
                    'ACC_CODE'=>!empty($value->ACC_CODE)?$value->ACC_CODE:NULL,
                    'PRICE_LIST_CATG'=>!empty($value->PRICE_LIST_CATG)?$value->PRICE_LIST_CATG:NULL,
                    'PROD_CATG'=>!empty($value->PROD_CATG)?$value->PROD_CATG:NULL,
                    'PROD_CODE'=>!empty($value->PROD_CODE)?$value->PROD_CODE:NULL,
                    'TI_RATE'=>!empty($value->TI_RATE)?$value->TI_RATE:'',
                    'OCTROI_RATE'=>!empty($value->OCTROI_RATE)?$value->OCTROI_RATE:'',
                    'LASTUPDATE'=>!empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'USER_CODE'=>!empty($value->USER_CODE)?$value->USER_CODE:'',
                    'FLAG'=>!empty($value->FLAG)?$value->FLAG:'',
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($DEALER_BILLTAX_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $DEALER_BILLTAX_MAST_display = DB::table('DEALER_BILLTAX_MAST')
                                    ->insert($value->toArray());

                if($DEALER_BILLTAX_MAST_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {
                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['FROM_DATE'] = $r_v['FROM_DATE'];
                        $out['TO_DATE'] = $r_v['TO_DATE'];
                        $out['ACC_CODE'] = $r_v['ACC_CODE'];
                        $out['PRICE_LIST_CATG'] = $r_v['PRICE_LIST_CATG'];
                        $out['PROD_CATG'] = $r_v['PROD_CATG'];
                        $out['PROD_CODE'] = $r_v['PROD_CODE'];
                        $DEALER_BILLTAX_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($DEALER_BILLTAX_MAST_display)
            {
                DB::commit();
                $ready_to_send['DEALER_BILLTAX_MAST']=$DEALER_BILLTAX_MAST_arr;
                $return_array['DEALER_BILLTAX_MAST'] = 'DEALER_BILLTAX_MAST table total records '.COUNT($DEALER_BILLTAX_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in DEALER_BILLTAX_MAST table';

            }
        }

        if(!empty($json_decode_data->response->prod_tax_mast) && COUNT($json_decode_data->response->prod_tax_mast)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->prod_tax_mast as $key => $value) {
                $check = DB::table('PROD_TAX_MAST')
                                ->where('PROD_TAX_MAST.PROD_CODE', $value->PROD_CODE)
                                ->where('PROD_TAX_MAST.PLANT_CODE', $value->PLANT_CODE)
                                ->where('PROD_TAX_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('PROD_TAX_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('PROD_TAX_MAST.TO_DATE', $value->TO_DATE)
                                ->where('PROD_TAX_MAST.STATE_CODE', $value->STATE_CODE)
                                ->where('PROD_TAX_MAST.PRICE_LIST_CATG', $value->PRICE_LIST_CATG)
                                ->where('PROD_TAX_MAST.PROD_CATG', $value->PROD_CATG)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('PROD_TAX_MAST')
                                ->where('PROD_TAX_MAST.PROD_CODE', $value->PROD_CODE)
                                ->where('PROD_TAX_MAST.PLANT_CODE', $value->PLANT_CODE)
                                ->where('PROD_TAX_MAST.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('PROD_TAX_MAST.FROM_DATE', $value->FROM_DATE)
                                ->where('PROD_TAX_MAST.TO_DATE', $value->TO_DATE)
                                ->where('PROD_TAX_MAST.STATE_CODE', $value->STATE_CODE)
                                ->where('PROD_TAX_MAST.PRICE_LIST_CATG', $value->PRICE_LIST_CATG)
                                ->where('PROD_TAX_MAST.PROD_CATG', $value->PROD_CATG)
                                ->delete();
                }

                $PROD_TAX_MAST_array[] = [

                    'ENTITY_CODE' => !empty($value->ENTITY_CODE)?$value->ENTITY_CODE:'',
                    'FROM_DATE' => !empty($value->FROM_DATE)?$value->FROM_DATE:'',
                    'TO_DATE' => !empty($value->TO_DATE)?$value->TO_DATE:'',
                    'STATE_CODE' => !empty($value->STATE_CODE)?$value->STATE_CODE:'',
                    'PRICE_LIST_CATG' => !empty($value->PRICE_LIST_CATG)?$value->PRICE_LIST_CATG:'',
                    'PROD_CATG' => !empty($value->PROD_CATG)?$value->PROD_CATG:'',
                    'PROD_CODE' => !empty($value->PROD_CODE)?$value->PROD_CODE:'',
                    'STAX_RATE' => !empty($value->STAX_RATE)?$value->STAX_RATE:'',
                    'SURCHARGE_RATE' => !empty($value->SURCHARGE_RATE)?$value->SURCHARGE_RATE:'',
                    'ETAX_RATE' => !empty($value->ETAX_RATE)?$value->ETAX_RATE:'',
                    'LASTUPDATE' => !empty($value->LASTUPDATE)?$value->LASTUPDATE:'',
                    'USER_CODE' => !empty($value->USER_CODE)?$value->USER_CODE:'',
                    'FLAG' => !empty($value->FLAG)?$value->FLAG:'',
                    'PLANT_CODE' => !empty($value->PLANT_CODE)?$value->PLANT_CODE:'',
                    'VAT_DEDUCTION_RATE' => !empty($value->VAT_DEDUCTION_RATE)?$value->VAT_DEDUCTION_RATE:'',
                    'ATD_FLAG' => !empty($value->ATD_FLAG)?$value->ATD_FLAG:'',
                    'server_date_time'=>date('Y-m-d H:i:s')

                ];

            }

            $collected = collect($PROD_TAX_MAST_array);
            $chunked_array = $collected->chunk(1100);
            foreach ($chunked_array as  $value) {

                $PROD_TAX_MAST_display = DB::table('PROD_TAX_MAST')
                                    ->insert($value->toArray());

                if($PROD_TAX_MAST_display)
                {
                    DB::commit();
                    foreach ($value as $r_k => $r_v) {
                        $out['PROD_CODE'] = $r_v['PROD_CODE'];
                        $out['PLANT_CODE'] = $r_v['PLANT_CODE'];
                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['FROM_DATE'] = $r_v['FROM_DATE'];
                        $out['TO_DATE'] = $r_v['TO_DATE'];
                        $out['STATE_CODE'] = $r_v['STATE_CODE'];
                        $out['PRICE_LIST_CATG'] = $r_v['PRICE_LIST_CATG'];
                        $out['PROD_CATG'] = $r_v['PROD_CATG'];
                        $PROD_TAX_MAST_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($PROD_TAX_MAST_display)
            {
                DB::commit();
                $ready_to_send['PROD_TAX_MAST']=$PROD_TAX_MAST_arr;
                $return_array['PROD_TAX_MAST'] = 'PROD_TAX_MAST table total records '.COUNT($PROD_TAX_MAST_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array[] = 'Error in PROD_TAX_MAST table';

            }
        }

        if(!empty($json_decode_data->response->accnote_tran) && COUNT($json_decode_data->response->accnote_tran)>0)
        {

            DB::beginTransaction();
            $check = [];
            $ACCNOTE_TRAN_arr =[];
            foreach ($json_decode_data->response->accnote_tran as $key => $value) {
                $check = DB::table('ACCNOTE_TRAN')
                                ->where('ACCNOTE_TRAN.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ACCNOTE_TRAN.DIV_CODE', $value->DIV_CODE)
                                ->where('ACCNOTE_TRAN.TCODE', $value->TCODE)
                                ->where('ACCNOTE_TRAN.VRNO', $value->VRNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ACCNOTE_TRAN')
                                ->where('ACCNOTE_TRAN.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ACCNOTE_TRAN.DIV_CODE', $value->DIV_CODE)
                                ->where('ACCNOTE_TRAN.TCODE', $value->TCODE)
                                ->where('ACCNOTE_TRAN.VRNO', $value->VRNO)
                                ->delete();
                }

                $accnote_tran_array[] = [
                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'DIV_CODE' => $value->DIV_CODE,
                    'TCODE' => $value->TCODE,
                    'VRNO' => $value->VRNO,
                    'VRDATE' => $value->VRDATE,
                    'VRDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->VRDATE))),

                    'NOTE_TYPE' => $value->NOTE_TYPE,
                    'ACC_CODE' => $value->ACC_CODE,
                    'DRAMT' => $value->DRAMT,
                    'CRAMT' => $value->CRAMT,
                    'PARTICULAR' => $value->PARTICULAR,
                    'BILL_CATG' => $value->BILL_CATG,
                    'STAX_RATE' => $value->STAX_RATE,
                    'EVRDATE' => $value->EVRDATE,
                    'EVRDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->EVRDATE))),

                    'DUEDATE' => $value->DUEDATE,
                    'DUEDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->DUEDATE))),

                    'QTYISSUED' => $value->QTYISSUED,
                    'QTYRECD' => $value->QTYRECD,
                    'OLD_VRNO' => $value->OLD_VRNO,
                    'EMP_CODE' => $value->EMP_CODE,
                    'APPROVED' => $value->APPROVED,
                    'APPROVEDBY' => $value->APPROVEDBY,
                    'APPROVEDDATE' => $value->APPROVEDDATE,
                    'APPROVEDDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->APPROVEDDATE))),

                    'APPROVEDREMARK' => $value->APPROVEDREMARK,
                    'USER_CODE' => $value->USER_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'LASTUPDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->LASTUPDATE))),

                    'FLAG' => $value->FLAG,
                    'SCHEME_NO' => $value->SCHEME_NO,
                    'ITEM_VRNO' => $value->ITEM_VRNO,
                    'ITEM_TCODE' => $value->ITEM_TCODE,
                    'RETURN_VRNO' => $value->RETURN_VRNO,
                    'RETURN_TCODE' => $value->RETURN_TCODE,

                    'server_date_time'=>date('Y-m-d H:i:s')
                ];

            }
            $collected = collect($accnote_tran_array);
            $chunked_array = $collected->chunk(700);
            foreach ($chunked_array as  $value) {

                $ACCNOTE_TRAN_display = DB::table('ACCNOTE_TRAN')
                                    ->insert($value->toArray());

                if($ACCNOTE_TRAN_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];
                        $ACCNOTE_TRAN_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ACCNOTE_TRAN_display)
            {
                DB::commit();
                $ready_to_send['ACCNOTE_TRAN']=$ACCNOTE_TRAN_arr;
                $return_array['ACCNOTE_TRAN'] = 'ACCNOTE_TRAN table total records '.COUNT($ACCNOTE_TRAN_arr).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ACCNOTE_TRAN'] = 'Error in ACCNOTE_TRAN table';

            }
        }

        if(!empty($json_decode_data->response->itemtran_body) && COUNT($json_decode_data->response->itemtran_body)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->itemtran_body as $key => $value) {
                $check = DB::table('ITEMTRAN_BODY')
                                ->where('ITEMTRAN_BODY.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEMTRAN_BODY.DIV_CODE', $value->DIV_CODE)
                                ->where('ITEMTRAN_BODY.TCODE', $value->TCODE)
                                ->where('ITEMTRAN_BODY.VRNO', $value->VRNO)
                                ->where('ITEMTRAN_BODY.SLNO', $value->SLNO)
                                ->get();

                if(count($check)>0){

                   $delete_row = DB::table('ITEMTRAN_BODY')
                                ->where('ITEMTRAN_BODY.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEMTRAN_BODY.DIV_CODE', $value->DIV_CODE)
                                ->where('ITEMTRAN_BODY.TCODE', $value->TCODE)
                                ->where('ITEMTRAN_BODY.VRNO', $value->VRNO)
                                ->where('ITEMTRAN_BODY.SLNO', $value->SLNO)
                                ->delete();
                }

                $itemtran_body_array[] = [

                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'TCODE' => $value->TCODE,
                    'VRNO' => $value->VRNO,
                    'SLNO' => $value->SLNO,
                    'VRDATE' => $value->VRDATE,
                    'VRDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->VRDATE))),

                    'BATCHNO' => $value->BATCHNO,
                    'ITEM_CODE' => $value->ITEM_CODE,
                    'EQPT_CODE' => $value->EQPT_CODE,
                    'MAKE_CODE' => $value->MAKE_CODE,
                    'PLANT_CODE' => $value->PLANT_CODE,
                    'TO_PLANT_CODE' => $value->TO_PLANT_CODE,
                    'DIV_CODE' => $value->DIV_CODE,
                    'TO_DIV_CODE' => $value->TO_DIV_CODE,
                    'DEPT_CODE' => $value->DEPT_CODE,
                    'REMARK' => $value->REMARK,
                    'INSP_REMARK' => $value->INSP_REMARK,
                    'PARTYQTY' => $value->PARTYQTY,
                    'REACHEDQTY' => $value->REACHEDQTY,
                    'QTYRECD' => $value->QTYRECD,
                    'QTYISSUED' => $value->QTYISSUED,
                    'REJECTRECD' => $value->REJECTRECD,
                    'REJECTISSUED' => $value->REJECTISSUED,
                    'REJECTRETURN' => $value->REJECTRETURN,
                    'QTYATJOBBER' => $value->QTYATJOBBER,
                    'RATE' => $value->RATE,
                    'RATE_BASIS' => $value->RATE_BASIS,
                    'UM' => $value->UM,
                    'AUM' => $value->AUM,
                    'PROVRATE' => $value->PROVRATE,
                    'LANDEDRATE' => $value->LANDEDRATE,
                    'PARTYRATE' => $value->PARTYRATE,
                    'CRAMT' => $value->CRAMT,
                    'DRAMT' => $value->DRAMT,
                    'VALRECD' => $value->VALRECD,
                    'VALISSUED' => $value->VALISSUED,
                    'ORDER_VRNO' => $value->ORDER_VRNO,
                    'ORDER_TCODE' => $value->ORDER_TCODE,
                    'ORDER_SLNO' => $value->ORDER_SLNO,
                    'PACKSLIP_VRNO' => $value->PACKSLIP_VRNO,
                    'PACKSLIP_TCODE' => $value->PACKSLIP_TCODE,
                    'PACKSLIP_SLNO' => $value->PACKSLIP_SLNO,
                    'SCHEME_NO' => $value->SCHEME_NO,
                    'SCHEME_DOMAIN_NO' => $value->SCHEME_DOMAIN_NO,
                    'SCHEME_SLNO' => $value->SCHEME_SLNO,
                    'INDENT_VRNO' => $value->INDENT_VRNO,
                    'INDENT_SLNO' => $value->INDENT_SLNO,
                    'GRN_VRNO' => $value->GRN_VRNO,
                    'INSPECTEDBY' => $value->INSPECTEDBY,
                    'INSPECTEDDATE' => $value->INSPECTEDDATE,
                    'INSPECTEDDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->INSPECTEDDATE))),

                    'LASTGRNNO' => $value->LASTGRNNO,
                    'LASTGRNACC_CODE' => $value->LASTGRNACC_CODE,
                    'LASTGRNDATE' => $value->LASTGRNDATE,
                    'LASTGRNDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->LASTGRNDATE))),

                    'LASTGRNRATE' => $value->LASTGRNRATE,
                    'NAFIELD1' => $value->NAFIELD1,
                    'NAFIELD2' => $value->NAFIELD2,
                    'NAFIELD3' => $value->NAFIELD3,
                    'NAFIELD4' => $value->NAFIELD4,
                    'NAFIELD5' => $value->NAFIELD5,
                    'AFIELD1' => $value->AFIELD1,
                    'AFIELD2' => $value->AFIELD2,
                    'AFIELD3' => $value->AFIELD3,
                    'AFIELD4' => $value->AFIELD4,
                    'AFIELD5' => $value->AFIELD5,
                    'AFIELD6' => $value->AFIELD6,
                    'AFIELD7' => $value->AFIELD7,
                    'AFIELD8' => $value->AFIELD8,
                    'AFIELD9' => $value->AFIELD9,
                    'AFIELD10' => $value->AFIELD10,
                    'AFIELD11' => $value->AFIELD11,
                    'AFIELD12' => $value->AFIELD12,
                    'AFIELD13' => $value->AFIELD13,
                    'AFIELD14' => $value->AFIELD14,
                    'AFIELD15' => $value->AFIELD15,
                    'AFIELD16' => $value->AFIELD16,
                    'AFIELD17' => $value->AFIELD17,
                    'AFIELD18' => $value->AFIELD18,
                    'FLAG' => $value->FLAG,
                    'QTYFREE' => $value->QTYFREE,
                    'MRP' => $value->MRP,
                    'TI_RATE' => $value->TI_RATE,
                    'IRATE' => $value->IRATE,
                    'DO_VRNO' => $value->DO_VRNO,
                    'GODOWN_CODE' => $value->GODOWN_CODE,
                    'ITEM_TYPE' => $value->ITEM_TYPE,
                    'BREAKAGE_RATE' => $value->BREAKAGE_RATE,
                    'ITEMPACK_VRNO' => $value->ITEMPACK_VRNO,
                    'ITEMPACK_TCODE' => $value->ITEMPACK_TCODE,
                    'MFG_DATE' => $value->MFG_DATE,
                    'MFG_DATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->MFG_DATE))),

                    'TRANUM' => $value->TRANUM,
                    'MODVAT_AVAILED' => $value->MODVAT_AVAILED,
                    'MODVAT_FLAG' => $value->MODVAT_FLAG,
                    'EXTEND_INSPECTION' => $value->EXTEND_INSPECTION,
                    'ITEM_TCODE' => $value->ITEM_TCODE,
                    'ITEM_VRNO' => $value->ITEM_VRNO,
                    'ITEM_SLNO' => $value->ITEM_SLNO,
                    'MODVAT_CESS_FLAG' => $value->MODVAT_CESS_FLAG,
                    'MODVAT_CESS_AVAILED' => $value->MODVAT_CESS_AVAILED,
                    'NAFIELD6' => $value->NAFIELD6,
                    'AQTYRECD' => $value->AQTYRECD,
                    'AQTYISSUED' => $value->AQTYISSUED,
                    'SYSTEM_RATE' => $value->SYSTEM_RATE,
                    'SYSTEM_FREEQTY' => $value->SYSTEM_FREEQTY,
                    'SYS_SCHEME_TAG' => $value->SYS_SCHEME_TAG,
                    'MRPVALISSUED' => $value->MRPVALISSUED,
                    'GST_PERCENTAGE' => $value->GST_PERCENTAGE,
                    'GST_AMT' => $value->GST_AMT,
                    'CGST_AMOUNT' => $value->CGST_AMOUNT,
                    'SGST_AMOUNT' => $value->SGST_AMOUNT,
                    'IGST_AMOUNT' => $value->IGST_AMOUNT,
                    'CGST_PERCENTAGE' => $value->CGST_PERCENTAGE,
                    'SGST_PERCENTAGE' => $value->SGST_PERCENTAGE,

                    'server_date_time'=>date('Y-m-d H:i:s')
                ];

            }
            $collected = collect($itemtran_body_array);
            $chunked_array = $collected->chunk(1000);
            foreach ($chunked_array as  $value) {

                $ITEMTRAN_BODY_display = DB::table('ITEMTRAN_BODY')
                                    ->insert($value->toArray());

                if($ITEMTRAN_BODY_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];
                        $out['SLNO'] = $r_v['SLNO'];
                        $ITEMTRAN_BODY_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ITEMTRAN_BODY_display)
            {
                DB::commit();
                $ready_to_send['ITEMTRAN_BODY']=$ITEMTRAN_BODY_arr;
                $return_array['ITEMTRAN_BODY'] = 'ITEMTRAN_BODY table total records '.COUNT($itemtran_body_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ITEMTRAN_BODY'] = 'Error in ITEMTRAN_BODY table';

            }
        }

        if(!empty($json_decode_data->response->itemtran_head) && COUNT($json_decode_data->response->itemtran_head)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->itemtran_head as $key => $value) {
                $check = DB::table('ITEMTRAN_HEAD')
                                ->where('ITEMTRAN_HEAD.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEMTRAN_HEAD.DIV_CODE', $value->DIV_CODE)
                                ->where('ITEMTRAN_HEAD.TCODE', $value->TCODE)
                                ->where('ITEMTRAN_HEAD.VRNO', $value->VRNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ITEMTRAN_HEAD')
                                ->where('ITEMTRAN_HEAD.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ITEMTRAN_HEAD.DIV_CODE', $value->DIV_CODE)
                                ->where('ITEMTRAN_HEAD.TCODE', $value->TCODE)
                                ->where('ITEMTRAN_HEAD.VRNO', $value->VRNO)
                                ->delete();
                }

                $itemtran_head_array[] = [

                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'DIV_CODE' => $value->DIV_CODE,
                    'VRNO' => $value->VRNO,
                    'VRDATE' => $value->VRDATE,

                    'VRDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->VRDATE))),

                    'TCODE' => $value->TCODE,
                    'ACC_CODE' => $value->ACC_CODE,
                    'CRAMT' => $value->CRAMT,
                    'DRAMT' => $value->DRAMT,
                    'VALRECD' => $value->VALRECD,
                    'VALISSUED' => $value->VALISSUED,
                    'DUEDATE' => $value->DUEDATE,
                    'DUEDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->DUEDATE))),

                    'BROKER_CODE' => $value->BROKER_CODE,
                    'COST_CODE' => $value->COST_CODE,
                    'CONSIGNEE_CODE' => $value->CONSIGNEE_CODE,
                    'STAX_CODE' => $value->STAX_CODE,
                    'ETAX_CODE' => $value->ETAX_CODE,
                    'DEPT_CODE' => $value->DEPT_CODE,
                    'APP_REMARK' => $value->APP_REMARK,
                    'APPROVEDBY' => $value->APPROVEDBY,
                    'APPROVEDDATE' => $value->APPROVEDDATE,
                    'APPROVEDDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->APPROVEDDATE))),

                    'VALUATIONBY' => $value->VALUATIONBY,
                    'VALUATIONDATE' => $value->VALUATIONDATE,
                    'VALUATIONDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->VALUATIONDATE))),

                    'TOKENNO' => $value->TOKENNO,
                    'TOKENDATE' => $value->TOKENDATE,
                    'TOKENDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->TOKENDATE))),

                    'ORDER_VRNO' => $value->ORDER_VRNO,
                    'ACC_VRNO' => $value->ACC_VRNO,
                    'ACC_SLNO' => $value->ACC_SLNO,
                    'ACC_TCODE' => $value->ACC_TCODE,
                    'FREIGHT_BASIS' => $value->FREIGHT_BASIS,
                    'FREIGHT_RATE' => $value->FREIGHT_RATE,
                    'FREIGHT_ADV' => $value->FREIGHT_ADV,
                    'FREIGHT_AMT' => $value->FREIGHT_AMT,
                    'FREIGHT_CREDIT' => $value->FREIGHT_CREDIT,
                    'TRPT_CODE' => $value->TRPT_CODE,
                    'TRUCKNO' => $value->TRUCKNO,
                    'LRNO' => $value->LRNO,
                    'LRDATE' => $value->LRDATE,
                    'LRDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->LRDATE))),

                    'FROM_PLACE' => $value->FROM_PLACE,
                    'TO_PLACE' => $value->TO_PLACE,
                    'PARTYBILLNO' => $value->PARTYBILLNO,
                    'PARTYBILLAMT' => $value->PARTYBILLAMT,
                    'PARTYBILLDATE' => $value->PARTYBILLDATE,
                    'PARTYBILLDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->PARTYBILLDATE))),

                    'CHALLANNO' => $value->CHALLANNO,
                    'CHALLANDATE' => $value->CHALLANDATE,
                    'CHALLANDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->CHALLANDATE))),

                    'CASHPARTY' => $value->CASHPARTY,
                    'OLD_VRNO' => $value->OLD_VRNO,
                    'TRPTADVICEBY' => $value->TRPTADVICEBY,
                    'TRPTADVICEDATE' => $value->TRPTADVICEDATE,
                    'TRPTADVICEDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->TRPTADVICEDATE))),

                    'TRPTBILLBY' => $value->TRPTBILLBY,
                    'TRPTBILLDATE' => $value->TRPTBILLDATE,
                    'TRPTBILLDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->TRPTBILLDATE))),

                    'TRPT_VRNO' => $value->TRPT_VRNO,
                    'TRPT_SLNO' => $value->TRPT_SLNO,
                    'TRPT_TCODE' => $value->TRPT_TCODE,
                    'MODVAT_AVAILED' => $value->MODVAT_AVAILED,
                    'MODVAT_FLAG' => $value->MODVAT_FLAG,
                    'NAFIELD1' => $value->NAFIELD1,
                    'NAFIELD2' => $value->NAFIELD2,
                    'NAFIELD3' => $value->NAFIELD3,
                    'NAFIELD4' => $value->NAFIELD4,
                    'NAFIELD5' => $value->NAFIELD5,
                    'AFIELD1' => $value->AFIELD1,
                    'AFIELD2' => $value->AFIELD2,
                    'AFIELD3' => $value->AFIELD3,
                    'AFIELD4' => $value->AFIELD4,
                    'AFIELD5' => $value->AFIELD5,
                    'AFIELD6' => $value->AFIELD6,
                    'AFIELD7' => $value->AFIELD7,
                    'AFIELD8' => $value->AFIELD8,
                    'AFIELD9' => $value->AFIELD9,
                    'AFIELD10' => $value->AFIELD10,
                    'AFIELD11' => $value->AFIELD11,
                    'AFIELD12' => $value->AFIELD12,
                    'AFIELD13' => $value->AFIELD13,
                    'AFIELD14' => $value->AFIELD14,
                    'AFIELD15' => $value->AFIELD15,
                    'AFIELD16' => $value->AFIELD16,
                    'AFIELD17' => $value->AFIELD17,
                    'AFIELD18' => $value->AFIELD18,
                    'AFRATE2' => $value->AFRATE2,
                    'AFRATE3' => $value->AFRATE3,
                    'AFRATE4' => $value->AFRATE4,
                    'AFRATE5' => $value->AFRATE5,
                    'AFRATE6' => $value->AFRATE6,
                    'AFRATE7' => $value->AFRATE7,
                    'AFRATE8' => $value->AFRATE8,
                    'AFRATE9' => $value->AFRATE9,
                    'AFRATE10' => $value->AFRATE10,
                    'AFRATE11' => $value->AFRATE11,
                    'AFRATE12' => $value->AFRATE12,
                    'AFRATE13' => $value->AFRATE13,
                    'AFRATE14' => $value->AFRATE14,
                    'AFRATE15' => $value->AFRATE15,
                    'AFRATE16' => $value->AFRATE16,
                    'AFRATE17' => $value->AFRATE17,
                    'AFRATE18' => $value->AFRATE18,
                    'AFRATEI2' => $value->AFRATEI2,
                    'AFRATEI3' => $value->AFRATEI3,
                    'AFRATEI4' => $value->AFRATEI4,
                    'AFRATEI5' => $value->AFRATEI5,
                    'AFRATEI6' => $value->AFRATEI6,
                    'AFRATEI7' => $value->AFRATEI7,
                    'AFRATEI8' => $value->AFRATEI8,
                    'AFRATEI9' => $value->AFRATEI9,
                    'AFRATEI10' => $value->AFRATEI10,
                    'AFRATEI11' => $value->AFRATEI11,
                    'AFRATEI12' => $value->AFRATEI12,
                    'AFRATEI13' => $value->AFRATEI13,
                    'AFRATEI14' => $value->AFRATEI14,
                    'AFRATEI15' => $value->AFRATEI15,
                    'AFRATEI16' => $value->AFRATEI16,
                    'AFRATEI17' => $value->AFRATEI17,
                    'AFRATEI18' => $value->AFRATEI18,
                    'IRFIELD1' => $value->IRFIELD1,
                    'IRFIELD2' => $value->IRFIELD2,
                    'IRFIELD3' => $value->IRFIELD3,
                    'IRFIELD4' => $value->IRFIELD4,
                    'IRFIELD5' => $value->IRFIELD5,
                    'IRFIELD6' => $value->IRFIELD6,
                    'IRFIELD7' => $value->IRFIELD7,
                    'IRFIELD8' => $value->IRFIELD8,
                    'IRFIELD9' => $value->IRFIELD9,
                    'IRFIELD10' => $value->IRFIELD10,
                    'IRFIELD11' => $value->IRFIELD11,
                    'IRFIELD12' => $value->IRFIELD12,
                    'IRFIELD13' => $value->IRFIELD13,
                    'IRFIELD14' => $value->IRFIELD14,
                    'IRFIELD15' => $value->IRFIELD15,
                    'USER_CODE' => $value->USER_CODE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'LASTUPDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->LASTUPDATE))),

                    'FLAG' => $value->FLAG,
                    'DO_VRNO' => $value->DO_VRNO,
                    'SITE_DAYS' => $value->SITE_DAYS,
                    'KHATA_VRNO' => $value->KHATA_VRNO,
                    'KHATA_SLNO' => $value->KHATA_SLNO,
                    'KHATA_TCODE' => $value->KHATA_TCODE,
                    'GODOWN_CODE' => $value->GODOWN_CODE,
                    'PARTYREFNO' => $value->PARTYREFNO,
                    'PARTYREFDATE' => $value->PARTYREFDATE,
                    'PARTYREFDATE_FILTER' => date('Y-m-d',strtotime(strtolower($value->PARTYREFDATE))),

                    'RATE' => $value->RATE,
                    'EXPENSE_CODE' => $value->EXPENSE_CODE,
                    'QTYISSUED' => $value->QTYISSUED,
                    'UM' => $value->UM,
                    'QTYRECD' => $value->QTYRECD,
                    'FORM_GIVE' => $value->FORM_GIVE,
                    'FORM_TAKE' => $value->FORM_TAKE,
                    'STAX_RATE' => $value->STAX_RATE,
                    'EXCISE_RATE' => $value->EXCISE_RATE,
                    'CESS_RATE' => $value->CESS_RATE,
                    'EXCISE_TYPE' => $value->EXCISE_TYPE,
                    'VAT_DEDUCTION_RATE' => $value->VAT_DEDUCTION_RATE,
                    'VAIDYA_CODE' => $value->VAIDYA_CODE,
                    'SR_CODE' => $value->SR_CODE,
                    'NAFIELD6' => $value->NAFIELD6,
                    'MRPVALISSUED' => $value->MRPVALISSUED,
                    'SHIP_ADD_CODE' => $value->SHIP_ADD_CODE,

                    'server_date_time'=>date('Y-m-d H:i:s')
                ];

            }
            $collected = collect($itemtran_head_array);

            $chunked_array = $collected->chunk(300);

            foreach ($chunked_array as  $value){

                $ITEMTRAN_HEAD_display = DB::table('ITEMTRAN_HEAD')
                                    ->insert($value->toArray());

                if($ITEMTRAN_HEAD_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];
                        $ITEMTRAN_HEAD_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ITEMTRAN_HEAD_display)
            {
                DB::commit();
                $ready_to_send['ITEMTRAN_HEAD']=$ITEMTRAN_HEAD_arr;
                $return_array['ITEMTRAN_HEAD'] = 'ITEMTRAN_HEAD table total records '.COUNT($itemtran_head_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ITEMTRAN_HEAD'] = 'Error in ITEMTRAN_HEAD table';

            }
        }

        if(!empty($json_decode_data->response->order_do) && COUNT($json_decode_data->response->order_do)>0)
        {

            DB::beginTransaction();
            $check = [];
            foreach ($json_decode_data->response->order_do as $key => $value) {

                $check = DB::table('ORDER_DO')
                                ->where('ORDER_DO.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_DO.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_DO.TCODE', $value->TCODE)
                                ->where('ORDER_DO.VRNO', $value->VRNO)
                                ->where('ORDER_DO.DO_VRNO', $value->DO_VRNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ORDER_DO')
                                ->where('ORDER_DO.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_DO.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_DO.TCODE', $value->TCODE)
                                ->where('ORDER_DO.VRNO', $value->VRNO)
                                ->where('ORDER_DO.DO_VRNO', $value->DO_VRNO)
                                ->delete();
                }

                $ORDER_DO_array[] = [
                    'RELEASEDDATE' => $value->RELEASEDDATE,
                    'RELEASEDDATE_FILTER' => date('Y-m-d',strtotime($value->RELEASEDDATE)),
                    'LRDATE' => $value->LRDATE,
                    'LRDATE_FILTER' => date('Y-m-d',strtotime($value->LRDATE)),
                    'BILLDELETEDDATE' => $value->BILLDELETEDDATE,
                    'APPROVEDDATE' => $value->APPROVEDDATE,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'DO_VRDATE_FILTER' => date('Y-m-d',strtotime($value->DO_VRDATE)),
                    'DO_VRDATE' => $value->DO_VRDATE,
                    'SUSPENDDATE' => $value->SUSPENDDATE,
                    'SUSPENDDATE_FILTER' => date('Y-m-d',strtotime($value->SUSPENDDATE)),
                    'CANCELLEDDATE' => $value->CANCELLEDDATE,
                    'CANCELLEDDATE_FILTER' => date('Y-m-d',strtotime($value->CANCELLEDDATE)),
                    'BILLING_FLAG' => $value->BILLING_FLAG,
                    'SITE_DAYS' => $value->SITE_DAYS,
                    'STAX_RATE' => $value->STAX_RATE,
                    'CBD_FLAG' => $value->CBD_FLAG,
                    'FLAG' => $value->FLAG,
                    'TCODE' => $value->TCODE,
                    'DO_VRNO' => $value->DO_VRNO,
                    'VRNO' => $value->VRNO,
                    'CHALLAN_VRNO' => $value->CHALLAN_VRNO,
                    'PLANT_CODE' => $value->PLANT_CODE,
                    'DIV_CODE' => $value->DIV_CODE,
                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'LRNO' => $value->LRNO,
                    'REMARK' => $value->REMARK,
                    'BILL_CATG' => $value->BILL_CATG,
                    'BILLDELETEDBY' => $value->BILLDELETEDBY,
                    'APPROVEDBY' => $value->APPROVEDBY,
                    'RELEASEDBY' => $value->RELEASEDBY,
                    'SUSPENDEDBY' => $value->SUSPENDEDBY,
                    'CANCELLEDBY' => $value->CANCELLEDBY,
                    'USER_CODE' => $value->USER_CODE,
                    'server_date_time'=>date('Y-m-d H:i:s'),
                ];

            }
            $collected = collect($ORDER_DO_array);
            $chunked_array = $collected->chunk(700);
            foreach ($chunked_array as  $value) {

                $ORDER_DO_display = DB::table('ORDER_DO')
                                    ->insert($value->toArray());

                if($ORDER_DO_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];
                        $out['DO_VRNO'] = $r_v['DO_VRNO'];
                        $ORDER_DO_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ORDER_DO_display)
            {
                DB::commit();
                $ready_to_send['ORDER_DO']=$ORDER_DO_arr;
                $return_array['ORDER_DO'] = 'ORDER_DO table total records '.COUNT($ORDER_DO_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ORDER_DO'] = 'Error in ORDER_DO table';

            }
        }

        if(!empty($json_decode_data->response->order_body) && COUNT($json_decode_data->response->order_body)>0)
        {

            DB::beginTransaction();
            $check = [];

            foreach ($json_decode_data->response->order_body as $key => $value) {

                $check = DB::table('ORDER_BODY')
                                ->where('ORDER_BODY.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_BODY.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_BODY.TCODE', $value->TCODE)
                                ->where('ORDER_BODY.VRNO', $value->VRNO)
                                ->where('ORDER_BODY.SLNO', $value->SLNO)
                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ORDER_BODY')
                                ->where('ORDER_BODY.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_BODY.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_BODY.TCODE', $value->TCODE)
                                ->where('ORDER_BODY.VRNO', $value->VRNO)
                                ->where('ORDER_BODY.SLNO', $value->SLNO)
                                ->delete();
                }

                $ORDER_BODY_array[] = [
                    'ENTITY_CODE'=>$value->ENTITY_CODE,
                    'DIV_CODE'=>$value->DIV_CODE,
                    'TCODE'=>$value->TCODE,
                    'VRNO'=>$value->VRNO,
                    'DO_VRNO'=>$value->DO_VRNO,
                    'SLNO'=>$value->SLNO,
                    'ITEM_CODE'=>$value->ITEM_CODE,
                    'UM'=>$value->UM,
                    'PLANT_CODE'=>$value->PLANT_CODE,
                    'FROM_DATE'=>$value->FROM_DATE,
                    'FROM_DATE_FILTER'=>date('Y-m-d',strtotime($value->FROM_DATE)),
                    'TO_DATE'=>$value->TO_DATE,
                    'TO_DATE_FILTER'=>date('Y-m-d',strtotime($value->TO_DATE)),
                    'DUEDATE'=>$value->DUEDATE,
                    'DUEDATE_FILTER'=>date('Y-m-d',strtotime($value->DUEDATE)),
                    'RATE'=>$value->RATE,
                    'CRAMT'=>$value->CRAMT,
                    'DRAMT'=>$value->DRAMT,
                    'VALRECD'=>$value->VALRECD,
                    'VALISSUED'=>$value->VALISSUED,
                    'PARTYQTY'=>$value->PARTYQTY,
                    'QTYORDER'=>$value->QTYORDER,
                    'QTYFREE'=>$value->QTYFREE,
                    'QTYAMEND'=>$value->QTYAMEND,
                    'QTYEXECUTE'=>$value->QTYEXECUTE,
                    'QTYPACK'=>$value->QTYPACK,
                    'QTYCANCELLED'=>$value->QTYCANCELLED,
                    'QTYPERMIT'=>$value->QTYPERMIT,
                    'ADVANCEAMT'=>$value->ADVANCEAMT,
                    'REMARK'=>$value->REMARK,
                    'EQPT_CODE'=>$value->EQPT_CODE,
                    'MAKE_CODE'=>$value->MAKE_CODE,
                    'NAFIELD1'=>$value->NAFIELD1,
                    'NAFIELD2'=>$value->NAFIELD2,
                    'NAFIELD3'=>$value->NAFIELD3,
                    'NAFIELD4'=>$value->NAFIELD4,
                    'NAFIELD5'=>$value->NAFIELD5,
                    'AFIELD1'=>$value->AFIELD1,
                    'AFIELD2'=>$value->AFIELD2,
                    'AFIELD3'=>$value->AFIELD3,
                    'AFIELD4'=>$value->AFIELD4,
                    'AFIELD5'=>$value->AFIELD5,
                    'AFIELD6'=>$value->AFIELD6,
                    'AFIELD7'=>$value->AFIELD7,
                    'AFIELD8'=>$value->AFIELD8,
                    'AFIELD9'=>$value->AFIELD9,
                    'AFIELD10'=>$value->AFIELD10,
                    'AFIELD11'=>$value->AFIELD11,
                    'AFIELD12'=>$value->AFIELD12,
                    'AFIELD13'=>$value->AFIELD13,
                    'AFIELD14'=>$value->AFIELD14,
                    'AFIELD15'=>$value->AFIELD15,
                    'AFIELD16'=>$value->AFIELD16,
                    'AFIELD17'=>$value->AFIELD17,
                    'AFIELD18'=>$value->AFIELD18,
                    'INDENT_VRNO'=>$value->INDENT_VRNO,
                    'INDENT_SLNO'=>$value->INDENT_SLNO,
                    'SCHEME_NO'=>$value->SCHEME_NO,
                    'SCHEME_SLNO'=>$value->SCHEME_SLNO,
                    'ENQUIRY_VRNO'=>$value->ENQUIRY_VRNO,
                    'ENQUIRY_SLNO'=>$value->ENQUIRY_SLNO,
                    'QUOTATION_VRNO'=>$value->QUOTATION_VRNO,
                    'QUOTATION_SLNO'=>$value->QUOTATION_SLNO,
                    'FLAG'=>$value->FLAG,
                    'VRDATE'=>$value->VRDATE,
                    'VRDATE_FILTER'=>date('Y-m-d',strtotime($value->VRDATE)),
                    'AUM'=>$value->AUM,
                    'APARTYQTY'=>$value->APARTYQTY,
                    'QTYCANCELLED_FLAG'=>$value->QTYCANCELLED_FLAG,
                    'IRATE'=>$value->IRATE,
                    'SITE_DAYS'=>$value->SITE_DAYS,
                    'LOT_RATE_FLAG'=>$value->LOT_RATE_FLAG,
                    'NPOR_FLAG'=>$value->NPOR_FLAG,
                    'AQTYFREE'=>$value->AQTYFREE,
                    'REBOOK_VRNO'=>$value->REBOOK_VRNO,
                    'REBOOK_SLNO'=>$value->REBOOK_SLNO,
                    'TI_RATE'=>$value->TI_RATE,
                    'GODOWN_CODE'=>$value->GODOWN_CODE,
                    'AORDERQTY'=>$value->AORDERQTY,
                    'ARATE'=>$value->ARATE,
                    'AQDECIMAL'=>$value->AQDECIMAL,
                    'AUMTOUM'=>$value->AUMTOUM,
                    'FROM_DIV_CODE'=>$value->FROM_DIV_CODE,
                    'DELIVERY_REMARK'=>$value->DELIVERY_REMARK,
                    'server_date_time'=>date('Y-m-d H:i:s'),
                ];

            }
            $collected = collect($ORDER_BODY_array);
            $chunked_array = $collected->chunk(500);
            foreach ($chunked_array as  $value) {

                $ORDER_BODY_display = DB::table('ORDER_BODY')
                                    ->insert($value->toArray());

                if($ORDER_BODY_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];
                        $out['SLNO'] = $r_v['SLNO'];
                        $ORDER_BODY_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ORDER_BODY_display)
            {
                DB::commit();
                $ready_to_send['ORDER_BODY']=$ORDER_BODY_arr;
                $return_array['ORDER_BODY'] = 'ORDER_BODY table total records '.COUNT($ORDER_BODY_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ORDER_BODY'] = 'Error in ORDER_BODY table';

            }
        }

        if(!empty($json_decode_data->response->order_head) && COUNT($json_decode_data->response->order_head)>0)
        {

            DB::beginTransaction();
            $check = [];

            foreach ($json_decode_data->response->order_head as $key => $value) {

                $check = DB::table('ORDER_HEAD')
                                ->where('ORDER_HEAD.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_HEAD.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_HEAD.TCODE', $value->TCODE)
                                ->where('ORDER_HEAD.VRNO', $value->VRNO)

                                ->get();
                if(count($check)>0){
                   $delete_row = DB::table('ORDER_HEAD')
                                ->where('ORDER_HEAD.ENTITY_CODE', $value->ENTITY_CODE)
                                ->where('ORDER_HEAD.DIV_CODE', $value->DIV_CODE)
                                ->where('ORDER_HEAD.TCODE', $value->TCODE)
                                ->where('ORDER_HEAD.VRNO', $value->VRNO)

                                ->delete();
                }

                $ORDER_HEAD_array[] = [
                    'ENTITY_CODE' => $value->ENTITY_CODE,
                    'DIV_CODE' => $value->DIV_CODE,
                    'TCODE' => $value->TCODE,
                    'VRNO' => $value->VRNO,
                    'VRDATE' => $value->VRDATE,
                    'VRDATE_FILTER' => date('Y-m-d',strtotime($value->VRDATE)),
                    'ACC_CODE' => $value->ACC_CODE,
                    'CRAMT' => $value->CRAMT,
                    'DRAMT' => $value->DRAMT,
                    'VALRECD' => $value->VALRECD,
                    'VALISSUED' => $value->VALISSUED,
                    'DUEDATE' => $value->DUEDATE,
                    'DUEDATE_FILTER' => date('Y-m-d',strtotime($value->DUEDATE)),
                    'PARTYREFNO' => $value->PARTYREFNO,
                    'PARTYREFDATE' => $value->PARTYREFDATE,
                    'PARTYREFDATE_FILTER' => date('Y-m-d',strtotime($value->PARTYREFDATE)),
                    'SALE_TYPE' => $value->SALE_TYPE,
                    'STAX_CODE' => $value->STAX_CODE,
                    'ETAX_CODE' => $value->ETAX_CODE,
                    'CONSIGNEE_CODE' => $value->CONSIGNEE_CODE,
                    'BROKER_CODE' => $value->BROKER_CODE,
                    'TRPT_CODE' => $value->TRPT_CODE,
                    'FREIGHT_BASIS' => $value->FREIGHT_BASIS,
                    'FREIGHT_RATE' => $value->FREIGHT_RATE,
                    'FROM_PLACE' => $value->FROM_PLACE,
                    'TRUCKTYPE' => $value->TRUCKTYPE,
                    'TO_PLACE' => $value->TO_PLACE,
                    'ADVANCERECD' => $value->ADVANCERECD,
                    'APP_REMARK' => $value->APP_REMARK,
                    'APPROVEDBY' => $value->APPROVEDBY,
                    'APPROVEDDATE' => $value->APPROVEDDATE,
                    'APPROVEDDATE_FILTER' => date('Y-m-d',strtotime($value->APPROVEDDATE)),
                    'OLD_VRNO' => $value->OLD_VRNO,
                    'NAFIELD1' => $value->NAFIELD1,
                    'NAFIELD2' => $value->NAFIELD2,
                    'NAFIELD3' => $value->NAFIELD3,
                    'NAFIELD4' => $value->NAFIELD4,
                    'NAFIELD5' => $value->NAFIELD5,
                    'AFIELD1' => $value->AFIELD1,
                    'AFIELD2' => $value->AFIELD2,
                    'AFIELD3' => $value->AFIELD3,
                    'AFIELD4' => $value->AFIELD4,
                    'AFIELD5' => $value->AFIELD5,
                    'AFIELD6' => $value->AFIELD6,
                    'AFIELD7' => $value->AFIELD7,
                    'AFIELD8' => $value->AFIELD8,
                    'AFIELD9' => $value->AFIELD9,
                    'AFIELD10' => $value->AFIELD10,
                    'AFIELD11' => $value->AFIELD11,
                    'AFIELD12' => $value->AFIELD12,
                    'AFIELD13' => $value->AFIELD13,
                    'AFIELD14' => $value->AFIELD14,
                    'AFIELD15' => $value->AFIELD15,
                    'AFIELD16' => $value->AFIELD16,
                    'AFIELD17' => $value->AFIELD17,
                    'AFIELD18' => $value->AFIELD18,
                    'AFRATE2' => $value->AFRATE2,
                    'AFRATE3' => $value->AFRATE3,
                    'AFRATE4' => $value->AFRATE4,
                    'AFRATE5' => $value->AFRATE5,
                    'AFRATE6' => $value->AFRATE6,
                    'AFRATE7' => $value->AFRATE7,
                    'AFRATE8' => $value->AFRATE8,
                    'AFRATE9' => $value->AFRATE9,
                    'AFRATE10' => $value->AFRATE10,
                    'AFRATE11' => $value->AFRATE11,
                    'AFRATE12' => $value->AFRATE12,
                    'AFRATE13' => $value->AFRATE13,
                    'AFRATE14' => $value->AFRATE14,
                    'AFRATE15' => $value->AFRATE15,
                    'AFRATE16' => $value->AFRATE16,
                    'AFRATE17' => $value->AFRATE17,
                    'AFRATE18' => $value->AFRATE18,
                    'AFRATEI2' => $value->AFRATEI2,
                    'AFRATEI3' => $value->AFRATEI3,
                    'AFRATEI4' => $value->AFRATEI4,
                    'AFRATEI5' => $value->AFRATEI5,
                    'AFRATEI6' => $value->AFRATEI6,
                    'AFRATEI7' => $value->AFRATEI7,
                    'AFRATEI8' => $value->AFRATEI8,
                    'AFRATEI9' => $value->AFRATEI9,
                    'AFRATEI10' => $value->AFRATEI10,
                    'AFRATEI11' => $value->AFRATEI11,
                    'AFRATEI12' => $value->AFRATEI12,
                    'AFRATEI13' => $value->AFRATEI13,
                    'AFRATEI14' => $value->AFRATEI14,
                    'AFRATEI15' => $value->AFRATEI15,
                    'AFRATEI16' => $value->AFRATEI16,
                    'AFRATEI17' => $value->AFRATEI17,
                    'AFRATEI18' => $value->AFRATEI18,
                    'IRFIELD1' => $value->IRFIELD1,
                    'IRFIELD2' => $value->IRFIELD2,
                    'IRFIELD3' => $value->IRFIELD3,
                    'IRFIELD4' => $value->IRFIELD4,
                    'IRFIELD5' => $value->IRFIELD5,
                    'IRFIELD6' => $value->IRFIELD6,
                    'IRFIELD7' => $value->IRFIELD7,
                    'IRFIELD8' => $value->IRFIELD8,
                    'IRFIELD9' => $value->IRFIELD9,
                    'IRFIELD10' => $value->IRFIELD10,
                    'IRFIELD11' => $value->IRFIELD11,
                    'IRFIELD12' => $value->IRFIELD12,
                    'IRFIELD13' => $value->IRFIELD13,
                    'IRFIELD14' => $value->IRFIELD14,
                    'IRFIELD15' => $value->IRFIELD15,
                    'LASTUPDATE' => $value->LASTUPDATE,
                    'USER_CODE' => $value->USER_CODE,
                    'FLAG' => $value->FLAG,
                    'PARTYCONTACTNO' => $value->PARTYCONTACTNO,
                    'PARTYCONTACTDATE' => $value->PARTYCONTACTDATE,
                    'SR_CODE' => $value->SR_CODE,
                    'SS_CODE' => $value->SS_CODE,
                    'ASM_CODE' => $value->ASM_CODE,
                    'RM_CODE' => $value->RM_CODE,
                    'MM_CODE' => $value->MM_CODE,
                    'WITHFREE_FLAG' => $value->WITHFREE_FLAG,
                    'REBOOK_FLAG' => $value->REBOOK_FLAG,
                    'COST_CODE' => $value->COST_CODE,
                    'FILE_CODE' => $value->FILE_CODE,
                    'EFFECTIVEDATE' => $value->EFFECTIVEDATE,
                    'SCHEME_FLAG' => $value->SCHEME_FLAG,
                    'server_date_time'=>date('Y-m-d H:i:s'),
                ];

            }
            $collected = collect($ORDER_HEAD_array);
            $chunked_array = $collected->chunk(500);
            foreach ($chunked_array as  $value) {

                $ORDER_HEAD_display = DB::table('ORDER_HEAD')
                                    ->insert($value->toArray());

                if($ORDER_HEAD_display)
                {
                    DB::commit();
                    $out = [];
                    foreach ($value as $r_k => $r_v) {

                        $out['ENTITY_CODE'] = $r_v['ENTITY_CODE'];
                        $out['DIV_CODE'] = $r_v['DIV_CODE'];
                        $out['TCODE'] = $r_v['TCODE'];
                        $out['VRNO'] = $r_v['VRNO'];

                        $ORDER_HEAD_arr[] = $out;
                    }
                }
                else
                {
                    DB::rollback();

                }

            }

            if($ORDER_HEAD_display)
            {
                DB::commit();
                $ready_to_send['ORDER_HEAD']=$ORDER_HEAD_arr;
                $return_array['ORDER_HEAD'] = 'ORDER_HEAD table total records '.COUNT($ORDER_HEAD_array).' inserted succesfully';
            }
            else
            {
                DB::rollback();
                $return_array['ORDER_HEAD'] = 'Error in ORDER_HEAD table';

            }
        }

        if(COUNT($return_array)>0)
        {
            return response()->json([ 'response' =>True,'Sync_status'=>$return_array,'ready_to_send'=>$ready_to_send,'Sync_date_time'=>date('Y-m-d H:i:s')]);
        }
        else
        {
            return response()->json([ 'response' =>False,'Sync_status'=>'No Data Found from your local Server Please Check your internet or database existense !!','Hit_date_time'=>date('Y-m-d H:i:s'),'ready_to_send'=>array()]);
        }

    }

    public function count_details_server(Request $request){
       $new_array =  array('ACCNOTE_TRAN','ACC_MAST','CIRCULAR_MAST','DEALER_BILLTAX_MAST','DEALER_TARGET_MAST','ITEMTRAN_BODY','ITEMTRAN_HEAD','ITEM_AUM_MAST','ITEM_MAST','ITEM_RATE_MAST','MKTG_CATG_MAST','ORDER_DO','ORDER_HEAD','ORDER_BODY','PROD_CATG_MAST','PROD_MAST','PROD_TAX_MAST','SCHEME_DOMAIN','SCHEME_MAST','SCHEME_SLAB','WWW_WEB_ITEM_DISPLAY');

        $count = [];
        for ($i=0; $i < 21; $i++) {

            $count[$new_array[$i]] = DB::table($new_array[$i])
                                    ->select(DB::raw('count(*) as count'))
                                    ->first();
        }

        return response()->json([ 'response' =>True,'data'=>$count,'Sync_date_time'=>date('Y-m-d H:i:s')]);
    }

    public function erp_request_delete_status(Request $request){

       $table_name = $request->table_name;
       $new_array =  array('ACCNOTE_TRAN','ACC_MAST','CIRCULAR_MAST','DEALER_BILLTAX_MAST','DEALER_TARGET_MAST','ITEMTRAN_BODY','ITEMTRAN_HEAD','ITEM_AUM_MAST','ITEM_MAST','ITEM_RATE_MAST','MKTG_CATG_MAST','ORDER_DO','ORDER_HEAD','ORDER_BODY','PROD_CATG_MAST','PROD_MAST','PROD_TAX_MAST','SCHEME_DOMAIN','SCHEME_MAST','SCHEME_SLAB','WWW_WEB_ITEM_DISPLAY');
        if(isset($table_name)){
            if(in_array($table_name, $new_array)){
                $status = DB::table($table_name)->truncate();
            }
        }
        return response()->json([ 'response' =>True,'message'=>'success','Sync_date_time'=>date('Y-m-d H:i:s')]);
    }
}
