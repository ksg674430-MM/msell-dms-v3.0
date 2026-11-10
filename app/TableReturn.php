<?php

namespace App;
use DB;
use Lang;
use Illuminate\Database\Eloquent\Model;

class TableReturn extends Model
{
    protected $guarded = array();
    protected $table='person';

    public static function table_return($from_date,$to_date)
    {

        $current_date = date('Y-m-d');
        $current_month = date('Y-m');
        $current_year =  date('Y');
        $last_3_month = date('Y-m',strtotime('-3 month')); // 2021-04
        $last_6_month = date('Y-m',strtotime('-6 month')); // 2021-01
        $from_year = date('Y' ,  strtotime($from_date));
        $to_year =  date('Y' ,  strtotime($to_date));
        // dd($from_date);

        $custom_from_date = date('Y-m',strtotime($from_date));
        // dd($current_month);
        if($from_date == $current_date && $to_date == $current_date){

            $table = 'user_sales_order_1_day';
        }
        elseif($custom_from_date >= $current_month)
        {
            $table = 'user_sales_order_1_month';
        }
        elseif($custom_from_date >= $last_3_month)
        {
            // $table = 'user_sales_order';
            $table = 'user_sales_order_3_month';
        }
        elseif($custom_from_date >= $last_6_month)
        {
            // $table = 'user_sales_order';
            $table = 'user_sales_order_6_month';
        }
        else
        {
            if(($from_year == $current_year) && ($to_year == $current_year)){
                $table = 'user_sales_order_12_month';
            }
            else{
            $table = 'user_sales_order';
            }
        }
        // dd($table);
        return $table;
    }
    public static function tracking_table_return($from_date,$to_date)
    {

        // $current_date = date('Y-m-d');
        // $last_3_month = date('Y-m',strtotime('-3 month')); // 2021-04
        // $last_6_month = date('Y-m',strtotime('-6 month')); // 2021-01
        // $last_9_month = date('Y-m',strtotime('-9 month')); // 2021-01
        // $current_month = date('Y-m');
        // $previous_month = date('Y-m',strtotime('-1 month'));

        // // dd($last_6_month);

        // $custom_from_date = date('Y-m-d',strtotime($from_date));
        // $custom_month = date('Y-m',strtotime($from_date));
        // if($custom_from_date == $current_date)
        // {
        //     $table = 'user_work_tracking_1_day';
        // }elseif($custom_month == $current_month){
        //     $table = 'user_work_tracking_1_month';
        // }elseif($custom_from_date >= $previous_month)
        // {
        //     $table = 'user_work_tracking';
        // }
        // elseif($custom_from_date >= $last_3_month)
        // {
        //     $table = 'user_work_tracking_2023';
        // }
        // elseif($custom_from_date >= $last_6_month)
        // {
        //     // $table = 'user_sales_order';
        //     $table = 'user_work_tracking_2023';
        // }
        // elseif($custom_from_date >= $last_9_month)
        // {
        //     // $table = 'user_sales_order';
        //     $table = 'user_work_tracking_2023';
        // }
        // else
        // {
        //     $table = 'user_work_tracking_2023';
        // }
        //  //dd($table);
        $custom_from_date = date('Y-m-d', strtotime($from_date));
        $custom_month     = date('Y-m', strtotime($from_date));

        $today            = date('Y-m-d');
        $yesterday        = date('Y-m-d', strtotime('-1 day'));
        $last_month = date('Y-m', strtotime('first day of last month'));

        $current_month    = date('Y-m');

        // Today or Yesterday
        if ($custom_from_date == $today) {

            $table = 'user_work_tracking_1_day';

        }elseif($custom_from_date == $yesterday){
            $table = 'user_work_tracking_1_month';
        }
        // Current month OR last month (covers 2025-10, 2025-11)
        elseif ($custom_month == $current_month || $custom_month == $last_month) {

            $table = 'user_work_tracking_1_month';

        }
        elseif ($custom_from_date >= '2025-09-30') {

            $table = 'user_work_tracking_1_month';

        }
        // 2025 historical data (Jan to Sep)
        elseif ($custom_from_date >= '2025-01-01' && $custom_from_date <= '2025-09-30') {

            $table = 'user_work_tracking_2025';

        }
        // Before 2025
        elseif ($custom_from_date < '2025-01-01') {

            $table = 'user_work_tracking_2024';

        }

        return $table;
    }

    public static function attendance_table_return($from_date,$to_date)
    {

        $current_date = date('Y-m-d');
        $last_3_month = date('Y-m',strtotime('-3 month')); // 2021-04
        $last_6_month = date('Y-m',strtotime('-6 month')); // 2021-01
        $last_9_month = date('Y-m',strtotime('-9 month')); // 2021-01
        $current_month = date('Y-m');
        $previous_month = date('Y-m',strtotime('-1 month'));

        // dd($last_6_month);

        $custom_from_date = date('Y-m-d',strtotime($from_date));
        if($custom_from_date < '2024-01-01')
        {
            $table = 'user_daily_attendance_log';
        }
       
        else
        {
            $table = 'user_daily_attendance';
        }
         //dd($table);
        return $table;
    }
    public static function attendance_table_return_view($from_date,$to_date)
    {

        $current_date = date('Y-m-d');
        $last_3_month = date('Y-m',strtotime('-3 month')); // 2021-04
        $last_6_month = date('Y-m',strtotime('-6 month')); // 2021-01
        $last_9_month = date('Y-m',strtotime('-9 month')); // 2021-01
        $current_month = date('Y-m');
        $previous_month = date('Y-m',strtotime('-1 month'));

        // dd($last_6_month);

        $custom_from_date = date('Y-m-d',strtotime($from_date));
        if($custom_from_date < '2024-01-01')
        {
            $table = 'daily_attendance_view_new_log';
        }
       
        else
        {
            $table = 'daily_attendance_view_new';
        }
         //dd($table);
        return $table;
    }
    public static function sale_details_table_return($from_date,$to_date)
    {

        $current_date = date('Y-m-d');
        $last_3_month = date('Y-m',strtotime('-3 month')); // 2021-04
        $last_6_month = date('Y-m',strtotime('-6 month')); // 2021-01
        $last_9_month = date('Y-m',strtotime('-9 month')); // 2021-01
        $current_month = date('Y-m');
        $previous_month = date('Y-m',strtotime('-1 month'));

        // dd($last_6_month);

        $custom_from_date = date('Y-m-d',strtotime($from_date));
        // if($custom_from_date < '2024-01-01')
        // {
        //     $table = 'user_sales_order_details';
        // }
       
        // else
        // {
        // }
        $table = 'user_sales_order_details';
         //dd($table);
        return $table;
    }
}
