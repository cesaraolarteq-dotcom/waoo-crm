<?php

namespace App\Filament\Support;

// One-tap sending from the owner's own phone/computer: WhatsApp and SMS links with the message already written.
// Nothing is sent by the CRM; the owner taps send in her own app. US numbers without a country code get +1.
final class ContactLinks
{
    public static function digits(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (strlen($d) === 10) $d = '1'.$d;

        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
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
