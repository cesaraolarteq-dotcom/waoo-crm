<?php

namespace App\Services\Sms;

/** US numbers only (Waoo's clients): "(346) 555-0100", "346.555.0100", "+1 346 555 0100" → "+13465550100". */
final class PhoneNumber
{
    public static function e164(?string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);

        return strlen($digits) === 10 && $digits[0] >= '2' ? '+1'.$digits : null;
    }
}
