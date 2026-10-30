<?php

namespace App\Services\Sms;

/** Owner hold: configuring Twilio never turns texting on; only TWILIO_SMS_ENABLED=true does, plus complete credentials. */
final class SmsGate
{
    public static function enabled(): bool
    {
        return config('waoo.sms_delivery_enabled') === true && self::configured();
    }

    public static function configured(): bool
    {
        $c = config('services.twilio');
        $ok = fn ($v, string $re) => is_string($v) && preg_match($re, $v) === 1;

        return is_array($c) && $ok($c['account_sid'] ?? null, '/^AC[0-9a-f]{32}$/D') && $ok($c['api_key_sid'] ?? null, '/^SK[0-9a-f]{32}$/D')
            && $ok($c['api_key_secret'] ?? null, '/^[A-Za-z0-9]{16,128}$/D') && $ok($c['messaging_service_sid'] ?? null, '/^MG[0-9a-f]{32}$/D');
    }
}
