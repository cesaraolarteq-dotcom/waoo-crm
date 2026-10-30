<?php
namespace App\Services\Availability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http};

/**
 * Owner 2026-10-29: until the switch, new HoneyBook bookings reach the CRM only through the private iCal calendar HoneyBook
 * writes to (the same feed the website uses today). Its events count as busy for website availability.
 * The URL is a secret (env WAOO_HONEYBOOK_ICAL_URL): never logged or shown. Only start/end times are read.
 * Anything the parser cannot prove (recurrence, DURATION, a timed start without end, a bad date, a truncated or oversized
 * calendar) makes the whole answer unknown (null), never free time (Codex review 2026-10-29).
 */
final class HoneyBookCalendarFeed
{
    public const MAX_BYTES = 2_000_000;

    public static function configured(): bool
    {
        return filled(config('waoo.honeybook_ical_url'));
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null null when the feed did not answer or cannot be trusted */
    public function between(CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $url = (string) config('waoo.honeybook_ical_url');
        $key = 'waoo.hb-ical.'.substr(hash('sha256', $url), 0, 16); // a changed URL never reuses the old calendar
        $ics = Cache::get($key);
        if (! is_string($ics)) {
            try {
                // Read at most MAX_BYTES + 1 from the network, so the size limit also bounds memory (Codex audit P2).
                $r = Http::connectTimeout(1)->timeout(2)->withoutRedirecting()->withOptions(['stream' => true])->get($url);
                if (! $r->successful()) return null;
                $stream = $r->toPsrResponse()->getBody(); $body = '';
                while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) $body .= $stream->read(65536);
            } catch (\Throwable) {
                return null;
            }
            if (strlen($body) > self::MAX_BYTES) return null;
            $ics = $body;
            Cache::put($key, $ics, 120);
        }
        $events = self::events($ics);
        if ($events === null) return null;
        return array_values(array_filter($events, fn ($e) => $e[0] < $to && $e[1] > $from));
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>|null null when anything is unsupported or malformed */
    public static function events(string $ics): ?array
    {
        // Property names and BEGIN/END values are case-insensitive in iCalendar (RFC 5545 3.1): normalize them, never skip a line.
        if (strlen($ics) > self::MAX_BYTES || ! preg_match('/^BEGIN:VCALENDAR\r?$/mi', $ics) || ! preg_match('/^END:VCALENDAR\r?$/mi', $ics)) return null;
        $lines = preg_split('/\r?\n/', preg_replace('/\r?\n[ \t]/', '', $ics)); // unfold
        $lines = array_map(function (string $l) {
            if (! preg_match('/^([A-Za-z0-9-]+)([;:].*)?$/s', $l, $m)) return $l;
            $l = strtoupper($m[1]).($m[2] ?? '');
            return preg_match('/^(BEGIN|END):/', $l) ? strtoupper(rtrim($l)) : $l;
        }, $lines);
        $events = []; $cur = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') { if ($cur !== null) return null; $cur = []; continue; }
            if ($line === 'END:VEVENT') {
                if ($cur === null || ! isset($cur['DTSTART'])) return null;
                if (($cur['STATUS'] ?? '') !== 'CANCELLED') {
                    $start = $cur['DTSTART'];
                    if (! isset($cur['DTEND']) && empty($cur['ALLDAY'])) return null; // a timed entry must say when it ends
                    $end = $cur['DTEND'] ?? $start->addDay();
                    if ($end <= $start) return null;
                    $events[] = [$start, $end];
                }
                $cur = null; continue;
            }
            if ($cur === null) continue;
            if (preg_match('/^(RRULE|RDATE|EXRULE|DURATION)[;:]/', $line)) return null; // recurrence/duration not supported
            if (! preg_match('/^(DTSTART|DTEND|STATUS)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
            if ($m[1] === 'STATUS') { $cur['STATUS'] = strtoupper(trim($m[3])); continue; }
            $value = trim($m[3]); $params = $m[2];
            if (str_contains($params, 'VALUE=DATE') && ! str_contains($params, 'VALUE=DATE-TIME')) {
                if (! preg_match('/^\d{8}$/', $value)) return null;
                $d = CarbonImmutable::createFromFormat('!Ymd', $value, 'America/Chicago');
                if (! $d || $d->format('Ymd') !== $value) return null;
                $cur[$m[1]] = $d; if ($m[1] === 'DTSTART') $cur['ALLDAY'] = true; // all day: the whole Houston day
                continue;
            }
            $tz = preg_match('/TZID=([^;:]+)/', $params, $t) ? trim($t[1], '"') : 'America/Chicago';
            if (! in_array($tz, timezone_identifiers_list(), true)) return null;
            if (preg_match('/^\d{8}T\d{6}Z$/', $value)) $at = CarbonImmutable::createFromFormat('!Ymd\THis\Z', $value, 'UTC');
            elseif (preg_match('/^\d{8}T\d{6}$/', $value)) $at = CarbonImmutable::createFromFormat('!Ymd\THis', $value, $tz);
            else return null;
            // A date that does not exist (Nov 31) must not roll over to another day: the calendar is unknown.
            if (! $at || $at->format('Ymd\THis') !== substr($value, 0, 15)) return null;
            $cur[$m[1]] = $at;
        }
        return $cur === null ? $events : null; // an unclosed VEVENT means a truncated calendar
    }
}
