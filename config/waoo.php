<?php

return [
    'record_forms' => true,
    'sandbox_finance' => env('WAOO_SANDBOX_FINANCE', false),
    'sandbox_documents' => env('WAOO_SANDBOX_DOCUMENTS', false),
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    'stripe_test_account' => env('STRIPE_TEST_ACCOUNT'),
];
