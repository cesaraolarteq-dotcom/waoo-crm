<?php
namespace Tests\Feature;

use App\Filament\Pages\EmailTemplates;
use App\Models\{AutoMessageLog, Customer, FinancialRevision, MailOccurrence, MailOccurrenceState, MessageSetting, OutboundMessage, OutboundMessageState};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment};
use App\Services\Messages\RunAutoMessages;
use App\Services\Mail\MailTransport;
use App\Services\Sms\SendSms;
use App\Support\AutoMessages;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\{FakeMailTransport, QuoteScenario};
use Tests\TestCase;

// Owner 2026-11-23 "Mensajes automáticos": switches, approvals, owner tests and the every-minute sender.
class AutoMessagesTest extends TestCase
{
    use RefreshDatabase, QuoteScenario;

    private FinancialRevision $finance;
    private FakeMailTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23T18:00:00Z'));
        $this->scenario();
        config(['waoo.sandbox_mail' => true, 'waoo.mail_test_recipients' => ['maria.gonzalez@example.test'], 'waoo.mail_review_url' => 'https://g.page/r/fixture/review']);
        Customer::whereKey($this->event->customer_id)->update(['email' => 'maria.gonzalez@example.test', 'name' => 'María González']);
        $this->finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->transport = new FakeMailTransport();
        $this->app->instance(MailTransport::class, $this->transport);
    }

    private function pay(int $cents): void
    {
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $this->finance->id, 'amount_cents' => $cents,
            'currency' => 'USD', 'reference' => 'Z-'.Str::random(6), 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
    }

    public function test_payment_email_goes_out_once_to_the_test_recipient(): void
    {
        $this->pay(5000);
        $run = app(RunAutoMessages::class)->run();
        $this->assertSame(1, $run['email']);
        $m = OutboundMessage::sole();
        $this->assertSame('payment_received', $m->template_key);
        $this->assertSame('maria.gonzalez@example.test', $m->payload['recipient']);
        $this->assertStringContainsString('$50.00', $m->payload['text']);
        $this->assertSame([$m->id], $this->transport->sent);
        $this->assertSame('sent', MailOccurrenceState::sole()->status);
        app(RunAutoMessages::class)->run();
        $this->assertCount(1, $this->transport->sent, 'Never twice.');
    }

    public function test_switched_off_message_is_skipped_and_never_sent_later(): void
    {
        AutoMessages::toggle($this->owner, 'payment_received', 'email');
        $this->pay(5000);
        app(RunAutoMessages::class)->run();
        $this->assertSame('owner_off', AutoMessageLog::where('channel', 'email')->sole()->reason);
        AutoMessages::toggle($this->owner, 'payment_received', 'email');
        app(RunAutoMessages::class)->run();
        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, OutboundMessage::count());
    }

    public function test_client_outside_the_test_list_is_held_in_test_mode(): void
    {
        Customer::whereKey($this->event->customer_id)->update(['email' => 'sofia.mendez@example.test']);
        $this->pay(5000);
        app(RunAutoMessages::class)->run();
        $log = AutoMessageLog::where('channel', 'email')->sole();
        $this->assertSame(['held', 'test_mode'], [$log->status, $log->reason]);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_switching_on_never_sends_old_events(): void
    {
        $this->pay(5000);
        $this->travel(3)->hours();
        app(RunAutoMessages::class)->run();
        $this->assertSame(['skipped', 'too_old'], [MailOccurrenceState::sole()->status, MailOccurrenceState::sole()->reason]);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_proposed_text_needs_owner_approval_and_a_changed_text_needs_it_again(): void
    {
        $this->assertFalse(AutoMessages::emailApproved('contract_signed'));
        $this->assertFalse(AutoMessages::emailOn('contract_signed'));
        try { AutoMessages::toggle($this->owner, 'contract_signed', 'email'); $this->fail('Unapproved text cannot be switched.'); }
        catch (ValidationException) {}
        AutoMessages::approve($this->owner, 'contract_signed', 'email');
        $this->assertTrue(AutoMessages::emailOn('contract_signed'));
        $this->assertFalse(AutoMessages::smsOn('contract_signed'), 'Texts start off even after approval.');
        $mail = AutoMessages::renderEmail('contract_signed', 'en', AutoMessages::sample('en'));
        $this->assertSame('We received your signed contract!', $mail['subject']);
        $this->assertStringContainsString('See my booking and contract', $mail['html']);
        config(['auto-messages.contract_signed_email.es.closing' => 'Otro cierre']);
        $this->assertFalse(AutoMessages::emailOn('contract_signed'), 'A text change turns it back into a proposal.');
    }

    public function test_reminder_switched_off_is_skipped_at_dispatch(): void
    {
        $this->pay($this->finance->deposit_target_cents);
        $resources = [\App\Models\Resource::create(['code' => 'AM-PERFORMER', 'name' => 'Ana Torres', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']])->id,
            \App\Models\Resource::create(['code' => 'AM-SPEAKER', 'name' => 'Bocina 1', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(\App\Services\Finance\ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $this->finance->id, 'resource_ids' => $resources,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Revisado.', 'transfers' => []]]);
        $grant = app(\App\Services\Portal\IssuePortalGrant::class)->execute($this->owner, $this->event->id, ['customer_id' => $this->event->customer_id, 'expires_at' => now()->addDays(29)->toIso8601String()]);
        app(\App\Services\Mail\PlanEventMail::class)->execute($this->owner, $this->event->id, ['expected_event_version' => $this->event->fresh()->version, 'expected_customer_version' => Customer::find($this->event->customer_id)->version, 'portal_grant_id' => $grant->id, 'templates' => ['week_before']], (string) Str::uuid());
        $m = OutboundMessage::where('template_key', 'week_before')->sole();
        AutoMessages::toggle($this->owner, 'week_before', 'email');
        $this->travelTo($m->due_at);
        app(\App\Services\Mail\DispatchOutboundMail::class)->execute($m->id);
        $state = OutboundMessageState::where('outbound_message_id', $m->id)->sole();
        $this->assertSame(['skipped', 'owner_off'], [$state->status, $state->error_code]);
        $this->assertNotContains($m->id, $this->transport->sent);
    }

    public function test_owner_test_text_goes_only_to_the_listed_number(): void
    {
        config(['waoo.sms_delivery_enabled' => true, 'waoo.sms_allowlist' => ['+13465550100'], 'app.url' => 'https://crm.example.test', 'services.twilio' => [
            'account_sid' => 'AC'.str_repeat('a', 32), 'api_key_sid' => 'SK'.str_repeat('b', 32), 'api_key_secret' => str_repeat('c', 32),
            'messaging_service_sid' => 'MG'.str_repeat('d', 32), 'auth_token' => str_repeat('e', 32)]]);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('f', 32)], 201)]);
        try { app(SendSms::class)->testToOwner($this->owner, '+13465550199', 'Hola'); $this->fail('Only the test number.'); }
        catch (ValidationException) {}
        $sms = app(SendSms::class)->testToOwner($this->owner, '+13465550100', AutoMessages::renderSms('week_before', 'es', AutoMessages::sample('es')));
        $this->assertSame('sent', $sms->status);
        $this->assertNull($sms->customer_id);
        $this->assertStringContainsString('STOP', $sms->body, 'First text carries the opt-out line.');
        Http::assertSentCount(1);
    }

    public function test_page_shows_the_journey_and_controls_switches_approvals_and_tests(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/correos')->assertOk()->assertSee('Mensajes automáticos')->assertSee('Modo prueba')
            ->assertSee('Recibimos tu solicitud')->assertSee('Contrato firmado')->assertSee('Por aprobar')->assertSee('Enviarme una prueba');
        Livewire::test(EmailTemplates::class)
            ->call('toggle', 'contract_signed', 'email')->assertSet('selected', 'contract_signed')
            ->call('approve', 'email')
            ->call('toggle', 'payment_received', 'email')
            ->call('focusOn', 'week_before', 'sms')->assertSet('channel', 'sms')->assertSee('Texto propuesto por Claude')
            ->call('setLang', 'en')->assertSee('One week to go, María!')
            ->call('select', 'inquiry_received')->assertSet('channel', 'email')
            ->call('sendTest');
        $this->assertTrue(AutoMessages::emailOn('contract_signed'));
        $this->assertFalse(MessageSetting::where('key', 'payment_received')->sole()->email_enabled);
        $test = OutboundMessage::where('template_key', 'test_inquiry_received')->sole();
        $this->assertSame('maria.gonzalez@example.test', $test->payload['recipient']);
        $this->assertStringStartsWith('[Prueba] ', $test->payload['subject']);
        $this->assertSame([$test->id], $this->transport->sent);
    }
}
