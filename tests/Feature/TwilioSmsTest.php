<?php
namespace Tests\Feature;

use App\Models\{Customer, Lead, LeadSubmission, SmsConsent, SmsMessage, User};
use App\Services\Sms\{RecordLeadSmsConsent, SendSms, VerifyTwilioSignature};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Owner 2026-10-28: texts through Twilio. Nothing leaves while texting is off; only numbers that checked the website box;
// STOP is honoured; every Twilio callback must carry a valid signature. All values below are fictitious.
class TwilioSmsTest extends TestCase
{
    use DatabaseMigrations;

    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private User $owner;
    private Customer $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://crm.example.test', 'waoo.sms_delivery_enabled' => false, 'services.twilio' => [
            'account_sid' => 'AC'.str_repeat('a', 32), 'api_key_sid' => 'SK'.str_repeat('b', 32), 'api_key_secret' => 'fixturesecretfixturesecret12',
            'auth_token' => self::TOKEN, 'messaging_service_sid' => 'MG'.str_repeat('c', 32), 'from_number' => '+13465550199']]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->client = Customer::create(['name' => 'María González', 'phone' => '(346) 555-0100', 'locale' => 'es']);
    }

    private function consent(string $status = 'opted_in'): void
    {
        SmsConsent::create(['phone_e164' => '+13465550100', 'status' => $status, 'source' => 'website_form', 'changed_at' => now()]);
    }

    private function twilio(string $path, array $params, ?string $signature = null)
    {
        $signature ??= VerifyTwilioSignature::sign(self::TOKEN, 'https://crm.example.test'.$path, $params);

        // Twilio posts a raw form body; the signature covers the values exactly as sent (" stop " keeps its spaces).
        return $this->call('POST', 'https://crm.example.test'.$path, [], [], [], ['HTTP_X_TWILIO_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($params));
    }

    private function refused(callable $send, string $why): void
    {
        try { $send(); $this->fail('Expected a refusal: '.$why); }
        catch (ValidationException $e) { $this->assertStringContainsString($why, collect($e->errors())->flatten()->first()); }
    }

    public function test_website_checkbox_records_consent_only_when_checked(): void
    {
        foreach (['YES' => '(346) 555-0100', 'NO' => '(346) 555-0111'] as $answer => $phone) {
            $submission = LeadSubmission::create(['request_key' => (string) \Illuminate\Support\Str::uuid(), 'payload_sha256' => hash('sha256', $answer),
                'payload' => ['name' => 'María González', 'phone' => $phone, 'consent' => ['version' => 'contact-2026-10-28', 'text' => 'By sending… SMS opt-in: '.$answer.'. Consent is not a condition of purchase.']],
                'submitted_at' => now(), 'received_at' => now()]);
            app(RecordLeadSmsConsent::class)->execute(new Lead(['lead_submission_id' => $submission->id]));
        }
        $this->assertSame(['+13465550100' => 'opted_in'], SmsConsent::pluck('status', 'phone_e164')->all());
        $this->assertStringContainsString('contact-2026-10-28', SmsConsent::sole()->evidence);
    }

    public function test_nothing_is_sent_while_texting_is_off_or_without_consent(): void
    {
        Http::fake();
        $send = fn () => app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola');
        $this->consent();
        $this->refused($send, 'apagados');
        config(['waoo.sms_delivery_enabled' => true]);
        SmsConsent::query()->delete();
        $this->refused($send, 'no aceptó');
        $this->consent('opted_out');
        $this->refused($send, 'STOP');
        Http::assertNothingSent();
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_sends_through_the_messaging_service_with_the_compliance_line_only_the_first_time(): void
    {
        config(['waoo.sms_delivery_enabled' => true]);
        $this->consent();
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['sid' => 'SM'.str_repeat('1', 32)], 201)->push(['sid' => 'SM'.str_repeat('2', 32)], 201)]);
        $first = app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Tu fiesta quedó reservada.');
        $second = app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Te esperamos el sábado.');
        $this->assertSame(['sent', 'sent'], [$first->status, $second->status]);
        $this->assertStringContainsString('STOP', $first->body);
        $this->assertStringNotContainsString('STOP', $second->body);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Accounts/AC'.str_repeat('a', 32).'/Messages.json')
            && $r['MessagingServiceSid'] === 'MG'.str_repeat('c', 32) && $r['To'] === '+13465550100'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('SK'.str_repeat('b', 32).':fixturesecretfixturesecret12')));
        $this->assertDatabaseMissing('sms_messages', ['body' => 'Tu fiesta quedó reservada.']); // stored encrypted
    }

    public function test_unsigned_callbacks_are_refused_and_stop_turns_texting_off(): void
    {
        $this->consent();
        $params = ['MessageSid' => 'SM'.str_repeat('3', 32), 'From' => '+13465550100', 'Body' => ' stop '];
        $this->twilio('/webhooks/twilio/inbound', $params, 'forged')->assertStatus(401);
        $this->assertSame(0, SmsMessage::count());
        $this->twilio('/webhooks/twilio/inbound', $params)->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8');
        $this->twilio('/webhooks/twilio/inbound', $params)->assertOk(); // Twilio retry: kept once
        $this->assertSame(1, SmsMessage::where('direction', 'in')->count());
        $this->assertSame($this->client->id, SmsMessage::sole()->customer_id);
        $this->assertSame('opted_out', SmsConsent::sole()->status);
        config(['waoo.sms_delivery_enabled' => true]);
        $this->refused(fn () => app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola'), 'STOP');
    }

    public function test_status_callback_moves_a_sent_text_forward_only(): void
    {
        $m = SmsMessage::create(['direction' => 'out', 'phone_e164' => '+13465550100', 'body' => 'Hola', 'status' => 'sent', 'provider_sid' => 'SM'.str_repeat('4', 32)]);
        $this->twilio('/webhooks/twilio/status', ['MessageSid' => $m->provider_sid, 'MessageStatus' => 'delivered'])->assertOk();
        $this->twilio('/webhooks/twilio/status', ['MessageSid' => $m->provider_sid, 'MessageStatus' => 'failed', 'ErrorCode' => '30003'])->assertOk();
        $this->assertSame('delivered', $m->fresh()->status);
    }

    public function test_a_later_website_submission_does_not_erase_an_existing_stop(): void
    {
        $this->consent('opted_out');
        $submission = LeadSubmission::create(['request_key' => (string)\Illuminate\Support\Str::uuid(),
            'payload_sha256' => hash('sha256', 'fixture-stop'), 'payload' => ['phone' => $this->client->phone,
                'consent' => ['version' => 'contact-2026-10-28', 'text' => 'SMS opt-in: YES']],
            'submitted_at' => now(), 'received_at' => now()]);
        app(RecordLeadSmsConsent::class)->execute(new Lead(['lead_submission_id' => $submission->id]));
        $this->assertSame('opted_out', SmsConsent::sole()->status);
        config(['waoo.sms_delivery_enabled' => true]); Http::fake();
        $this->refused(fn () => app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola'), 'STOP');
        Http::assertNothingSent();
    }

    public function test_uncertain_provider_failure_is_not_reported_as_a_confirmed_rejection(): void
    {
        config(['waoo.sms_delivery_enabled' => true]); $this->consent();
        $calls = 0;
        Http::fake(function () use (&$calls) { $calls++; throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout after dispatch'); });
        $m = app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola');
        $this->assertSame('unknown', $m->status);
        $this->assertSame(1, $calls);
    }

    public function test_inbound_verification_and_secure_callback_configuration_are_required_before_sending(): void
    {
        config(['waoo.sms_delivery_enabled' => true]); $this->consent(); Http::fake();
        $send = fn () => app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola');
        config(['services.twilio.auth_token' => null]); $this->refused($send, 'apagados');
        config(['services.twilio.auth_token' => self::TOKEN, 'app.url' => 'http://crm.example.test']); $this->refused($send, 'apagados');
        Http::assertNothingSent(); $this->assertDatabaseCount('sms_messages', 0);
    }

    public function test_owner_action_reports_unknown_dispatch_without_claiming_twilio_rejected_it(): void
    {
        $this->actingAs($this->owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        config(['waoo.sms_delivery_enabled' => true]); $this->consent();
        $event = \App\Models\Event::create(['customer_id' => $this->client->id, 'title' => 'Fiesta de María',
            'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 3000]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Synthetic unknown outcome'));
        \Livewire\Livewire::test(\App\Filament\Resources\Events\Pages\ViewEvent::class, ['record' => $event->id])
            ->callAction('smsSend', ['body' => 'Hola'])->assertNotified('Envío sin confirmar');
        $this->assertSame('unknown', SmsMessage::sole()->status);
    }
    public function test_server_failure_never_echoes_provider_text_and_is_not_retried(): void
    {
        config(['waoo.sms_delivery_enabled' => true]); $this->consent();
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 'PRIVATE_PROVIDER_VALUE', 'message' => 'PRIVATE_PROVIDER_TEXT'], 503)]);
        $message = app(SendSms::class)->execute($this->owner, $this->client->id, null, 'Hola');
        $this->assertSame(['unknown', 'twilio_503'], [$message->status, $message->error_code]);
        Http::assertSentCount(1);
    }

}
