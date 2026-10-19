<?php
namespace Tests\Feature;

use App\Models\{OutboundMessage, OutboundMessageState};
use App\Services\Finance\CreateFinancialRevision;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

class PostmarkBounceTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;
    private OutboundMessage $message;
    private array $record;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-17T18:00:00Z'));
        $this->scenario(); Http::preventStrayRequests(); Mail::fake();
        config(['waoo.sandbox_mail' => false, 'waoo.sandbox_mail_bounces' => true,
            'waoo.postmark_bounce_username' => 'fixture-webhook', 'waoo.postmark_bounce_password' => str_repeat('fixture-', 8),
            'waoo.postmark_server_id' => 123, 'waoo.postmark_message_stream' => 'fixture-stream']);
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        $this->message = OutboundMessage::create(['event_id' => $this->event->id, 'customer_id' => $this->event->customer_id,
            'financial_revision_id' => $finance->id, 'created_by' => $this->owner->id, 'event_version' => 1, 'customer_version' => 1,
            'template_key' => 'contract_ready', 'template_version' => 'fixture', 'locale' => 'es', 'channel' => 'email', 'environment' => 'sandbox',
            'template_sha256' => str_repeat('a', 64), 'recipient_sha256' => hash('sha256', 'client@example.test'),
            'intent_sha256' => str_repeat('b', 64), 'source_sha256' => str_repeat('c', 64), 'due_at' => now(),
            'payload' => ['recipient' => 'client@example.test', 'subject' => 'Fixture', 'text' => 'PRIVATE_BODY', 'html' => '<p>Fixture</p>']]);
        OutboundMessageState::create(['outbound_message_id' => $this->message->id, 'status' => 'sent', 'provider' => 'postmark',
            'provider_message_id' => 'fixture-provider-id', 'provider_message_sha256' => hash('sha256', "postmark\0fixture-provider-id")]);
        $this->record = ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Inactive' => true, 'ID' => 123456, 'MessageID' => 'fixture-provider-id', 'ServerID' => 123,
            'MessageStream' => 'fixture-stream', 'Email' => 'client@example.test', 'BouncedAt' => '2026-10-17T12:59:00-05:00',
            'Description' => 'PRIVATE_PROVIDER_BODY', 'Content' => 'PRIVATE_ORIGINAL_EMAIL'];
    }
    private function receive(array $record = [], array $server = [], ?string $body = null, string $url = 'https://localhost/webhooks/postmark/bounces')
    {
        return $this->call('POST', $url, [], [], [], [...['PHP_AUTH_USER' => 'fixture-webhook', 'PHP_AUTH_PW' => str_repeat('fixture-', 8),
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], ...$server], $body ?? json_encode([...$this->record, ...$record]));
    }
    public function test_authenticated_bounce_is_idempotent_private_and_works_with_sending_off(): void
    {
        $before = DB::table('ledger_entries')->count();
        $this->receive()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['received' => true]);
        $this->receive()->assertOk();
        $this->assertSame('bounced', OutboundMessageState::sole()->status);
        $this->assertDatabaseCount('outbound_message_events', 1);
        $evidence = json_encode(DB::table('outbound_message_events')->get());
        foreach (['client@example.test', 'PRIVATE_', 'Authorization', 'fixture-webhook'] as $secret) $this->assertStringNotContainsString($secret, $evidence);
        $this->assertSame($before, DB::table('ledger_entries')->count()); Mail::assertNothingSent();
        $this->assertSame('draft', $this->event->fresh()->status);
    }
    public function test_bad_auth_https_and_feature_flag_are_rejected_before_json_parsing(): void
    {
        $this->receive(server: ['PHP_AUTH_PW' => 'wrong'], body: 'broken')->assertUnauthorized();
        $this->receive(server: ['PHP_AUTH_USER' => 'another'])->assertUnauthorized();
        $this->receive(url: 'http://localhost/webhooks/postmark/bounces')->assertForbidden();
        $this->receive(server: ['HTTP_X_FORWARDED_PROTO' => 'https'], url: 'http://localhost/webhooks/postmark/bounces')->assertForbidden();
        config(['waoo.sandbox_mail_bounces' => false]); $this->receive()->assertNotFound();
        $this->assertDatabaseCount('outbound_message_events', 0);
        $this->assertSame('sent', OutboundMessageState::sole()->status);
    }
    public function test_unconfigured_receiver_never_accepts_default_credentials(): void
    {
        config(['waoo.postmark_bounce_password' => 'short']);
        $this->receive()->assertStatus(503);
        $this->assertDatabaseCount('outbound_message_events', 0);
    }
    public function test_wrong_server_stream_recipient_or_future_time_is_rejected(): void
    {
        foreach ([['ServerID' => 124], ['MessageStream' => 'another'], ['Email' => 'other@example.test'],
            ['BouncedAt' => '2026-10-17T19:00:00Z'], ['BouncedAt' => '2026-10-17 12:59:00'], ['RecordType' => 'Delivery']] as $invalid) {
            $this->receive($invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('outbound_message_events', 0);
    }
    public function test_missing_or_cross_provider_send_receipt_is_retryable_not_a_fake_delivery(): void
    {
        $this->receive(['MessageID' => 'not-yet-recorded'])->assertStatus(503);
        OutboundMessageState::query()->update(['provider' => 'another']);
        $this->receive()->assertStatus(503);
        $this->assertDatabaseCount('outbound_message_events', 0);
    }
    public function test_complaint_outranks_bounce_and_conflicting_provider_record_does_not_rewrite_evidence(): void
    {
        $this->receive()->assertOk();
        $this->receive(['RecordType' => 'SpamComplaint'])->assertConflict();
        $this->receive(['RecordType' => 'SpamComplaint', 'ID' => 123457])->assertOk();
        $this->receive(['ID' => 123458])->assertOk();
        $this->assertSame('complained', OutboundMessageState::sole()->status);
        $this->assertDatabaseCount('outbound_message_events', 3);
    }
    public function test_transient_and_automatic_responses_are_private_nonblocking_evidence(): void
    {
        foreach (['SoftBounce', 'Transient', 'AutoResponder', 'DnsError', 'FutureProviderType'] as $i => $type) {
            $payload = ['ID' => 200000 + $i, 'Type' => $type, 'Inactive' => false];
            $this->receive($payload)->assertOk();
            $this->receive($payload)->assertOk();
        }
        $state = OutboundMessageState::sole();
        $this->assertSame('sent', $state->status);
        $this->assertSame(1, $state->version);
        $this->assertNull($state->delivered_at);
        $this->assertDatabaseCount('outbound_message_events', 5);
        $this->assertSame(5, DB::table('outbound_message_events')->where('kind', 'provider_notice')->count());
        $evidence = json_encode(DB::table('outbound_message_events')->get());
        foreach (['PRIVATE_', 'client@example.test', 'fixture-webhook'] as $secret) $this->assertStringNotContainsString($secret, $evidence);
        // Absence of Inactive is not the same as true.
        $withoutInactive = [...$this->record, 'ID' => 200010, 'Type' => 'SoftBounce'];
        unset($withoutInactive['Inactive']);
        $this->receive(body: json_encode($withoutInactive))->assertOk();
        $this->assertSame('sent', OutboundMessageState::sole()->status);
        Mail::assertNothingSent();
    }
    public function test_permanent_types_inactive_flag_and_complaint_type_are_classified(): void
    {
        foreach (['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'] as $i => $type) {
            $this->receive(['ID' => 210000 + $i, 'Type' => $type, 'Inactive' => false])->assertOk();
            $this->assertSame('bounced', OutboundMessageState::sole()->status);
        }
        $this->receive(['ID' => 210004, 'Type' => 'SoftBounce', 'Inactive' => true])->assertOk();
        $this->assertSame('bounced', OutboundMessageState::sole()->status);
        $this->receive(['ID' => 210005, 'Type' => 'SpamComplaint', 'Inactive' => false])->assertOk();
        $this->assertSame('complained', OutboundMessageState::sole()->status);
        $this->receive(['ID' => 210006, 'Type' => 'AutoResponder', 'Inactive' => false])->assertOk();
        $this->assertSame('complained', OutboundMessageState::sole()->status);
    }
    public function test_notice_id_cannot_be_reused_for_different_type_inactive_or_timestamp(): void
    {
        $this->receive(['Type' => 'SoftBounce', 'Inactive' => false])->assertOk();
        $this->receive(['Type' => 'AutoResponder', 'Inactive' => false])->assertConflict();
        $this->receive(['Type' => 'SoftBounce', 'Inactive' => true])->assertConflict();
        $this->receive(['Type' => 'SoftBounce', 'Inactive' => false, 'BouncedAt' => '2026-10-17T17:58:00Z'])->assertConflict();
        $this->assertDatabaseCount('outbound_message_events', 1);
        $this->assertSame('sent', OutboundMessageState::sole()->status);
    }
    public function test_authenticated_clock_skew_is_bounded_and_preserves_provider_time(): void
    {
        $this->receive(['BouncedAt' => '2026-10-17T18:05:01Z'])->assertUnprocessable();
        $this->receive(['BouncedAt' => '2026-10-17T18:05:00Z'])->assertOk();
        $this->receive(['BouncedAt' => '2026-10-17T18:05:00Z'])->assertOk();
        $this->assertSame('2026-10-17 18:05:00', DB::table('outbound_message_events')->sole()->occurred_at);
        $this->assertDatabaseCount('outbound_message_events', 1);
    }
    public function test_missing_type_and_non_boolean_inactive_are_not_guessed_permanent(): void
    {
        $missing = $this->record;
        unset($missing['Type']);
        $this->receive(body: json_encode($missing))->assertUnprocessable();
        foreach (['true', 'false', 1, 0, null] as $inactive) $this->receive(['Inactive' => $inactive])->assertUnprocessable();
        $this->assertSame('sent', OutboundMessageState::sole()->status);
        $this->assertDatabaseCount('outbound_message_events', 0);
    }
    public function test_generic_delivery_seam_still_rejects_future_events_and_observations(): void
    {
        config(['waoo.sandbox_mail' => true]);
        foreach ([['delivered', CarbonImmutable::now()->addMinute()], ['provider_notice', CarbonImmutable::now()]] as [$outcome, $at]) {
            try {
                app(\App\Services\Mail\RecordMailDelivery::class)->execute('postmark', 'fixture-provider-id', 'generic-fixture', $outcome, $at);
                $this->fail('Generic delivery must keep its original validation.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('mail', $e->errors());
            }
        }
        $this->assertDatabaseCount('outbound_message_events', 0);
        $this->assertSame('sent', OutboundMessageState::sole()->status);
    }
    public function test_malformed_and_oversize_bodies_cannot_mutate_state(): void
    {
        $this->receive(body: '{')->assertUnprocessable();
        $this->receive(body: str_repeat('x', 65537))->assertStatus(413);
        $this->assertDatabaseCount('outbound_message_events', 0);
    }
}
