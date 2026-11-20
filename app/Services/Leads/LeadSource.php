<?php
namespace App\Services\Leads;

use App\Models\Lead;
use Carbon\CarbonImmutable;

/** Owner 2026-11-17: how a website lead found Waoo ("Llegó por"), sent by the website with the quote form. Website text is untrusted. */
final class LeadSource
{
    public const LABELS = [
        'google_ads' => 'Anuncio de Google', 'google_organic' => 'Google (búsqueda o Maps, gratis)', 'instagram' => 'Instagram',
        'facebook' => 'Facebook', 'campaign' => 'Otra campaña', 'referral' => 'Otra página', 'direct' => 'Directo (escribió la dirección)',
    ];

    /** Spanish line for one lead payload, or null when the website did not send it (older leads). */
    public static function label(array $payload): ?string
    {
        $a = $payload['attribution'] ?? null;
        if (! is_array($a) || ! isset(self::LABELS[$a['channel'] ?? ''])) return null;
        $extra = match ($a['channel']) {
            'google_ads', 'campaign' => filled($a['term'] ?? null) ? 'buscó «'.$a['term'].'»' : null,
            'referral' => $a['referrer'] ?? null,
            default => null,
        };

        return self::LABELS[$a['channel']].($extra ? ' · '.$extra : '');
    }

    /** Requests and bookings by channel for leads received in [from, to). Leads without a channel count as "unknown". */
    public static function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        Lead::query()->with('submission:id,payload')->whereBetween('created_at', [$from->utc(), $to->utc()->subSecond()])
            ->get(['id', 'lead_submission_id', 'status'])
            ->each(function (Lead $l) use (&$out) {
                $c = $l->submission?->payload['attribution']['channel'] ?? 'unknown';
                $c = isset(self::LABELS[$c]) ? $c : 'unknown';
                $out[$c] ??= ['leads' => 0, 'booked' => 0];
                $out[$c]['leads']++;
                if ($l->status === 'booked') $out[$c]['booked']++;
            });
        uasort($out, fn ($a, $b) => $b['leads'] <=> $a['leads']);

        return $out;
    }
}
