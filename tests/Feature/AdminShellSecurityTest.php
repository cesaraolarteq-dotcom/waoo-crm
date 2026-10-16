<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class AdminShellSecurityTest extends TestCase
{
    use DatabaseMigrations;

    private function owner(): User
    {
        return User::factory()->create([
            'role' => 'owner_admin', 'active' => true, 'password' => 'FictitiousPassword123!',
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['fixture-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function dashboardSnapshot(User $owner): string
    {
        $this->postJson('/login', ['email' => $owner->email, 'password' => 'FictitiousPassword123!'])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/two-factor-challenge', ['code' => (new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertSuccessful();
        $html = $this->get('/admin')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $memo = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo'];
            if (str_contains(strtolower($memo['name']), 'dashboard')) return $snapshot;
        }
        $this->fail('Dashboard signed Livewire snapshot not found.');
    }

    private function update(string $snapshot)
    {
        $this->app['auth']->forgetGuards(); // A new HTTP request reloads the current DB user, not a cached test guard.
        return $this->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]]],
        ], ['X-Livewire' => 'true']);
    }

    public function test_real_livewire_update_succeeds_for_an_active_enrolled_owner(): void
    {
        $snapshot = $this->dashboardSnapshot($this->owner());
        $this->update($snapshot)->assertOk()->assertJsonStructure(['components']);
    }

    public function test_real_livewire_update_is_denied_after_owner_is_deactivated(): void
    {
        $owner = $this->owner(); $snapshot = $this->dashboardSnapshot($owner);
        $owner->forceFill(['active' => false])->save();
        $this->update($snapshot)->assertForbidden();
    }

    public function test_real_livewire_update_is_denied_after_owner_becomes_staff(): void
    {
        $owner = $this->owner(); $snapshot = $this->dashboardSnapshot($owner);
        $owner->forceFill(['role' => 'staff'])->save();
        $this->update($snapshot)->assertForbidden();
    }

    public function test_real_livewire_update_redirects_after_two_factor_enrollment_is_revoked(): void
    {
        $owner = $this->owner(); $snapshot = $this->dashboardSnapshot($owner);
        $owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->update($snapshot)->assertRedirect(route('security.two-factor'));
    }

    public function test_real_livewire_update_rejects_snapshot_tampering(): void
    {
        $snapshot = $this->dashboardSnapshot($this->owner());
        $payload = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
        $payload['memo']['path'] = 'login';
        $this->withoutExceptionHandling();
        $this->expectException(\Livewire\Mechanisms\HandleComponents\CorruptComponentPayloadException::class);
        $this->update(json_encode($payload, JSON_THROW_ON_ERROR));
    }
    public function test_real_livewire_update_is_denied_after_logout(): void
    {
        $snapshot = $this->dashboardSnapshot($this->owner());
        $this->postJson('/logout')->assertSuccessful();
        $this->update($snapshot)->assertUnauthorized();
    }

    public function test_missing_secret_with_a_remaining_confirmation_timestamp_fails_closed(): void
    {
        $owner = $this->owner(); $snapshot = $this->dashboardSnapshot($owner);
        $owner->forceFill(['two_factor_secret' => null])->save();
        $this->update($snapshot)->assertForbidden();
    }
}
