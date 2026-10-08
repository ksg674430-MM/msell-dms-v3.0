<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'irn' => [
    'url_auth'    => env('IRN_URL_AUTH',  'http://powergstservice.microvistatech.com/api/MVEINVAuthenticate/EINVAuthentication'),
    'url_generate'=> env('IRN_URL_GEN',   'http://powergstservice.microvistatech.com/api/MVEINVAuthenticate/EINVGeneration'),
    'mv_api_key'  => env('IRN_MV_API_KEY',  'IPSZfNmcQCUNMfx'),
    'mv_secret'   => env('IRN_MV_SECRET',   'NyWQEq+4YWungcL1hfzGQA=='),
    'test_gstin'  => env('IRN_TEST_GSTIN',  '24AAAPI3182M002'),
    'test_user'   => env('IRN_TEST_USER',   'test_24_001'),
    'test_pass'   => env('IRN_TEST_PASS',   'Trial63$value'),
    'test_mode'   => env('IRN_TEST_MODE',   true),
],

'dms_order' => [
    'url' => env('DMS_ORDER_SYNC_URL', 'http://c9erp.msell.in/public/api/submitOrderToErp'),
],

];
