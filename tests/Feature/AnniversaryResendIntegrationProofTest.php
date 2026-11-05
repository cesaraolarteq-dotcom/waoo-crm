<?php
namespace Tests\Feature;

use App\Models\{AnniversaryCode, Customer, OutboundMessage, OutboundMessageState, User};
use App\Services\Campaigns\AnniversaryMail;
use App\Services\Mail\{MailTransport, ResendMailTransport};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Codex review #163/#167: the campaign must reach the actual Resend adapter through its durable outbox (HTTP fully
// simulated, no secret or real recipient), and an unknown outcome is never reset or resent.
class AnniversaryResendIntegrationProofTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Customer $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->client = Customer::create(['name' => 'Fictional Example', 'email' => $this->owner->email, 'locale' => 'en']);
        AnniversaryCode::create(['customer_id' => $this->client->id, 'anchor_key' => 'synthetic-anchor', 'anchor_date' => now()->subDays(330), 'code' => 'WAOO-ABCDE',
            'state' => 'ready', 'issued_at' => now(), 'expires_at' => now()->addDays(7)]);
        config(['waoo.mail_delivery_enabled' => true, 'waoo.anniversary_mail_enabled' => true, 'waoo.sandbox_mail' => true, 'waoo.mail_provider' => 'resend',
            'services.resend.key' => str_repeat('synthetic-key-', 4), 'waoo.mail_from_address' => 'sender@example.test', 'waoo.mail_from_name' => 'Waoo Party',
            'waoo.mail_reply_to' => 'reply@example.test', 'waoo.mail_allowlist' => [$this->owner->email], 'app.url' => 'https://crm.example.test']);
        Http::preventStrayRequests();
    }

    public function test_campaign_dispatch_reaches_the_actual_resend_adapter(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => '11111111-2222-4333-8444-555555555555'], 200)]);
        $this->assertInstanceOf(ResendMailTransport::class, app(MailTransport::class));
        $result = app(AnniversaryMail::class)->sendDue();
        $this->assertSame(1, $result['sent'], 'Valid campaign + real adapter must dispatch through its durable outbox.');
        Http::assertSentCount(1);
        $this->assertSame('sent', OutboundMessageState::sole()->status);
        $this->assertSame(0, app(AnniversaryMail::class)->sendDue()['sent'], 'never twice');
        Http::assertSentCount(1);
    }

    public function test_a_timeout_stays_unknown_and_is_never_resent(): void
    {
        Http::fake(['https://api.resend.com/emails' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['unknown']);
        $this->assertSame('delivery_unknown', OutboundMessageState::sole()->status);
        $this->assertNotNull(AnniversaryCode::sole()->sent_at, 'the claim is not reset');
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => '11111111-2222-4333-8444-555555555555'], 200)]);
        $this->assertSame(['sent' => 0, 'reminders' => 0, 'held' => 0, 'unknown' => 0, 'blocked' => 0], app(AnniversaryMail::class)->sendDue());
        $this->assertSame(1, OutboundMessage::count());
    }

    public function test_bounced_or_unsubscribed_or_not_allowed_recipients_get_nothing(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => '11111111-2222-4333-8444-555555555555'], 200)]);
        config(['waoo.mail_allowlist' => []]);
        $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['held'], 'outside the sandbox allowlist');
        config(['waoo.mail_allowlist' => [$this->owner->email]]);
        // An earlier message to the same address bounced: suppressed.
        $bounced = OutboundMessage::create(['customer_id' => $this->client->id, 'template_key' => 'anniversary_main', 'template_version' => 'x', 'locale' => 'en', 'channel' => 'email',
            'environment' => 'sandbox', 'template_sha256' => str_repeat('a', 64), 'recipient_sha256' => hash('sha256', mb_strtolower($this->owner->email)),
            'intent_sha256' => str_repeat('b', 64), 'source_sha256' => str_repeat('c', 64), 'due_at' => now(), 'payload' => []]);
        OutboundMessageState::create(['outbound_message_id' => $bounced->id, 'status' => 'bounced']);
        $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['held'], 'bounced address');
        $this->client->forceFill(['promo_opt_out_at' => now()])->save();
        $this->assertSame(0, array_sum(app(AnniversaryMail::class)->sendDue()), 'unsubscribed');
        Http::assertNothingSent();
        $this->assertNull(AnniversaryCode::sole()->sent_at);
    }

    private function changeAfterPlanning(\Closure $change): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => '11111111-2222-4333-8444-555555555555'], 200)]);
        OutboundMessage::created(function (OutboundMessage $message) use ($change) {
            if ($message->template_key === 'anniversary_main') $change();
        });
        try {
            $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['blocked']);
            Http::assertNothingSent();
            $this->assertSame('blocked', OutboundMessageState::sole()->status);
            $this->assertSame(0, app(AnniversaryMail::class)->sendDue()['sent'], 'a blocked immutable intent is never replayed');
        } finally { OutboundMessage::flushEventListeners(); }
    }

    public function test_the_campaign_switch_is_rechecked_after_planning(): void
    {
        $this->changeAfterPlanning(fn () => config(['waoo.anniversary_mail_enabled' => false]));
    }

    public function test_a_changed_contact_cannot_receive_a_frozen_campaign(): void
    {
        $this->changeAfterPlanning(fn () => $this->client->update(['email' => 'changed@example.test']));
    }

    public function test_a_code_that_expires_after_planning_is_not_sent(): void
    {
        $this->changeAfterPlanning(fn () => AnniversaryCode::query()->update(['expires_at' => now()->subSecond()]));
    }

    public function test_opt_out_after_claim_but_before_transport_blocks_delivery(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => '11111111-2222-4333-8444-555555555555'], 200)]);
        OutboundMessageState::updated(function (OutboundMessageState $state) {
            if ($state->status === 'dispatching') $this->client->forceFill(['promo_opt_out_at' => now()])->save();
        });
        try {
            $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['blocked']);
            Http::assertNothingSent();
            $this->assertSame('blocked', OutboundMessageState::sole()->status);
        } finally { OutboundMessageState::flushEventListeners(); }
    }

    public function test_a_campaign_warning_without_an_event_keeps_inicio_usable(): void
    {
        $this->actingAs($this->owner);
        Http::fake(['https://api.resend.com/emails' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->assertSame(1, app(AnniversaryMail::class)->sendDue()['unknown']);
        \Livewire\Livewire::test(\App\Filament\Pages\Dashboard::class)
            ->assertSee('Correos por revisar')
            ->assertSee(\App\Filament\Resources\Customers\CustomerResource::getUrl('view', ['record' => $this->client->id]), false);
    }
}
