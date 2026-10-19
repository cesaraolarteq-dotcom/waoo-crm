<?php
namespace Tests\Feature;

use App\Models\{BookingEvidence, Character, Customer, Event, EventService, PriceVersion, Resource, ResourceReservation, ServiceVariant, StaffAccessGrant, StaffIdentity, User};
use App\Services\Staff\{IssueStaffGrant, RevealStaffGrantLink, RotateStaffGrant, RevokeStaffGrant, SaveStaffIdentity, SaveStaffJobInstructions, StaffContext};
use App\Services\Records\SaveResource;
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffPortalBackendTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Resource $person;
    private Resource $teammate;
    private Event $event;
    private array $identity;
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T15:00:00Z'));
        config(['waoo.sandbox_staff'=>true,'app.url'=>'https://localhost']);
        Http::preventStrayRequests(); Mail::fake(); $this->withCredentials();
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $this->person=Resource::create(['code'=>'STAFF-TEST','name'=>'Lucia PRIVATE_LASTNAME','kind'=>'staff','status'=>'active','skills'=>['performer','driver']]);
        $this->teammate=Resource::create(['code'=>'TEAM-TEST','name'=>'Carlos PRIVATE_TEAM_LASTNAME','kind'=>'staff','status'=>'active','skills'=>['performer']]);
        $character=Character::create(['name'=>'Synthetic Character','active'=>true,'outfits'=>['0']]);
        $costume=Resource::create(['code'=>'COSTUME-TEST','name'=>'Synthetic Costume','kind'=>'costume','status'=>'active','character_id'=>$character->id,'outfit'=>'0','skills'=>[]]);
        DB::table('costume_compatibilities')->insert(['staff_id'=>$this->person->id,'costume_id'=>$costume->id]);
        $customer=Customer::create(['name'=>'PRIVATE_CLIENT','email'=>'private@example.test','phone'=>'3465550199','locale'=>'es']);
        $this->event=Event::create(['customer_id'=>$customer->id,'title'=>'PRIVATE_TITLE','starts_at'=>'2026-10-20 20:00:00','ends_at'=>'2026-10-20 21:00:00',
            'timezone'=>'America/Chicago','address'=>'123 Fictional Road, TX 77494','setup_minutes'=>15,'breakdown_minutes'=>15,'travel_fee_cents'=>0]);
        $requirements=[['kind'=>'staff','skill'=>'performer','quantity'=>2],['kind'=>'costume','character_id'=>$character->id,'outfit'=>'0','quantity'=>1]];
        $variant=ServiceVariant::create(['name'=>'PRIVATE_PACKAGE','status'=>'active','duration_minutes'=>60,'requirements'=>$requirements]);
        $price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>123456,'currency'=>'USD','created_by'=>$this->owner->id]);
        EventService::create(['event_id'=>$this->event->id,'service_variant_id'=>$variant->id,'price_version_id'=>$price->id,'requirements_snapshot'=>$requirements,'duration_minutes'=>60,'operational_ready'=>true]);
        $this->event=app(ConfirmBooking::class)->execute($this->event->id,['expected_version'=>1,'resource_ids'=>[$this->person->id,$this->teammate->id,$costume->id],
            'payment'=>['method'=>'external_zelle','reference'=>'PRIVATE_PAYMENT','amount_cents'=>61728,'received_at'=>now()->subHour()->toIso8601String()],
            'review'=>['short_notice_reason'=>'Fixture owner approved short notice','staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'PRIVATE_INTERNAL_NOTES','transfers'=>[]]],$this->owner);
        $this->identity=$this->configure($this->person);
    }
    private function configure(Resource $resource, array $extra=[]): array
    {
        return app(SaveStaffIdentity::class)->execute($this->owner,[...['resource_id'=>$resource->id,'expected_resource_version'=>$resource->fresh()->version,'locale'=>'es','active'=>true,'phone'=>'+13465550100'],...$extra],(string)Str::uuid());
    }
    private function issue(?array $identity=null,?string $key=null): array
    {
        $i=$identity??$this->identity;
        return app(IssueStaffGrant::class)->execute($this->owner,$i['staff_id'],['expected_version'=>$i['version']],$key??(string)Str::uuid());
    }
    private function token(array $issued): string { return parse_url($issued['link'],PHP_URL_FRAGMENT); }
    private function enter(array $issued): string
    {
        auth()->logout();
        $response=$this->postJson('/staff/session',['token'=>$this->token($issued)])->assertOk()->assertCookie(StaffContext::COOKIE);
        return $response->getCookie(StaffContext::COOKIE)->getValue();
    }
    private function week(string $cookie) { return $this->withCookie(StaffContext::COOKIE,$cookie)->getJson('/staff/api/week'); }

    public function test_owner_issue_reveal_rotate_are_pinned_private_and_phone_is_owner_only(): void
    {
        $key=(string)Str::uuid(); $a=$this->issue(key:$key); $again=$this->issue(key:$key);
        $this->assertSame($a,$again);
        $this->assertSame('+13465550100',$a['phone']);
        $this->assertSame($a,app(RevealStaffGrantLink::class)->execute($this->owner,$a['grant']['id'],['expected_version'=>1]));
        $this->getJson('/admin/api/staff/links')->assertOk()->assertJsonMissingPath('data.0.phone')->assertJsonMissingPath('data.0.link');
        $this->assertStringNotContainsString('+13465550100',DB::table('staff_identities')->sole()->phone);
        $this->assertStringNotContainsString($this->token($a),json_encode(DB::table('audit_entries')->get()));
        $b=app(RotateStaffGrant::class)->execute($this->owner,$a['grant']['id'],['expected_version'=>1],(string)Str::uuid());
        $this->assertNotSame($a['link'],$b['link']);
        $this->postJson('/admin/api/staff/grants/'.$a['grant']['id'].'/reveal',['expected_version'=>1])->assertConflict();
        $this->postJson('/admin/api/staff/'.$this->identity['staff_id'].'/grants',['expected_version'=>1],['Idempotency-Key'=>$key])->assertConflict();
        $this->assertDatabaseCount('staff_access_grants',2);
    }
    public function test_real_week_uses_confirmed_snapshot_and_never_serializes_private_fields(): void
    {
        $cookie=$this->enter($this->issue());
        $json=$this->week($cookie)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertHeader('X-Robots-Tag','noindex, nofollow')
            ->assertJsonPath('preview',false)->assertJsonPath('jobs.0.outfit_version','0')->assertJsonPath('jobs.0.is_driver',true)
            ->assertJsonPath('jobs.0.day_contact',null)->assertJsonCount(1,'jobs')->assertJsonPath('jobs.0.team.0.first_name','Carlos')->getContent();
        foreach (['PRIVATE_','private@example.test','+13465550100','61728','123456','token_hash','encrypted_token'] as $secret) $this->assertStringNotContainsString($secret,$json);
        $this->withCookie(StaffContext::COOKIE,$cookie)->get('/staff')->assertOk()->assertSee('Synthetic Character (0)')->assertSee('2:45')->assertDontSee('Modo prueba');
        Mail::assertNothingSent();
    }
    public function test_rotation_revoke_and_expiry_cut_existing_sessions_without_extending_access(): void
    {
        $a=$this->issue(); $cookie=$this->enter($a);
        $this->week($cookie)->assertOk();
        $this->actingAs($this->owner);
        $b=app(RotateStaffGrant::class)->execute($this->owner,$a['grant']['id'],['expected_version'=>1],(string)Str::uuid());
        auth()->logout(); $this->week($cookie)->assertUnauthorized();
        // A browser holding an obsolete cookie can still exchange the new durable link.
        $this->withCookie(StaffContext::COOKIE,$cookie)->get('/staff')->assertOk()->assertSee('csrf-token',false);
        $fresh=$this->enter($b); $this->week($fresh)->assertOk();
        DB::table('staff_portal_sessions')->where('staff_access_grant_id',$b['grant']['id'])->update(['expires_at'=>now()]);
        $this->week($fresh)->assertUnauthorized();
        $this->travel(8)->days();
        $later=$this->enter($b); $this->week($later)->assertOk(); // Link itself has no seven-day expiry.
        $this->actingAs($this->owner);
        app(RevokeStaffGrant::class)->execute($this->owner,$b['grant']['id'],['expected_version'=>1],(string)Str::uuid());
        auth()->logout(); $this->week($later)->assertUnauthorized();
        $this->postJson('/staff/session',['token'=>$this->token($b)])->assertUnauthorized();
    }
    public function test_staff_cannot_choose_another_person_event_or_admin_session(): void
    {
        $other=$this->configure($this->teammate); $otherGrant=$this->issue($other);
        $a=$this->issue();
        $this->postJson('/staff/session',['token'=>$this->token($a)])->assertForbidden(); // Owner session cannot impersonate.
        auth()->logout();
        $this->withSession(['portal_grant_id'=>999])->postJson('/staff/session',['token'=>$this->token($a)])->assertForbidden();
        session()->forget('portal_grant_id');
        $cookie=$this->enter($a);
        $this->withCookie(StaffContext::COOKIE,$cookie)->getJson('/staff/api/week?staff_id='.$other['staff_id'].'&event=999')->assertOk()->assertJsonPath('staff.display_name','Lucia');
        $this->getJson('/admin/api/staff/links')->assertUnauthorized();
        $this->getJson('/staff/api/week')->assertOk(); // Previous withCookie is retained by the test client.
        $this->actingAs($this->owner); $this->week($cookie)->assertForbidden();
        $this->assertNotSame($a['link'],$otherGrant['link']);
    }
    public function test_only_current_houston_week_future_confirmed_actual_assignments_are_visible(): void
    {
        $cookie=$this->enter($this->issue());
        $this->week($cookie)->assertJsonCount(1,'jobs');
        foreach ([['status'=>'draft'],['status'=>'cancelled'],['starts_at'=>'2026-10-26 05:00:00','ends_at'=>'2026-10-26 06:00:00'],
            ['starts_at'=>'2026-10-19 12:00:00','ends_at'=>'2026-10-19 13:00:00']] as $change) {
            $before=$this->event->fresh()->getAttributes();
            DB::table('events')->where('id',$this->event->id)->update($change);
            $this->week($cookie)->assertJsonCount(0,'jobs');
            DB::table('events')->where('id',$this->event->id)->update($before);
        }
        $snapshot=BookingEvidence::sole()->staff_assignment_snapshot;
        $snapshot['assignments']=array_values(array_filter($snapshot['assignments'],fn($a)=>(int)$a['resource_id']!==$this->person->id));
        BookingEvidence::sole()->forceFill(['staff_assignment_snapshot'=>$snapshot])->save();
        $this->week($cookie)->assertJsonCount(0,'jobs');
    }
    public function test_operational_instructions_are_encrypted_versioned_and_contact_only_on_local_day(): void
    {
        $result=app(SaveStaffJobInstructions::class)->execute($this->owner,$this->event->id,['expected_event_version'=>$this->event->version,'expected_version'=>0,
            'bring'=>['Speaker'],'parking'=>'Staff-only parking','instructions'=>'Bring spare batteries','contact_name'=>'Fixture Contact','contact_phone'=>'+13465550123'],(string)Str::uuid());
        $raw=DB::table('staff_job_instructions')->sole();
        $this->assertStringNotContainsString('Fixture Contact',$raw->payload);
        $this->assertSame(1,$result['version']);
        $cookie=$this->enter($this->issue());
        $this->week($cookie)->assertJsonPath('jobs.0.day_contact',null)->assertJsonPath('jobs.0.bring.0','Speaker');
        $this->travelTo(CarbonImmutable::parse('2026-10-20T05:01:00Z'));
        $this->week($cookie)->assertJsonPath('jobs.0.day_contact.phone','+13465550123');
        $this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_missing_setup_address_and_old_confirmation_snapshot_remain_unknown(): void
    {
        DB::table('events')->where('id',$this->event->id)->update(['setup_minutes'=>null,'address'=>null]);
        DB::table('booking_evidence')->update(['staff_assignment_snapshot'=>null]);
        $cookie=$this->enter($this->issue());
        $this->week($cookie)->assertJsonPath('jobs.0.arrival','Pendiente')->assertJsonPath('jobs.0.maps',null)->assertJsonPath('jobs.0.is_driver',false);
        $this->withCookie(StaffContext::COOKIE,$cookie)->get('/staff')->assertOk()->assertDontSee('href=""',false);
    }
    public function test_week_bounds_are_local_mondays_across_both_dst_changes(): void
    {
        $issued=$this->issue();
        foreach ([['2026-03-08T15:00:00Z','2026-03-02T06:00:00+00:00','2026-03-09T05:00:00+00:00'],
            ['2026-11-01T16:00:00Z','2026-10-26T05:00:00+00:00','2026-11-02T06:00:00+00:00']] as [$now,$from,$to]) {
            $this->travelTo(CarbonImmutable::parse($now));
            $cookie=$this->enter($issued);
            $this->week($cookie)->assertJsonPath('week.starts_at',$from)->assertJsonPath('week.ends_at',$to);
        }
    }
    public function test_identity_disable_and_reenable_do_not_revive_old_grants(): void
    {
        $a=$this->issue(); $cookie=$this->enter($a);
        $this->actingAs($this->owner);
        $this->configure($this->person,['expected_version'=>2,'active'=>false]);
        auth()->logout();$this->week($cookie)->assertUnauthorized();
        $this->actingAs($this->owner);
        $this->configure($this->person,['expected_version'=>3,'active'=>true]);
        auth()->logout();$this->postJson('/staff/session',['token'=>$this->token($a)])->assertUnauthorized();
    }
    public function test_resource_deactivation_revokes_access_even_while_staff_flag_is_off(): void
    {
        $a=$this->issue(); $cookie=$this->enter($a);
        $this->actingAs($this->owner);
        ResourceReservation::query()->delete(); // Owner has resolved this person's future commitments first.
        config(['waoo.sandbox_staff'=>false]);
        $input=[...$this->person->only(['code','name','kind','skills']),'status'=>'inactive','expected_version'=>1];
        app(SaveResource::class)->execute($this->owner,$input,$this->person->id);
        $this->assertNotNull(StaffAccessGrant::find($a['grant']['id'])->revoked_at);
        config(['waoo.sandbox_staff'=>true]);auth()->logout();$this->week($cookie)->assertUnauthorized();
    }
    public function test_arrival_uses_its_own_dst_offset_and_stale_reservation_times_are_hidden(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T05:00:00Z'));
        DB::table('events')->where('id',$this->event->id)->update(['starts_at'=>'2026-11-01 07:10:00','ends_at'=>'2026-11-01 08:10:00','setup_minutes'=>30]);
        $cookie=$this->enter($this->issue());
        $this->week($cookie)->assertJsonCount(0,'jobs'); // Old resource interval is not an assignment at this new time.
        DB::table('resource_reservations')->where('event_id',$this->event->id)->update(['starts_at'=>'2026-11-01 07:10:00','ends_at'=>'2026-11-01 08:10:00']);
        $this->week($cookie)->assertJsonPath('jobs.0.arrival','1:40 a. m. CDT')->assertJsonPath('jobs.0.hours','1:10 a. m. CST – 2:10 a. m. CST');
        $this->travelTo(CarbonImmutable::parse('2026-03-08T06:00:00Z'));
        DB::table('events')->where('id',$this->event->id)->update(['starts_at'=>'2026-03-08 08:10:00','ends_at'=>'2026-03-08 09:10:00']);
        DB::table('resource_reservations')->where('event_id',$this->event->id)->update(['starts_at'=>'2026-03-08 08:10:00','ends_at'=>'2026-03-08 09:10:00']);
        $this->week($cookie)->assertJsonPath('jobs.0.arrival','1:40 a. m. CST')->assertJsonPath('jobs.0.hours','3:10 a. m. CDT – 4:10 a. m. CDT');
    }
    public function test_session_ceiling_is_168_hours_even_with_a_houston_php_timezone(): void
    {
        $issued=$this->issue();$zone=date_default_timezone_get();
        try {
            date_default_timezone_set('America/Chicago');
            $this->travelTo(CarbonImmutable::parse('2026-10-30T15:00:00Z'));
            $this->enter($issued);
            $expiry=CarbonImmutable::parse(DB::table('staff_portal_sessions')->sole()->expires_at,'UTC');
            $this->assertSame(604800,(int)CarbonImmutable::now('UTC')->diffInSeconds($expiry));
        } finally { date_default_timezone_set($zone); }
    }
    public function test_main_owner_screen_calls_real_services_and_does_not_reveal_a_replacement(): void
    {
        $page=\Livewire\Livewire::test(\App\Filament\Pages\StaffLinks::class)
            ->assertDontSee('Vista previa con personas de ejemplo')
            ->callAction('issue',arguments:['staff'=>$this->identity['staff_id']])
            ->assertSet('newLink.phone','+13465550100');
        $link=$page->get('newLink.url');
        $page->callAction('reveal',arguments:['staff'=>$this->identity['staff_id']])->assertSet('newLink.url',$link);
        $old=StaffAccessGrant::sole();
        $new=app(RotateStaffGrant::class)->execute($this->owner,$old->id,['expected_version'=>1],(string)Str::uuid());
        $page->callAction('reveal',arguments:['staff'=>$this->identity['staff_id']])->assertSet('newLink',null)->assertNotified('No se hizo el cambio');
        $this->assertNotSame($link,$new['link']);
        \Livewire\Livewire::test(\App\Filament\Pages\StaffLinks::class)
            ->callAction('revoke',arguments:['staff'=>$this->identity['staff_id']])->assertNotified('Enlace apagado');
        $this->assertNull(StaffAccessGrant::find($new['grant']['id'])->encrypted_token);
    }
    public function test_owner_api_crypto_binding_and_stale_version_cannot_retarget_a_link(): void
    {
        $a=$this->issue(); $other=$this->configure($this->teammate); $b=$this->issue($other);
        $this->postJson('/admin/api/staff/grants/'.$a['grant']['id'].'/reveal',['expected_version'=>1])->assertOk()->assertJsonPath('phone','+13465550100')->assertJsonPath('link',$a['link']);
        $this->postJson('/admin/api/staff/grants/'.$a['grant']['id'].'/reveal',['expected_version'=>99])->assertConflict();
        $raw=StaffAccessGrant::find($b['grant']['id'])->encrypted_token;
        StaffAccessGrant::find($a['grant']['id'])->forceFill(['encrypted_token'=>$raw])->save();
        $this->postJson('/admin/api/staff/grants/'.$a['grant']['id'].'/reveal',['expected_version'=>1])->assertConflict()->assertJsonMissingPath('link');
        $this->assertDatabaseCount('staff_access_grants',2);
    }
    public function test_private_failures_and_logout_never_leave_a_usable_staff_cookie(): void
    {
        $issued=$this->issue(); $cookie=$this->enter($issued);
        $this->withCookie(StaffContext::COOKIE,$cookie)->deleteJson('/staff/session')->assertOk()->assertCookieExpired(StaffContext::COOKIE);
        $this->week($cookie)->assertUnauthorized()->assertHeader('Referrer-Policy','no-referrer')->assertHeader('Cache-Control','no-store, private');
        $this->postJson('/staff/session',['token'=>'not-a-valid-token'])->assertUnprocessable()->assertJsonMissingPath('token');
        config(['waoo.sandbox_staff'=>false]);
        $this->get('/staff')->assertForbidden()->assertHeader('X-Robots-Tag','noindex, nofollow');
    }
    public function test_two_real_concurrent_issues_share_one_grant_and_receipt(): void
    {
        if (DB::connection()->getDriverName()!=='mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl.');
        $key=(string)Str::uuid();
        $worker=function () use ($key) { return $this->issue(key:$key)['grant']['id']; };
        $results=\Tests\Support\ConcurrentWorkers::run([$worker,$worker]);
        $this->assertIsInt($results[0]);
        $this->assertSame($results[0],$results[1]);
        $this->assertDatabaseCount('staff_access_grants',1);
        $this->assertSame(1,DB::table('mutation_receipts')->where('result_type','staff_grant')->count());
    }
    public function test_owner_permissions_flag_and_audit_rollback_are_enforced(): void
    {
        foreach ([['active'=>false],['role'=>'staff'],['two_factor_confirmed_at'=>null]] as $change) {
            User::whereKey($this->owner->id)->update($change);
            $this->getJson('/admin/api/staff/links')->assertForbidden();
            User::whereKey($this->owner->id)->update(['active'=>true,'role'=>'owner_admin','two_factor_confirmed_at'=>now()]);
        }
        config(['waoo.sandbox_staff'=>false]);$this->getJson('/admin/api/staff/links')->assertForbidden();
        config(['waoo.sandbox_staff'=>true]);
        $fail=true;
        DB::connection()->beforeExecuting(function ($query,$bindings) use (&$fail) {
            if ($fail && str_contains($query,'audit_entries') && in_array('staff.grant_issue',$bindings,true)) throw new \RuntimeException('Fixture audit rollback');
        });
        try { $this->issue(); $this->fail('Expected audit rollback.'); }
        catch (\RuntimeException $e) { $this->assertSame('Fixture audit rollback',$e->getMessage()); }
        finally { $fail=false; }
        $this->assertDatabaseCount('staff_access_grants',0);
        $this->assertSame(1,StaffIdentity::find($this->identity['staff_id'])->version);
        $this->issue();$this->assertDatabaseCount('staff_access_grants',1);
    }
    public function test_staging_csrf_origin_https_and_secure_cookie_are_enforced(): void
    {
        $issued=$this->issue();auth()->logout();
        $this->app->instance('env','staging');config(['session.secure'=>true]);
        try {
            $this->withSession(['_token'=>'fixture-csrf'])->postJson('https://localhost/staff/session',['token'=>$this->token($issued)])->assertStatus(419)->assertHeader('X-Robots-Tag','noindex, nofollow');
            $this->withHeader('X-CSRF-TOKEN','fixture-csrf');
            $this->postJson('http://localhost/staff/session',['token'=>$this->token($issued)])->assertForbidden();
            config(['session.secure'=>false]);$this->postJson('https://localhost/staff/session',['token'=>$this->token($issued)])->assertForbidden();
            config(['session.secure'=>true]);
            $this->withHeader('Origin','https://evil.invalid')->postJson('https://localhost/staff/session',['token'=>$this->token($issued)])->assertForbidden();
            $this->withHeader('Origin','https://localhost')->postJson('https://localhost/staff/session',['token'=>$this->token($issued)])->assertOk()->assertCookie(StaffContext::COOKIE);
        } finally { $this->app->instance('env','testing'); }
    }
}
