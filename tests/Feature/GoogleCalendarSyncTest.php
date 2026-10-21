<?php
namespace Tests\Feature;

use App\Filament\Pages\Calendar;
use App\Filament\Pages\QuickBooking;
use App\Models\{Customer, EventGoogleLink, GoogleCalendarConnection, PriceVersion, ServiceVariant, User};
use App\Services\Google\GoogleCalendar;
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-10-21: two-way Google Calendar (synthetic Google responses only; no real account is touched). */
class GoogleCalendarSyncTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.google_calendar.client_id' => 'client.apps.googleusercontent.com', 'waoo.google_calendar.client_secret' => 'synthetic-secret']);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'email' => 'maria@example.test',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Música y Juegos 2.0', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => [['kind' => 'staff', 'skill' => 'host', 'quantity' => 1]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 60000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    private function fakeGoogle(array $busy = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600, 'refresh_token' => 'rt',
                'id_token' => 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'maria@example.test'])), '+/', '-_'), '=').'.y']),
            'www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'cal-waoo@group.calendar.google.com']),
            'www.googleapis.com/calendar/v3/calendars/cal-waoo%40group.calendar.google.com' => Http::response(['id' => 'cal-waoo@group.calendar.google.com']),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'g-1']),
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'g-1']),
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => $busy]]]),
        ]);
    }

    private function party(string $title = 'Fiesta de Lucía'): \App\Models\Event
    {
        $customer = Customer::create(['name' => 'María González', 'phone' => '346-555-0100', 'locale' => 'es']);

        return app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, 'title' => $title, 'address' => '123 Oak Street', 'postal_code' => '77494',
            'starts_at' => '2026-12-19T15:00:00-06:00', 'ends_at' => '2026-12-19T17:00:00-06:00', 'timezone' => 'America/Chicago',
            'travel_fee_cents' => 0, 'services' => [['price_version_id' => $this->price->id]]]);
    }

    public function test_connect_flow_checks_state_creates_the_parties_calendar_and_sends_upcoming_parties(): void
    {
        $this->fakeGoogle();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-01T10:00:00-06:00'));
        $this->party();
        $this->get('/admin/google/connect')->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth');
        $state = session('google_calendar_state');
        $this->get('/admin/google/callback?state=wrong&code=abc')->assertRedirect('/admin/calendario');
        $this->assertDatabaseCount('google_calendar_connections', 0);
        $this->withSession(['google_calendar_state' => $state])->get('/admin/google/callback?state='.$state.'&code=abc')->assertRedirect('/admin/calendario');
        $c = GoogleCalendarConnection::sole();
        $this->assertSame('maria@example.test', $c->google_email);
        $this->assertSame('cal-waoo@group.calendar.google.com', $c->calendar_id);
        $this->assertSame('g-1', EventGoogleLink::sole()->google_event_id);
        $this->assertNotSame('rt', $c->getRawOriginal('refresh_token')); // encrypted at rest
    }

    public function test_saving_a_party_creates_then_updates_it_in_google_without_client_contact(): void
    {
        $this->fakeGoogle();
        GoogleCalendarConnection::create(['user_id' => $this->owner->id, 'refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => now()->addHour(), 'calendar_id' => 'cal-waoo@group.calendar.google.com']);
        $event = $this->party();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/events') && $r['summary'] === 'Fiesta de Lucía'
            && $r['location'] === '123 Oak Street, 77494' && str_contains($r['description'], 'Cliente: María González')
            && ! str_contains($r['description'], '555-0100') && $r['start']['timeZone'] === 'America/Chicago');
        app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $event->customer_id, 'title' => 'Fiesta de Lucía (cambio)',
            'starts_at' => '2026-12-19T16:00:00-06:00', 'ends_at' => '2026-12-19T18:00:00-06:00', 'timezone' => 'America/Chicago',
            'travel_fee_cents' => 0, 'services' => [['price_version_id' => $this->price->id]], 'expected_version' => $event->version], $event->id);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/g-1') && $r['summary'] === 'Fiesta de Lucía (cambio)');
    }

    public function test_google_failure_never_blocks_saving_and_is_shown_for_retry(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        GoogleCalendarConnection::create(['user_id' => $this->owner->id, 'refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => now()->addHour(), 'calendar_id' => 'cal-waoo@group.calendar.google.com']);
        $event = $this->party();
        $this->assertNotNull($event->id);
        $this->assertNotNull(EventGoogleLink::where('event_id', $event->id)->value('last_error'));
        Livewire::test(Calendar::class)->assertSee('1 fiesta sin enviar')->assertSee('Sincronizar ahora');
    }

    public function test_busy_times_from_google_show_in_calendar_and_quick_booking(): void
    {
        $this->fakeGoogle([['start' => '2026-12-20T20:00:00Z', 'end' => '2026-12-20T22:00:00Z']]);
        GoogleCalendarConnection::create(['user_id' => $this->owner->id, 'refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => now()->addHour(), 'calendar_id' => 'cal-waoo@group.calendar.google.com']);
        $this->assertSame('No hay fiestas ese día. En tu Google estás ocupado: 2:00 PM–4:00 PM.', QuickBooking::dayLine('2026-12-20'));
        Livewire::withQueryParams(['mode' => 'day', 'date' => '2026-12-20'])->test(Calendar::class)->assertSee('Ocupado en tu Google · 2:00 PM–4:00 PM');
    }

    public function test_without_client_configuration_nothing_is_offered_or_sent(): void
    {
        config(['waoo.google_calendar.client_id' => null]);
        Http::fake();
        $this->party();
        Http::assertNothingSent();
        Livewire::test(Calendar::class)->assertDontSee('Google Calendar');
        $this->get('/admin/google/connect')->assertNotFound();
    }
}
