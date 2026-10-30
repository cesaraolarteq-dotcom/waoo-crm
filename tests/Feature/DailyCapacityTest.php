<?php

namespace Tests\Feature;

use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class DailyCapacityTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Customer $customer;
    private ServiceVariant $variant;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28T12:00:00-05:00'));
        config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true,'waoo.sandbox_quick_stripe'=>true]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Queue::fake();
        $this->owner = User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->customer = Customer::create(['name'=>'María González','locale'=>'es']);
        $this->variant = ServiceVariant::create(['name'=>'Servicio de ejemplo','status'=>'active','duration_minutes'=>60,
            'requirements'=>[['kind'=>'equipment','equipment_kind'=>'speaker','quantity'=>1]]]);
        $this->price = PriceVersion::create(['service_variant_id'=>$this->variant->id,'amount_cents'=>10000,'currency'=>'USD','created_by'=>$this->owner->id]);
    }

    private function event(string $start = '2026-12-12T12:00:00-06:00', bool $committed = false): Event
    {
        $at=CarbonImmutable::parse($start)->utc();
        $event=Event::create(['customer_id'=>$this->customer->id,'title'=>'Cumpleaños de Sofía','starts_at'=>$at,'ends_at'=>$at->addHour(),
            'timezone'=>'America/Chicago','address'=>'Salón de ejemplo','setup_minutes'=>0,'breakdown_minutes'=>0,'travel_fee_cents'=>0]);
        EventService::create(['event_id'=>$event->id,'service_variant_id'=>$this->variant->id,'price_version_id'=>$this->price->id,
            'requirements_snapshot'=>$this->variant->requirements,'duration_minutes'=>60,'operational_ready'=>true]);
        if($committed)$event->forceFill(['status'=>'capacity_reserved'])->save();
        return $event->fresh();
    }

    private function input(Event $event): array
    {
        $unit=Resource::create(['code'=>'SPK-'.$event->id,'name'=>'Bocina de ejemplo','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]]);
        return ['expected_version'=>$event->version,'resource_ids'=>[$unit->id],
            'payment'=>['method'=>'external_zelle','reference'=>'DAILY-'.$event->id,'amount_cents'=>5000,'received_at'=>now()->subMinute()->toIso8601String()],
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Origen y regreso ficticios revisados por el dueño.','transfers'=>[]]];
    }

    public function test_an_eleventh_party_is_rejected_even_with_its_own_free_equipment(): void
    {
        for($i=0;$i<10;$i++)$this->event(committed:true);
        $event=$this->event();$input=$this->input($event);
        try { app(ConfirmBooking::class)->execute($event->id,$input,$this->owner);$this->fail('Eleventh party accepted.'); }
        catch(ValidationException $e){$this->assertArrayHasKey('booking',$e->errors());}
        $this->assertSame('draft',$event->fresh()->status);
        foreach(['resource_reservations','booking_evidence','operational_reviews'] as $table)$this->assertDatabaseCount($table,0);
    }

    public function test_tenth_party_and_exact_retry_count_only_one_event(): void
    {
        for($i=0;$i<9;$i++)$this->event(committed:true);
        $event=$this->event();$input=$this->input($event);
        $saved=app(ConfirmBooking::class)->execute($event->id,$input,$this->owner);
        $this->assertSame('capacity_reserved',$saved->status);
        app(ConfirmBooking::class)->execute($event->id,$input,$this->owner);
        $this->assertDatabaseCount('booking_evidence',1);
        $this->assertDatabaseCount('resource_reservations',1);
    }

    public function test_two_processes_with_distinct_resources_cannot_both_take_the_last_daily_place(): void
    {
        for($i=0;$i<9;$i++)$this->event(committed:true);
        $a=$this->event();$b=$this->event();$pa=$this->input($a);$pb=$this->input($b);
        $ownerId=$this->owner->id;
        $worker=static function(int $id,array $payload)use($ownerId){
            try{app(ConfirmBooking::class)->execute($id,$payload,User::findOrFail($ownerId));return 'accepted';}
            catch(ValidationException){return 'full';}
        };
        $results=ConcurrentWorkers::run([fn()=>$worker($a->id,$pa),fn()=>$worker($b->id,$pb)]);sort($results);
        $this->assertSame(['accepted','full'],$results);
        $this->assertDatabaseCount('booking_evidence',1);
        $this->assertSame(10,Event::where('status','capacity_reserved')->count());
    }

    public function test_uncommitted_drafts_and_cancelled_parties_do_not_consume_daily_quota(): void
    {
        for($i=0;$i<12;$i++)$this->event();
        for($i=0;$i<10;$i++)$this->event(committed:true)->forceFill(['status'=>'cancelled'])->save();
        $event=$this->event();
        $this->assertSame('capacity_reserved',app(ConfirmBooking::class)->execute($event->id,$this->input($event),$this->owner)->status);
    }

    public function test_overnight_operating_day_is_anchored_at_eight_houston_and_not_counted_twice(): void
    {
        for($i=0;$i<10;$i++)$this->event('2026-12-12T12:00:00-06:00',true);
        $night=$this->event('2026-12-13T00:30:00-06:00');
        try{app(ConfirmBooking::class)->execute($night->id,$this->input($night),$this->owner);$this->fail('Overnight quota bypassed.');}
        catch(ValidationException){$this->assertSame('draft',$night->fresh()->status);}
        $next=$this->event('2026-12-13T08:00:00-06:00');
        $this->assertSame('capacity_reserved',app(ConfirmBooking::class)->execute($next->id,$this->input($next),$this->owner)->status);
        foreach(['2027-03-13T12:00:00-06:00'=>23,'2026-10-31T12:00:00-05:00'=>25] as $start=>$hours){
            [$from,$until]=\App\Services\Scheduling\DailyCapacity::bounds(CarbonImmutable::parse($start));
            $this->assertSame($hours,(int)$from->diffInHours($until));
        }
    }

    public function test_unpaid_lease_without_costumes_occupies_a_place_until_actual_expiry_not_just_its_clock_deadline(): void
    {
        for($i=0;$i<9;$i++)$this->event(committed:true);
        $event=$this->event();
        $finance=app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$event->id,['expected_version'=>1],(string)\Illuminate\Support\Str::uuid());
        $lease=app(\App\Services\Booking\PrepareQuickStripeLease::class)->execute($this->owner,$event->id,
            ['expected_event_version'=>1,'financial_revision_id'=>$finance->id],(string)\Illuminate\Support\Str::uuid());
        $this->assertDatabaseCount('resource_reservations',0);
        $this->travel(49)->hours();
        $other=$this->event();$input=$this->input($other);
        try{app(ConfirmBooking::class)->execute($other->id,$input,$this->owner);$this->fail('Wall clock freed a live lease.');}
        catch(ValidationException){$this->assertSame('draft',$other->fresh()->status);}
        app(\App\Services\Booking\ExpireQuickStripeLease::class)->execute($this->owner,$event->id,['expected_lease_version'=>$lease['version']],(string)\Illuminate\Support\Str::uuid());
        $this->assertSame('capacity_reserved',app(ConfirmBooking::class)->execute($other->id,$input,$this->owner)->status);
    }

    public function test_imported_honeybook_operating_commitments_count_even_before_resources_are_assigned(): void
    {
        for($i=0;$i<10;$i++){
            $event=$this->event();$event->forceFill(['responsible_system'=>'honeybook'])->save();
            // Minimal legacy linkage deliberately has no money/revision: incomplete external truth still blocks admission.
            \App\Models\ImportedProjectLink::create(['event_id'=>$event->id,'customer_id'=>$event->customer_id,
                'project_key'=>hash('sha256','synthetic-'.$event->id),'domain_sha256'=>str_repeat('0',64)]);
        }
        $other=$this->event();
        $this->expectException(ValidationException::class);
        app(ConfirmBooking::class)->execute($other->id,$this->input($other),$this->owner);
    }

    public function test_external_money_remains_durable_when_the_day_is_already_full(): void
    {
        for($i=0;$i<10;$i++)$this->event(committed:true);
        $event=$this->event();
        $finance=app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$event->id,['expected_version'=>1],(string)\Illuminate\Support\Str::uuid());
        $key=(string)\Illuminate\Support\Str::uuid();
        $data=['financial_revision_id'=>$finance->id,'amount_cents'=>5000,'currency'=>'USD','reference'=>'ACTUAL-SYNTHETIC-MONEY',
            'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true];
        $entry=app(\App\Services\Finance\RecordZellePayment::class)->execute($this->owner,$event->id,$data,$key);
        $this->assertSame(5000,$entry->amount_cents);
        $this->assertDatabaseHas('payment_exceptions',['event_id'=>$event->id,'kind'=>'daily_capacity_exceeded','state'=>'open']);
        app(\App\Services\Finance\RecordZellePayment::class)->execute($this->owner,$event->id,$data,$key);
        $this->assertDatabaseCount('ledger_entries',1);
        $this->assertDatabaseCount('resource_reservations',0);
        $this->assertSame('draft',$event->fresh()->status);
    }

    public function test_a_paid_event_with_several_resource_rows_still_occupies_only_one_place(): void
    {
        for($i=0;$i<8;$i++)$this->event(committed:true);
        $paid=$this->event();$this->payment($paid);
        $paid->forceFill(['status'=>'capacity_reserved'])->save();
        foreach([1,2] as $index){
            $r=Resource::create(['code'=>'MULTI-'.$index,'name'=>'Bocina de ejemplo','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]]);
            \App\Models\ResourceReservation::create(['event_id'=>$paid->id,'resource_id'=>$r->id,'starts_at'=>$paid->starts_at,'ends_at'=>$paid->ends_at]);
        }
        $tenth=$this->event();
        $this->assertSame('capacity_reserved',app(ConfirmBooking::class)->execute($tenth->id,$this->input($tenth),$this->owner)->status);
        $this->assertSame(10,Event::where('status','capacity_reserved')->count());
    }

    public function test_new_unpaid_lease_at_a_full_day_does_not_create_stock_or_a_dummy_receipt(): void
    {
        for($i=0;$i<10;$i++)$this->event(committed:true);
        $event=$this->event();
        $finance=app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$event->id,['expected_version'=>1],(string)\Illuminate\Support\Str::uuid());
        try{
            app(\App\Services\Booking\PrepareQuickStripeLease::class)->execute($this->owner,$event->id,
                ['expected_event_version'=>1,'financial_revision_id'=>$finance->id],(string)\Illuminate\Support\Str::uuid());
            $this->fail('Unpaid lease bypassed a full day.');
        }catch(ValidationException){
            foreach(['quick_stripe_leases','resource_reservations','ledger_entries'] as $table)$this->assertDatabaseCount($table,0);
        }
    }

    public function test_moving_a_paid_native_draft_cannot_bypass_the_full_day_guard(): void
    {
        for($i=0;$i<10;$i++)$this->event(committed:true);
        $event=$this->event('2026-12-14T12:00:00-06:00');$this->payment($event);
        $original=$event->starts_at;
        try{
            app(\App\Services\Records\SaveEventDraft::class)->execute($this->owner,[
                ...$event->only(['customer_id','title','timezone','address','setup_minutes','breakdown_minutes','travel_fee_cents']),
                'starts_at'=>'2026-12-12T12:00:00-06:00','ends_at'=>'2026-12-12T13:00:00-06:00','expected_version'=>1,
                'travel_fee_override_note'=>'Costo de transporte ficticio revisado por el dueño.',
                'services'=>[['price_version_id'=>$this->price->id]],
            ],$event->id);
            $this->fail('Moving a paid party bypassed daily admission.');
        }catch(ValidationException $e){$this->assertArrayHasKey('booking',$e->errors());}
        $this->assertTrue($original->equalTo($event->fresh()->starts_at));
        $this->assertDatabaseCount('ledger_entries',1);
    }

    private function payment(Event $event): void
    {
        $finance=app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$event->id,['expected_version'=>1],(string)\Illuminate\Support\Str::uuid());
        app(\App\Services\Finance\RecordZellePayment::class)->execute($this->owner,$event->id,[
            'financial_revision_id'=>$finance->id,'amount_cents'=>5000,'currency'=>'USD','reference'=>'SYNTHETIC-'.$event->id,
            'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)\Illuminate\Support\Str::uuid());
    }
}
