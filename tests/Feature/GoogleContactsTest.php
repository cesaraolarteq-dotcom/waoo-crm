<?php
namespace Tests\Feature;

use App\Models\{Customer, CustomerGoogleContact, Event, GoogleContactConnection, User};
use App\Services\Google\GoogleContacts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner 2026-11-23: booked clients go to his Google Contacts (synthetic Google responses only). */
class GoogleContactsTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.google_calendar.client_id' => 'client.apps.googleusercontent.com', 'waoo.google_calendar.client_secret' => 'synthetic-secret']);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600, 'refresh_token' => 'rt', 'scope' => 'openid email https://www.googleapis.com/auth/contacts',
                'id_token' => 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'waoo@example.test'])), '+/', '-_'), '=').'.y']),
            'people.googleapis.com/v1/contactGroups' => Http::response(['resourceName' => 'contactGroups/abc', 'name' => 'Clientes Waoo']),
            'people.googleapis.com/v1/people:createContact*' => Http::sequence()->push(['resourceName' => 'people/c1'])->push(['resourceName' => 'people/c2']),
        ]);
    }

    private function event(string $name, string $status, string $start): Event
    {
        $c = Customer::create(['name' => $name, 'phone' => '346-555-0123', 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test', 'locale' => 'es']);
        $e = new Event(['customer_id' => $c->id, 'title' => 'Fiesta', 'status' => $status, 'starts_at' => $start, 'ends_at' => CarbonImmutable::parse($start)->addHours(2)]);
        $e->forceFill(['customer_id' => $c->id, 'status' => $status, 'starts_at' => $start, 'ends_at' => CarbonImmutable::parse($start)->addHours(2)])->save();
        return $e;
    }

    public function test_connect_through_the_shared_callback_and_save_only_booked_clients_once(): void
    {
        $this->event('María González', 'capacity_reserved', '2026-12-12T21:00:00Z');
        $this->event('Sofía Méndez', 'draft', '2026-12-19T21:00:00Z'); // not booked: never saved
        $state = $this->get('/admin/google/contacts/connect')->assertRedirect()->baseResponse->getTargetUrl();
        parse_str(parse_url($state, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('auth/contacts', $q['scope']);
        $this->get('/admin/google/callback?state='.$q['state'].'&code=abc')->assertRedirect('/admin/clientas');
        $c = GoogleContactConnection::sole();
        $this->assertSame(['waoo@example.test', 'contactGroups/abc'], [$c->google_email, $c->group_resource_name]);
        $this->assertSame(1, CustomerGoogleContact::where('status', 'saved')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'createContact')
            && $r['names'][0]['unstructuredName'] === 'María González · Waoo dic 2026'
            && $r['phoneNumbers'][0]['value'] === '+13465550123'
            && $r['memberships'][0]['contactGroupMembership']['contactGroupResourceName'] === 'contactGroups/abc');
        $this->assertSame(['saved' => 0, 'failed' => 0], app(GoogleContacts::class)->sync(), 'Never twice.');
    }

    public function test_first_booked_party_names_the_month_in_houston_time(): void
    {
        $c = new Customer(['name' => 'Ana Torres']);
        $this->assertSame('Ana Torres · Waoo nov 2026', GoogleContacts::displayName($c, CarbonImmutable::parse('2026-12-01T03:00:00Z')));
    }

    public function test_past_honeybook_clients_count_but_cancelled_ones_do_not(): void
    {
        foreach (['completed' => 'Lucía Ramos', 'cancelled' => 'Carla Díaz'] as $status => $name) {
            $c = Customer::create(['name' => $name, 'phone' => '346-555-0144', 'locale' => 'es']);
            $h = \App\Models\HistoricalEvent::create(['project_key' => hash('sha256', $name), 'customer_id' => $c->id, 'version' => 1]);
            $r = \App\Models\HistoricalEventRevision::create(['historical_event_id' => $h->id, 'customer_id' => $c->id, 'version' => 1, 'source_sha256' => str_repeat('a', 64),
                'facts_sha256' => str_repeat('b', 64), 'payload' => ['facts' => ['status' => $status, 'start_at' => '2025-03-08T20:00:00Z']], 'payload_sha256' => str_repeat('c', 64), 'created_by' => $this->owner->id]);
            $h->forceFill(['current_revision_id' => $r->id])->save();
        }
        $booked = GoogleContacts::bookedCustomers();
        $this->assertCount(1, $booked);
        $this->assertSame('Lucía Ramos · Waoo mar 2025', GoogleContacts::displayName(Customer::find(array_key_first($booked)), $booked[array_key_first($booked)]));
    }

    public function test_without_a_connection_nothing_is_sent(): void
    {
        $this->event('María González', 'capacity_reserved', '2026-12-12T21:00:00Z');
        $this->assertSame(['saved' => 0, 'failed' => 0], app(GoogleContacts::class)->sync());
        Http::assertNothingSent();
    }
}
