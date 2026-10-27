<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRequestBudgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute()->addSeconds(10));
    }

    private function owner(array $attributes = []): User
    {
        return User::factory()->create([
            'name' => 'María González', 'role' => 'owner_admin', 'active' => true,
            'password' => 'FictitiousPassword123!',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
            ...$attributes,
        ]);
    }

    private function exhaustReads(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/admin/api/session')->assertOk();
        }
        $this->getJson('/admin/api/session')->assertTooManyRequests()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Retry-After');
    }

    private function exhaustWrites(): void
    {
        // Rejected input must still be bounded, and must not create customer/audit rows.
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/admin/api/customers', [])->assertUnprocessable();
        }
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])
            ->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_reads_do_not_prevent_a_real_customer_save_and_remain_bounded(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->exhaustReads();

        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])
            ->assertCreated()->assertJsonPath('name', 'Lucía Pérez');
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $owner->id, 'action' => 'customers.created']);
        $this->getJson('/admin/api/session')->assertTooManyRequests();
    }

    public function test_writes_do_not_prevent_reads_and_are_shared_across_admin_route_files(): void
    {
        $this->actingAs($this->owner());
        $this->exhaustWrites();

        $this->getJson('/admin/api/session')->assertOk();
        $this->postJson('/admin/api/staff/grants/999/revoke', [])->assertTooManyRequests();
        $this->postJson('/admin/api/events/999/tip-grants', [])->assertTooManyRequests();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_read_limit_is_shared_across_admin_route_files(): void
    {
        $this->actingAs($this->owner());
        $this->exhaustReads();

        $this->getJson('/admin/api/staff/links')->assertTooManyRequests();
        $this->getJson('/admin/api/events/999/tips')->assertTooManyRequests();
        $this->getJson('/admin/api/customers')->assertTooManyRequests();
    }

    public function test_two_owners_on_the_same_ip_have_independent_read_and_write_budgets(): void
    {
        $first = $this->owner();
        $second = $this->owner(['name' => 'Elena Martínez']);
        $this->actingAs($first);
        $this->exhaustReads();
        $this->exhaustWrites();

        $this->actingAs($second)->getJson('/admin/api/session')->assertOk();
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertCreated();
        $this->actingAs($first)->getJson('/admin/api/session')->assertTooManyRequests();
        $this->actingAs($second)->getJson('/admin/api/session')->assertOk();
    }

    public function test_head_uses_the_same_read_budget_without_consuming_writes(): void
    {
        $this->actingAs($this->owner());
        for ($i = 0; $i < 60; $i++) {
            $this->json('HEAD', '/admin/api/session')->assertOk();
        }
        $this->getJson('/admin/api/session')->assertTooManyRequests();
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertCreated();
    }

    public function test_both_budgets_recover_after_the_window_expires(): void
    {
        $this->actingAs($this->owner());
        $this->exhaustReads();
        $this->exhaustWrites();

        $this->travel(61)->seconds();
        $this->getJson('/admin/api/session')->assertOk();
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertCreated();
    }

    public function test_anonymous_staff_inactive_and_unenrolled_users_remain_denied(): void
    {
        $this->getJson('/admin/api/session')->assertUnauthorized();
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertUnauthorized();
        foreach ([['role' => 'staff'], ['active' => false], ['two_factor_secret' => null], ['two_factor_confirmed_at' => null]] as $attributes) {
            $this->actingAs($this->owner($attributes))->getJson('/admin/api/session')->assertForbidden();
            $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertForbidden();
            $this->getJson('/admin/api/staff/links')->assertForbidden();
            $this->getJson('/admin/api/events/999/tips')->assertForbidden();
        }
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_two_factor_challenges_keep_their_five_attempt_limit(): void
    {
        $owner = $this->owner();
        $this->postJson('/login', ['email' => $owner->email, 'password' => 'FictitiousPassword123!'])
            ->assertOk()->assertJsonPath('two_factor', true);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/two-factor-challenge', ['code' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/two-factor-challenge', ['code' => 'invalid'])->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_revoked_owner_is_denied_even_when_both_budgets_are_exhausted(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->exhaustReads();
        $this->exhaustWrites();

        $owner->forceFill(['active' => false])->save();
        $this->actingAs($owner->fresh())->getJson('/admin/api/session')->assertForbidden();
        $this->postJson('/admin/api/customers', ['name' => 'Lucía Pérez', 'locale' => 'es'])->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_current_calendar_http_navigation_does_not_consume_admin_api_budgets(): void
    {
        $this->actingAs($this->owner());
        $this->exhaustReads();
        $this->exhaustWrites();

        $html = $this->get('/admin/calendario?date=2026-12-19')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = null;
        foreach ($matches[1] as $encoded) {
            $candidate = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $memo = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR)['memo'];
            if (str_contains(strtolower($memo['name']), 'calendar')) {
                $snapshot = $candidate;
                break;
            }
        }
        $this->assertNotNull($snapshot, 'Signed calendar Livewire snapshot exists.');
        $calls = [['setMode', ['month']], ['move', [1]], ['setMode', ['day']], ['move', [-1]], ['setMode', ['week']], ['today', []]];
        foreach ($calls as [$method, $params]) {
            $response = $this->postJson(Livewire::getUpdateUri(), [
                'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => $method, 'params' => $params]]]],
            ], ['X-Livewire' => 'true'])->assertOk();
            $snapshot = $response->json('components.0.snapshot');
            $this->assertIsString($snapshot);
        }
        $this->getJson('/admin/api/session')->assertTooManyRequests();
        $this->postJson('/admin/api/customers', [])->assertTooManyRequests();
    }
}
