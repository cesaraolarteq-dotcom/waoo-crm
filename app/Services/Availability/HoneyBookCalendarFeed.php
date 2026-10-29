<?php
namespace App\Services\Availability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http};

/**
 * Owner 2026-10-29: until the switch, new HoneyBook bookings reach the CRM only through the private iCal calendar HoneyBook
 * writes to (the same feed the website uses today). Its events count as busy for website availability.
 * The URL is a secret (env WAOO_HONEYBOOK_ICAL_URL): never logged or shown. Only start/end times are read.
 */
final class HoneyBookCalendarFeed
{
    public static function configured(): bool
    {
        return filled(config('waoo.honeybook_ical_url'));
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null null when the feed did not answer (never treat as free) */
    public function between(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $ics = Cache::get('waoo.hb-ical');
        if (! is_string($ics)) {
            try {
                $r = Http::connectTimeout(1)->timeout(2)->withoutRedirecting()->get((string) config('waoo.honeybook_ical_url'));
            } catch (\Throwable) {
                return null;
            }
            if (! $r->successful() || ! str_contains($r->body(), 'BEGIN:VCALENDAR')) return null;
            $ics = $r->body();
            Cache::put('waoo.hb-ical', $ics, 120);
        }
        $out = [];
        foreach (self::events($ics) as [$start, $end]) {
            if ($start < $to && $end > $from) $out[] = [$start, $end];
        }
        return $out;
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> */
    public static function events(string $ics): array
    {
        $lines = preg_split('/\r?\n/', preg_replace('/\r?\n[ \t]/', '', $ics)); // unfold
        $events = []; $cur = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') { $cur = []; continue; }
            if ($line === 'END:VEVENT') {
                if (isset($cur['DTSTART'])) {
                    $start = $cur['DTSTART'];
                    $end = $cur['DTEND'] ?? (! empty($cur['ALLDAY']) ? $start->addDay() : $start->addHour());
                    if (($cur['STATUS'] ?? '') !== 'CANCELLED' && $end > $start) $events[] = [$start, $end];
                }
                $cur = null; continue;
            }
            if ($cur === null || ! preg_match('/^(DTSTART|DTEND|STATUS)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
            if ($m[1] === 'STATUS') { $cur['STATUS'] = trim($m[3]); continue; }
            $value = trim($m[3]); $params = $m[2];
            if (str_contains($params, 'VALUE=DATE') || preg_match('/^\d{8}$/', $value)) {
                // All-day entry: the whole Houston day.
                $d = CarbonImmutable::createFromFormat('!Ymd', substr($value, 0, 8), 'America/Chicago');
                $cur[$m[1]] = $d; if ($m[1] === 'DTSTART') $cur['ALLDAY'] = true;
                continue;
            }
            $tz = preg_match('/TZID=([^;:]+)/', $params, $t) ? $t[1] : 'America/Chicago';
            try {
                $cur[$m[1]] = str_ends_with($value, 'Z') ? CarbonImmutable::createFromFormat('Ymd\THis\Z', $value, 'UTC')
                    : CarbonImmutable::createFromFormat('Ymd\THis', $value, $tz);
            } catch (\Throwable) {
                continue;
            }
        }
        return $events;
    }
}
