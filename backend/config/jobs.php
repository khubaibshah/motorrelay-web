<?php

return [
    'paid_plans' => [
        'gold_driver',
        'dealer_pro',
    ],

    'unlimited_test_accounts' => array_filter(array_map(
        'trim',
        explode(',', env('UNLIMITED_TEST_ACCOUNT_EMAILS', 'dealer@motorrelay.com,driver@motorrelay.com'))
    )),

    'pricing' => [
        'platform_fee_rate' => (float) env('JOB_PLATFORM_FEE_RATE', 0.1),
        'base_fee' => (float) env('JOB_PRICE_BASE_FEE', 35),
        'minimum_price' => (float) env('JOB_PRICE_MINIMUM', 75),
        'drive_away_rate_per_mile' => (float) env('JOB_PRICE_DRIVE_AWAY_PER_MILE', 1.5),
        'trailer_rate_per_mile' => (float) env('JOB_PRICE_TRAILER_PER_MILE', 2.5),
    ],

    'plan_limits' => [
        'starter' => [
            'monthly_job_posts' => 5,
            'daily_applications' => 3,
            'message_cooldown_hours' => 24,
            'max_expenses_per_job' => 10,
            'job_distance_radius' => 50,
        ],
    ],
];
