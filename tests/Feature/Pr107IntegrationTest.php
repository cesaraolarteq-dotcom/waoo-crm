<?php

namespace Tests\Feature;

use App\Models\{Character, Contract, Event, LedgerEntry, PriceVersion, Resource, ServiceVariant, SignatureEvidence, User};
use App\Services\Booking\{PrepareQuickStripeCheckoutRequest, PrepareQuickStripeLease, QuickBook};
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion};
use App\Services\Finance\{CreateFinancialRevision, StripeGateway};
use App\Services\Records\SaveEventDraft;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Queue, Schema, Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Crosses PR106's additional-character pricing with PR107's paid hold, signature and unpaid request sources. */
class Pr107IntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private PriceVersion $price;
    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_immediate_contracts' => true,
            'waoo.sandbox_quick_stripe' => true, 'waoo.stripe_test_account' => 'acct_syntheticWaoo', 'app.url' => 'http://localhost']);
        Http::preventStrayRequests(); Queue::fake(); Storage::fake('local');
        $gateway = \Mockery::mock(StripeGateway::class);
        $gateway->shouldNotReceive('createCheckout', 'retrieveCheckout', 'expireCheckout');
        $this->app->instance(StripeGateway::class, $gateway);
        $this->owner = User::factory()->create(['name' => 'María González', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['0']]);
        foreach ([1, 2] as $n) Resource::create(['code' => 'MICKEY-'.$n, 'name' => 'Cabeza de Mickey '.$n,
            'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        $variant = ServiceVariant::create(['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120,
            'character_quantity' => 1, 'requirements' => [], 'character_options' => [['character_id' => $this->character->id,
                'requirements' => [['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1],
                    ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 60000,
            'currency' => 'USD', 'created_by' => $this->owner->id]);
    }

    private function input(int $days = 20): array
    {
        $start = now()->addDays($days)->startOfHour();
        return ['new_customer' => ['name' => 'Elena Martínez', 'locale' => 'es'],
            'title' => 'Cumpleaños de Lucía', 'event_type' => 'birthday', 'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Example Lane', 'postal_code' => '77001', 'travel_fee_cents' => 2000, 'setup_minutes' => 0, 'breakdown_minutes' => 0,
            'services' => [['price_version_id' => $this->price->id,
                'characters' => [['character_id' => $this->character->id, 'quantity' => 2, 'outfit' => '0']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 38500, 'reference' => 'ELENA-INTEGRATION-'.$days,
                'received_on' => now('America/Chicago')->toDateString(), 'verified_received' => true]];
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('quick_stripe_checkout_requests')) DB::table('quick_stripe_checkout_requests')->delete();
        parent::tearDown();
    }

    private function booking(int $days = 20): \App\Services\Booking\QuickBooking
    {
        return app(QuickBook::class)->execute($this->owner, $this->input($days), (string) Str::uuid());
    }

    private function token(\App\Services\Booking\QuickBooking $booking): string
    {
        $this->assertNotNull($booking->portal_url);
        parse_str(parse_url($booking->portal_url, PHP_URL_FRAGMENT), $parts);
        return $parts['token'];
    }

    private function details(Event $event): array
    {
        return ['expected_event_version' => $event->version, 'expected_revision' => 0,
            'first_name' => 'Elena', 'last_name' => 'Martínez', 'phone' => '+17135550126', 'email' => 'elena@example.test',
            'billing_address' => '456 Example Road', 'event_address' => '123 Example Lane', 'event_postal_code' => '77001', 'start_time_confirmed' => true];
    }

    public function test_additional_character_price_and_two_real_costumes_survive_same_visit_pdf_and_signature(): void
    {
        $booking = $this->booking();
        $this->assertSame(77000, $booking->financial_revision->total_cents);
        $this->assertSame('held', $booking->costume_hold['state']);
        $this->assertCount(2, $booking->costume_hold['rows']);
        $this->assertDatabaseCount('resource_reservations', 2);
        $originalReceipt = LedgerEntry::sole()->getRawOriginal();
        $template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Acuerdo sintético para la celebración de Lucía.</p>',
            'change_note' => 'Plantilla ficticia para comprobar la integración.'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $template->id,
            ['expected_source_sha256' => $template->source_sha256, 'approval_note' => 'Aprobación sintética para esta prueba de integración.']);
        $this->postJson('/portal/api/session', ['token' => $this->token($booking)])->assertOk();
        $this->get('/portal?lang=es')->assertOk()->assertSee('1 personaje adicional');
        $this->get('/portal?lang=en')->assertOk()->assertSee('1 extra character');
        $this->postJson('/portal/api/details', $this->details($booking->event), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('contract_preparation.status', 'ready');
        $contract = Contract::sole();
        $this->assertSame(77000, $contract->commercial_snapshot['total_cents']);
        $this->assertSame(75000, $contract->commercial_snapshot['services'][0]['amount_cents']);
        $this->assertSame(1, $contract->commercial_snapshot['services'][0]['extra_characters']);
        $this->assertSame(15000, $contract->commercial_snapshot['services'][0]['extra_character_cents']);
        $this->get('/portal/api/contracts/'.$contract->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/portal/api/contracts/'.$contract->id.'/sign', ['expected_version' => $contract->version,
            'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'Elena Martínez',
            'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v2-2026-10-20-es'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('signed', $contract->fresh()->state);
        $this->assertDatabaseCount('signature_evidence', 1);
        $this->assertSame($contract->presented_pdf_sha256, SignatureEvidence::sole()->presented_pdf_sha256);
        $this->assertDatabaseCount('client_details_reviews', 0);
        $this->assertSame($originalReceipt, LedgerEntry::sole()->getRawOriginal());
        $this->assertNull($booking->customer->fresh()->email);
        $this->assertSame('draft', $booking->event->fresh()->status);
        $this->assertDatabaseCount('resource_reservations', 2);
    }

    public function test_unpaid_extra_character_price_is_frozen_into_request_and_fee_without_a_receipt(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Elena Martínez', 'locale' => 'es']);
        $input = $this->input(); unset($input['new_customer'], $input['deposit']);
        $event = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $customer->id, ...$input]);
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $event->id, ['expected_version' => $event->version], (string) Str::uuid());
        $lease = app(PrepareQuickStripeLease::class)->execute($this->owner, $event->id,
            ['expected_event_version' => $event->version, 'financial_revision_id' => $finance->id], (string) Str::uuid());
        $this->assertCount(2, $lease['rows']);
        $this->assertSame(77000, $finance->total_cents);
        $view = app(PrepareQuickStripeCheckoutRequest::class)->execute($this->owner, $event->id,
            ['expected_event_version' => $event->version, 'expected_lease_version' => $lease['version'],
                'expected_terms_sha256' => $lease['terms']['sha256']], (string) Str::uuid());
        $this->assertSame(38500, $view['principal_cents']);
        $this->assertSame(1155, $view['customer_fee_cents']);
        $this->assertSame(39655, $view['payable_cents']);
        $this->assertFalse($view['provider_called']);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('resource_reservations', 2);
    }

    public function test_new_link_rotates_csrf_and_an_old_tab_cannot_submit_details_to_the_new_party(): void
    {
        $old = $this->booking(); $new = $this->booking(21);
        config(['session.secure' => true]); $this->app->instance('env', 'staging');
        $this->withSession(['_token' => 'synthetic-first-link-csrf']);
        $first = $this->postJson('https://localhost/portal/api/session', ['token' => $this->token($old)],
            ['X-CSRF-TOKEN' => 'synthetic-first-link-csrf'])->assertOk();
        $oldCsrf = $first->json('csrf_token');
        $second = $this->postJson('https://localhost/portal/api/session', ['token' => $this->token($new)],
            ['X-CSRF-TOKEN' => $oldCsrf])->assertOk();
        $this->assertNotSame($oldCsrf, $second->json('csrf_token'));
        $this->postJson('https://localhost/portal/api/details', $this->details($old->event),
            ['X-CSRF-TOKEN' => $oldCsrf, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(419);
        $this->assertDatabaseCount('client_details_submissions', 0);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
}
