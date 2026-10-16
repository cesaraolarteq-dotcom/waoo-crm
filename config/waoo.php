<?php

return [
    // Empty until exact owner-approved TCPA wording/version is configured. No provider enrollment is performed.
    'sms_consent_versions' => [],
    'record_forms' => true,
    'sandbox_report_outbox' => env('WAOO_SANDBOX_REPORT_OUTBOX', false),
    'sandbox_finance' => env('WAOO_SANDBOX_FINANCE', false),
    'sandbox_documents' => env('WAOO_SANDBOX_DOCUMENTS', false),
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    'stripe_test_account' => env('STRIPE_TEST_ACCOUNT'),
];
