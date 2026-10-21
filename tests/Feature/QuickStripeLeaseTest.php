<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Event, FinancialRevision, PriceVersion, QuickStripeLease, QuickStripeLeaseEvent, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\{ExpireQuickStripeLease, PrepareQuickStripeLease, ReadQuickStripeLease, ReleaseQuickStripeLease};
use App\Services\Finance\{CreateFinancialRevision, RecordZellePayment};
use App\Services\Records\{SaveCharacter, SaveEventDraft};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class QuickStripeLeaseTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;private Character $character;private Resource $unit;private PriceVersion $price;private Event $event;private FinancialRevision $finance;
    protected function setUp():void
    {
        parent::setUp();$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-20T12:00:00-05:00'));config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.sandbox_quick_stripe'=>true]);Http::preventStrayRequests();
        $this->owner=User::factory()->create(['name'=>'María González','role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
        $this->character=Character::create(['name'=>'Mickey','active'=>true,'outfits'=>['0','Clásico']]);
        $this->unit=Resource::create(['code'=>'MICKEY-1','name'=>'Cabeza de Mickey','kind'=>'costume','status'=>'active','character_id'=>$this->character->id,'skills'=>[]]);
        $variant=ServiceVariant::create(['name'=>'Música y juegos','status'=>'active','duration_minutes'=>120,'character_quantity'=>1,'requirements'=>[],
            'character_options'=>[['character_id'=>$this->character->id,'requirements'=>[['kind'=>'costume','character_id'=>$this->character->id,'quantity'=>1],['kind'=>'staff','quantity'=>1,'skill'=>'performer']]]]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30001,'currency'=>'USD','created_by'=>$this->owner->id]);
        [$this->event,$this->finance]=$this->draft();
    }
    private function draft(array $over=[]):array
    {
        $customer=\App\Models\Customer::create(['name'=>'Elena Martínez','phone'=>'346-555-0101','locale'=>'es']);
        $start=\Carbon\CarbonImmutable::now('America/Chicago')->addDays(20)->setTime(12,0)->utc();
        $event=app(SaveEventDraft::class)->execute($this->owner,[
            'customer_id'=>$customer->id,'title'=>'Cumpleaños de Lucía','event_type'=>'birthday','starts_at'=>$start->toIso8601String(),'ends_at'=>$start->addHours(2)->toIso8601String(),
            'timezone'=>'America/Chicago','postal_code'=>'77493','travel_fee_cents'=>0,'setup_minutes'=>null,'breakdown_minutes'=>null,
            'services'=>[['price_version_id'=>$this->price->id,'characters'=>[['character_id'=>$this->character->id,'quantity'=>1,'outfit'=>'0']]]],...$over]);
        $finance=app(CreateFinancialRevision::class)->execute($this->owner,$event->id,['expected_version'=>$event->version],(string)Str::uuid());
        return [$event,$finance];
    }
    private function input(array $over=[]):array{return ['expected_event_version'=>$this->event->version,'financial_revision_id'=>$this->finance->id,...$over];}
    private function prepare(array $over=[],?string $key=null):array{return app(PrepareQuickStripeLease::class)->execute($this->owner,$this->event->id,$this->input($over),$key??(string)Str::uuid());}
    private function release(array $v,?string $key=null):array{return app(ReleaseQuickStripeLease::class)->execute($this->owner,$this->event->id,[
        'expected_event_version'=>$v['event_version'],'expected_lease_version'=>$v['version'],'reason'=>'La dueña revisó otro acuerdo antes de continuar.'],$key??(string)Str::uuid());}
    private function conflict(callable $call):void{try{$call();$this->fail('Mutation should be rejected');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}}

    public function test_unpaid_lease_reserves_exact_wardrobe_and_terms_without_inventing_money_or_staff():void
    {
        $v=$this->prepare();$this->assertSame('active',$v['state']);$this->assertSame('0',$v['rows'][0]['outfit']);$this->assertSame(['timing_unknown'],$v['issues']);
        $this->assertSame(15001,$v['terms']['principal_cents']);$this->assertSame(450,$v['terms']['customer_fee_cents']);$this->assertSame(15451,$v['terms']['payable_cents']);
        $this->assertSame(172800,$v['terms']['expires_at']-$v['terms']['created_at']);$this->assertSame(86400,$v['terms']['reminder_at']-$v['terms']['created_at']);
        $this->assertFalse($v['staff_confirmed']);$this->assertSame('draft',$this->event->fresh()->status);
        foreach(['ledger_entries','quick_bookings','booking_evidence','payment_intents','portal_grants','outbound_messages'] as $table)$this->assertDatabaseCount($table,0);
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('quick_stripe_lease_events',1);
        $this->assertArrayNotHasKey('payload',QuickStripeLease::sole()->toArray());
        $this->assertStringNotContainsString('Mickey',DB::table('quick_stripe_leases')->sole()->payload);
    }
    public function test_exact_retry_and_read_never_renew_or_expire_the_lease():void
    {
        $key=(string)Str::uuid();$first=$this->prepare(key:$key);$this->travel(49)->hours();
        $this->assertSame($first,$this->prepare(key:$key));$this->assertSame($first,app(ReadQuickStripeLease::class)->execute($this->owner,$this->event->id));
        $this->conflict(fn()=>$this->prepare());$this->conflict(fn()=>$this->prepare(['deposit_percent'=>100],$key));
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('quick_stripe_lease_events',1);
    }
    public function test_expiry_only_after_original_deadline_releases_owned_inventory_and_keeps_agreement():void
    {
        $v=$this->prepare();$key=(string)Str::uuid();$input=['expected_lease_version'=>$v['version']];
        $this->conflict(fn()=>app(ExpireQuickStripeLease::class)->execute($this->owner,$this->event->id,$input,$key));
        $this->travelTo(\Carbon\CarbonImmutable::createFromTimestampUTC($v['terms']['expires_at']));
        $done=app(ExpireQuickStripeLease::class)->execute($this->owner,$this->event->id,$input,$key);$this->assertSame('expired',$done['state']);$this->assertSame($v['terms'],$done['terms']);
        $this->assertSame($done,app(ExpireQuickStripeLease::class)->execute($this->owner,$this->event->id,$input,$key));
        $this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('financial_revisions',1);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('events',1);
        $this->conflict(fn()=>$this->prepare());
    }
    public function test_release_is_audited_idempotent_terminal_and_does_not_rename_or_cancel_the_party():void
    {
        $v=$this->prepare(['deposit_percent'=>100]);$key=(string)Str::uuid();$done=$this->release($v,$key);
        $this->assertSame('cancelled',$done['state']);$this->assertSame($done,$this->release($v,$key));
        $this->assertSame('draft',$this->event->fresh()->status);$this->assertSame(30001,$done['terms']['principal_cents']);
        $this->assertDatabaseHas('audit_entries',['action'=>'quick_stripe_lease.cancelled','actor_id'=>$this->owner->id]);
        $this->conflict(fn()=>$this->release($v));$this->assertDatabaseCount('quick_stripe_lease_events',2);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_unavailable_stock_rolls_back_unpaid_lease_without_a_dummy_receipt():void
    {
        $this->prepare();[$event,$finance]=$this->draft();
        try{app(PrepareQuickStripeLease::class)->execute($this->owner,$event->id,['expected_event_version'=>1,'financial_revision_id'=>$finance->id],(string)Str::uuid());$this->fail('Unavailable stock allocated');}
        catch(\Illuminate\Validation\ValidationException){$this->assertDatabaseCount('quick_stripe_leases',1);$this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',0);}
    }
    public function test_corrupted_evidence_or_reservation_cannot_release_another_events_unit():void
    {
        $v=$this->prepare();[$other]=$this->draft();$row=ResourceReservation::sole();DB::table('resource_reservations')->where('id',$row->id)->update(['event_id'=>$other->id]);
        $this->conflict(fn()=>$this->release($v));$this->assertDatabaseHas('resource_reservations',['id'=>$row->id,'event_id'=>$other->id]);
        DB::table('resource_reservations')->where('id',$row->id)->update(['event_id'=>$this->event->id]);
        DB::table('quick_stripe_leases')->where('id',$v['lease_id'])->update(['payload'=>'synthetic-corruption']);
        $this->conflict(fn()=>$this->release($v));$this->assertDatabaseCount('resource_reservations',1);
    }
    public function test_active_lease_blocks_native_writes_even_after_feature_switch_off():void
    {
        $this->prepare();config(['waoo.sandbox_quick_stripe'=>false]);
        $this->conflict(fn()=>app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid()));
        $this->conflict(fn()=>app(RecordZellePayment::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>$this->finance->id,'amount_cents'=>15001,
            'currency'=>'USD','reference'=>'ELENA-BANK-RECEIPT','received_on'=>now('America/Chicago')->format('Y-m-d'),'verified_received'=>true],(string)Str::uuid()));
        $this->conflict(fn()=>app(SaveCharacter::class)->execute($this->owner,['name'=>'Mickey','active'=>false,'outfits'=>['0','Clásico'],'expected_version'=>1],$this->character->id));
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('financial_revisions',1);
    }
    public function test_audit_failure_rolls_back_inventory_head_history_and_request():void
    {
        DB::listen(function($query){if(str_starts_with(strtolower($query->sql),'insert into ')&&str_contains($query->sql,'audit_entries')&&in_array('quick_stripe_lease.active',$query->bindings,true))throw new \RuntimeException('Synthetic lease audit failure');});
        try{$this->prepare();$this->fail('Partial lease committed');}catch(\RuntimeException $e){$this->assertSame('Synthetic lease audit failure',$e->getMessage());}
        $this->assertDatabaseCount('quick_stripe_leases',0);$this->assertDatabaseCount('quick_stripe_lease_events',0);$this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_two_owners_racing_the_last_head_create_one_lease_and_no_payment():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required.');
        [$otherEvent,$otherFinance]=$this->draft();$otherOwner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $workers=[];foreach([[$this->owner->id,$this->event->id,$this->finance->id],[$otherOwner->id,$otherEvent->id,$otherFinance->id]] as [$owner,$event,$finance])$workers[]=function()use($owner,$event,$finance){
            try{app(PrepareQuickStripeLease::class)->execute(User::findOrFail($owner),$event,['expected_event_version'=>1,'financial_revision_id'=>$finance],(string)Str::uuid());return 'held';}
            catch(\Illuminate\Validation\ValidationException){return 'blocked';}
        };
        $results=ConcurrentWorkers::run($workers);sort($results);$this->assertSame(['blocked','held'],$results);
        $this->assertDatabaseCount('quick_stripe_leases',1);$this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_dst_deadline_is_absolute_and_terminal_read_keeps_old_terms_after_an_explicit_draft_edit():void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-31T12:00:00-05:00'));
        $v=$this->prepare();$this->assertSame('2026-11-02 11:00',\Carbon\CarbonImmutable::createFromTimestampUTC($v['terms']['expires_at'])->setTimezone('America/Chicago')->format('Y-m-d H:i'));
        $this->release($v);$event=$this->event->fresh();
        $input=[...$event->only(['customer_id','title','event_type','timezone','address','postal_code','setup_minutes','breakdown_minutes','travel_fee_cents']),
            'starts_at'=>$event->starts_at->toIso8601String(),'ends_at'=>$event->ends_at->toIso8601String(),'expected_version'=>1,
            'services'=>[['price_version_id'=>$this->price->id]],'title'=>'Celebración de Elena'];
        app(SaveEventDraft::class)->execute($this->owner,$input,$event->id);
        $read=app(ReadQuickStripeLease::class)->execute($this->owner,$event->id);$this->assertSame($v['terms'],$read['terms']);$this->assertSame('cancelled',$read['state']);
    }
    public function test_native_edit_checkout_portal_quote_and_confirmation_cannot_bypass_the_active_lease():void
    {
        $this->prepare();$event=$this->event->fresh();
        $input=[...$event->only(['customer_id','title','event_type','timezone','address','postal_code','setup_minutes','breakdown_minutes','travel_fee_cents']),
            'starts_at'=>$event->starts_at->toIso8601String(),'ends_at'=>$event->ends_at->toIso8601String(),'expected_version'=>1,
            'services'=>[['price_version_id'=>$this->price->id]],'title'=>'Celebración de Elena'];
        $this->conflict(fn()=>app(SaveEventDraft::class)->execute($this->owner,$input,$event->id));
        $this->conflict(fn()=>app(\App\Services\Finance\CreateCheckoutIntent::class)->execute($this->owner,$event->id,['financial_revision_id'=>$this->finance->id,'purpose'=>'deposit'],(string)Str::uuid()));
        $this->conflict(fn()=>app(\App\Services\Portal\IssuePortalGrant::class)->execute($this->owner,$event->id,['customer_id'=>$event->customer_id,'expires_at'=>now()->addDays(2)->toIso8601String()]));
        config(['waoo.sandbox_quotes'=>true]);
        $this->conflict(fn()=>app(\App\Services\Quotes\CreateQuote::class)->execute($this->owner,['event_id'=>$event->id,'expected_event_version'=>1],(string)Str::uuid()));
        $this->conflict(fn()=>app(\App\Services\ConfirmBooking::class)->execute($event->id,['resource_ids'=>[]],$this->owner));
        $this->assertSame(1,$event->fresh()->version);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('payment_intents',0);$this->assertDatabaseCount('portal_grants',0);
    }
    public function test_corrupt_terminal_label_does_not_unlock_native_writes():void
    {
        $v=$this->prepare();DB::table('quick_stripe_leases')->where('id',$v['lease_id'])->update(['state'=>'cancelled']);
        $this->conflict(fn()=>app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid()));
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('financial_revisions',1);
    }
    public function test_incomplete_character_recipe_and_active_resource_block_do_not_create_a_payable_lease():void
    {
        [$event,$finance]=$this->draft(['services'=>[['price_version_id'=>$this->price->id,'characters'=>[]]]]);
        try{app(PrepareQuickStripeLease::class)->execute($this->owner,$event->id,['expected_event_version'=>1,'financial_revision_id'=>$finance->id],(string)Str::uuid());$this->fail('Missing character accepted');}
        catch(RecordWriteRejected $e){$this->assertSame(422,$e->status);}
        \App\Models\ResourceBlock::create(['resource_id'=>$this->unit->id,'starts_at'=>$this->event->starts_at,'ends_at'=>$this->event->ends_at,'reason'=>'Mantenimiento de la cabeza','created_by'=>$this->owner->id]);
        try{$this->prepare();$this->fail('Blocked unit allocated');}catch(\Illuminate\Validation\ValidationException){$this->assertDatabaseCount('quick_stripe_leases',0);$this->assertDatabaseCount('resource_reservations',0);}
    }
    public static function access():array{return [['off'],['staff'],['unenrolled']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('access')]
    public function test_owner_enrollment_and_independent_sandbox_are_required(string $kind):void
    {
        $actor=$this->owner;
        if($kind==='off')config(['waoo.sandbox_quick_stripe'=>false]);
        else $actor=User::factory()->create(['active'=>true,'role'=>$kind==='staff'?'staff':'owner_admin']);
        try{app(PrepareQuickStripeLease::class)->execute($actor,$this->event->id,$this->input(),(string)Str::uuid());$this->fail('Unauthorized lease accepted');}
        catch(\Illuminate\Auth\Access\AuthorizationException){$this->assertDatabaseCount('quick_stripe_leases',0);$this->assertDatabaseCount('resource_reservations',0);}
    }
    public function test_closed_input_and_source_version_guards_refuse_client_supplied_money():void
    {
        try{$this->prepare(['payable_cents'=>1]);$this->fail('Client amount accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('payable_cents',$e->errors());}
        $this->conflict(fn()=>$this->prepare(['expected_event_version'=>2]));$this->assertDatabaseCount('quick_stripe_leases',0);
    }

    public function test_unpaid_lease_and_actual_paid_quick_booking_share_the_same_last_unit():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required.');
        $paidOwner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $ownerId=$this->owner->id;$eventId=$this->event->id;$financeId=$this->finance->id;$paidOwnerId=$paidOwner->id;
        $input=['new_customer'=>['name'=>'Lucía Torres','phone'=>'346-555-0102','locale'=>'es'],'title'=>'Celebración de Lucía','event_type'=>'birthday',
            'starts_at'=>$this->event->starts_at->toIso8601String(),'ends_at'=>$this->event->ends_at->toIso8601String(),'timezone'=>'America/Chicago','postal_code'=>'77493',
            'travel_fee_cents'=>0,'setup_minutes'=>0,'breakdown_minutes'=>0,
            'services'=>[['price_version_id'=>$this->price->id,'characters'=>[['character_id'=>$this->character->id,'quantity'=>1,'outfit'=>'0']]]],
            'deposit'=>['method'=>'zelle','amount_cents'=>15001,'reference'=>'LUCIA-BANK-RECEIPT','received_on'=>now('America/Chicago')->format('Y-m-d'),'verified_received'=>true]];
        $results=ConcurrentWorkers::run([
            function()use($ownerId,$eventId,$financeId){try{app(PrepareQuickStripeLease::class)->execute(User::findOrFail($ownerId),$eventId,['expected_event_version'=>1,'financial_revision_id'=>$financeId],(string)Str::uuid());return 'pending';}catch(\Illuminate\Validation\ValidationException){return 'blocked';}},
            function()use($paidOwnerId,$input){$b=app(\App\Services\Booking\QuickBook::class)->execute(User::findOrFail($paidOwnerId),$input,(string)Str::uuid());return $b->costume_hold['state'];},
        ]);
        $this->assertTrue(in_array($results,[['pending','blocked'],['blocked','held']],true),json_encode($results));
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);$this->assertDatabaseCount('quick_bookings',1);
        $this->assertSame(15001,\App\Models\LedgerEntry::sole()->amount_cents);
    }

}
