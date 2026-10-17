<?php

return [
    // Empty until exact owner-approved TCPA wording/version is configured. No provider enrollment is performed.
    'sms_consent_versions' => [],
    'record_forms' => true,
    'sandbox_lead_intake' => env('WAOO_SANDBOX_LEAD_INTAKE', false),
    'lead_shared_secret' => env('WAOO_LEAD_SHARED_SECRET'),
    'sandbox_routes' => env('WAOO_SANDBOX_ROUTES', false),
    'google_routes_key' => env('WAOO_GOOGLE_ROUTES_KEY'),
    'sandbox_finance' => env('WAOO_SANDBOX_FINANCE', false),
    'sandbox_quotes' => env('WAOO_SANDBOX_QUOTES', false),
    'sandbox_documents' => env('WAOO_SANDBOX_DOCUMENTS', false),
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    'stripe_test_account' => env('STRIPE_TEST_ACCOUNT'),
];
