<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, PortalGrant, User};
use App\Services\Portal\{IssuePortalGrant, RevealPortalGrantLink, RevokePortalGrant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class ShortPortalLinksTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Event $event;
    protected function setUp(): void
    {
        parent::setUp();config(['waoo.sandbox_documents'=>true,'waoo.short_portal_links'=>true]);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $customer=Customer::create(['name'=>'María González','email'=>'maria@example.test','locale'=>'es']);
        $this->event=Event::create(['customer_id'=>$customer->id,'title'=>'Celebración musical','starts_at'=>now()->addDays(20),'ends_at'=>now()->addDays(20)->addHour(),
            'timezone'=>'America/Chicago','status'=>'draft','version'=>1]);
    }
    private function issue(){return app(IssuePortalGrant::class)->execute($this->owner,$this->event->id,['customer_id'=>$this->event->customer_id,'expires_at'=>now()->addDays(2)->toIso8601String()]);}
    private function path($grant):string{return parse_url($grant->short_url,PHP_URL_PATH);}
    public function test_short_link_is_stable_encrypted_bound_and_does_not_replace_the_original_api_token():void
    {
        $g=$this->issue();$this->assertMatchesRegularExpression('~/r/[A-Za-z0-9_-]{12}$~',$g->short_url);
        $this->assertStringContainsString('/portal#token='.$g->token,$g->url);
        $copy=app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$g->id);
        $this->assertSame($g->short_url,$copy->short_url);$this->assertSame($g->url,$copy->url);
        $row=DB::table('short_portal_links')->sole();$code=basename($this->path($g));
        $this->assertSame(hash('sha256',$code),$row->code_hash);$this->assertStringNotContainsString($code,$row->encrypted_code);
        $audit=DB::table('audit_entries')->get()->toJson();$this->assertStringNotContainsString($code,$audit);$this->assertStringNotContainsString($g->token,$audit);
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('short_portal_links',1);
    }
    public function test_get_redirects_only_and_the_existing_token_exchange_still_works():void
    {
        $g=$this->issue();auth()->logout();$before=DB::table('audit_entries')->count();
        $this->get($this->path($g))->assertRedirect($g->url)->assertHeader('Cache-Control','no-store, private')
            ->assertHeader('Referrer-Policy','no-referrer')->assertHeader('X-Robots-Tag','noindex, nofollow');
        $this->assertSame($before,DB::table('audit_entries')->count());$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('signature_evidence',0);
        $this->postJson('/portal/api/session',['token'=>$g->token])->assertOk();
        $this->assertSame($g->id,session('portal_grant_id'));
    }
    public static function inactive():array{return [['expired'],['revoked'],['customer'],['honeybook']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('inactive')]
    public function test_inactive_links_fail_without_customer_details(string $reason):void
    {
        $g=$this->issue();$path=$this->path($g);
        if($reason==='expired')$this->travel(3)->days();
        if($reason==='revoked')app(RevokePortalGrant::class)->execute($this->owner,$g->id);
        if($reason==='customer')$this->event->update(['customer_id'=>Customer::create(['name'=>'Elena Martínez','locale'=>'es'])->id]);
        if($reason==='honeybook')$this->event->forceFill(['responsible_system'=>'honeybook'])->save();
        auth()->logout();$this->get($path)->assertNotFound()->assertDontSee('María')->assertHeader('Cache-Control','no-store, private')->assertHeader('X-Robots-Tag','noindex, nofollow');
    }
    public function test_invalid_and_disabled_routes_have_the_same_private_failure_headers():void
    {
        $g=$this->issue();$code=basename($this->path($g));
        $different=($code[0]==='a'?'b':'a').substr($code,1);
        foreach(['/r/not-a-valid-code','/r/'.str_repeat($code==='aaaaaaaaaaaa'?'b':'a',12),'/r/'.$different] as $path){
            $this->get($path)->assertNotFound()->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer');
        }
        config(['waoo.short_portal_links'=>false]);$this->get($this->path($g))->assertNotFound();
        $old=$this->issue();$this->assertNull($old->short_url);$this->assertDatabaseCount('short_portal_links',1);
    }
    public function test_tampering_with_the_mapping_or_original_token_fails_closed():void
    {
        $g=$this->issue();$row=DB::table('short_portal_links')->sole();
        DB::table('short_portal_links')->where('id',$row->id)->update(['encrypted_code'=>'synthetic-corruption']);
        $this->get($this->path($g))->assertNotFound();
        DB::table('short_portal_links')->where('id',$row->id)->update(['encrypted_code'=>$row->encrypted_code]);
        PortalGrant::findOrFail($g->id)->forceFill(['encrypted_token'=>'synthetic-corruption'])->save();
        $this->get($this->path($g))->assertNotFound();
    }
    public function test_staff_cannot_reveal_or_create_an_owner_link():void
    {
        $g=$this->issue();$staff=User::factory()->create(['role'=>'staff']);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(RevealPortalGrantLink::class)->execute($staff,$this->event->id,$g->id);
    }
    public function test_old_grants_can_be_shortened_concurrently_without_a_replacement_grant():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required.');
        config(['waoo.short_portal_links'=>false]);$g=$this->issue();config(['waoo.short_portal_links'=>true]);
        $ownerId=$this->owner->id;$eventId=$this->event->id;$id=$g->id;
        $worker=fn()=>app(RevealPortalGrantLink::class)->execute(User::findOrFail($ownerId),$eventId,$id)->short_url;
        $results=ConcurrentWorkers::run([$worker,$worker]);$this->assertSame($results[0],$results[1]);$this->assertStringContainsString('/r/',$results[0]);
        $this->assertDatabaseCount('short_portal_links',1);$this->assertDatabaseCount('portal_grants',1);
    }
    public function test_resolver_is_rate_limited_without_losing_privacy_headers():void
    {
        $g=$this->issue();$path=$this->path($g);auth()->logout();
        for($i=0;$i<20;$i++)$this->get($path)->assertRedirect();
        $this->get($path)->assertStatus(429)->assertHeader('Cache-Control','no-store, private')->assertHeader('Referrer-Policy','no-referrer')->assertHeader('X-Robots-Tag','noindex, nofollow');
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_staging_requires_https_and_secure_session_cookie_configuration():void
    {
        config(['app.url'=>'https://localhost']);$g=$this->issue();$this->app['env']='staging';config(['session.secure'=>true]);
        $this->get('http://localhost'.$this->path($g))->assertNotFound();
        $this->get('https://localhost'.$this->path($g))->assertRedirect($g->url);
        config(['session.secure'=>false]);$this->get('https://localhost'.$this->path($g))->assertNotFound();
    }
    public function test_real_quick_booking_and_owner_copy_prefer_the_same_short_link():void
    {
        config(['waoo.sandbox_finance'=>true]);
        $variant=\App\Models\ServiceVariant::create(['name'=>'Celebración musical','status'=>'active','duration_minutes'=>60,'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host']]]);
        $price=\App\Models\PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30000,'currency'=>'USD','created_by'=>$this->owner->id]);
        $result=app(\App\Services\Booking\QuickBook::class)->execute($this->owner,[
            'customer_id'=>$this->event->customer_id,'title'=>'Celebración de María','event_type'=>'birthday',
            'starts_at'=>'2026-12-19T15:00:00-06:00','ends_at'=>'2026-12-19T16:00:00-06:00','timezone'=>'America/Chicago','postal_code'=>'77493',
            'travel_fee_cents'=>0,'services'=>[['price_version_id'=>$price->id]],
            'deposit'=>['method'=>'zelle','amount_cents'=>15000,'reference'=>'MARIA-BANK-RECEIPT','received_on'=>now('America/Chicago')->format('Y-m-d'),'verified_received'=>true],
        ],(string)\Illuminate\Support\Str::uuid());
        $grant=PortalGrant::where('event_id',$result->event->id)->sole();
        $copy=app(RevealPortalGrantLink::class)->execute($this->owner,$result->event->id,$grant->id);
        $this->assertSame($copy->short_url,$result->portal_url);$this->assertStringNotContainsString('#token=',$result->portal_url);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $page=\Livewire\Livewire::test(\App\Filament\Resources\Events\Pages\EventContract::class,['record'=>$result->event->id])
            ->callAction('copyLink',[],['id'=>$grant->id])->assertSet('newLink.url',$copy->short_url)->assertSee($copy->short_url,false);
        $this->assertDatabaseCount('portal_grants',1);$this->assertDatabaseCount('ledger_entries',1);
    }

    public function test_quote_short_link_uses_its_own_portal_and_rejects_a_replaced_revision():void
    {
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_quotes'=>true]);
        $this->event->update(['travel_fee_cents'=>0]);
        $variant=\App\Models\ServiceVariant::create(['name'=>'Celebración musical','status'=>'active','duration_minutes'=>60,'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host']]]);
        $price=\App\Models\PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30000,'currency'=>'USD','created_by'=>$this->owner->id]);
        \App\Models\EventService::create(['event_id'=>$this->event->id,'service_variant_id'=>$variant->id,'price_version_id'=>$price->id,
            'requirements_snapshot'=>$variant->requirements,'duration_minutes'=>60,'operational_ready'=>true]);
        $revision=app(\App\Services\Quotes\CreateQuote::class)->execute($this->owner,['event_id'=>$this->event->id,'expected_event_version'=>1],(string)\Illuminate\Support\Str::uuid());
        $sent=app(\App\Services\Quotes\SendQuote::class)->execute($this->owner,$revision->quote_id,['expected_version'=>1,'expected_revision'=>1],(string)\Illuminate\Support\Str::uuid());
        $copy=app(RevealPortalGrantLink::class)->execute($this->owner,$this->event->id,$sent['portal_grant_id']);
        $path=parse_url($copy->short_url,PHP_URL_PATH);
        $destination=str_replace('/portal#','/portal/quote#',$copy->url);
        auth()->logout();$this->get($path)->assertRedirect($destination);
        \App\Models\Quote::findOrFail($revision->quote_id)->forceFill(['current_revision'=>2])->save();
        $this->get($path)->assertNotFound()->assertDontSee('María');
    }

}
