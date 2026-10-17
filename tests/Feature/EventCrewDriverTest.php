<?php
namespace Tests\Feature;
use App\Models\{BookingEvidence,Customer,Event,EventService,PriceVersion,Resource,ServiceVariant,User};
use App\Services\Records\ConfirmCapacity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class EventCrewDriverTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    protected function setUp():void{parent::setUp();$this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);}
    private function draft(array $roles):Event
    {
        $c=Customer::create(['name'=>'Synthetic crew client','locale'=>'es']);$e=Event::create(['customer_id'=>$c->id,'title'=>'Synthetic crew event','starts_at'=>now()->addDays(20),'ends_at'=>now()->addDays(20)->addHour(),'timezone'=>'America/Chicago','address'=>'Synthetic venue','setup_minutes'=>10,'breakdown_minutes'=>10,'travel_fee_cents'=>0]);
        foreach($roles as $role){$requirements=[['kind'=>'staff','skill'=>$role,'quantity'=>1]];$v=ServiceVariant::create(['name'=>'Synthetic role '.$role,'status'=>'active','duration_minutes'=>60,'requirements'=>$requirements]);$p=PriceVersion::create(['service_variant_id'=>$v->id,'amount_cents'=>10000,'currency'=>'USD','created_by'=>$this->owner->id]);EventService::create(['event_id'=>$e->id,'service_variant_id'=>$v->id,'price_version_id'=>$p->id,'requirements_snapshot'=>$requirements,'duration_minutes'=>60,'operational_ready'=>true]);}return $e->fresh();
    }
    private function staff(array $skills):Resource{return Resource::create(['code'=>'CREW-'.Str::random(10),'name'=>'Synthetic staff','kind'=>'staff','status'=>'active','skills'=>$skills]);}
    private function input(Event $e,array $ids):array{return ['expected_version'=>$e->version,'resource_ids'=>$ids,'payment'=>['method'=>'external_zelle','reference'=>'CREW-'.$e->id,'amount_cents'=>100000,'received_at'=>now()->subHour()->toIso8601String()],'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Synthetic origin and return checked.','transfers'=>[]]];}
    private function confirm(Event $e,array $ids):Event{return app(ConfirmCapacity::class)->execute($this->owner,$e->id,$this->input($e,$ids));}
    public function test_one_driver_across_two_services_needs_no_extra_person_and_freezes_assignment():void
    {
        $e=$this->draft(['host','face_painter']);$a=$this->staff(['host']);$b=$this->staff(['face_painter','driver']);$input=$this->input($e,[$a->id,$b->id]);app(ConfirmCapacity::class)->execute($this->owner,$e->id,$input);
        $snapshot=BookingEvidence::sole()->staff_assignment_snapshot;$this->assertSame('event-driver-v1',$snapshot['policy_version']);$this->assertSame([$b->id],$snapshot['assigned_driver_ids']);$this->assertCount(2,$snapshot['assignments']);$this->assertCount(2,array_unique(array_column(array_column($snapshot['assignments'],'slot'),'event_service_id')));$this->assertDatabaseCount('resource_reservations',2);
        $b->forceFill(['skills'=>['face_painter']])->save();app(ConfirmCapacity::class)->execute($this->owner,$e->id,$input);$this->assertSame($snapshot,BookingEvidence::sole()->staff_assignment_snapshot);$this->assertDatabaseCount('booking_evidence',1);
    }
    public function test_unused_spare_driver_is_rejected_atomically():void
    {
        $e=$this->draft(['host']);$host=$this->staff(['host']);$spare=$this->staff(['driver']);
        try{$this->confirm($e,[$host->id,$spare->id]);$this->fail('Unused driver counted');}catch(ValidationException $x){$this->assertSame('Falta alguien que maneje en el equipo.',$x->errors()['booking'][0]);}
        foreach(['booking_evidence','resource_reservations','operational_reviews'] as $t)$this->assertDatabaseCount($t,0);$this->assertSame('draft',$e->fresh()->status);
    }
    public function test_driver_occupies_real_role_even_when_ordinary_matching_would_choose_nondriver():void
    {
        $e=$this->draft(['host']);$n=$this->staff(['host']);$d=$this->staff(['host','driver']);$this->confirm($e,[$n->id,$d->id]);$s=BookingEvidence::sole()->staff_assignment_snapshot;$this->assertSame([$d->id],$s['assigned_driver_ids']);$this->assertCount(1,$s['assignments']);$this->assertSame($d->id,$s['assignments'][0]['resource_id']);
    }
    public function test_matching_reassigns_multiskilled_driver_without_double_counting():void
    {
        $e=$this->draft(['host','face_painter']);$driver=$this->staff(['driver','host','face_painter']);$host=$this->staff(['host']);$this->confirm($e,[$driver->id,$host->id]);$s=BookingEvidence::sole()->staff_assignment_snapshot;$this->assertSame([$driver->id],$s['assigned_driver_ids']);$this->assertCount(2,array_unique(array_column($s['assignments'],'resource_id')));
    }
    public function test_pre_policy_confirmation_replay_does_not_backfill_a_snapshot_or_recheck_new_rule():void
    {
        $e=$this->draft(['host']);$person=$this->staff(['host','driver']);$input=$this->input($e,[$person->id]);app(ConfirmCapacity::class)->execute($this->owner,$e->id,$input);
        // Simulate a pre-migration row; existing rows were left null, not reconstructed.
        \Illuminate\Support\Facades\DB::table('booking_evidence')->update(['staff_assignment_snapshot'=>null]);$person->forceFill(['skills'=>['host']])->save();
        app(ConfirmCapacity::class)->execute($this->owner,$e->id,$input);$this->assertNull(BookingEvidence::sole()->staff_assignment_snapshot);$this->assertDatabaseCount('booking_evidence',1);
    }
    public function test_one_driver_does_not_cover_two_vehicles():void
    {
        $e=$this->draft(['host','face_painter']);$a=$this->staff(['host','driver']);$b=$this->staff(['face_painter']);$vehicles=[];foreach([1,2] as $i)$vehicles[]=Resource::create(['code'=>'V'.$i,'name'=>'Synthetic vehicle','kind'=>'vehicle','status'=>'active','seats'=>4])->id;
        try{$this->confirm($e,[$a->id,$b->id,...$vehicles]);$this->fail('Missing second assigned driver');}catch(ValidationException $x){$this->assertSame('Faltan conductores para los vehículos seleccionados.',$x->errors()['booking'][0]);}
        $b->forceFill(['skills'=>['face_painter','driver']])->save();$this->confirm($e,[$a->id,$b->id,...$vehicles]);$this->assertSame(2,BookingEvidence::sole()->staff_assignment_snapshot['minimum_drivers']);
    }
}
