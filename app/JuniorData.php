<?php

namespace App;
use Session;
use DB;

use Illuminate\Database\Eloquent\Model;

class JuniorData extends Model
{
    //  for 371 in all app every where we need direct junior but from some places we need both that's why we take status key for handling , so please discuss with nirulas 
    public static function getJuniorUser($code,$company_id,$status =0) {

        $res1=""; $res2="";

        $detailsdata = DB::table('person')
            ->join('users','users.id','=','person.id')
            ->join('person_login','person_login.person_id','=','person.id')
            ->join('_role','_role.role_id','=','person.role_id')
            ->select('person.id as user_id','person.company_id as p_company_id','_role.role_group_id')
            ->where('person_id_senior',$code)
            //->where('_role.role_group_id','!=',56)
            ->where('person.company_id',$company_id)
            ->where('person_login.company_id',$company_id)
            ->where('person_id_senior','!=',0);
            if($company_id != 380){
                $detailsdata->where('person_status',1);
            }
            // ->where('is_admin',0)
        $details = $detailsdata->get();
        // dd($details);
            $num = count($details); 

            if($num>0) {

                foreach($details as $key=>$res2) {
                    if($res2->user_id!="" || $res2->user_id!="0") {
                        if ($res2->role_group_id != 56) {
                            Session::push('juniordata', $res2->user_id);
                        }
                        if ($company_id != 371) {
                            Self::getJuniorUser($res2->user_id,$res2->p_company_id);
                        }
                        if($status == 1){
                            Self::getJuniorUser($res2->user_id,$res2->p_company_id,1);
                        }
                        
                    }
                }    
            } else {
                foreach($details as $key1=>$res1) {
                    if($res1->user_id!="" || $res2->user_id!="0") {
                        Session::push('juniordata', $res1->user_id);
                    }
                }
                
            }
            // dd(Session::get('juniordata'))
            
            return 1;
    }


    // public function 
    public static function getSeniorUser($code,$company_id)
    {
    $res1="";
    $res2="";
    // dd($code);
    $detailsdata = DB::table('person')
        ->join('users','users.id','=','person.id')
        ->join('person_login','person_login.person_id','=','person.id')
        ->join('_role','_role.role_id','=','person.role_id')
        ->select('person_id_senior as user_id','person.company_id as p_company_id')
        ->where('person.id',$code)
        ->where('_role.role_group_id','!=',56)
        ->where('person.company_id',$company_id)
        ->where('person_login.company_id',$company_id)
        ->where('person_id_senior','!=',0)
        ->where('is_admin','!=',1);
        if($company_id != 380){
            $detailsdata->where('person_status',1);
        }
        $details = $detailsdata->get();
        $num = count($details);  
        if($num>0)
        {

            foreach($details as $key=>$res2)
            {
                if($res2->user_id!="" || $res2->user_id!="0")
                {
                    // dd($res2);
                    //$product = collect([1,2,3,4]);
                    Session::push('seniorData', $res2->user_id);
                   // $_SESSION['juniordata'][]=$res2->user_id;
                    Self::getSeniorUser($res2->user_id,$res2->p_company_id);
                }
            }
            
        }
        else
        {
            foreach($details as $key1=>$res1)
            {
                if($res1->user_id!="" || $res2->user_id!="0")
                {
                    Session::push('seniorData', $res1->user_id);
                    // $_SESSION['juniordata'][]= $res1->user_id;
                }
            }

            
        }
        // dd(Session::get('juniordata'))
        return 1;
    }

    public static function getgetActiveDeactiveSeniorUser($code,$company_id)
    {
    $res1="";
    $res2="";
    // dd($code);
    $details = DB::table('person')
        ->join('users','users.id','=','person.id')
        ->join('person_login','person_login.person_id','=','person.id')
        ->select('person_id_senior as user_id','person.company_id as p_company_id')
        ->where('person.id',$code)
        ->where('person.company_id',$company_id)
        ->where('person_login.company_id',$company_id)
        ->where('person_id_senior','!=',0)
        ->where('is_admin','!=',1)
        // ->where('person_status',1)
        ->get();
        $num = count($details);  
        if($num>0)
        {

            foreach($details as $key=>$res2)
            {
                if($res2->user_id!="" || $res2->user_id!="0")
                {
                    // dd($res2);
                    //$product = collect([1,2,3,4]);
                    Session::push('seniorData', $res2->user_id);
                   // $_SESSION['juniordata'][]=$res2->user_id;
                    Self::getgetActiveDeactiveSeniorUser($res2->user_id,$res2->p_company_id);
                }
            }
            
        }
        else
        {
            foreach($details as $key1=>$res1)
            {
                if($res1->user_id!="" || $res2->user_id!="0")
                {
                    Session::push('seniorData', $res1->user_id);
                    // $_SESSION['juniordata'][]= $res1->user_id;
                }
            }

            
        }
        // dd(Session::get('juniordata'))
        return 1;
    }


    public static function getActiveDeactiveJuniorUser($code,$company_id)
    {
        $res1="";
        $res2="";
        // dd($code);
        $details = DB::table('person')
            ->join('users','users.id','=','person.id')
            ->join('person_login','person_login.person_id','=','person.id')
            ->select('person.id as user_id','person.company_id as p_company_id')
            ->where('person_id_senior',$code)
            ->where('person.company_id',$company_id)
            ->where('person_login.company_id',$company_id)
            ->where('person_id_senior','!=',0)
            // ->where('is_admin',0)
            ->where('person_status','!=' , 9)
            ->get();
        // dd($details);
            $num = count($details);  
            if($num>0)
            {

                foreach($details as $key=>$res2)
                {
                    if($res2->user_id!="" || $res2->user_id!="0")
                    {
                        // dd($res2);
                        //$product = collect([1,2,3,4]);
                        Session::push('juniordata', $res2->user_id);
                       // $_SESSION['juniordata'][]=$res2->user_id;
                        Self::getActiveDeactiveJuniorUser($res2->user_id,$res2->p_company_id);
                    }
                }
                
            }
            else
            {
                foreach($details as $key1=>$res1)
                {
                    if($res1->user_id!="" || $res2->user_id!="0")
                    {
                        Session::push('juniordata', $res1->user_id);
                        // $_SESSION['juniordata'][]= $res1->user_id;
                    }
                }

                
            }
            // dd(Session::get('juniordata'))
            
            return 1;
    }

    public static function getJuniorUserForSenior($senior_id,$company_id)
    {
 
      $details = DB::table('person')
            ->join('users','users.id','=','person.id')
            ->join('person_login','person_login.person_id','=','person.id')
            ->join('_role','_role.role_id','=','person.role_id')
            ->select('person.id as user_id','person.company_id as p_company_id','_role.role_group_id')
            ->where('person_id_senior',$senior_id)
            ->where('person.company_id',$company_id)
            ->where('person_login.company_id',$company_id)
            ->where('person_id_senior','!=',0)
            ->where('person_status',1)
            ->pluck('user_id')->toArray();
         return $details;
    
    }
    
}
