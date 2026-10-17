<?php

namespace App\Filament\Support;

// One-tap opening of the owner's own WhatsApp/SMS with the message ready. Nothing is sent by the CRM.
// Conservative on purpose (the message carries the client's link): only a clear US number (10 digits, or 11 with a
// leading 1, valid NANP shape) or an explicit international "+" number. Letters, extensions, notes like
// "DO NOT TEXT" or impossible numbers → no buttons, so a link never goes to a guessed recipient.
final class ContactLinks
{
    public static function digits(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '' || ! preg_match('/^\+?[\d\s().\-]{7,24}$/', $raw)) return null;
        $d = preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+')) {
            if (str_starts_with($d, '1')) return self::nanp(substr($d, 1));
            // Explicit international number: country code 2–9xx, 8–15 digits total, not all the same digit.
            return preg_match('/^[2-9]\d{7,14}$/', $d) && ! preg_match('/^(\d)\1+$/', $d) ? $d : null;
        }
        if (strlen($d) === 10) return self::nanp($d);
        if (strlen($d) === 11 && $d[0] === '1') return self::nanp(substr($d, 1));

        return null;
    }

    /** NANP: area code and exchange start with 2–9, area code not N11, not 555-01xx-style all zeros. */
    private static function nanp(string $ten): ?string
    {
        if (! preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $ten)) return null;
        if (preg_match('/^\d11/', $ten) || preg_match('/^(\d)\1{9}$/', $ten)) return null;

        return '1'.$ten;
    }

    public static function whatsapp(?string $phone, string $message): ?string
    {
        $d = self::digits($phone);

        return $d ? 'https://wa.me/'.$d.'?text='.rawurlencode($message) : null;
    }

    public static function sms(?string $phone, string $message): ?string
    {
        $d = self::digits($phone);

        return $d ? 'sms:+'.$d.'?&body='.rawurlencode($message) : null;
    }
}
