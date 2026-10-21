<?php

$clientTexts = require __DIR__.'/client-texts.php';

return [
    // Exact owner-approved wording; no transport activation or provider enrollment.
    'sms_consent_versions' => [
        'client-texts-2026-10-20-sms-es' => $clientTexts['texts']['sms_consent']['es'],
        'client-texts-2026-10-20-sms-en' => $clientTexts['texts']['sms_consent']['en'],
    ],
    'record_forms' => true,
    'sandbox_mail_bounces' => env('WAOO_SANDBOX_MAIL_BOUNCES', false),
    'postmark_bounce_username' => env('WAOO_POSTMARK_BOUNCE_USERNAME'),
    'postmark_bounce_password' => env('WAOO_POSTMARK_BOUNCE_PASSWORD'),
    'postmark_server_id' => env('WAOO_POSTMARK_SERVER_ID'),
    'postmark_message_stream' => env('WAOO_POSTMARK_MESSAGE_STREAM'),
    'sandbox_mail' => env('WAOO_SANDBOX_MAIL', false),
    'mail_allowlist' => [],
    'mail_templates' => \App\Support\ApprovedClientTexts::mailDefinitions($clientTexts),
    'mail_review_url' => 'https://g.page/r/CVT57MD29OwXEAE/review', // Waoo Party's public Google review link (owner 2026-10-18).
    'sandbox_lead_intake' => env('WAOO_SANDBOX_LEAD_INTAKE', false),
    'lead_shared_secret' => env('WAOO_LEAD_SHARED_SECRET'),
    'sandbox_routes' => env('WAOO_SANDBOX_ROUTES', false),
    'google_routes_key' => env('WAOO_GOOGLE_ROUTES_KEY'),
    // Browser key (Maps JavaScript + Places New), HTTP-referrer restricted to the CRM hosts; it is visible in the page by design.
    'google_maps_browser_key' => env('WAOO_GOOGLE_MAPS_BROWSER_KEY'),
    'sandbox_tips' => env('WAOO_SANDBOX_TIPS', false),
    'sandbox_quick_stripe' => env('WAOO_SANDBOX_QUICK_STRIPE', false), // Internal leases/request preparation only; no provider or cron activation.
    'quick_stripe_requested_methods' => ['card'], // Requested policy only, never verified account capability or financing activation.
    'sandbox_finance' => env('WAOO_SANDBOX_FINANCE', false),
    'sandbox_honeybook_supplement' => env('WAOO_SANDBOX_HONEYBOOK_SUPPLEMENT', false),
    'sandbox_imports' => env('WAOO_SANDBOX_IMPORTS', false),
    'sandbox_cutover' => env('WAOO_SANDBOX_CUTOVER', false), // Evidence preparation only; no transfer endpoint.
    'cutover_reconciliation_max_age_minutes' => 240, // César approved 2026-10-19; not automatic polling.
    'sandbox_quotes' => env('WAOO_SANDBOX_QUOTES', false),
    // Owner-approved outcome-specific wording; old presentations retain their frozen text.
    'quote_acceptance_consent' => ['version' => 'quote-consent-v3-2026-10-20',
        'es' => ['accepted' => $clientTexts['texts']['quote_accept']['es'], 'rejected' => $clientTexts['texts']['quote_reject']['es']],
        'en' => ['accepted' => $clientTexts['texts']['quote_accept']['en'], 'rejected' => $clientTexts['texts']['quote_reject']['en']],
    ],
    'extra_character_cents' => 15000, // César 2026-10-21: each character beyond the package's included ones adds $150, any package.
    // Owner 2026-10-21: two-way Google Calendar (OAuth client from the owner's Google Cloud project; never in Git).
    'google_calendar' => ['client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'), 'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'), 'calendar_name' => 'Waoo Party fiestas'],
    'short_portal_links' => env('WAOO_SHORT_PORTAL_LINKS', false), // Requires log redaction before activation on a host.
    'sandbox_documents' => env('WAOO_SANDBOX_DOCUMENTS', false),
    'sandbox_immediate_contracts' => env('WAOO_SANDBOX_IMMEDIATE_CONTRACTS', false),
    'sandbox_staff' => env('WAOO_SANDBOX_STAFF', false), // STAFF-01 v2 owner link actions; read by Codex's services too.
    'stripe_test_secret' => env('STRIPE_TEST_SECRET'),
    'stripe_test_webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    'stripe_test_account' => env('STRIPE_TEST_ACCOUNT'),
];
