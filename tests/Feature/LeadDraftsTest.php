<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\Leads\{ReceiveLeadDraft, ReceiveWebsiteLead};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

/** Owner 2026-11-26: unfinished website requests («Sin terminar» in Prospectos). */
class LeadDraftsTest extends TestCase
{
    use DatabaseMigrations;
    private string $secret = 'fictional-lead-test-secret-0000000000000000';

    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-26 15:00:00', 'America/Chicago'));
        config(['waoo.sandbox_lead_intake' => true, 'waoo.lead_shared_secret' => $this->secret]); Mail::fake(); Queue::fake();
    }

    private function post(array $payload, ?string $secret = null)
    {
        $body = json_encode($payload); $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        return $this->call('POST', 'http://localhost/api/leads/draft', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key, 'HTTP_X_WAOO_TIMESTAMP' => $at, 'HTTP_X_WAOO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $secret ?? $this->secret)], $body);
    }

    private function draft(array $over = []): array
    {
        return [...['draft_key' => '6f1c2d3e-4b5a-4c6d-8e7f-9a0b1c2d3e4f', 'name' => 'María González', 'phone' => '(832) 555-0101', 'email' => null,
            'event_date' => '2026-12-19', 'zip' => '77494', 'package' => 'Waoo Package', 'locale' => 'es', 'attribution' => ['channel' => 'google_ads', 'gclid' => 'Fixture_click_1']], ...$over];
    }

    public function test_a_signed_draft_is_saved_once_per_visit_and_closed_when_the_full_request_arrives(): void
    {
        $this->post($this->draft(), 'wrong-secret-wrong-secret-wrong-secret-0000')->assertUnauthorized();
        $this->post($this->draft(['phone' => '(123) 555-0101']))->assertStatus(422);
        $this->post($this->draft())->assertStatus(202);
        $this->post($this->draft(['email' => 'maria@example.test']))->assertStatus(202);
        $this->assertDatabaseCount('lead_drafts', 1);
        $this->assertCount(1, ReceiveLeadDraft::open());
        $this->assertSame('8325550101', ReceiveLeadDraft::open()[0]->phone_digits);

        $body = json_encode(['name' => 'María González', 'phone' => '+1 832 555 0101', 'email' => 'maria@example.test', 'event_date' => '2026-12-19', 'start_time' => '14:00',
            'zip' => '77494', 'event_type' => 'Birthday', 'package' => 'Waoo Package', 'message' => null, 'travel_estimate_text' => null, 'locale' => 'es', 'consent' => null,
            'demand_flag' => 'open', 'submitted_at' => now()->subMinute()->toIso8601String(), 'page' => '/contact']);
        $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        app(ReceiveWebsiteLead::class)->execute($body, $at, $key, 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $this->secret));
        $this->assertSame([], ReceiveLeadDraft::open());
        // A later draft from the same phone is not reopened.
        $this->post($this->draft(['draft_key' => '7f1c2d3e-4b5a-4c6d-8e7f-9a0b1c2d3e4f']))->assertStatus(202);
        $this->assertSame([], ReceiveLeadDraft::open());
    }

    public function test_drafts_are_deleted_after_sixty_days(): void
    {
        $this->post($this->draft())->assertStatus(202);
        $this->travel(61)->days();
        $this->assertSame(1, ReceiveLeadDraft::prune());
        $this->assertDatabaseCount('lead_drafts', 0);
    }

    public function test_the_owner_sees_the_sin_terminar_tab(): void
    {
        $this->post($this->draft())->assertStatus(202);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        config(['waoo.sandbox_finance' => true]);
        $this->actingAs($owner);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        \Livewire\Livewire::test(\App\Filament\Pages\Leads::class)->set('status', 'drafts')->assertSee('María González')->assertSee('Escribir por WhatsApp')
            ->call('dismissDraft', DB::table('lead_drafts')->value('id'));
        $this->assertSame([], ReceiveLeadDraft::open());
    }
}
