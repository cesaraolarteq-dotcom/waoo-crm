<?php
namespace Tests\Feature;

use App\Models\{Contract, Event, EventExtra, FinancialRevision, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion};
use App\Services\Extras\EventExtras;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Schema, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** EXTRAS-01 (owner 2026-11-02/03): add an extra from the link with a checkbox annex; final, added to the balance, same time. */
class EventExtrasTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Event $event; private ServiceVariant $slime;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_immediate_contracts' => true, 'waoo.sandbox_extras' => true, 'app.url' => 'https://localhost']);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        foreach (['ANIMA-1' => ['entertainer', 'driver'], 'RECRE-1' => ['host']] as $code => $skills) Resource::create(['code' => $code, 'name' => 'Sofía Méndez '.$code, 'kind' => 'staff', 'status' => 'active', 'skills' => $skills]);
        $party = ServiceVariant::create(['name' => 'Celebración alegre', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer']]]);
        $price = PriceVersion::create(['service_variant_id' => $party->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->slime = ServiceVariant::create(['name' => 'Estación de slime', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']]]);
        $this->slime->forceFill(['offered_as_extra' => true])->save();
        PriceVersion::create(['service_variant_id' => $this->slime->id, 'amount_cents' => 25000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $booking = app(QuickBook::class)->execute($this->owner, [
            'new_customer' => ['phone' => '+17135550126', 'locale' => 'es'], 'title' => 'Cumpleaños de Sofía', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Example Lane', 'postal_code' => '77001', 'travel_fee_cents' => 0, 'setup_minutes' => 0, 'breakdown_minutes' => 0,
            'services' => [['price_version_id' => $price->id]], 'deposit' => ['method' => 'zelle', 'amount_cents' => 20000,
                'reference' => 'MARIAG-001', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        $this->event = $booking->event;
        $t = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Acuerdo ficticio, solo para pruebas.</p>', 'change_note' => 'Plantilla sintética para la visita de María.'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $t->id, ['expected_source_sha256' => $t->source_sha256, 'approval_note' => 'Aprobación ficticia para prueba automatizada.']);
        parse_str(parse_url($booking->portal_url, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/portal/api/session', ['token' => $fragment['token']])->assertOk();
        $this->postJson('/portal/api/details', ['expected_event_version' => $this->event->version, 'expected_revision' => 0, 'first_name' => 'María', 'last_name' => 'González',
            'phone' => '+17135550126', 'email' => 'maria@example.test', 'billing_address' => '456 Example Road', 'event_address' => '123 Example Lane', 'event_postal_code' => '77001',
            'start_time_confirmed' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/portal/api/details/contract', ['expected_event_version' => $this->event->fresh()->version, 'expected_revision' => 1], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $c = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk();
        $this->postJson('/portal/api/contracts/'.$c->id.'/sign', ['expected_version' => $c->version, 'presented_pdf_sha256' => $c->presented_pdf_sha256, 'signer_name' => 'María González',
            'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v2-2026-10-20-es'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        // The owner confirms the booking (Confirmar reserva); only confirmed parties are offered extras.
        DB::table('events')->where('id', $this->event->id)->update(['status' => 'capacity_reserved']);
        $this->event->refresh();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('customers')) DB::table('customers')->whereNull('name')->update(['name' => 'María González']);
        parent::tearDown();
    }

    public function test_client_reads_the_annex_ticks_and_the_extra_is_added_to_the_balance(): void
    {
        $this->assertNull(EventExtras::blocked($this->event));
        $page = $this->get('/portal?lang=es')->assertOk()->assertSee('Agrega algo a tu fiesta')->assertSee('Estación de slime')->assertSee('no extiende la hora de la fiesta');
        $offer = collect($page->viewData('vm')['extras']['offers'])->firstWhere('key', 'v'.$this->slime->id);
        $before = FinancialRevision::where('event_id', $this->event->id)->orderByDesc('revision')->first();
        // Without the box ticked nothing happens; a stale annex is refused.
        $this->postJson('/portal/api/extras', ['offer' => $offer['key'], 'annex_sha256' => $offer['sha'], 'lang' => 'es'])->assertStatus(422);
        $this->postJson('/portal/api/extras', ['offer' => $offer['key'], 'annex_sha256' => str_repeat('a', 64), 'accept' => true, 'lang' => 'es'])->assertStatus(409);
        $this->postJson('/portal/api/extras', ['offer' => $offer['key'], 'annex_sha256' => $offer['sha'], 'accept' => true, 'lang' => 'es'])->assertCreated();

        $after = FinancialRevision::where('event_id', $this->event->id)->orderByDesc('revision')->first();
        $this->assertSame($before->total_cents + 25000, $after->total_cents);
        $this->assertSame($before->deposit_target_cents, $after->deposit_target_cents, 'the deposit does not change');
        $this->assertTrue($before->balance_due_at->equalTo($after->balance_due_at));
        $x = EventExtra::sole();
        $this->assertSame(hash('sha256', $x->annex_text), $x->annex_sha256);
        $this->assertStringContainsString('no se puede cancelar ni reembolsar', $x->annex_text);
        $this->assertSame(2, $this->event->services()->count());
        // Already added: no longer offered.
        $this->assertNull(collect(EventExtras::offers($this->event->fresh()))->firstWhere('key', 'v'.$this->slime->id));
    }

    public function test_not_offered_when_the_team_does_not_fit_or_within_7_days(): void
    {
        Resource::where('code', 'RECRE-1')->update(['status' => 'inactive']); // nobody can run the slime station
        $offer = collect(EventExtras::offers($this->event))->firstWhere('key', 'v'.$this->slime->id);
        $sha = hash('sha256', EventExtras::annex($this->event, $offer, 'es'));
        $this->postJson('/portal/api/extras', ['offer' => $offer['key'], 'annex_sha256' => $sha, 'accept' => true, 'lang' => 'es'])->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'ya no cabe'));
        $this->assertSame(1, $this->event->services()->count(), 'nothing was added');
        $this->assertSame(0, EventExtra::count());
        // The link no longer shows what does not fit, so the client never reads an annex for it.
        $this->assertNull(collect(EventExtras::fitting($this->event))->firstWhere('key', 'v'.$this->slime->id));
        $this->get('/portal?lang=es')->assertDontSee($this->slime->name);
        $this->travelTo($this->event->starts_at->subDays(6));
        $this->assertSame('too_late', EventExtras::blocked($this->event->fresh()));
        $this->get('/portal?lang=es')->assertDontSee('Agrega algo a tu fiesta');
    }
}
