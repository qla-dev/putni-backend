<?php

return [
    'google' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))
        ))),
    ],
    'apple' => [
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],
    'revenuecat' => [
        'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
        'ios_api_key' => env('REVENUECAT_APPLE_API_KEY'),
        'android_api_key' => env('REVENUECAT_GOOGLE_API_KEY'),
        'credit_products' => array_filter([
            env('REVENUECAT_STARTER_PRODUCT_ID') => 10,
            env('REVENUECAT_TEAM_PRODUCT_ID') => 25,
        ], fn ($value, $key) => is_string($key) && $key !== '', ARRAY_FILTER_USE_BOTH),
    ],
];
