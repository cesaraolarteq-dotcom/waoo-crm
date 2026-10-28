<?php
namespace Tests\Feature;

use App\Models\{Contract, Customer, Event, EventService, PriceVersion, ServiceVariant, SignatureEvidence, User};
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment, StripeGateway};
use App\Services\Portal\IssuePortalGrant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

// The client's real portal page end to end, through Codex's services and /portal/api (fixture terms; fake Stripe adapter).
class ClientPortalPageTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost',
            'waoo.stripe_test_secret' => 'sk_test_fixture', 'waoo.stripe_test_account' => 'acct_fixture']);
        Queue::fake();
        $this->app->instance(StripeGateway::class, new FakeStripeGateway());
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $customer = Customer::create(['name' => 'Ana Ficticia', 'email' => 'ana@example.test', 'phone' => '555-0100', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $start = CarbonImmutable::parse('2026-12-19 15:00', 'America/Chicago');
        $this->event = Event::create(['customer_id' => $customer->id, 'title' => 'Fiesta', 'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'timezone' => 'America/Chicago',
            'address' => 'Fixture address 1', 'postal_code' => '00001', 'travel_fee_cents' => 2500]);
        EventService::create(['event_id' => $this->event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        $service = $this->event->services()->sole();
        $service->update(['extra_characters'=>2,'extra_character_cents'=>0]);
        $character = \App\Models\Character::create(['name'=>'Rumi','active'=>true]);
        \App\Models\EventServiceCharacter::create(['event_service_id'=>$service->id,'character_id'=>$character->id,'quantity'=>1,'character_name_snapshot'=>'Rumi','outfit'=>'Golden (blanco/negro)']);
        $t = app(CreateTemplateVersion::class)->execute($owner, ['terms_html' => '<p>Fixture terms only.</p>', 'change_note' => 'Fixture portal page test'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($owner, $t->id, ['expected_source_sha256' => $t->source_sha256, 'approval_note' => 'Fixture approval only']);
        $rev = app(CreateFinancialRevision::class)->execute($owner, $this->event->id, ['expected_version' => $this->event->fresh()->version], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($owner, $this->event->id, ['financial_revision_id' => $rev->id, 'amount_cents' => 6250, 'currency' => 'USD', 'reference' => 'ZELLE-PORTAL-1', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $c = app(CreateContract::class)->execute($owner, $this->event->id, ['expected_version' => $this->event->fresh()->version, 'financial_revision_id' => $rev->id, 'template_version_id' => $t->id,
            'client' => ['first_name' => 'Ana', 'last_name' => 'Ficticia', 'address' => 'Fixture billing', 'email' => 'ana@example.test', 'phone' => '555-0100']], (string) Str::uuid());
        app(IssueContract::class)->execute($owner, $c->id, ['expected_version' => $c->version], (string) Str::uuid());
        $grant = app(IssuePortalGrant::class)->execute($owner, $this->event->id, ['customer_id' => $customer->id, 'expires_at' => now()->addDays(7)->toIso8601String()]);
        $this->token = $grant->token;
    }

    public function test_landing_exchanges_the_token_then_the_client_reads_signs_and_sees_how_to_pay(): void
    {
        $this->get('/portal')->assertOk()->assertSee('Abriendo tu reserva')->assertSee('csrf-token', false)->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee('fonts.googleapis');
        $this->postJson('/portal/api/session', ['token' => $this->token])->assertOk();

        $page = $this->get('/portal')->assertOk()->assertSee('¡Hola, Ana!')->assertSee('en 2 pasos')->assertSee('Tu contrato')->assertSee('$125')->assertSee('$62.50')
            ->assertSee('Acepto firmar electrónicamente y recibir este contrato en formato electrónico.')->assertDontSee('Tus datos');
        $contract = Contract::sole();
        $this->postJson("/portal/api/contracts/{$contract->id}/sign", ['expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256,
            'signer_name' => 'Ana Ficticia', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => \App\Services\Documents\InHouseSignatureProvider::CONSENT_VERSION], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable(); // must open the full PDF first
        $this->get("/portal/api/contracts/{$contract->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/portal/api/contracts/{$contract->id}/sign", ['expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256,
            'signer_name' => 'Ana Ficticia', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => \App\Services\Documents\InHouseSignatureProvider::CONSENT_VERSION], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertSuccessful();
        $this->assertSame('Ana Ficticia', SignatureEvidence::sole()->signer_name);

        // Owner 2026-10-22: signed + deposit met -> the party reads as booked and the balance is a reminder.
        $this->get('/portal')->assertOk()->assertSee('¡Tu fiesta está reservada!')->assertDontSee('en 2 pasos')->assertSee('Saldo pendiente')->assertSee('No tienes que pagar nada más ahora')->assertSee('Pagar el saldo')->assertSee('Waoo Party LLC · 346-216-8739')->assertSee('WP-'.$this->event->id)->assertSee('Pagar $64.38')->assertSee('Tarjeta de crédito o débito')->assertDontSee('Klarna');
        $this->get('/portal?lang=en')->assertSee('Remaining balance')->assertSee('Pay the balance')->assertSee('Pay $64.38')->assertSee('$62.50 + $1.88 (3 %) =')->assertSee('Cards carry a 3% processing fee');
        $this->postJson('/portal/api/checkout-intents', ['financial_revision_id' => \App\Models\FinancialRevision::sole()->id, 'purpose' => 'balance'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('principal_cents', 6250)->assertJsonPath('customer_fee_cents', 188)->assertJsonPath('payable_cents', 6438);
        $this->get('/portal/payment-return')->assertRedirect('/portal');
        $this->get('/portal?lang=es')->assertSee('Estamos confirmando tu pago');
    }

    public function test_client_summary_and_api_hide_internal_wardrobe_without_changing_the_financial_snapshot(): void
    {
        $this->postJson('/portal/api/session',['token'=>$this->token])->assertOk();
        foreach (['es','en'] as $lang) {
            $this->get('/portal?lang='.$lang)->assertOk()->assertSee('Rumi')->assertDontSee('Golden (blanco/negro)')->assertDontSee('+ 2 personajes adicionales')->assertDontSee('+ 2 extra characters');
        }
        $this->getJson('/portal/api/event')->assertOk()->assertJsonPath('services.0.characters.0.name','Rumi')->assertJsonMissingPath('services.0.characters.0.outfit');
        $this->assertSame('Golden (blanco/negro)',\App\Models\FinancialRevision::sole()->lines_snapshot[0]['characters'][0]['outfit']);
    }

    public function test_invalid_link_and_disabled_sandbox(): void
    {
        $this->postJson('/portal/api/session', ['token' => str_repeat('x', 43)])->assertForbidden();
        $this->get('/portal')->assertSee('Abriendo tu reserva'); // landing explains expired links client-side
        config(['waoo.sandbox_documents' => false]);
        $this->get('/portal')->assertNotFound();
    }
}
