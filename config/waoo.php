<?php

return [
    // Empty until exact owner-approved TCPA wording/version is configured. No provider enrollment is performed.
    'sms_consent_versions' => [],
    'record_forms' => true,
    'sandbox_mail_bounces' => env('WAOO_SANDBOX_MAIL_BOUNCES', false),
    'postmark_bounce_username' => env('WAOO_POSTMARK_BOUNCE_USERNAME'),
    'postmark_bounce_password' => env('WAOO_POSTMARK_BOUNCE_PASSWORD'),
    'postmark_server_id' => env('WAOO_POSTMARK_SERVER_ID'),
    'postmark_message_stream' => env('WAOO_POSTMARK_MESSAGE_STREAM'),
    'sandbox_staff' => env('WAOO_SANDBOX_STAFF', false),
    'sandbox_mail' => env('WAOO_SANDBOX_MAIL', false),
    'mail_allowlist' => [],
    'mail_templates' => [], // Only reviewed owner-approved versions; never draft preview copy.
    'mail_review_url' => 'https://g.page/r/CVT57MD29OwXEAE/review', // Waoo Party's public Google review link (owner 2026-10-18).
    'sandbox_lead_intake' => env('WAOO_SANDBOX_LEAD_INTAKE', false),
    'lead_shared_secret' => env('WAOO_LEAD_SHARED_SECRET'),
    'sandbox_routes' => env('WAOO_SANDBOX_ROUTES', false),
    'google_routes_key' => env('WAOO_GOOGLE_ROUTES_KEY'),
    'sandbox_finance' => env('WAOO_SANDBOX_FINANCE', false),
    'sandbox_imports' => env('WAOO_SANDBOX_IMPORTS', false),
    'sandbox_quotes' => env('WAOO_SANDBOX_QUOTES', false),
    // Empty until the owner approves versioned, outcome-specific ES/EN decision wording.
    'quote_acceptance_consent' => [],
    'sandbox_documents' => env('WAOO_SANDBOX_DOCUMENTS', false),
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    'stripe_test_account' => env('STRIPE_TEST_ACCOUNT'),
];
