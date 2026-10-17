<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Mail,Queue};
use Livewire\Livewire;
use Tests\TestCase;

class EmailTemplatesBoundaryTest extends TestCase
{
    use DatabaseMigrations;
    private function owner(array $attributes=[]):User
    {
        return User::factory()->create([...['role'=>'owner_admin','active'=>true,'password'=>'FictitiousPassword123!',
            'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()],...$attributes]);
    }
    public function test_drafts_are_owner_only_and_never_queue_or_send_mail():void
    {
        Mail::fake();Queue::fake();
        $this->actingAs($this->owner(['role'=>'staff']))->get('/admin/correos')->assertForbidden();
        $this->actingAs($this->owner(['active'=>false]))->get('/admin/correos')->assertForbidden();
        $this->actingAs($this->owner(['two_factor_secret'=>null,'two_factor_confirmed_at'=>null]))->get('/admin/correos')->assertRedirect(route('security.two-factor'));
        $this->actingAs($this->owner())->get('/admin/correos')->assertOk()->assertHeader('Cache-Control','no-store, private');
        Mail::assertNothingSent();Mail::assertNothingQueued();Queue::assertNothingPushed();
    }
    public function test_real_livewire_preview_call_rechecks_revoked_owner_role():void
    {
        Mail::fake();Queue::fake();$owner=$this->owner();
        $this->postJson('/login',['email'=>$owner->email,'password'=>'FictitiousPassword123!'])->assertOk();
        $this->postJson('/two-factor-challenge',['code'=>(new \PragmaRX\Google2FA\Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP')])->assertSuccessful();
        $html=$this->get('/admin/correos')->assertOk()->getContent();preg_match_all('/wire:snapshot="([^"]+)"/',$html,$matches);$snapshot=null;
        foreach($matches[1] as $encoded){$candidate=html_entity_decode($encoded,ENT_QUOTES|ENT_HTML5,'UTF-8');$memo=json_decode($candidate,true)['memo'];if(($memo['path']??'')==='admin/correos'){$snapshot=$candidate;break;}}
        $this->assertNotNull($snapshot);
        $payload=['_token'=>session()->token(),'components'=>[['snapshot'=>$snapshot,'updates'=>[],
            'calls'=>[['method'=>'$refresh','params'=>[]]]]]];
        $this->app['auth']->forgetGuards();Livewire::flushState();
        $this->postJson(Livewire::getUpdateUri(),$payload,['X-Livewire'=>'true'])->assertOk();
        $owner->forceFill(['role'=>'staff'])->save();$this->app['auth']->forgetGuards();
        Livewire::flushState(); // Fresh PHP-FPM middleware cycle; PHPUnit reuses the application.
        $this->postJson(Livewire::getUpdateUri(),$payload,['X-Livewire'=>'true'])->assertForbidden()->assertHeader('Cache-Control','no-store, private');
        Mail::assertNothingSent();Mail::assertNothingQueued();Queue::assertNothingPushed();
    }
}
