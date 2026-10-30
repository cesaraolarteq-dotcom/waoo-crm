<?php

namespace App\Services\Sms;

use App\Models\{Lead, SmsConsent};

/**
 * A website request whose optional SMS box was checked (consent text "SMS opt-in: YES", form version contact-2026-10-28)
 * records that number as opted in, with the exact consent text as evidence. An unchecked box records nothing.
 */
final class RecordLeadSmsConsent
{
    public function execute(Lead $lead): void
    {
        $p = $lead->submission?->payload ?? [];
        $text = $p['consent']['text'] ?? null;
        $phone = PhoneNumber::e164($p['phone'] ?? null);
        if (! $phone || ! is_string($text) || ! str_contains($text, 'SMS opt-in: YES') && ! str_contains($text, 'SMS opt-in: SÍ')) return;
        SmsConsent::updateOrCreate(['phone_e164' => $phone], ['status' => 'opted_in', 'source' => 'website_form',
            'evidence' => mb_substr(($p['consent']['version'] ?? '').' · '.$text, 0, 4000), 'changed_at' => $lead->created_at ?? now(),
            'customer_id' => ReceiveTwilioWebhook::customerFor($phone)]);
    }
}
