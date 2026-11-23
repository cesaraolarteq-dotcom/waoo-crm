<?php
namespace App\Services\Google;

use App\Models\{Customer, CustomerGoogleContact, Event, GoogleContactConnection, LedgerEntry, User};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owner 2026-11-23: clients who book go to the owner's Google Contacts (People API), in the group «Clientes Waoo», named
 * «María González · Waoo dic 2026» (month of the first booked party). Only clients who booked (paid something or have a
 * confirmed party), never leads. Uses the same Google OAuth client and callback as the calendar; its own connection, so
 * the calendar and the contacts can be different Google accounts. Write-only from the CRM: never reads his other contacts.
 */
final class GoogleContacts
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/contacts'];
    public const GROUP = 'Clientes Waoo';
    private const API = 'https://people.googleapis.com/v1/';

    public static function configured(): bool { return GoogleCalendar::configured(); }

    public static function connection(): ?GoogleContactConnection { return GoogleContactConnection::query()->latest('id')->first(); }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('waoo.google_calendar.client_id'), 'redirect_uri' => GoogleCalendar::redirectUri(), 'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES), 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    public function connect(User $owner, string $code): GoogleContactConnection
    {
        $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('waoo.google_calendar.client_id'), 'client_secret' => config('waoo.google_calendar.client_secret'),
            'redirect_uri' => GoogleCalendar::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful() || blank($r->json('refresh_token'))) throw new RuntimeException('Google no entregó el permiso. Vuelve a conectar y acepta el permiso de contactos.');
        if (! str_contains((string) $r->json('scope'), 'auth/contacts')) throw new RuntimeException('Falta el permiso de contactos. Vuelve a conectar y marca «Ver, editar… tus contactos».');
        $email = null;
        if ($jwt = $r->json('id_token')) {
            $payload = json_decode(base64_decode(strtr(explode('.', $jwt)[1] ?? '', '-_', '+/')), true);
            $email = is_array($payload) ? ($payload['email'] ?? null) : null;
        }
        $previous = self::connection();
        $c = GoogleContactConnection::create(['user_id' => $owner->id, 'google_email' => $email, 'refresh_token' => $r->json('refresh_token'),
            'access_token' => $r->json('access_token'), 'access_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60),
            'group_resource_name' => $previous && $previous->google_email === $email ? $previous->group_resource_name : null]);
        GoogleContactConnection::whereKeyNot($c->id)->delete();
        $this->group($c);
        return $c;
    }

    public function disconnect(): void
    {
        if ($c = self::connection()) {
            try { Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $c->refresh_token]); } catch (\Throwable) {}
            GoogleContactConnection::query()->delete();
        }
    }

    private function token(GoogleContactConnection $c): string
    {
        if ($c->access_token && $c->access_expires_at?->isFuture()) return $c->access_token;
        $r = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('waoo.google_calendar.client_id'), 'client_secret' => config('waoo.google_calendar.client_secret'),
            'refresh_token' => $c->refresh_token, 'grant_type' => 'refresh_token',
        ]);
        if (! $r->successful()) {
            $c->forceFill(['last_error' => 'Google pidió volver a conectar los contactos.'])->save();
            throw new RuntimeException('google_contacts_token');
        }
        $c->forceFill(['access_token' => $r->json('access_token'), 'access_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600) - 60), 'last_error' => null])->save();
        return $c->access_token;
    }

    private function api(GoogleContactConnection $c) { return Http::withToken($this->token($c))->acceptJson()->timeout(15); }

    /** The «Clientes Waoo» contact group, created once. */
    private function group(GoogleContactConnection $c): string
    {
        if ($c->group_resource_name) return $c->group_resource_name;
        $r = $this->api($c)->post(self::API.'contactGroups', ['contactGroup' => ['name' => self::GROUP]]);
        if (! $r->successful()) {
            // Already there (e.g. reconnected): find it by name.
            $list = $this->api($c)->get(self::API.'contactGroups', ['pageSize' => 200])->json('contactGroups') ?? [];
            $found = collect($list)->firstWhere('name', self::GROUP)['resourceName'] ?? null;
            if (! $found) throw new RuntimeException('No se pudo crear el grupo «'.self::GROUP.'» en Google Contactos.');
            $c->forceFill(['group_resource_name' => $found])->save();
            return $found;
        }
        $c->forceFill(['group_resource_name' => $r->json('resourceName')])->save();
        return $c->group_resource_name;
    }

    /** «María González · Waoo dic 2026»: month of her first booked party (Houston time). */
    public static function displayName(Customer $customer, CarbonImmutable $firstParty): string
    {
        $month = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int) $firstParty->setTimezone('America/Chicago')->format('n') - 1];
        return trim((string) $customer->name).' · Waoo '.$month.' '.$firstParty->setTimezone('America/Chicago')->format('Y');
    }

    /** Customers who booked: a confirmed party, money received on a party, or a past party in the HoneyBook history. @return array<int, CarbonImmutable> customer id => first booked party start */
    public static function bookedCustomers(): array
    {
        $paid = LedgerEntry::where('kind', 'receipt')->where('environment', \App\Support\WaooRuntime::ledgerEnvironment())->whereNotNull('event_id')->distinct()->pluck('event_id');
        $events = Event::where(fn ($q) => $q->where('status', 'capacity_reserved')->orWhereIn('id', $paid))
            ->whereNotIn('status', ['cancelled', 'canceled'])->whereNotNull('customer_id')->get(['id', 'customer_id', 'starts_at']);
        $out = [];
        $keep = function (int $customerId, $at) use (&$out) {
            if (! $at) return;
            $at = CarbonImmutable::parse($at);
            if (! isset($out[$customerId]) || $at->lessThan($out[$customerId])) $out[$customerId] = $at;
        };
        foreach ($events as $e) $keep((int) $e->customer_id, $e->starts_at);
        // Past clients from the HoneyBook history import: parties that happened (or archived with money paid), never cancelled.
        \App\Models\HistoricalEvent::query()->whereNotNull('current_revision_id')->orderBy('id')->chunk(200, function ($rows) use ($keep) {
            $revisions = \App\Models\HistoricalEventRevision::whereIn('id', $rows->pluck('current_revision_id'))->get()->keyBy('id');
            foreach ($rows as $h) {
                $f = $revisions->get($h->current_revision_id)?->payload['facts'] ?? [];
                $status = $f['status'] ?? null;
                if ($status === 'completed' || ($status === 'archived_unknown' && (int) ($f['paid_total_cents'] ?? 0) > 0)) $keep((int) $h->customer_id, $f['start_at'] ?? $f['source_date'] ?? null);
            }
        });
        return $out;
    }

    /** Saves the booked clients not saved yet (at most $limit per run). @return array{saved:int, failed:int} */
    public function sync(int $limit = 50): array
    {
        $c = self::connection(); $out = ['saved' => 0, 'failed' => 0];
        if (! $c || ! self::configured()) return $out;
        $booked = self::bookedCustomers();
        $done = CustomerGoogleContact::whereIn('customer_id', array_keys($booked))->where('status', 'saved')->pluck('customer_id')->all();
        $todo = array_slice(array_diff_key($booked, array_flip($done)), 0, $limit, true);
        if ($todo === []) return $out;
        try { $group = $this->group($c); } catch (\Throwable $e) { $c->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->save(); return $out; }
        foreach ($todo as $customerId => $first) {
            $customer = Customer::find($customerId); if (! $customer) continue;
            $row = CustomerGoogleContact::firstOrNew(['customer_id' => $customerId]);
            $person = ['names' => [['unstructuredName' => self::displayName($customer, $first)]],
                'memberships' => [['contactGroupMembership' => ['contactGroupResourceName' => $group]]]];
            if ($phone = \App\Services\Sms\PhoneNumber::e164($customer->phone)) $person['phoneNumbers'] = [['value' => $phone, 'type' => 'mobile']];
            if (filter_var($customer->email, FILTER_VALIDATE_EMAIL)) $person['emailAddresses'] = [['value' => $customer->email]];
            try {
                $r = $this->api($c)->post(self::API.'people:createContact?personFields=names', $person);
                if (! $r->successful()) throw new RuntimeException('Google respondió '.$r->status());
                $row->fill(['resource_name' => $r->json('resourceName'), 'google_email' => $c->google_email, 'status' => 'saved', 'error' => null])->save();
                $out['saved']++;
            } catch (\Throwable $e) {
                $row->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 255)])->save();
                $out['failed']++;
                if ($e->getMessage() === 'google_contacts_token') break;
            }
        }
        return $out;
    }

    public static function counts(): array
    {
        return ['saved' => CustomerGoogleContact::where('status', 'saved')->count(), 'failed' => CustomerGoogleContact::where('status', 'failed')->count(),
            'pending' => max(0, count(self::bookedCustomers()) - CustomerGoogleContact::where('status', 'saved')->count())];
    }
}
