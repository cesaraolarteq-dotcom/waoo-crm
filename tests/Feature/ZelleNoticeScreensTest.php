<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EventMoney;
use App\Models\{Contract, Customer, Event, EventService, LedgerEntry, PriceVersion, ServiceVariant, User, ZelleNotice, ZelleNoticeReview};
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, InHouseSignatureProvider, IssueContract};
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, RecordZellePayment};
use App\Services\Portal\IssuePortalGrant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's screens over Codex's PAY-02 Zelle notices: the client declares "Ya envié el Zelle" (pending, never money),
// the owner verifies what really arrived or rejects it on Dinero; Inicio and the event history show it. Fixtures only.
class ZelleNoticeScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Event $event;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16T00:01:00-05:00'));
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost']);
        Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $customer = Customer::create(['name' => 'Ana Ficticia', 'email' => 'ana@example.test', 'phone' => '555-0100', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = CarbonImmutable::parse('2026-12-19 15:00', 'America/Chicago');
        $this->event = Event::create(['customer_id' => $customer->id, 'title' => 'Fiesta Zelle', 'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'timezone' => 'America/Chicago',
            'address' => 'Fixture address 1', 'postal_code' => '00001', 'travel_fee_cents' => 2500]);
        EventService::create(['event_id' => $this->event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        $t = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Fixture terms only.</p>', 'change_note' => 'Fixture Zelle screens test'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $t->id, ['expected_source_sha256' => $t->source_sha256, 'approval_note' => 'Fixture approval only']);
        $rev = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $rev->id, 'amount_cents' => 6250, 'currency' => 'USD', 'reference' => 'ZELLE-DEPOSIT-1', 'received_at' => now()->subHour()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $c = app(CreateContract::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version, 'financial_revision_id' => $rev->id, 'template_version_id' => $t->id,
            'client' => ['first_name' => 'Ana', 'last_name' => 'Ficticia', 'address' => 'Fixture billing', 'email' => 'ana@example.test', 'phone' => '555-0100']], (string) Str::uuid());
        app(IssueContract::class)->execute($this->owner, $c->id, ['expected_version' => $c->version], (string) Str::uuid());
        $this->token = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $customer->id, 'expires_at' => now()->addDays(7)->toIso8601String()])->token;

        // The client signs in her own session, so the portal reaches the payment step.
        $this->postJson('/portal/api/session', ['token' => $this->token])->assertOk();
        $contract = Contract::sole();
        $this->get("/portal/api/contracts/{$contract->id}/pdf")->assertOk();
        $this->postJson("/portal/api/contracts/{$contract->id}/sign", ['expected_version' => $contract->version, 'presented_pdf_sha256' => $contract->presented_pdf_sha256,
            'signer_name' => 'Ana Ficticia', 'consent' => true, 'intent_to_sign' => true, 'consent_version' => InHouseSignatureProvider::CONSENT_VERSION], ['Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
    }

    private function clientNotice(int $cents, string $ref = 'CLIENT-CLAIM-1'): int
    {
        return $this->postJson('/portal/api/zelle-notices', ['financial_revision_id' => \App\Models\FinancialRevision::sole()->id, 'amount_cents' => $cents, 'reference' => $ref, 'payer_name' => 'Ana F'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('notice.id');
    }

    private function asOwner(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_client_notice_is_pending_until_the_owner_verifies_the_real_amount(): void
    {
        $this->get('/portal')->assertOk()->assertSee('Saldo pendiente')->assertSee('Waoo Party LLC')
            // Owner 2026-10-26: the portal no longer shows the "Ya envié el Zelle" form; the notice API stays for existing clients.
            ->assertDontSee('id="zelle-form"', false)->assertDontSee('Ya envié el Zelle');
        $this->get('/portal?lang=en')->assertDontSee('I sent my Zelle payment');
        $id = $this->clientNotice(6250);

        // Pending: the client sees it is not a payment yet; the balance does not move.
        $this->get('/portal?lang=es')->assertSee('Pendiente de verificación')->assertDontSee('¿Enviaste otro Zelle? Avísanos')->assertSee('Saldo')->assertSee('$62.50');
        $this->assertSame(6250, app(FinanceSummary::class)->forEvent($this->event->fresh())['balance_cents']);
        $this->assertSame(1, LedgerEntry::count());

        // Owner: Inicio card, Dinero list with the claim, history.
        $this->asOwner();
        $this->get('/admin')->assertSee('Avisos de Zelle por verificar')->assertSee('Fiesta Zelle · dice $62.50');
        $this->get("/admin/eventos/{$this->event->id}/dinero")->assertOk()->assertSee('Avisos de Zelle del cliente')->assertSee('Dice $62.50')->assertSee('Por verificar')->assertSee('su ref. CLIENT-CLAIM-1');
        $this->get("/admin/eventos/{$this->event->id}")->assertSee('El cliente avisó que envió un Zelle');

        // Verify with what really arrived (different from the claim) and a review note.
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->callAction('verifyNotice', ['amount' => '60', 'reference' => 'BANK-REF-77', 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05',
                'verified' => true, 'review_note' => 'Llegaron 60 en el banco, faltan 2.50.'], ['id' => $id])
            ->assertHasNoActionErrors()->assertNotified('Aviso verificado y pago registrado');
        $this->assertSame('verified', ZelleNoticeReview::sole()->decision);
        $this->assertSame(250, app(FinanceSummary::class)->forEvent($this->event->fresh())['balance_cents']);
        $this->get('/admin')->assertDontSee('Avisos de Zelle por verificar');
        $this->get("/admin/eventos/{$this->event->id}/dinero")->assertSee('Verificado')->assertSee('llegó $60')->assertSee('Llegaron 60 en el banco');
        $this->get("/admin/eventos/{$this->event->id}")->assertSee('Revisó un aviso de Zelle del cliente: verificado');

        // The client sees the verified amount, never the owner's note or bank reference.
        auth()->logout();
        $this->get('/portal')->assertSee('Verificamos tu Zelle: llegaron $60')->assertDontSee('BANK-REF-77')->assertDontSee('Llegaron 60 en el banco');
    }

    public function test_owner_rejects_a_notice_without_touching_money(): void
    {
        $id = $this->clientNotice(6250, 'NOT-IN-BANK');
        $this->asOwner();
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->callAction('rejectNotice', ['review_note' => 'corto'], ['id' => $id])->assertHasActionErrors(['review_note']);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->callAction('rejectNotice', ['review_note' => 'No aparece en el banco después de 2 días.'], ['id' => $id])->assertNotified('Aviso rechazado');
        $this->assertSame('rejected', ZelleNoticeReview::sole()->decision);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(6250, app(FinanceSummary::class)->forEvent($this->event->fresh())['balance_cents']);
        $this->get("/admin/eventos/{$this->event->id}")->assertSee('Revisó un aviso de Zelle del cliente: rechazado');

        auth()->logout();
        $this->get('/portal')->assertSee('No pudimos verificar tu aviso de $62.50')->assertDontSee('No aparece en el banco')->assertDontSee('id="zelle-form"', false);
    }

    public function test_notices_from_another_event_are_not_reachable_from_this_dinero_page(): void
    {
        $id = $this->clientNotice(6250);
        $other = Event::create(['customer_id' => $this->event->customer_id, 'title' => 'Otra fiesta', 'starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHour(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 0]);
        $this->asOwner();
        Livewire::test(EventMoney::class, ['record' => $other->id])->assertDontSee('Avisos de Zelle del cliente')
            ->assertActionHidden('rejectNotice', ['id' => $id])->assertActionHidden('verifyNotice', ['id' => $id]);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertActionVisible('rejectNotice', ['id' => $id]);
        $this->assertSame(0, ZelleNoticeReview::count());
        $this->assertSame(1, ZelleNotice::count());
    }
}
