<?php

namespace App\Services\Ads;

use App\Models\{Event, FinancialRevision, Lead, QuickPaymentBooking};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-26 (point 5): a script inside Waoo's Google Ads account posts the daily spend here and asks which
 * prospects booked, so it can upload them as the «Fiesta reservada (CRM)» conversion. No Google API approval needed.
 */
final class AdsSync
{
    /** @param list<array{date:string,campaign_id:string,campaign:string,cost_micros:int|string,clicks?:int,impressions?:int}> $rows */
    public function storeSpend(string $platform, array $rows): int
    {
        $n = 0;
        foreach (array_slice($rows, 0, 2000) as $r) {
            $day = (string) ($r['date'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ! is_numeric($r['cost_micros'] ?? null)) continue;
            DB::table('ad_spend_daily')->updateOrInsert(
                ['day' => $day, 'platform' => $platform, 'campaign_id' => mb_substr((string) ($r['campaign_id'] ?? ''), 0, 40)],
                ['campaign' => mb_substr((string) ($r['campaign'] ?? ''), 0, 200), 'cost_cents' => (int) round(((int) $r['cost_micros']) / 10000),
                    'clicks' => (int) ($r['clicks'] ?? 0), 'impressions' => (int) ($r['impressions'] ?? 0), 'updated_at' => now(), 'created_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** Booked prospects with a Google click ID not yet reported (click within the last 90 days, as Google requires). */
    public function pendingGoogleConversions(): array
    {
        $done = DB::table('ad_conversion_uploads')->where('platform', 'google')->pluck('lead_id')->all();
        $out = [];
        foreach (Lead::with('submission')->where('status', 'booked')->whereNotIn('id', $done)->where('created_at', '>=', now()->subDays(90))->orderBy('id')->limit(200)->get() as $lead) {
            $a = $lead->submission?->payload['attribution'] ?? [];
            $id = $a['gclid'] ?? $a['gbraid'] ?? $a['wbraid'] ?? null;
            if (! is_string($id)) continue;
            [$value, $when] = self::booking($lead);
            if ($value === null) continue;
            $out[] = ['lead_id' => $lead->id, 'gclid' => $a['gclid'] ?? null, 'gbraid' => $a['gbraid'] ?? null, 'wbraid' => $a['wbraid'] ?? null,
                'conversion_time' => $when->setTimezone('America/Chicago')->format('Y-m-d H:i:sP'), 'value' => round($value / 100, 2), 'currency' => 'USD'];
        }
        return $out;
    }

    /** The script confirms which ones it uploaded, so they are never sent twice. */
    public function acknowledge(string $platform, array $leadIds): int
    {
        $n = 0;
        foreach (array_slice(array_unique(array_map('intval', $leadIds)), 0, 500) as $id) {
            $lead = Lead::find($id);
            if (! $lead || $lead->status !== 'booked') continue;
            [$value] = self::booking($lead);
            $n += DB::table('ad_conversion_uploads')->insertOrIgnore(['lead_id' => $id, 'platform' => $platform, 'value_cents' => (int) $value, 'reported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        return $n;
    }

    /** [total in cents, booking moment] of the party this prospect became. */
    public static function booking(Lead $lead): array
    {
        $eventId = $lead->booked_event_id ?? ($lead->quick_booking_id ? DB::table('quick_bookings')->where('id', $lead->quick_booking_id)->value('event_id') : null);
        $event = $eventId ? Event::find($eventId) : null;
        if (! $event) return [null, CarbonImmutable::now()];
        $total = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->value('total_cents');
        return [$total !== null ? (int) $total : null, CarbonImmutable::parse($lead->updated_at)];
    }

    /** Owner report: spend, prospects, bookings and cost per booked party by channel for [from, to). */
    public static function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $leads = \App\Services\Leads\LeadSource::summary($from, $to);
        $spend = DB::table('ad_spend_daily')->whereBetween('day', [$from->toDateString(), $to->subDay()->toDateString()])
            ->selectRaw('platform, sum(cost_cents) c, sum(clicks) k')->groupBy('platform')->get()->keyBy('platform');
        $rows = [];
        foreach (['google_ads' => 'google', 'instagram' => 'meta', 'facebook' => 'meta'] as $channel => $platform) {
            $l = $leads[$channel] ?? ['leads' => 0, 'booked' => 0];
            $rows[$channel] = ['leads' => $l['leads'], 'booked' => $l['booked'], 'spend_cents' => $channel === 'facebook' ? null : (int) ($spend[$platform]->c ?? 0),
                'clicks' => (int) ($spend[$platform]->k ?? 0)];
        }
        foreach ($leads as $channel => $l) if (! isset($rows[$channel])) $rows[$channel] = ['leads' => $l['leads'], 'booked' => $l['booked'], 'spend_cents' => null, 'clicks' => null];
        return $rows;
    }
}
