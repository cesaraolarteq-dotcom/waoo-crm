<?php
namespace App\Services\Finance;

use App\Support\WaooRuntime;

/**
 * Owner 2026-11-04 (step 2 of the real CRM): Stripe runs live only on the real CRM (WAOO_LIVE) with STRIPE_LIVE=true and
 * live keys; every other environment stays on the test account. One place decides the mode, the keys and the account,
 * so a test object can never be credited on the real CRM and a live one never on crm-test.
 */
final class StripeMode
{
    public static function live(): bool
    {
        return WaooRuntime::live() && config('waoo.stripe_live') === true;
    }

    /** The `livemode` every Stripe object must carry here. */
    public static function livemode(): bool
    {
        return self::live();
    }

    public static function account(): ?string
    {
        $a = config(self::live() ? 'waoo.stripe_live_account' : 'waoo.stripe_test_account');
        return is_string($a) && $a !== '' ? $a : null;
    }

    public static function secret(): string
    {
        return (string) config(self::live() ? 'waoo.stripe_live_secret' : 'waoo.stripe_test_secret');
    }

    public static function webhookSecret(): string
    {
        return (string) config(self::live() ? 'waoo.stripe_live_webhook_secret' : 'waoo.stripe_test_webhook_secret');
    }

    public static function keyAllowed(string $key): bool
    {
        $prefixes = self::live() ? ['sk_live_', 'rk_live_'] : ['sk_test_', 'rk_test_'];
        foreach ($prefixes as $p) if (str_starts_with($key, $p)) return true;
        return false;
    }

    public static function sessionPrefix(): string
    {
        return self::live() ? 'cs_live_' : 'cs_test_';
    }
}
