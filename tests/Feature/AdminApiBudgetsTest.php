<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AdminApiBudgetsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $customer = ['name' => 'Fixture Client', 'email' => 'client@example.test', 'locale' => 'es'];
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->newOwner(); $this->actingAs($this->owner);
    }
    private function newOwner(): User
    {
        return User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    }
    public function test_exhausted_read_budget_does_not_block_real_audited_save(): void
    {
        for ($i = 0; $i < 60; $i++) $this->getJson('/admin/api/session')->assertOk();
        $response = $this->getJson('/admin/api/customers')->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->postJson('/admin/api/customers', $this->customer)->assertCreated()->assertHeader('X-RateLimit-Remaining', '59');
        $this->assertDatabaseHas('customers', ['email' => 'client@example.test']);
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $this->owner->id, 'action' => 'customers.created']);
        $this->getJson('/admin/api/session')->assertStatus(429);
    }
    public function test_write_limit_stays_bounded_and_reads_and_expiration_remain_usable(): void
    {
        for ($i = 0; $i < 60; $i++) $this->postJson('/admin/api/customers', [])->assertUnprocessable();
        $this->postJson('/admin/api/customers', $this->customer)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('customers', 0);
        $this->getJson('/admin/api/session')->assertOk()->assertHeader('X-RateLimit-Remaining', '59');
        $this->travel(61)->seconds();
        $this->postJson('/admin/api/customers', $this->customer)->assertCreated();
        $this->assertDatabaseCount('customers', 1);
    }
    public function test_two_owners_on_same_ip_have_independent_read_and_write_counters(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/admin/api/session')->assertOk();
            $this->postJson('/admin/api/customers', [])->assertUnprocessable();
        }
        $this->getJson('/admin/api/session')->assertStatus(429);
        $this->postJson('/admin/api/customers', $this->customer)->assertStatus(429);
        $other = $this->newOwner(); $this->actingAs($other);
        $this->getJson('/admin/api/session')->assertOk()->assertHeader('X-RateLimit-Remaining', '59');
        $this->postJson('/admin/api/customers', $this->customer)->assertCreated()->assertHeader('X-RateLimit-Remaining', '59');
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $other->id, 'action' => 'customers.created']);
    }
    public function test_head_uses_read_counter_and_does_not_consume_write_counter(): void
    {
        $this->head('/admin/api/session', ['Accept' => 'application/json'])->assertOk()->assertHeader('X-RateLimit-Limit', '60')->assertHeader('X-RateLimit-Remaining', '59');
        $this->getJson('/admin/api/session')->assertOk()->assertHeader('X-RateLimit-Remaining', '58');
        $this->postJson('/admin/api/customers', $this->customer)->assertCreated()->assertHeader('X-RateLimit-Remaining', '59');
    }
    public function test_owner_authentication_and_2fa_gates_run_before_budget_consumption(): void
    {
        auth()->logout(); $this->app['auth']->forgetGuards();
        $this->getJson('/admin/api/session')->assertUnauthorized();
        $this->actingAs($this->owner); $this->owner->forceFill(['active' => false])->save();
        $this->getJson('/admin/api/session')->assertForbidden();
        $this->postJson('/admin/api/customers', $this->customer)->assertForbidden();
        $this->owner->forceFill(['active' => true, 'role' => 'staff'])->save(); $this->getJson('/admin/api/session')->assertForbidden();
        $this->owner->forceFill(['role' => 'owner_admin', 'two_factor_confirmed_at' => null])->save(); $this->getJson('/admin/api/session')->assertForbidden();
        $this->owner->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->getJson('/admin/api/session')->assertOk()->assertHeader('X-RateLimit-Remaining', '59');
        $this->postJson('/admin/api/customers', $this->customer)->assertCreated()->assertHeader('X-RateLimit-Remaining', '59');
    }
    public function test_current_calendar_initial_render_does_not_consume_api_read_budget(): void
    {
        $this->get('/admin/calendario')->assertOk();
        $this->getJson('/admin/api/session')->assertOk()->assertHeader('X-RateLimit-Remaining', '59');
    }
}
