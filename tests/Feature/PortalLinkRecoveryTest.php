<?php
namespace Tests\Feature;

use App\Exceptions\PortalLinkRejected;
use App\Models\{Customer, Event, PortalGrant, User};
use App\Services\Portal\{IssuePortalGrant, PortalTokenVault, RevealPortalGrantLink, RevokePortalGrant};
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Crypt, DB, Log};
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class PortalLinkRecoveryTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_documents' => true,'app.url' => 'https://localhost']);
        $this->owner = $this->owner(); $this->actingAs($this->owner);
        $customer = Customer::create(['name' => 'Fixture client','email' => 'fixture@example.test','locale' => 'es']);
        $start = now()->addDays(20)->startOfHour();
        $this->event = Event::create(['customer_id' => $customer->id,'title' => 'Fixture party','starts_at' => $start,'ends_at' => $start->copy()->addHour(),'timezone' => 'America/Chicago']);
    }
    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner_admin','active' => true,'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at' => now()]);
    }
    private function issue()
    {
        return app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id' => $this->event->customer_id,'expires_at' => now()->addDays(5)->toIso8601String()]);
    }
    private function path(int $grantId): string
    {
        return '/admin/api/events/'.$this->event->id.'/portal-grants/'.$grantId.'/reveal-link';
    }
    private function reveal(int $grantId)
    {
        return $this->postJson($this->path($grantId));
    }
    public function test_same_active_link_is_recoverable_by_both_owners_without_issuing_or_touching_event(): void
    {
        $issued = $this->issue(); $before = $this->event->fresh()->getAttributes(); $row = PortalGrant::findOrFail($issued->id); $attrs = $row->getAttributes();
        $this->assertTrue($row->canRevealLink());
        $this->assertStringNotContainsString($issued->token,$row->encrypted_token);
        $this->assertStringNotContainsString($issued->token,json_encode($attrs));
        $this->assertArrayNotHasKey('encrypted_token',$row->toArray()); $this->assertArrayNotHasKey('token_hash',$row->toArray());
        $row->fill(['encrypted_token' => 'browser injection']); $this->assertSame($attrs['encrypted_token'],$row->encrypted_token);
        $this->reveal($issued->id)->assertOk()->assertExactJson(['grant_id' => $issued->id,'event_id' => $this->event->id,'url' => $issued->url,'expires_at' => $issued->expiresAt])
            ->assertHeader('Cache-Control','no-store, private')->assertHeader('Pragma','no-cache')->assertHeader('Referrer-Policy','no-referrer');
        $other = $this->owner(); // Explicit actor wins over the authenticated owner, no per-issuer restriction.
        $again = app(RevealPortalGrantLink::class)->execute($other,$this->event->id,$issued->id);
        $this->assertSame($issued->url,$again->url); $this->assertDatabaseCount('portal_grants',1);
        $this->assertSame($before,$this->event->fresh()->getAttributes()); $this->assertSame($attrs,PortalGrant::find($issued->id)->getAttributes());
        foreach (['ledger_entries','contracts','contract_presentations','signature_evidence'] as $table) $this->assertDatabaseCount($table,0);
        $audit = DB::table('audit_entries')->where('action','portal.link_revealed')->orderByDesc('id')->first();
        $this->assertSame($other->id,(int) $audit->actor_id); $this->assertSame(['event_id' => $this->event->id,'grant_id' => $issued->id],json_decode($audit->after,true));
        $this->assertStringNotContainsString($issued->token,json_encode(DB::table('audit_entries')->get()));
        $this->assertStringNotContainsString($row->encrypted_token,json_encode(DB::table('audit_entries')->get()));
        $this->assertSame(2,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
    }
    public function test_recovered_url_authenticates_the_client_and_old_hash_only_access_still_works(): void
    {
        $issued = $this->issue(); $url = $this->reveal($issued->id)->assertOk()->json('url');
        parse_str(parse_url($url,PHP_URL_FRAGMENT),$fragment); auth()->logout();
        $response = $this->postJson('/portal/api/session',['token' => $fragment['token']])->assertOk();
        $response->assertDontSee($issued->token)->assertDontSee('encrypted_token');
        PortalGrant::whereKey($issued->id)->firstOrFail()->forceFill(['encrypted_token' => null])->save();
        $this->postJson('/portal/api/session',['token' => $issued->token])->assertOk();
        $this->actingAs($this->owner); $this->reveal($issued->id)->assertConflict()->assertJsonPath('code','link_not_recoverable')->assertDontSee($issued->token);
    }
    public function test_missing_cross_event_and_changed_customer_do_not_reveal(): void
    {
        $issued = $this->issue();
        $this->reveal(999999)->assertNotFound()->assertJsonPath('code','grant_not_found');
        $this->postJson('/admin/api/events/999999/portal-grants/'.$issued->id.'/reveal-link')->assertNotFound()->assertJsonPath('code','grant_not_found');
        $customer = Customer::create(['name' => 'Other fixture','locale' => 'es']); $this->event->update(['customer_id' => $customer->id]);
        $this->reveal($issued->id)->assertNotFound()->assertJsonPath('code','grant_not_found')->assertDontSee($issued->token);
        $this->assertSame(0,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
    }
    public function test_expired_and_revoked_grants_are_not_renewed_or_recoverable(): void
    {
        $issued = $this->issue(); $this->travel(6)->days();
        try { $this->reveal($issued->id)->assertStatus(410)->assertJsonPath('code','grant_inactive'); $this->assertFalse(PortalGrant::find($issued->id)->canRevealLink()); }
        finally { $this->travelBack(); }
        $grant = app(RevokePortalGrant::class)->execute($this->owner,$issued->id);
        $this->assertNull($grant->encrypted_token); $this->assertSame(hash('sha256',$issued->token),$grant->token_hash);
        $this->reveal($issued->id)->assertStatus(410)->assertJsonPath('code','grant_inactive');
        $this->assertDatabaseCount('portal_grants',1); $this->assertSame($issued->expiresAt,$grant->expires_at->toIso8601String());
    }
    public function test_corrupt_wrong_hash_and_swapped_binding_return_safe_uniform_errors(): void
    {
        $issued = $this->issue(); $row = PortalGrant::findOrFail($issued->id); $cipher = $row->encrypted_token;
        $payload = json_decode(Crypt::decryptString($cipher),true);
        $bad = ['not a ciphertext',Crypt::encryptString('not JSON')];
        foreach (['grant_id','event_id','customer_id'] as $field) { $copy = $payload; $copy[$field]++; $bad[] = Crypt::encryptString(json_encode($copy)); }
        $copy = $payload; $copy['scopes'] = ['contract.read']; $bad[] = Crypt::encryptString(json_encode($copy));
        $copy = $payload; $copy['expires_at'] = now()->addDays(6)->toIso8601String(); $bad[] = Crypt::encryptString(json_encode($copy));
        $copy = $payload; $copy['token'] = str_repeat('x',43); $bad[] = Crypt::encryptString(json_encode($copy));
        foreach ($bad as $value) {
            $row->forceFill(['encrypted_token' => $value])->save();
            $this->reveal($issued->id)->assertConflict()->assertJsonPath('code','link_not_recoverable')->assertDontSee($issued->token)->assertDontSee($value)
                ->assertHeader('Cache-Control','no-store, private')->assertHeader('Pragma','no-cache');
        }
        $this->assertSame(0,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
    }
    public function test_previous_keys_allow_recovery_but_lost_key_does_not_change_hash_authentication(): void
    {
        $issued = $this->issue(); $original = Crypt::getFacadeRoot();
        try {
            Crypt::swap((new Encrypter(random_bytes(32),'AES-256-CBC'))->previousKeys([$original->getKey()]));
            $this->reveal($issued->id)->assertOk()->assertJsonPath('url',$issued->url);
            Crypt::swap(new Encrypter(random_bytes(32),'AES-256-CBC'));
            $this->reveal($issued->id)->assertConflict()->assertJsonPath('code','link_not_recoverable');
        } finally { Crypt::swap($original); }
        $this->assertSame(hash('sha256',$issued->token),PortalGrant::find($issued->id)->token_hash);
    }
    public function test_audit_failure_returns_no_url_or_original_exception_secret(): void
    {
        $issued = $this->issue(); $cipher = PortalGrant::find($issued->id)->encrypted_token; $fail = true; Log::spy();
        DB::connection()->beforeExecuting(function ($query,$bindings) use (&$fail,$issued,$cipher) {
            if ($fail && str_contains($query,'audit_entries') && in_array('portal.link_revealed',$bindings,true)) throw new \RuntimeException('Fixture '.$issued->token.' '.$cipher);
        });
        try { $this->reveal($issued->id)->assertStatus(503)->assertJsonPath('code','link_reveal_unavailable')->assertDontSee($issued->token)->assertDontSee($cipher)->assertJsonMissingPath('url'); }
        finally { $fail = false; }
        Log::shouldHaveReceived('error')->once()->with('portal.link_reveal_unavailable');
        $this->assertSame(0,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
        $this->assertSame($cipher,PortalGrant::find($issued->id)->encrypted_token);
    }
    public function test_failed_ciphertext_write_rolls_back_the_entire_issue_and_sanitizes_error(): void
    {
        $fail = true; Log::spy();
        DB::connection()->beforeExecuting(function ($query) use (&$fail) {
            if ($fail && str_contains($query,'portal_grants') && str_starts_with(strtolower(ltrim($query)),'update')) throw new \RuntimeException('Fixture encrypted write failure');
        });
        try { $this->issue(); $this->fail('Expected rollback'); }
        catch (PortalLinkRejected $e) { $this->assertSame(503,$e->status); $this->assertNull($e->getPrevious()); }
        finally { $fail = false; }
        $this->assertDatabaseCount('portal_grants',0); $this->assertSame(0,DB::table('audit_entries')->where('action','portal.grant_issued')->count());
        Log::shouldHaveReceived('error')->once()->with('portal.link_issue_unavailable');
    }
    public function test_stale_owner_roles_feature_and_production_gates_protect_direct_service_and_http(): void
    {
        $issued = $this->issue();
        foreach ([['active' => false],['role' => 'staff'],['two_factor_confirmed_at' => null]] as $change) {
            User::whereKey($this->owner->id)->update($change);
            try { app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$issued->id); $this->fail('Owner gate bypassed'); }
            catch (\Illuminate\Auth\Access\AuthorizationException) { $this->addToAssertionCount(1); }
            User::whereKey($this->owner->id)->update(['active' => true,'role' => 'owner_admin','two_factor_confirmed_at' => now()]);
        }
        config(['waoo.sandbox_documents' => false]); $this->reveal($issued->id)->assertForbidden();
        config(['waoo.sandbox_documents' => true]); $this->app->instance('env','production');
        try { $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN','fixture-csrf')->reveal($issued->id)->assertForbidden(); }
        finally { $this->app->instance('env','testing'); }
        auth()->logout(); $this->reveal($issued->id)->assertUnauthorized();
    }
    public function test_staging_csrf_https_cookie_and_no_store_guards_are_real(): void
    {
        $issued = $this->issue(); $this->app->instance('env','staging'); config(['session.secure' => true]);
        try {
            $this->withSession(['_token' => 'fixture-csrf'])->postJson('https://localhost'.$this->path($issued->id))->assertStatus(419)->assertHeader('Pragma','no-cache');
            $this->withSession(['_token' => 'fixture-csrf'])->withHeader('X-CSRF-TOKEN','fixture-csrf');
            $this->postJson('http://localhost'.$this->path($issued->id))->assertForbidden();
            config(['session.secure' => false]); $this->postJson('https://localhost'.$this->path($issued->id))->assertForbidden();
            config(['session.secure' => true]); $this->postJson('https://localhost'.$this->path($issued->id))->assertOk()->assertJsonPath('url',$issued->url)->assertHeader('Pragma','no-cache');
        } finally { $this->app->instance('env','testing'); }
    }
    public function test_unconfigured_origin_fails_without_issuing_a_replacement_or_reveal_audit(): void
    {
        $issued = $this->issue(); config(['app.url' => 'http://insecure.example.invalid']);
        $this->reveal($issued->id)->assertStatus(503)->assertJsonPath('code','link_reveal_unavailable')->assertJsonMissingPath('url');
        $this->assertDatabaseCount('portal_grants',1); $this->assertSame(0,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
    }
    public function test_revoke_rollback_preserves_ciphertext_and_idempotent_revoke_clears_legacy_residue(): void
    {
        $issued = $this->issue(); $cipher = PortalGrant::find($issued->id)->encrypted_token; $fail = true;
        DB::connection()->beforeExecuting(function ($query,$bindings) use (&$fail) {
            if ($fail && str_contains($query,'audit_entries') && in_array('portal.grant_revoked',$bindings,true)) throw new \RuntimeException('Fixture revoke audit failure');
        });
        try { app(RevokePortalGrant::class)->execute($this->owner,$issued->id); $this->fail('Expected audit rejection'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture revoke audit failure',$e->getMessage()); }
        finally { $fail = false; }
        $this->assertNull(PortalGrant::find($issued->id)->revoked_at); $this->assertSame($cipher,PortalGrant::find($issued->id)->encrypted_token);
        app(RevokePortalGrant::class)->execute($this->owner,$issued->id);
        PortalGrant::find($issued->id)->forceFill(['encrypted_token' => $cipher])->save();
        app(RevokePortalGrant::class)->execute($this->owner,$issued->id);
        $this->assertNull(PortalGrant::find($issued->id)->encrypted_token);
        $this->assertSame(1,DB::table('audit_entries')->where('action','portal.grant_revoked')->count());
    }
    public function test_real_reveal_waits_for_competing_revoke_and_then_observes_inactive_state(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $issued = $this->issue(); $dir = sys_get_temp_dir().'/waoo-reveal-race-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
        $wait = function ($file) { $deadline = microtime(true)+5; while (!file_exists($file) && microtime(true)<$deadline) usleep(1000); if (!file_exists($file)) throw new \RuntimeException('Fixture synchronization timed out'); };
        try {
            $results = ConcurrentWorkers::run([
                function () use ($issued,$dir,$wait) {
                    DB::transaction(function () use ($issued,$dir,$wait) {
                        app(RevokePortalGrant::class)->execute($this->owner,$issued->id);
                        touch($dir.'/locked'); $wait($dir.'/attempting'); usleep(100000);
                    });
                    return 'revoked';
                },
                function () use ($issued,$dir,$wait) {
                    $wait($dir.'/locked'); touch($dir.'/attempting');
                    try { app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$issued->id); return 'unsafe'; }
                    catch (PortalLinkRejected $e) { return $e->errorCode; }
                },
            ]);
            $this->assertSame(['revoked','grant_inactive'],$results); $this->assertNull(PortalGrant::find($issued->id)->encrypted_token);
            $this->assertSame(0,DB::table('audit_entries')->where('action','portal.link_revealed')->count());
        } finally { foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
    }
}
