<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\RecoveryCodeDelivery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

// Codex review of PR #19: R1 (no-store on every sensitive auth response) and R2 (one-time delivery per generation).
class RecoveryCodeDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function enrolled(array $attributes = []): User
    {
        return User::factory()->create([...[
            'role' => 'owner_admin', 'active' => true, 'password' => 'FictitiousPassword123!',
            'two_factor_secret' => encrypt(self::SECRET), 'two_factor_confirmed_at' => now()->subDay(),
            'two_factor_recovery_codes' => encrypt(json_encode(['old-code-one', 'old-code-two'])),
        ], ...$attributes]);
    }

    private function confirmedSession(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function otp(): string
    {
        return (new Google2FA)->getCurrentOtp(self::SECRET);
    }

    public function test_every_sensitive_auth_response_is_no_store(): void
    {
        foreach (['/login', '/two-factor-challenge'] as $guestPage) {
            $this->assertNoStore($this->get($guestPage));
        }
        $owner = $this->enrolled();
        $this->confirmedSession($owner);
        foreach (['/user/two-factor-secret-key', '/user/two-factor-qr-code', '/user/two-factor-recovery-codes'] as $json) {
            $this->assertNoStore($this->getJson($json)->assertOk());
        }
        $this->assertNoStore($this->getJson('/user/two-factor-recovery-codes')->assertStatus(410)); // also the refusal
        $this->assertNoStore($this->get('/user/confirm-password'));
        $unenrolled = $this->enrolled(['two_factor_confirmed_at' => null]);
        $this->confirmedSession($unenrolled);
        $this->assertNoStore($this->get('/seguridad/dos-pasos')->assertOk());
    }

    public function test_reconfirming_an_enrolled_secret_does_not_show_the_same_codes_again(): void
    {
        $owner = $this->enrolled(['two_factor_recovery_codes_delivered_at' => now()->subDay()]);
        $this->confirmedSession($owner);
        $this->get('/seguridad/dos-pasos')->assertRedirect('/admin');
        $this->from('/seguridad/dos-pasos')->post('/user/confirmed-two-factor-authentication', ['code' => $this->otp()])->assertRedirect('/seguridad/dos-pasos');
        $this->get('/seguridad/dos-pasos')->assertRedirect('/admin')->assertDontSee('old-code-one');
        $this->getJson('/user/two-factor-recovery-codes')->assertStatus(410)->assertDontSee('old-code-one');
    }

    public function test_screen_first_then_json_read_is_refused(): void
    {
        $owner = $this->enrolled();
        $this->confirmedSession($owner);
        $this->withSession(['status' => 'two-factor-authentication-confirmed', 'auth.password_confirmed_at' => time()])
            ->get('/seguridad/dos-pasos')->assertOk()->assertSee('old-code-one');
        $this->getJson('/user/two-factor-recovery-codes')->assertStatus(410)->assertDontSee('old-code-one');
    }

    public function test_json_first_then_screen_redirects_without_codes(): void
    {
        $owner = $this->enrolled();
        $this->confirmedSession($owner);
        $this->getJson('/user/two-factor-recovery-codes')->assertOk()->assertJson(['old-code-one', 'old-code-two']);
        $this->getJson('/user/two-factor-recovery-codes')->assertStatus(410);
        $this->withSession(['status' => 'two-factor-authentication-confirmed', 'auth.password_confirmed_at' => time()])
            ->get('/seguridad/dos-pasos')->assertRedirect('/admin');
    }

    public function test_regenerating_issues_a_new_set_shown_once(): void
    {
        $owner = $this->enrolled(['two_factor_recovery_codes_delivered_at' => now()->subDay()]);
        $this->confirmedSession($owner);
        $this->from('/seguridad/dos-pasos')->post('/user/two-factor-recovery-codes')->assertRedirect('/seguridad/dos-pasos');
        $fresh = $owner->fresh()->recoveryCodes();
        $this->assertNotContains('old-code-one', $fresh);
        $this->get('/seguridad/dos-pasos')->assertOk()->assertSee($fresh[0])->assertDontSee('old-code-one');
        $this->get('/seguridad/dos-pasos')->assertRedirect('/admin');
        $this->getJson('/user/two-factor-recovery-codes')->assertStatus(410);
    }

    public function test_disabling_and_enabling_again_starts_a_new_generation(): void
    {
        $owner = $this->enrolled(['two_factor_recovery_codes_delivered_at' => now()->subDay()]);
        $this->confirmedSession($owner);
        $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();
        $this->assertNull($owner->fresh()->two_factor_recovery_codes_delivered_at);
        $this->assertNull($owner->fresh()->two_factor_confirmed_at);
    }

    public function test_claim_is_atomic_and_only_succeeds_once(): void
    {
        $owner = $this->enrolled();
        $this->assertSame(['old-code-one', 'old-code-two'], RecoveryCodeDelivery::claim($owner));
        $this->assertNull(RecoveryCodeDelivery::claim($owner));
        $this->assertNull(RecoveryCodeDelivery::claim($owner->fresh()));
    }

    // Codex re-review (v2): regeneration between the claim's write and its read must not deliver one generation twice.
    // Deterministic seam (same hook style as Codex's probe): regenerate right after the claim marks delivery.
    public function test_regeneration_during_a_claim_never_delivers_one_generation_twice(): void
    {
        $owner = $this->enrolled();
        $interleaved = false;
        DB::listen(function (QueryExecuted $query) use ($owner, &$interleaved): void {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'two_factor_recovery_codes_delivered_at')) {
                $interleaved = true;
                app(GenerateNewRecoveryCodes::class)($owner->fresh());
            }
        });
        $first = RecoveryCodeDelivery::claim($owner);
        $second = RecoveryCodeDelivery::claim($owner->fresh());
        $third = RecoveryCodeDelivery::claim($owner->fresh());

        $this->assertTrue($interleaved, 'The interleaving hook must have run.');
        $this->assertSame(['old-code-one', 'old-code-two'], $first, 'The claim returns the generation it marked, read under the lock.');
        $this->assertNotNull($second, 'The new generation is still deliverable exactly once.');
        $this->assertNotSame($first, $second);
        $this->assertSame($owner->fresh()->recoveryCodes(), $second);
        $this->assertNull($third, 'The new generation is never delivered twice.');
    }

    public function test_regeneration_after_delivery_reopens_once_and_using_a_recovery_code_does_not(): void
    {
        $owner = $this->enrolled();
        $this->assertNotNull(RecoveryCodeDelivery::claim($owner));
        app(\Laravel\Fortify\Actions\GenerateNewRecoveryCodes::class)($owner->fresh());
        $new = RecoveryCodeDelivery::claim($owner->fresh());
        $this->assertNotNull($new);
        $this->assertNull(RecoveryCodeDelivery::claim($owner->fresh()));

        $owner->fresh()->replaceRecoveryCode($new[0]); // what Fortify does when a recovery code is used to log in
        $this->assertNull(RecoveryCodeDelivery::claim($owner->fresh()), 'Using a code must not re-show the rest of the set.');
    }

    public function test_code_changing_fortify_actions_are_the_locked_versions(): void
    {
        $this->assertInstanceOf(\App\Actions\Fortify\LockedGenerateNewRecoveryCodes::class, app(\Laravel\Fortify\Actions\GenerateNewRecoveryCodes::class));
        $this->assertInstanceOf(\App\Actions\Fortify\LockedEnableTwoFactorAuthentication::class, app(\Laravel\Fortify\Actions\EnableTwoFactorAuthentication::class));
        $this->assertInstanceOf(\App\Actions\Fortify\LockedDisableTwoFactorAuthentication::class, app(\Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class));
    }

    private function assertNoStore($response): void
    {
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }
}
