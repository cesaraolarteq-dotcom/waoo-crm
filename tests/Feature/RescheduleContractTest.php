<?php
namespace Tests\Feature;

use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, LedgerEntry, PortalGrant, PriceVersion, ResourceReservation, ServiceVariant, SignatureEvidence, User};
use App\Services\Booking\QuickBook;
use App\Services\Documents\{ActivateTemplateVersion, CreateTemplateVersion, InHouseSignatureProvider};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Log, Queue, Schema, Storage};
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RescheduleContractTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    private array $details;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true,
            'waoo.sandbox_immediate_contracts' => true, 'waoo.sandbox_changes' => true, 'app.url' => 'http://localhost']);
        Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $service = ServiceVariant::create(['name' => 'Celebración alegre', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer']]]);
        $price = PriceVersion::create(['service_variant_id' => $service->id, 'amount_cents' => 20000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = now()->addDays(20)->startOfHour();
        $booking = app(QuickBook::class)->execute($this->owner, [
            'new_customer' => ['phone' => '+17135550126', 'locale' => 'es'], 'title' => 'Cumpleaños de Sofía', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Example Lane', 'postal_code' => '77001', 'travel_fee_cents' => 0, 'setup_minutes' => 0, 'breakdown_minutes' => 0,
            'services' => [['price_version_id' => $price->id]], 'deposit' => ['method' => 'zelle', 'amount_cents' => 10000,
                'reference' => 'MARIAG-001', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) Str::uuid());
        $this->event = $booking->event;
        $template = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Acuerdo ficticio, solo para pruebas.</p>',
            'change_note' => 'Plantilla sintética para la visita de María.'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $template->id, ['expected_source_sha256' => $template->source_sha256,
            'approval_note' => 'Aprobación ficticia para prueba automatizada.']);
        parse_str(parse_url($booking->portal_url, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/portal/api/session', ['token' => $fragment['token']])->assertOk();
        $this->details = ['expected_event_version' => $this->event->version, 'expected_revision' => 0,
            'first_name' => 'María', 'last_name' => 'González', 'phone' => '+17135550126', 'email' => 'maria@example.test',
            'billing_address' => '456 Example Road', 'event_address' => '123 Example Lane', 'event_postal_code' => '77001', 'start_time_confirmed' => true];
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('customers')) DB::table('customers')->whereNull('name')->update(['name' => 'María González']);
        if(Schema::hasColumn('events','billing_company')) DB::table('events')->update(['billing_company'=>null,'billing_company_version'=>0]);
        parent::tearDown();
    }

    private function submit(?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/details', [...$this->details, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function retry(?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/details/contract', ['expected_event_version' => $this->event->version,
            'expected_revision' => ClientDetailsSubmission::max('revision') ?? 1, ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function sign(Contract $contract, ?string $key = null, array $extra = [])
    {
        return $this->postJson('/portal/api/contracts/'.$contract->id.'/sign', ['expected_version' => $contract->version,
            'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'signer_name' => 'María González',
            'consent' => true, 'intent_to_sign' => true, 'consent_version' => 'esign-consent-v2-2026-10-20-es', ...$extra], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_after_a_reschedule_the_client_signs_a_new_contract_and_the_old_one_stays(): void
    {
        $this->submit()->assertCreated();
        $this->retry()->assertOk();
        $first = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$first->id.'/pdf')->assertOk();
        $this->sign($first)->assertOk();
        $this->assertSame('signed', $first->fresh()->state);

        $change = app(\App\Services\Changes\RescheduleEvent::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version,
            'date' => now('America/Chicago')->addDays(40)->toDateString(), 'time' => '16:00', 'fee_decision' => 'charge', 'fee_reason' => 'La clienta pidió otra fecha.'], (string) Str::uuid());
        $this->assertSame('pending_signature', $change->state);
        $this->get('/portal?lang=es')->assertOk()->assertViewHas('vm', fn ($vm) => $vm['reserved'] === false && $vm['step'] !== 'done');

        $this->event->refresh();
        $this->retry()->assertOk();
        $second = Contract::where('state', 'issued')->sole();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($change->financial_revision_id, $second->financial_revision_id);
        $this->assertStringContainsString('Cargo por cambio de fecha', json_encode($second->commercial_snapshot['services']));
        $this->assertSame('signed', $first->fresh()->state, 'the old signed contract stays on file');
        $this->get('/portal/api/contracts/'.$second->id.'/pdf')->assertOk();
        $this->sign($second)->assertOk();
        $this->assertSame(['completed', $second->id], [$change->fresh()->state, $change->fresh()->contract_id]);
        $this->assertSame(2, Contract::where('state', 'signed')->count());
    }

    public function test_without_a_pending_change_a_signed_contract_still_blocks_a_second_one(): void
    {
        $this->submit()->assertCreated(); $this->retry()->assertOk();
        $c = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk();
        $this->sign($c)->assertOk();
        $this->retry()->assertStatus(409);
    }

    private function signedThenRescheduled(): \App\Models\EventChange
    {
        $this->submit()->assertCreated(); $this->retry()->assertOk();
        $c = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk(); $this->sign($c)->assertOk();
        return app(\App\Services\Changes\RescheduleEvent::class)->execute($this->owner, $this->event->id, ['expected_version' => $this->event->fresh()->version,
            'date' => now('America/Chicago')->addDays(40)->toDateString(), 'time' => '16:00', 'fee_decision' => 'charge', 'fee_reason' => 'La clienta pidió otra fecha.'], (string) Str::uuid());
    }

    public function test_notice_by_email_and_text_then_one_reminder_after_48_hours_and_nothing_while_off(): void
    {
        $change = $this->signedThenRescheduled();
        $notices = app(\App\Services\Changes\RescheduleNotices::class);
        $this->assertSame(1, $notices->sendDue()['held'], 'mail and SMS off: nothing leaves');
        $this->assertNull($change->fresh()->notified_at);
        $mail = new class implements \App\Services\Mail\MailTransport { public array $sent = [];
            public function configured(): bool { return true; }
            public function send(#[\SensitiveParameter] \App\Services\Mail\OutboundEnvelope $m): \App\Services\Mail\TransportReceipt { $this->sent[] = $m; return new \App\Services\Mail\TransportReceipt('fake', 'x'); } };
        $this->app->instance(\App\Services\Mail\MailTransport::class, $mail);
        config(['waoo.mail_delivery_enabled' => true, 'waoo.sms_delivery_enabled' => true, 'services.twilio' => ['account_sid' => 'AC'.str_repeat('a', 32),
            'api_key_sid' => 'SK'.str_repeat('b', 32), 'api_key_secret' => str_repeat('c', 32), 'messaging_service_sid' => 'MG'.str_repeat('d', 32)]]);
        \Illuminate\Support\Facades\Http::fake(['api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM'.str_repeat('e', 32)], 201)]);
        DB::table('sms_consents')->insert(['phone_e164' => '+17135550126', 'status' => 'opted_in', 'source' => 'website_form', 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $notices->sendDue()['notices']);
        $this->assertSame(0, $notices->sendDue()['notices'], 'never twice');
        $this->assertSame('maria@example.test', $mail->sent[0]->recipient);
        $this->assertStringContainsString('firma tu contrato', $mail->sent[0]->subject);
        $this->assertStringContainsString('cargo por cambio de fecha de $100', $mail->sent[0]->html);
        $this->assertStringContainsString('/portal', $mail->sent[0]->html);
        $this->assertSame(1, \App\Models\SmsMessage::where('direction', 'out')->where('status', 'sent')->count());
        $this->travel(47)->hours();
        $this->assertSame(0, $notices->sendDue()['reminders']);
        $this->travel(2)->hours();
        $this->assertSame(1, $notices->sendDue()['reminders']);
        $this->assertSame(0, $notices->sendDue()['reminders']);
        $this->assertStringContainsString('Recordatorio', $mail->sent[1]->subject);
    }

    public function test_once_signed_no_reminder_goes_out(): void
    {
        $change = $this->signedThenRescheduled();
        $this->event->refresh(); $this->retry()->assertOk();
        $c = Contract::where('state', 'issued')->sole();
        $this->get('/portal/api/contracts/'.$c->id.'/pdf')->assertOk(); $this->sign($c)->assertOk();
        config(['waoo.mail_delivery_enabled' => true]);
        $this->assertSame(['notices' => 0, 'reminders' => 0, 'held' => 0, 'no_link' => 0], app(\App\Services\Changes\RescheduleNotices::class)->sendDue());
    }
}
