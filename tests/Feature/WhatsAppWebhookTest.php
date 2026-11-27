<?php
namespace Tests\Feature;

use App\Models\{Customer, WhatsappMessage};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Owner 2026-11-26: WhatsApp 8830 through Waoo's own Meta app. */
class WhatsAppWebhookTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.whatsapp' => ['enabled' => true, 'app_secret' => 'fixture-app-secret', 'verify_token' => 'fixture-verify', 'token' => 'fixture-token', 'phone_number_id' => '111', 'waba_id' => '222']]);
    }

    private function signed(array $payload)
    {
        $raw = json_encode($payload);
        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'fixture-app-secret')], $raw);
    }

    private function event(string $field, array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '222', 'changes' => [['field' => $field, 'value' => ['metadata' => ['phone_number_id' => '111'], ...$value]]]]]];
    }

    public function test_subscription_check_and_signature_are_enforced(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=fixture-verify&hub.challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=1')->assertForbidden();
        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{}')->assertUnauthorized();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }

    public function test_client_message_app_echo_and_read_status_are_kept_once(): void
    {
        $c = Customer::create(['name' => 'María González', 'phone' => '(832) 555-0101', 'locale' => 'es']);
        $msg = $this->event('messages', ['messages' => [['from' => '18325550101', 'id' => 'wamid.IN1', 'timestamp' => '1795700000', 'type' => 'text', 'text' => ['body' => 'Hola, ¿tienen el 12 de diciembre?']]]]);
        $this->signed($msg)->assertOk(); $this->signed($msg)->assertOk(); // Meta retries
        $this->assertSame(1, WhatsappMessage::count());
        $this->assertSame($c->id, WhatsappMessage::first()->customer_id);
        $this->signed($this->event('smb_message_echoes', ['message_echoes' => [['to' => '18325550101', 'id' => 'wamid.APP1', 'type' => 'text', 'text' => ['body' => 'Sí, tenemos']]]]))->assertOk();
        $this->assertSame('app', WhatsappMessage::where('provider_id', 'wamid.APP1')->value('source'));

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $sent = app(\App\Services\WhatsApp\WhatsAppCloud::class)->sendText('(832) 555-0101', 'Te mandamos el enlace.');
        $this->assertSame(['sent', '18325550101'], [$sent->status, $sent->wa_id]);
        $this->signed($this->event('messages', ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']]]))->assertOk();
        $this->signed($this->event('messages', ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']]]))->assertOk(); // late, never steps back
        $this->assertSame('read', $sent->fresh()->status);
        $this->signed($this->event('messages', ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'failed']]]))->assertOk();
        $this->assertSame('read', $sent->fresh()->status);
    }

    public function test_events_for_another_number_are_ignored(): void
    {
        $this->signed(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [['from' => '18325550101', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'x']]]]]]]]])->assertOk();
        $this->assertDatabaseCount('whatsapp_messages', 0);
    }
}
