<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use DatabaseMigrations;

    private function owner(array $attributes=[]): User
    {
        return User::factory()->create([...['role'=>'owner_admin','active'=>true,'password'=>'FictitiousPassword123!'],...$attributes]);
    }

    public function test_anonymous_and_non_owner_users_cannot_read_customer_data(): void
    {
        $this->getJson('/admin/api/session')->assertUnauthorized();
        $staff=$this->owner(['role'=>'staff','two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($staff)->getJson('/admin/api/session')->assertForbidden();
        $this->postJson('/register',['name'=>'Intruder','email'=>'intruder@example.test','password'=>'FictitiousPassword123!'])->assertNotFound();
    }

    public function test_unenrolled_owner_can_login_but_cannot_access_crm(): void
    {
        $owner=$this->owner();
        $this->postJson('/login',['email'=>$owner->email,'password'=>'FictitiousPassword123!'])->assertOk();
        $this->assertAuthenticatedAs($owner);
        $this->getJson('/admin/api/session')->assertForbidden();
    }

    public function test_owner_enrolls_confirms_and_can_then_access_crm(): void
    {
        $owner=$this->owner();$this->actingAs($owner);
        $this->postJson('/user/confirm-password',['password'=>'FictitiousPassword123!'])->assertSuccessful();
        $this->postJson('/user/two-factor-authentication')->assertSuccessful();
        $this->getJson('/admin/api/session')->assertForbidden();
        $owner->refresh();$secret=decrypt($owner->two_factor_secret);
        $this->assertNotSame($secret,$owner->getRawOriginal('two_factor_secret'));
        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg','url']);
        $this->postJson('/user/confirmed-two-factor-authentication',['code'=>(new Google2FA)->getCurrentOtp($secret)])->assertSuccessful();
        $this->getJson('/admin/api/session')->assertOk()->assertJsonPath('user.role','owner_admin');
        $this->getJson('/user/two-factor-recovery-codes')->assertOk();
        $this->assertArrayNotHasKey('two_factor_secret',$owner->fresh()->toArray());
        $this->deleteJson('/user/two-factor-authentication')->assertSuccessful();
        $this->getJson('/admin/api/session')->assertForbidden();
    }

    public function test_password_alone_cannot_bypass_two_factor_challenge(): void
    {
        $secret='JBSWY3DPEHPK3PXP';
        $owner=$this->owner(['two_factor_secret'=>encrypt($secret),'two_factor_confirmed_at'=>now(),'two_factor_recovery_codes'=>encrypt(json_encode(['fixture-recovery-code']))]);
        $this->postJson('/login',['email'=>$owner->email,'password'=>'FictitiousPassword123!'])->assertOk()->assertJsonPath('two_factor',true);
        $this->assertGuest();$this->getJson('/admin/api/session')->assertUnauthorized();
        $this->postJson('/two-factor-challenge',['code'=>'invalid'])->assertUnprocessable();$this->assertGuest();
        $this->postJson('/two-factor-challenge',['code'=>(new Google2FA)->getCurrentOtp($secret)])->assertSuccessful();
        $this->assertAuthenticatedAs($owner);$this->getJson('/admin/api/session')->assertOk();
        $this->postJson('/logout')->assertSuccessful();$this->assertGuest();
    }

    public function test_recovery_code_is_single_use_and_inactive_owner_is_denied(): void
    {
        $owner=$this->owner(['two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now(),'two_factor_recovery_codes'=>encrypt(json_encode(['fixture-recovery-code']))]);
        $this->postJson('/login',['email'=>$owner->email,'password'=>'FictitiousPassword123!'])->assertOk();
        $this->postJson('/two-factor-challenge',['recovery_code'=>'fixture-recovery-code'])->assertSuccessful();
        $this->assertNotContains('fixture-recovery-code',json_decode(decrypt($owner->fresh()->two_factor_recovery_codes),true));
        $owner->active=false;$owner->save();
        $this->actingAs($owner)->getJson('/admin/api/session')->assertForbidden();
        $this->postJson('/logout')->assertSuccessful();
        $this->postJson('/login',['email'=>$owner->email,'password'=>'FictitiousPassword123!'])->assertUnprocessable();
    }
    public function test_private_cli_provisions_an_owner_without_logging_password_or_skipping_2fa(): void
    {
        $this->artisan('waoo:admin', ['email'=>'owner@example.test','--name'=>'Fictitious owner'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres)', 'FictitiousPassword123!')->assertSuccessful();
        $user=User::where('email','owner@example.test')->firstOrFail();
        $this->assertSame('owner_admin',$user->role);$this->assertTrue($user->active);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('FictitiousPassword123!',$user->password));
        $this->assertNull($user->two_factor_confirmed_at);
        $audit=\Illuminate\Support\Facades\DB::table('audit_entries')->first();
        $this->assertSame('admin.provisioned',$audit->action);
        $this->assertStringNotContainsString('FictitiousPassword',$audit->after);
        $this->actingAs($user)->getJson('/admin/api/session')->assertForbidden();
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        for($i=0;$i<5;$i++)$this->postJson('/login',['email'=>'nobody@example.test','password'=>'FictitiousPassword123!'])->assertUnprocessable();
        $this->postJson('/login',['email'=>'nobody@example.test','password'=>'FictitiousPassword123!'])->assertTooManyRequests();
    }
}
