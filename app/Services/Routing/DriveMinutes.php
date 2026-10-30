<?php
namespace App\Services\Routing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http};

/**
 * Drive time between two ZIP codes for the buffer between parties (César 2026-10-29: real drive + 30 minutes; the 30 is
 * WebsiteCapacity::GAP_MINUTES). Google Routes with traffic for that date and hour, plus a slack because a ZIP centre is
 * not the exact door. Cached per ZIP pair and Houston hour. The website answers in 2 seconds, so a request may fetch at
 * most one uncached route quickly; anything unknown is null and the caller falls back to owner approval.
 */
final class DriveMinutes
{
    public const ZIP_SLACK_MINUTES = 10;
    private int $fetches = 0;

    public function __construct(private int $maxFetches = 1, private float $timeout = 0.9) {}

    public function between(string $fromZip, string $toZip, CarbonImmutable $departAt): ?int
    {
        if (! preg_match('/^\d{5}$/D', $fromZip) || ! preg_match('/^\d{5}$/D', $toZip)) return null;
        if ($fromZip === $toZip) return self::ZIP_SLACK_MINUTES;
        $hour = $departAt->setTimezone('America/Chicago')->format('Y-m-d-H');
        $key = 'waoo.drive.'.$fromZip.'.'.$toZip.'.'.$hour;
        $cached = Cache::get($key);
        if (is_int($cached)) return $cached;
        if ($this->fetches >= $this->maxFetches || blank(config('waoo.google_routes_key')) || $departAt <= CarbonImmutable::now()->addMinute()) return null;
        $this->fetches++;
        try {
            $r = Http::connectTimeout($this->timeout)->timeout($this->timeout)->withHeaders(['X-Goog-Api-Key' => config('waoo.google_routes_key'),
                'X-Goog-FieldMask' => 'routes.duration'])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                'origin' => ['address' => $fromZip.', TX, USA'], 'destination' => ['address' => $toZip.', TX, USA'], 'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_AWARE', 'departureTime' => $departAt->utc()->format('Y-m-d\TH:i:s\Z')]);
            $duration = $r->successful() ? $r->json('routes.0.duration') : null;
            if (! is_string($duration) || ! preg_match('/^(\d+)(?:\.\d+)?s$/D', $duration, $m)) return null;
        } catch (\Throwable) { return null; } // never log the key or the request
        $minutes = (int) ceil((int) $m[1] / 60) + self::ZIP_SLACK_MINUTES;
        Cache::put($key, $minutes, now()->addDays(7));
        return $minutes;
    }
    /** Operational route proof needs the actual locations, not ZIP centroids. No network while DB locks are held. */
    public function betweenAddresses(?string $from, ?string $to, CarbonImmutable $departAt): ?int
    {
        if (blank($from) || blank($to) || strlen($from) > 500 || strlen($to) > 500
            || \Illuminate\Support\Facades\DB::transactionLevel() > 0 || $departAt <= CarbonImmutable::now()->addMinute()) return null;
        $departure = $departAt->utc()->format('Y-m-d\TH:i:s\Z');
        $key = 'waoo.drive.exact.'.hash('sha256', json_encode([$from, $to, $departure]));
        $cached = Cache::get($key);
        if (is_int($cached) && $cached >= 0 && $cached <= 1440) return $cached;
        if ($this->fetches >= $this->maxFetches || blank(config('waoo.google_routes_key'))) return null;
        $this->fetches++;
        try {
            $r = Http::connectTimeout($this->timeout)->timeout($this->timeout)->withoutRedirecting()->withHeaders([
                'X-Goog-Api-Key' => config('waoo.google_routes_key'), 'X-Goog-FieldMask' => 'routes.duration'])
                ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => ['address' => $from], 'destination' => ['address' => $to], 'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_AWARE', 'departureTime' => $departure]);
            $duration = $r->successful() ? $r->json('routes.0.duration') : null;
            if (!is_string($duration) || !preg_match('/^(\d+(?:\.\d+)?)s$/D', $duration, $m)) return null;
            $minutes = (int) ceil((float) $m[1] / 60);
            if ($minutes > 1440) return null;
            Cache::put($key, $minutes, now()->addMinutes(10));
            return $minutes;
        } catch (\Throwable) { return null; }
    }

}
