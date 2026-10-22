<?php
namespace Tests\Feature;

use App\Filament\Pages\{Dashboard, EmailTemplates};
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Customer, EventMessagePolicy, FinancialRevision, OutboundMessage, OutboundMessageState, Resource};
use App\Services\Finance\{ConfirmPaidCapacity, CreateFinancialRevision, RecordZellePayment};
use App\Services\Mail\{EventMailSource, MailTransport, PauseEventMail, PlanEventMail};
use App\Services\Portal\IssuePortalGrant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\{FakeMailTransport, QuoteScenario};
use Tests\TestCase;

// MAIL-01 owner screens over Codex's outbox (synthetic copy marked as NOT owner approval; nothing is ever sent here).
class MailScreensTest extends TestCase
{
    use RefreshDatabase, QuoteScenario;

    private FinancialRevision $finance;
    private int $grantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-17T12:00:00Z'));
        $this->scenario();
        config(['waoo.sandbox_mail' => true, 'waoo.mail_allowlist' => [$this->owner->email], 'waoo.mail_review_url' => 'https://reviews.example.test/fixture']);
        $templates = [];
        foreach (EventMailSource::REMINDERS as $key) foreach (['es', 'en'] as $lang) $templates[$key][$lang] = ['version' => 'fixture1', 'approval_reference' => 'SYNTHETIC_ONLY_NOT_OWNER_APPROVAL',
            'subject' => 'Fixture '.$key, 'paragraphs' => ['Fixture {first_name}', '{event_date} {address}', '__totals__', '{balance}{balance_due}'],
            'button' => ['label' => 'Fixture link', 'variable' => $key === 'thank_you_review' ? 'review_link' : 'link'], 'closing' => 'Fixture closing', 'required_variables' => ['first_name', 'event_date', 'address', 'balance']];
        config(['waoo.mail_templates' => $templates]);
        Customer::whereKey($this->event->customer_id)->update(['email' => $this->owner->email]);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $this->finance->id, 'amount_cents' => $this->finance->deposit_target_cents, 'currency' => 'USD',
            'reference' => 'MAIL-UI-DEPOSIT', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $units = [Resource::create(['code' => 'MAILUI-PERF', 'name' => 'Fixture Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']])->id,
            Resource::create(['code' => 'MAILUI-SPK', 'name' => 'Fixture Speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        $this->event = app(ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $this->finance->id, 'resource_ids' => $units,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return reviewed.', 'transfers' => []]]);
        $this->grantId = app(IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDays(29)->toIso8601String()])->id;
        $this->app->instance(MailTransport::class, new FakeMailTransport());
    }

    private function file()
    {
        return Livewire::test(ViewEvent::class, ['record' => $this->event->getKey()]);
    }

    public function test_owner_plans_reminders_from_the_event_and_sees_honest_states(): void
    {
        $this->file()->assertSee('Correos al cliente')->assertSee('Ningún correo programado')
            ->callAction('mailPlan', data: ['portal_grant_id' => $this->grantId, 'templates' => EventMailSource::REMINDERS])
            ->assertNotified()->assertSee('Recordatorio de saldo')->assertSee('Falta una semana')->assertSee('Gracias + reseña')->assertSee('Programado')
            ->assertDontSee('Entregado')->assertDontSee($this->owner->email);
        $this->assertSame(3, OutboundMessage::count());
        $this->assertSame(['scheduled'], OutboundMessageState::pluck('status')->unique()->values()->all());
    }

    public function test_planning_refusals_are_explained_and_write_nothing(): void
    {
        config(['waoo.mail_templates' => []]);
        $this->file()->callAction('mailPlan', data: ['portal_grant_id' => $this->grantId, 'templates' => ['week_before']])
            ->assertNotified('No se programó nada');
        $this->assertDatabaseCount('outbound_messages', 0);
    }

    public function test_pause_uses_the_version_shown_and_a_stale_screen_is_refused(): void
    {
        $page = $this->file()->assertSet('mailPolicyVersion', 0)->assertSee('Pausar correos');
        $page->call('toggleMailPause')->assertNotified('Correos en pausa')->assertSet('mailPolicyVersion', 1)->assertSee('Reanudar correos')->assertSee('En pausa');
        $this->assertTrue(EventMessagePolicy::sole()->paused);
        // Another owner resumes meanwhile → this screen still shows "paused" with version 1, the server is at 2.
        app(PauseEventMail::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'paused' => false], (string) Str::uuid());
        $other = $this->file(); // fresh screen sees version 2
        $other->assertSet('mailPolicyVersion', 2);
        app(PauseEventMail::class)->execute($this->owner, $this->event->id, ['expected_version' => 2, 'paused' => true], (string) Str::uuid());
        $other->call('toggleMailPause')->assertNotified('No se cambió');
        $this->assertSame(3, EventMessagePolicy::sole()->version);
        $this->assertTrue(EventMessagePolicy::sole()->paused);
    }

    public function test_dashboard_separates_bounces_from_other_warnings(): void
    {
        $ids = app(PlanEventMail::class)->execute($this->owner, $this->event->id, ['expected_event_version' => 2, 'expected_customer_version' => 1, 'portal_grant_id' => $this->grantId], (string) Str::uuid())['message_ids'];
        OutboundMessageState::where('outbound_message_id', $ids[0])->update(['status' => 'bounced']);
        OutboundMessageState::where('outbound_message_id', $ids[1])->update(['status' => 'delivery_unknown']);
        $html = Livewire::test(Dashboard::class)->assertSee('Correos que rebotaron')->assertSee('Correos por revisar')->assertSee('No se sabe si salió')->html();
        $this->assertSame(1, substr_count($html, 'Rebotó'));
    }

    public function test_settings_page_lists_what_is_missing_without_secrets_and_fixes_the_quote_subject(): void
    {
        $this->app->forgetInstance(MailTransport::class);
        Livewire::test(EmailTemplates::class)->assertSee('¿Listos para enviar?')->assertSee('Sin configurar')->assertSee($this->owner->email)
            ->assertSee('Aprobar los textos (6 de 14 aprobados') // fixture templates for 3 of the 7 emails left after retiring contract_ready and zelle_notice_pending
            ->assertSee('Conectar un proveedor de correo')
            ->assertSee('Tu cotización Waoo Party para el sábado 21 de noviembre');
    }

    public function test_everything_mail_is_hidden_while_mail_is_off(): void
    {
        config(['waoo.sandbox_mail' => false]);
        $this->file()->assertDontSee('Correos al cliente')->assertActionHidden('mailPlan');
        Livewire::test(Dashboard::class)->assertDontSee('Correos que rebotaron');
        Livewire::test(EmailTemplates::class)->assertSee('Apagados');
    }
}
