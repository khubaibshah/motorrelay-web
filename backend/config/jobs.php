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
