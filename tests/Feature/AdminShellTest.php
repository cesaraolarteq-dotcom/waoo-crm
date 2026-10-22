<?php
namespace Tests\Feature;

use App\Http\Middleware\RequireOwnerAdmin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $attributes = []): User
    {
        return User::factory()->create([...['name' => 'Fictitious Owner', 'role' => 'owner_admin', 'active' => true, 'password' => 'FictitiousPassword123!'], ...$attributes]);
    }

    private function enrolled(array $attributes = []): User
    {
        return $this->owner([...['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode(['fixture-recovery-code']))], ...$attributes]);
    }

    public function test_guest_is_sent_to_the_fortify_login_screen_in_spanish(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar')->assertSee('name="_token"', false)->assertSee('noindex', false);
        $this->get('/admin/login')->assertNotFound();
    }

    public function test_wrong_password_shows_a_spanish_error(): void
    {
        $owner = $this->owner();
        $this->from('/login')->post('/login', ['email' => $owner->email, 'password' => 'WrongPassword123!'])->assertRedirect('/login');
        $this->get('/login')->assertSee('El correo o la contraseña no son correctos.');
    }

    public function test_owner_with_2fa_goes_through_the_challenge_screen(): void
    {
        $owner = $this->enrolled();
        $this->post('/login', ['email' => $owner->email, 'password' => 'FictitiousPassword123!'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertOk()->assertSee('Código de 6 dígitos')->assertSee('código de recuperación');
        $this->from('/two-factor-challenge')->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertRedirect('/admin');
        $this->get('/admin')->assertOk()->assertSee('Waoo')->assertSee('Inicio');
    }

    public function test_unenrolled_owner_is_guided_through_enrollment_and_sees_recovery_codes_once(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->get('/admin')->assertRedirect(route('security.two-factor'));
        $this->get('/seguridad/dos-pasos')->assertRedirect('/user/confirm-password');
        $this->get('/user/confirm-password')->assertOk()->assertSee('Confirma tu');
        $this->post('/user/confirm-password', ['password' => 'FictitiousPassword123!'])->assertRedirect();

        $this->get('/seguridad/dos-pasos')->assertOk()->assertSee('Empezar')->assertHeader('Cache-Control', 'no-store, private');
        $this->from('/seguridad/dos-pasos')->post('/user/two-factor-authentication')->assertRedirect('/seguridad/dos-pasos');
        $this->get('/seguridad/dos-pasos')->assertOk()->assertSee('<svg', false)->assertSee('Escanea');

        $secret = decrypt($owner->fresh()->two_factor_secret);
        $this->from('/seguridad/dos-pasos')->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertRedirect('/seguridad/dos-pasos');
        $this->get('/seguridad/dos-pasos')->assertSee('El código no es correcto');
        $this->assertNull($owner->fresh()->two_factor_confirmed_at);

        $this->from('/seguridad/dos-pasos')->post('/user/confirmed-two-factor-authentication', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect('/seguridad/dos-pasos');
        $codes = $owner->fresh()->recoveryCodes();
        $this->assertCount(8, $codes);
        $this->get('/seguridad/dos-pasos')->assertOk()->assertSee($codes[0]);
        $this->get('/seguridad/dos-pasos')->assertRedirect('/admin'); // shown once, never again
        $this->get('/admin')->assertOk();
    }

    public function test_recovery_codes_render_right_after_confirmation(): void
    {
        $owner = $this->owner(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['code-one', 'code-two']))]);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $owner->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->withSession(['status' => 'two-factor-authentication-confirmed', 'auth.password_confirmed_at' => time()])
            ->get('/seguridad/dos-pasos')->assertOk()->assertSee('code-one')->assertSee('code-two')->assertSee('entrar al CRM');
    }

    public function test_panel_is_closed_to_staff_and_inactive_owners(): void
    {
        $this->actingAs($this->enrolled(['role' => 'staff']))->get('/admin')->assertForbidden();
        $this->actingAs($this->enrolled(['active' => false]))->get('/admin')->assertForbidden();
        $this->actingAs($this->owner(['role' => 'staff']))->get('/seguridad/dos-pasos')->assertRedirect('/user/confirm-password');
        $this->actingAs($this->owner(['role' => 'staff']))->withSession(['auth.password_confirmed_at' => time()])->get('/seguridad/dos-pasos')->assertForbidden();
    }

    public function test_owner_gate_also_runs_on_livewire_requests(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertFalse($panel->hasLogin(), 'Filament must not offer a password-only login');
        $this->assertContains(RequireOwnerAdmin::class, $panel->getAuthMiddleware());
        $this->assertContains(RequireOwnerAdmin::class, \Livewire\Livewire::getPersistentMiddleware());
    }
}
