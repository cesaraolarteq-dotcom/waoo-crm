<?php

namespace App\Services\Ads;

use App\Models\{Event, FinancialRevision, Lead};
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
        $entries = [];
        $now = now();
        foreach (array_slice($rows, 0, 2000) as $r) {
            if (! is_array($r)) continue;
            $day = $r['date'] ?? null;
            $campaignId = $r['campaign_id'] ?? null;
            if (is_int($campaignId)) $campaignId = (string) $campaignId;
            $campaign = $r['campaign'] ?? '';
            if (! is_string($day) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)
                || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))
                || ! is_string($campaignId) || ! preg_match('/^[0-9]{1,40}$/D', $campaignId) || ! is_string($campaign)) continue;
            $cost = self::unsignedInt($r['cost_micros'] ?? null, PHP_INT_MAX);
            $clicks = self::unsignedInt($r['clicks'] ?? 0, 4_294_967_295);
            $impressions = self::unsignedInt($r['impressions'] ?? 0, 4_294_967_295);
            if ($cost === null || $clicks === null || $impressions === null) continue;
            $entries[$day.':'.$campaignId] = ['day' => $day, 'platform' => $platform, 'campaign_id' => $campaignId,
                'campaign' => mb_substr($campaign, 0, 200), 'cost_cents' => intdiv($cost, 10000) + (int) ($cost % 10000 >= 5000),
                'clicks' => $clicks, 'impressions' => $impressions, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::transaction(function () use ($entries) {
            foreach (array_chunk($entries, 100) as $chunk) {
                DB::table('ad_spend_daily')->upsert($chunk, ['day', 'platform', 'campaign_id'], ['campaign', 'cost_cents', 'clicks', 'impressions', 'updated_at']);
            }
        }, 3);
        return count($entries);
    }

    private static function unsignedInt(mixed $value, int $max): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $value))) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        return $parsed === false ? null : $parsed;
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
        $ids = array_unique(array_filter(array_map(fn ($id) => self::unsignedInt($id, PHP_INT_MAX), array_slice($leadIds, 0, 500))));
        foreach (Lead::with('submission')->whereKey($ids)->where('status', 'booked')->where('created_at', '>=', now()->subDays(90))->get() as $lead) {
            $a = $lead->submission?->payload['attribution'] ?? [];
            if (! is_string($a['gclid'] ?? $a['gbraid'] ?? $a['wbraid'] ?? null)) continue;
            [$value] = self::booking($lead);
            if ($value === null) continue;
            $n += DB::table('ad_conversion_uploads')->insertOrIgnore(['lead_id' => $lead->id, 'platform' => $platform, 'value_cents' => $value, 'reported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
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
