<?php

return [
    'users' => env('DB_EXPORT_USERS', ''),
    'max_bytes' => (int) env('DB_EXPORT_MAX_BYTES', 1073741824),
    'large_table_mb' => (int) env('DB_EXPORT_LARGE_TABLE_MB', 100),
    'large_table_rows' => (int) env('DB_EXPORT_LARGE_TABLE_ROWS', 1000),
    'list_cache_seconds' => (int) env('DB_EXPORT_LIST_CACHE_SECONDS', 300),
    'skip_tables' => [
        'personal_access_tokens',
        'sessions',
        'password_reset*',
        'oauth_*',
        '*_tokens',
        '*otp*',
        'failed_jobs',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
    ],
    'secret_columns' => '/(^|_)(pass|password|passwd|pwd|token|secret|otp|api_key|apikey)(_|$)/i',
];
