<?php

return [
    'google' => [
        'client_ids' => array_values(array_unique(array_filter([
            ...array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))),
            trim((string) env('GOOGLE_WEB_CLIENT_ID', '')),
            trim((string) env('GOOGLE_IOS_CLIENT_ID', '')),
            trim((string) env('GOOGLE_ANDROID_CLIENT_ID', '')),
        ]))),
    ],
    'apple' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],
    'lampyris' => [
        'google_client_ids' => array_values(array_unique(array_filter([
            ...array_map('trim', explode(',', (string) (env('LAMPYRIS_GOOGLE_CLIENT_IDS') ?: env('GOOGLE_CLIENT_IDS', '')))),
            trim((string) (env('LAMPYRIS_GOOGLE_WEB_CLIENT_ID') ?: env('GOOGLE_WEB_CLIENT_ID', ''))),
            trim((string) (env('LAMPYRIS_GOOGLE_IOS_CLIENT_ID') ?: env('GOOGLE_IOS_CLIENT_ID', ''))),
            trim((string) (env('LAMPYRIS_GOOGLE_ANDROID_CLIENT_ID') ?: env('GOOGLE_ANDROID_CLIENT_ID', ''))),
        ]))),
        'apple_client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('LAMPYRIS_APPLE_CLIENT_IDS', 'lampyris.qla.dev'))
        ))),
    ],
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions'),
    ],
    'revenuecat' => [
        'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
        'ios_api_key' => env('REVENUECAT_APPLE_API_KEY'),
        'android_api_key' => env('REVENUECAT_GOOGLE_API_KEY'),
        'offering_identifier' => env('REVENUECAT_OFFERING_IDENTIFIER', 'putni-nalozi'),
        'offering_rest_id' => env('REVENUECAT_OFFERING_REST_ID', 'ofrng6542b044e6'),
        'credit_products' => array_filter([
            env('REVENUECAT_STARTER_PRODUCT_ID') => 10,
            env('REVENUECAT_TEAM_PRODUCT_ID') => 25,
        ], fn ($value, $key) => is_string($key) && $key !== '', ARRAY_FILTER_USE_BOTH),
    ],
];
