<?php

namespace App\Services\Google;

use App\Filament\Support\ChicagoTime;
use App\Filament\Support\Labels;
use App\Models\Event;
use App\Models\EventGoogleLink;
use App\Models\GoogleCalendarConnection;
use App\Models\ServiceVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owner 2026-10-21: two-way Google Calendar like HoneyBook.
 * Push: every CRM party is created/updated/removed in a separate "Waoo Party fiestas" calendar the app creates.
 * Pull: the owner's primary calendar is read only as free/busy (no titles or details) to avoid booking on top.
 * Scopes stay narrow: calendar.app.created (only calendars this app created) + calendar.freebusy + email.
 */
final class GoogleCalendar
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/calendar.app.created', 'https://www.googleapis.com/auth/calendar.freebusy'];
    private const API = 'https://www.googleapis.com/calendar/v3';

    public static function configured(): bool
    {
        return filled(config('waoo.google_calendar.client_id')) && filled(config('waoo.google_calendar.client_secret'));
    }

    public static function connection(): ?GoogleCalendarConnection
    {
        return self::configured() ? GoogleCalendarConnection::query()->latest('id')->first() : null;
    }

    public static function redirectUri(): string
    {
        return url('/admin/google/callback');
    }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('waoo.google_calendar.client_id'), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]);
    }

    /** OAuth code → stored connection (one active connection; a reconnect replaces the old one but keeps the same calendar). */
    public function connect(User $owner, string $code): GoogleCalendarConnection
    {
        $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('waoo.google_calendar.client_id'), 'client_secret' => config('waoo.google_calendar.client_secret'),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful() || blank($r->json('refresh_token'))) throw new RuntimeException('Google no entregó el permiso. Vuelve a conectar y acepta todos los permisos.');
        $email = null;
        if ($jwt = $r->json('id_token')) {
            $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
            $email = is_array($payload) ? ($payload['email'] ?? null) : null;
        }
        $previous = GoogleCalendarConnection::query()->latest('id')->first();
        $connection = GoogleCalendarConnection::create(['user_id' => $owner->id, 'google_email' => $email, 'refresh_token' => $r->json('refresh_token'),
            'access_token' => $r->json('access_token'), 'access_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60),
            'calendar_id' => $previous && $previous->google_email === $email ? $previous->calendar_id : null]);
        GoogleCalendarConnection::whereKeyNot($connection->id)->delete();
        $this->ensureCalendar($connection);

        return $connection;
    }

    public function disconnect(): void
    {
        foreach (GoogleCalendarConnection::all() as $c) {
            try { Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $c->refresh_token]); } catch (\Throwable) {}
            $c->delete();
        }
        Cache::forget('waoo.google.busy');
    }

    private function token(GoogleCalendarConnection $c): string
    {
        if ($c->access_token && $c->access_expires_at?->isFuture()) return $c->access_token;
        $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('waoo.google_calendar.client_id'), 'client_secret' => config('waoo.google_calendar.client_secret'),
            'refresh_token' => $c->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful()) {
            $c->forceFill(['last_error' => 'Google rechazó el permiso. Vuelve a conectar Google Calendar.'])->save();
            throw new RuntimeException('Google rechazó el permiso guardado.');
        }
        $c->forceFill(['access_token' => $r->json('access_token'), 'access_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60)])->save();

        return $c->access_token;
    }

    private function api(GoogleCalendarConnection $c)
    {
        return Http::withToken($this->token($c))->acceptJson()->timeout(15);
    }

    /** The separate parties calendar, created once (or again if the owner deleted it in Google). */
    public function ensureCalendar(GoogleCalendarConnection $c): string
    {
        if ($c->calendar_id) {
            $r = $this->api($c)->get(self::API.'/calendars/'.rawurlencode($c->calendar_id));
            if ($r->successful()) return $c->calendar_id;
            if (! in_array($r->status(), [404, 410], true)) throw new RuntimeException('Google Calendar no respondió ('.$r->status().').');
            EventGoogleLink::query()->update(['google_event_id' => null, 'synced_hash' => null]);
        }
        $r = $this->api($c)->post(self::API.'/calendars', ['summary' => config('waoo.google_calendar.calendar_name'), 'timeZone' => ChicagoTime::TZ,
            'description' => 'Fiestas del CRM de Waoo Party. Se actualiza solo: los cambios hechos aquí se reemplazan desde el CRM.']);
        if (! $r->successful()) throw new RuntimeException('No se pudo crear el calendario en Google ('.$r->status().').');
        $c->forceFill(['calendar_id' => $r->json('id'), 'last_error' => null])->save();

        return $c->calendar_id;
    }

    /** Google event body for a CRM party (no client phone/email: the calendar may be shared with staff). */
    public static function body(Event $e): array
    {
        $e->loadMissing(['customer', 'services.characters']);
        $names = ServiceVariant::whereIn('id', $e->services->pluck('service_variant_id'))->pluck('name', 'id');
        $characters = $e->services->flatMap(fn ($s) => $s->characters)->map(fn ($c) => ($c->quantity > 1 ? $c->quantity.' × ' : '').$c->character_name_snapshot)->implode(', ');
        $lines = array_filter([
            'Paquete: '.($e->services->map(fn ($s) => $names[$s->service_variant_id] ?? '')->filter()->implode(' + ') ?: 'sin servicios'),
            $characters ? 'Personajes: '.$characters : null,
            'Cliente: '.($e->customer?->name ?: 'pendiente'),
            $e->setup_minutes ? 'Montaje: '.$e->setup_minutes.' min antes' : null,
            $e->breakdown_minutes ? 'Desmontaje: '.$e->breakdown_minutes.' min después' : null,
            'Estado: '.Labels::label(Labels::EVENT_STATUS, $e->status),
            'Ver en el CRM: '.url('/admin/eventos/'.$e->id),
        ]);

        return [
            'summary' => $e->title,
            'location' => trim(collect([\App\Support\ClientAddress::forEvent($e)[0], $e->postal_code])->filter()->implode(', ')) ?: null,
            'description' => implode("\n", $lines),
            'start' => ['dateTime' => CarbonImmutable::instance($e->starts_at)->setTimezone(ChicagoTime::TZ)->toIso8601String(), 'timeZone' => ChicagoTime::TZ],
            'end' => ['dateTime' => CarbonImmutable::instance($e->ends_at)->setTimezone(ChicagoTime::TZ)->toIso8601String(), 'timeZone' => ChicagoTime::TZ],
            'colorId' => $e->status === 'draft' ? '8' : '9',
            'extendedProperties' => ['private' => ['waoo_event_id' => (string) $e->id]],
        ];
    }

    /** Create or update one party in Google; unchanged content is skipped. Returns false when not connected. */
    public function push(Event $e): bool
    {
        $c = self::connection();
        if (! $c) return false;
        $link = EventGoogleLink::firstOrNew(['event_id' => $e->id]);
        $body = self::body($e);
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        if ($link->google_event_id && $link->synced_hash === $hash && ! $link->last_error) return true;
        try {
            $calendar = $this->ensureCalendar($c);
            $base = self::API.'/calendars/'.rawurlencode($calendar).'/events';
            $r = $link->google_event_id ? $this->api($c)->patch($base.'/'.rawurlencode($link->google_event_id), $body) : null;
            if (! $r || in_array($r->status(), [404, 410], true)) $r = $this->api($c)->post($base, $body);
            if (! $r->successful()) throw new RuntimeException('Google respondió '.$r->status().'.');
            $link->fill(['google_event_id' => $r->json('id'), 'synced_hash' => $hash, 'synced_at' => now(), 'last_error' => null])->save();

            return true;
        } catch (\Throwable $ex) {
            $link->fill(['last_error' => mb_substr($ex->getMessage(), 0, 500)])->save();
            throw $ex;
        }
    }

    public function remove(int $eventId): void
    {
        $c = self::connection();
        $link = EventGoogleLink::where('event_id', $eventId)->first();
        if (! $c || ! $link?->google_event_id || ! $c->calendar_id) return;
        $r = $this->api($c)->delete(self::API.'/calendars/'.rawurlencode($c->calendar_id).'/events/'.rawurlencode($link->google_event_id));
        if (! $r->successful() && ! in_array($r->status(), [404, 410], true)) throw new RuntimeException('Google respondió '.$r->status().'.');
    }

    /**
     * Busy blocks from the owner's primary calendar, Houston day => [['from' => 'g:i A', 'to' => 'g:i A']], cached 5 minutes.
     * Free/busy only: Google never tells the CRM what the appointment is.
     */
    public function busy(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $c = self::connection();
        if (! $c) return [];
        $key = 'waoo.google.busy.'.$c->id.'.'.$from->format('Ymd').'.'.$to->format('Ymd');

        return Cache::remember($key, 300, function () use ($c, $from, $to) {
            try {
                $r = $this->api($c)->post(self::API.'/freeBusy', ['timeMin' => $from->utc()->toIso8601String(), 'timeMax' => $to->utc()->toIso8601String(),
                    'timeZone' => ChicagoTime::TZ, 'items' => [['id' => 'primary']]]);
            } catch (\Throwable) {
                return [];
            }
            $days = [];
            foreach ((array) $r->json('calendars.primary.busy', []) as $b) {
                $s = CarbonImmutable::parse($b['start'])->setTimezone(ChicagoTime::TZ);
                $e = CarbonImmutable::parse($b['end'])->setTimezone(ChicagoTime::TZ);
                $days[$s->format('Y-m-d')][] = ['from' => $s->format('g:i A'), 'to' => $e->format('g:i A')];
            }

            return $days;
        });
    }

    /** Push every party from today on (first connection, "Sincronizar ahora", retries). Returns [ok, failed]. */
    public function syncUpcoming(): array
    {
        $ok = 0; $failed = 0;
        Event::query()->where('ends_at', '>=', now()->subDay())->orderBy('starts_at')->each(function (Event $e) use (&$ok, &$failed) {
            try { $this->push($e) ? $ok++ : null; } catch (\Throwable) { $failed++; }
        });

        return [$ok, $failed];
    }
}
