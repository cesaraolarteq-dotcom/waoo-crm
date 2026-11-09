<?php

namespace App\Support;

/**
 * Owner 2026-11-04: the real CRM (crm.waooparty.com) runs as APP_ENV=production with WAOO_LIVE=true.
 * Features that were limited to the test environments also run there; each one still needs its own WAOO_SANDBOX_* flag.
 * Stripe stays test-only (StripeTestGateway refuses production) until live Stripe is built and reviewed.
 */
final class WaooRuntime
{
    public static function trusted(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) || self::live();
    }

    public static function live(): bool
    {
        return app()->environment('production') && config('waoo.live') === true;
    }

    /**
     * Label on every money row and report (ledger, payment intents, owner reports). The real CRM writes and reads `live`;
     * every test environment keeps `test`. Its own database, so the label never mixes the two; it states what the money is.
     */
    public static function ledgerEnvironment(): string
    {
        return self::live() ? 'live' : 'test';
    }

    /** Environments reached over the internet must use HTTPS and secure cookies. */
    public static function hosted(): bool
    {
        return app()->environment('staging') || self::live();
    }
}
