<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, Lead, LeadSubmission, User};
use App\Services\Leads\{ReadLeads, ReceiveWebsiteLead, UpdateLead};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Mail, Queue};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class WebsiteLeadsTest extends TestCase
{
    use DatabaseMigrations;
    private string $secret = 'fictional-lead-test-secret-0000000000000000';
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp(); $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16 15:00:00', 'America/Chicago'));
        config(['waoo.sandbox_lead_intake' => true, 'waoo.lead_shared_secret' => $this->secret]); Mail::fake(); Queue::fake();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
    }
    private function payload(array $over = []): array
    {
        return [...['name' => 'Fixture Lead', 'phone' => '+1 (346) 555-0199', 'email' => 'fixture@example.invalid', 'event_date' => '2026-11-01',
            'start_time' => '01:30', 'zip' => '77493', 'event_type' => 'birthday', 'package' => 'website-fixture', 'message' => 'Fixture private message',
            'travel_estimate_text' => '$999 informational fixture', 'locale' => 'es', 'consent' => ['text' => '  Exact consent EN + ES  ', 'version' => 'fixture-v1'],
            'demand_flag' => 'filling', 'submitted_at' => now()->subMinute()->toIso8601String(), 'page' => '/contact'], ...$over];
    }
    private function signed(string $body, ?string $key = null, ?string $at = null, array $headers = [])
    {
        $key ??= (string) Str::uuid(); $at ??= (string) now()->getTimestamp();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key,
            'HTTP_X_WAOO_TIMESTAMP' => $at, 'HTTP_X_WAOO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $this->secret)];
        return $this->call('POST', ($headers['HTTPS'] ?? null) === 'on' ? 'https://localhost/api/leads' : '/api/leads', [], [], [], [...$server, ...$headers], $body);
    }
    private function receive(array $over = []): Lead
    {
        $body = json_encode($this->payload($over)); $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        return app(ReceiveWebsiteLead::class)->execute($body, $at, $key, 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $this->secret));
    }
    public function test_stateless_signed_intake_and_spool_retry_preserve_exact_evidence_once(): void
    {
        $body = json_encode($this->payload()); $key = (string) Str::uuid();
        $this->signed($body, $key)->assertStatus(202)->assertExactJson(['accepted' => true])->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Set-Cookie');
        $this->travel(10)->minutes(); $this->signed($body, $key)->assertStatus(202);
        $this->assertDatabaseCount('lead_submissions', 1); $this->assertDatabaseCount('leads', 1);
        $row = LeadSubmission::sole(); $this->assertSame(hash('sha256', $body), $row->payload_sha256);
        $this->assertSame('  Exact consent EN + ES  ', $row->payload['consent']['text']);
        $this->assertSame('01:30', $row->payload['start_time']); $this->assertSame('$999 informational fixture', $row->payload['travel_estimate_text']);
        $this->assertDatabaseCount('customers', 0); $this->assertDatabaseCount('events', 0);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'lead.received')->count());
        $this->assertStringNotContainsString('fixture@example.invalid', json_encode(DB::table('audit_entries')->get()));
        Mail::assertNothingSent(); Queue::assertNothingPushed();
    }
    public function test_signature_binds_body_timestamp_and_uuid_and_rejects_body_only_scheme(): void
    {
        $body = json_encode($this->payload()); $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        $sig = 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $this->secret);
        $this->signed($body.' ', $key, $at, ['HTTP_X_WAOO_SIGNATURE' => $sig])->assertUnauthorized();
        $this->signed($body, (string) Str::uuid(), $at, ['HTTP_X_WAOO_SIGNATURE' => $sig])->assertUnauthorized();
        $this->signed($body, $key, (string) ((int) $at + 1), ['HTTP_X_WAOO_SIGNATURE' => $sig])->assertUnauthorized();
        $this->signed($body, $key, $at, ['HTTP_X_WAOO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, $this->secret)])->assertUnauthorized();
        $this->signed($body, $key, (string) ((int) $at - 301))->assertUnauthorized();
        $this->signed($body, $key, (string) ((int) $at + 301))->assertUnauthorized();
        $this->assertDatabaseCount('leads', 0);
    }
    public function test_same_uuid_different_exact_body_conflicts_but_new_submission_is_not_merged(): void
    {
        $body = json_encode($this->payload()); $key = (string) Str::uuid();
        $this->signed($body, $key)->assertStatus(202); $this->signed($body.' ', $key)->assertConflict();
        $this->signed($body)->assertStatus(202); $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseCount('customers', 0);
    }
    public function test_validation_media_size_and_no_approved_consent_are_safe(): void
    {
        foreach ([['event_date' => '2026-02-30'], ['start_time' => '25:00'], ['page' => '/other'], ['card_number' => 'PRIVATE FORBIDDEN'], ['consent' => ['text' => 'hello', 'version' => 'v1', 'extra' => true]]] as $over) {
            $this->signed(json_encode($this->payload($over)))->assertUnprocessable();
        }
        $this->signed('{')->assertUnprocessable();
        $this->signed(json_encode($this->payload()), null, null, ['CONTENT_TYPE' => 'text/plain'])->assertStatus(415);
        $this->signed(str_repeat('x', 65537))->assertStatus(413);
        $this->signed(json_encode($this->payload(['consent' => null, 'start_time' => null, 'submitted_at' => '2020-01-01T00:00:00Z'])))->assertStatus(202);
        $this->assertNull(LeadSubmission::sole()->payload['consent']);
    }
    public function test_missing_secret_disabled_and_production_refuse_intake(): void
    {
        $body = json_encode($this->payload()); config(['waoo.lead_shared_secret' => null]); $this->signed($body)->assertStatus(503);
        config(['waoo.lead_shared_secret' => $this->secret, 'waoo.sandbox_lead_intake' => false]); $this->signed($body)->assertStatus(503);
        config(['waoo.sandbox_lead_intake' => true]); $this->app->instance('env', 'production');
        try { $this->signed($body, null, null, ['HTTPS' => 'on'])->assertStatus(503); } finally { $this->app->instance('env', 'testing'); }
        $this->assertDatabaseCount('leads', 0);
    }
    public function test_concurrent_signed_retries_commit_one_lead_submission_and_audit(): void
    {
        $body = json_encode($this->payload()); $key = (string) Str::uuid(); $at = (string) now()->getTimestamp();
        $signature = 'sha256='.hash_hmac('sha256', $at."\n".$key."\n".$body, $this->secret);
        $worker = fn () => app(ReceiveWebsiteLead::class)->execute($body, $at, $key, $signature)->id;
        $ids = ConcurrentWorkers::run([$worker, $worker]); $this->assertSame($ids[0], $ids[1]);
        $this->assertDatabaseCount('leads', 1); $this->assertDatabaseCount('lead_submissions', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'lead.received')->count());
    }
    public function test_audit_failure_rolls_back_intake_and_returns_sanitized_retryable_failure(): void
    {
        $fail = true; DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail) {
            if ($fail && str_contains($query, 'audit_entries') && in_array('lead.received', $bindings, true)) throw new \RuntimeException('PRIVATE FIXTURE FAILURE');
        });
        $body = json_encode($this->payload()); $key = (string) Str::uuid();
        try { $this->signed($body, $key)->assertStatus(503)->assertDontSee('PRIVATE FIXTURE')->assertDontSee('fixture@example.invalid'); }
        finally { $fail = false; }
        $this->assertDatabaseCount('leads', 0); $this->assertDatabaseCount('lead_submissions', 0);
        $this->signed($body, $key)->assertStatus(202);
    }
    public function test_owner_reads_duplicate_hints_without_merging_and_updates_are_version_guarded(): void
    {
        $lead = $this->receive(); $customer = Customer::create(['name' => 'Existing fixture', 'phone' => '346.555.0199', 'email' => 'FIXTURE@example.invalid', 'locale' => 'es']);
        $this->getJson('/admin/api/leads')->assertUnauthorized(); $this->actingAs($this->owner);
        $this->getJson('/admin/api/leads?per_page=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.duplicate_hints.0.customer_id', $customer->id)
            ->assertJsonPath('data.0.duplicate_hints.0.matched_by', ['email','phone'])->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/admin/api/leads/'.$lead->id, ['expected_version' => 1, 'status' => 'contacted', 'owner_note' => 'Fixture contacted owner note'])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson('/admin/api/leads/'.$lead->id, ['expected_version' => 1, 'status' => 'lost'])->assertConflict();
        $this->patchJson('/admin/api/leads/'.$lead->id, ['expected_version' => 2, 'status' => 'booked'])->assertUnprocessable();
        $this->assertSame(1, Customer::count()); $this->assertSame(0, Event::count());
        try { $lead->submission->update(['payload' => []]); $this->fail('Submission mutated.'); } catch (\LogicException) { $this->assertTrue(true); }
    }
    public function test_concurrent_status_editors_do_not_overwrite(): void
    {
        $id = $this->receive()->id; $actor = $this->owner;
        $worker = function () use ($id, $actor) {
            try { return app(UpdateLead::class)->execute($actor, $id, ['expected_version' => 1, 'status' => 'quoted'])->version; }
            catch (RecordWriteRejected $e) { return $e->status; }
        };
        $results = ConcurrentWorkers::run([$worker,$worker]); $this->assertContains(2, $results); $this->assertContains(409, $results);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'lead.updated')->count());
    }
    public function test_owner_role_2fa_and_feature_gates_cover_direct_and_http_read_write(): void
    {
        $lead = $this->receive(); $this->owner->forceFill(['role' => 'staff'])->save(); $this->actingAs($this->owner)->getJson('/admin/api/leads/'.$lead->id)->assertForbidden();
        try { app(ReadLeads::class)->show($this->owner, $lead->id); $this->fail('Staff read lead.'); } catch (\Illuminate\Auth\Access\AuthorizationException) { $this->assertTrue(true); }
        $this->owner->forceFill(['role' => 'owner_admin', 'two_factor_confirmed_at' => null])->save(); $this->getJson('/admin/api/leads')->assertForbidden();
        $this->owner->forceFill(['two_factor_confirmed_at' => now()])->save(); config(['waoo.sandbox_lead_intake' => false]); $this->getJson('/admin/api/leads')->assertForbidden();
        $this->assertSame('new', $lead->fresh()->status);
    }
    public function test_public_rate_limit_applies_and_has_private_error_headers(): void
    {
        for ($n = 0; $n < 120; $n++) $this->signed('{}', null, null, ['HTTP_X_WAOO_SIGNATURE' => 'invalid'])->assertUnauthorized();
        $this->signed('{}')->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private'); $this->assertDatabaseCount('leads', 0);
    }
    public function test_staging_uses_actual_stateless_no_csrf_route_and_requires_https(): void
    {
        $body = json_encode($this->payload()); $this->app->instance('env', 'staging');
        try {
            $this->signed($body)->assertForbidden();
            $this->signed($body, null, null, ['HTTPS' => 'on'])->assertStatus(202)->assertHeaderMissing('Set-Cookie');
        } finally { $this->app->instance('env', 'testing'); }
    }

}
