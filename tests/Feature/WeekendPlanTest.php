<?php
namespace Tests\Feature;

use App\Models\{BookingEvidence, Character, Customer, Event, EventService, EventTeamPlan, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Team\{AssignEventTeam, SuggestWeekendTeams, TeamSlots};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-02: plan the weekend's teams at once; seats come from the package; someone on the team drives. */
class WeekendPlanTest extends TestCase
{
    use DatabaseMigrations;

    public function test_unknown_or_changed_route_evidence_never_releases_the_previous_team(): void
    {
        $a=$this->party('2026-11-07 14:00');$b=$this->party('2026-11-07 19:00');$id=Resource::where('code','CESAR')->value('id');
        $this->save($a,['people'=>[TeamSlots::for($a)['people'][0]['key']=>$id]]);$old=ResourceReservation::pluck('id')->all();
        foreach([false,true] as $changed){
            app()->instance('waoo.availability.drive',function(...$args)use($a,$changed){
                $this->assertSame(0,DB::transactionLevel());if($changed)$a->update(['address'=>'456 Example Ave']);return $changed?15:null;
            });
            try{$this->save($b,['people'=>[TeamSlots::for($b)['people'][0]['key']=>$id]]);$this->fail('Unknown or stale route evidence reached the commit.');}
            catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(409,$e->status);}
            $this->assertSame($old,ResourceReservation::pluck('id')->all());$this->assertFalse(EventTeamPlan::where('event_id',$b->id)->exists());
        }
    }

    public function test_team_replay_neither_calls_the_route_provider_nor_replaces_reservations(): void
    {
        $e=$this->party('2026-11-07 14:00');$id=Resource::where('code','CESAR')->value('id');$key=(string)Str::uuid();
        $input=['expected_plan_version'=>0,'people'=>[TeamSlots::for($e)['people'][0]['key']=>$id],'costumes'=>[],'driver'=>null];
        $p=app(AssignEventTeam::class)->execute($this->owner,$e->id,$input,$key);$before=ResourceReservation::all()->toArray();
        app()->instance('waoo.availability.drive',fn(...$args)=>throw new \RuntimeException('A replay must not access the provider.'));
        $this->travel(3)->minutes();$again=app(AssignEventTeam::class)->execute($this->owner,$e->id,$input,$key);
        $this->assertSame($p->version,$again->version);$this->assertSame($before,ResourceReservation::all()->toArray());
    }

    public function test_one_costume_unit_cannot_cover_two_character_seats(): void
    {
        $e=$this->party('2026-11-07 14:00',elsa:true);$s=$e->services()->first();$recipe=$s->requirements_snapshot;
        foreach($recipe as &$r)if(in_array($r['kind'],['costume'],true)||($r['skill']??null)==='performer')$r['quantity']=2;
        unset($r);$s->update(['requirements_snapshot'=>$recipe]);$seats=TeamSlots::for($e)['costumes'];
        $unit=Resource::where('code','ELSA-1')->value('id');$a=Resource::where('code','NICOLE-HERNANDEZ')->value('id');$b=Resource::where('code','ANA-RODRIGUEZ')->value('id');
        try{$this->save($e,['people'=>[],'costumes'=>[$seats[0]['key']=>['unit_id'=>$unit,'wearer_id'=>$a],$seats[1]['key']=>['unit_id'=>$unit,'wearer_id'=>$b]]]);$this->fail('The same physical costume was used twice.');}
        catch(\App\Exceptions\RecordWriteRejected $x){$this->assertSame(422,$x->status);}
        $this->assertDatabaseCount('resource_reservations',0);
    }

    public function test_paired_wig_roles_are_not_duplicated_and_unpaired_roles_are_not_lost(): void
    {
        $e=$this->party('2026-11-07 14:00',elsa:true);$s=$e->services()->first();$recipe=$s->requirements_snapshot;
        foreach($recipe as &$r)if(($r['skill']??null)==='performer')$r['skill']='performer_wig';unset($r);
        $s->update(['requirements_snapshot'=>$recipe]);$this->assertCount(2,TeamSlots::for($e)['people']);
        foreach($recipe as &$r)if(($r['skill']??null)==='performer_wig')$r['quantity']=2;unset($r);
        $s->update(['requirements_snapshot'=>$recipe]);$this->assertCount(3,TeamSlots::for($e)['people']);
    }

    protected function tearDown(): void
    {
        // Disposable synthetic fixtures only; preserve the production rollback guard.
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('event_team_plans')) \Illuminate\Support\Facades\DB::table('event_team_plans')->delete();
        parent::tearDown();
    }


    public function test_a_fixed_gap_cannot_authorize_a_long_drive_for_the_saved_team(): void
    {
        $a=$this->party('2026-11-07 14:00');$b=$this->party('2026-11-07 18:00');
        $staff=Resource::where('code','CESAR')->value('id');
        $this->save($a,['people'=>[TeamSlots::for($a)['people'][0]['key']=>$staff]]);
        app()->instance('waoo.availability.drive',function(...$args){$this->assertSame(0,DB::transactionLevel());return 60;});
        try{$this->save($b,['people'=>[TeamSlots::for($b)['people'][0]['key']=>$staff]]);$this->fail('Saved a team without enough time for the real drive plus30/setup/breakdown.');}
        catch(\App\Exceptions\RecordWriteRejected $x){$this->assertSame(409,$x->status);}
        $this->assertFalse(EventTeamPlan::where('event_id',$b->id)->exists());
        $this->assertSame(1,ResourceReservation::where('event_id',$a->id)->count());
    }

    public function test_a_proven_short_drive_uses_drive_plus_thirty_instead_of_a_fixed_sixty(): void
    {
        $a=$this->party('2026-11-07 14:00');$b=$this->party('2026-11-07 17:45');
        $staff=Resource::where('code','CESAR')->value('id');
        $this->save($a,['people'=>[TeamSlots::for($a)['people'][0]['key']=>$staff]]);
        app()->instance('waoo.availability.drive',function(...$args){$this->assertSame(0,DB::transactionLevel());return 15;});
        $this->save($b,['people'=>[TeamSlots::for($b)['people'][0]['key']=>$staff]]);
        $this->assertTrue(EventTeamPlan::where('event_id',$b->id)->exists());
    }

    private User $owner; private ServiceVariant $waoo; private PriceVersion $price; private Character $elsa; private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'America/Chicago')); // Tuesday: planning day
        // These fixtures share one venue; explicit route cases override this synthetic provider.
        app()->instance('waoo.availability.drive',fn(...$args)=>0);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->elsa = Character::create(['name' => 'Elsa', 'active' => true]);
        $this->waoo = ServiceVariant::create(['name' => 'Waoo', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => []]);
        $this->price = PriceVersion::create(['service_variant_id' => $this->waoo->id, 'amount_cents' => 80000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        foreach ([['César', ['entertainer', 'host', 'driver']], ['Maybeli', ['entertainer', 'host', 'driver']], ['Nicole Hernández', ['host', 'performer']], ['Ana Rodríguez', ['host', 'performer']], ['Luis Pérez', ['host']]] as [$name, $skills])
            Resource::create(['code' => Str::upper(Str::slug($name)), 'name' => $name, 'kind' => 'staff', 'status' => 'active', 'skills' => $skills]);
        $unit = Resource::create(['code' => 'ELSA-1', 'name' => 'Elsa 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->elsa->id, 'skills' => []]);
        foreach (Resource::where('kind', 'staff')->whereJsonContains('skills', 'performer')->pluck('id') as $id) DB::table('costume_compatibilities')->insert(['staff_id' => $id, 'costume_id' => $unit->id]);
    }

    private function party(string $local, bool $elsa = false): Event
    {
        $c = Customer::create(['name' => 'María González '.(++$this->n), 'locale' => 'es']);
        $s = CarbonImmutable::parse($local, 'America/Chicago')->utc();
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Cumpleaños '.$this->n, 'starts_at' => $s, 'ends_at' => $s->addHours(2), 'timezone' => 'America/Chicago',
            'address' => '123 Oak Street', 'setup_minutes' => 30, 'breakdown_minutes' => 30, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->waoo->id, 'price_version_id' => $this->price->id, 'duration_minutes' => 120, 'operational_ready' => true,
            'requirements_snapshot' => [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'host', 'quantity' => 1],
                ...($elsa ? [['kind' => 'costume', 'character_id' => $this->elsa->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]] : [])]]);
        $e->status = 'capacity_reserved'; $e->save();
        BookingEvidence::create(['event_id' => $e->id, 'method' => 'external_zelle', 'reference' => 'Z-'.$e->id, 'amount_cents' => 40000, 'received_at' => now(), 'verified_by' => $this->owner->id,
            'staff_assignment_snapshot' => ['staff_mode' => 'later', 'assignments' => []]]);
        return $e->fresh();
    }

    private function save(Event $e, array $team, int $version = 0): EventTeamPlan
    {
        return app(AssignEventTeam::class)->execute($this->owner, $e->id, ['expected_plan_version' => $version, 'people' => $team['people'], 'costumes' => $team['costumes'] ?? [], 'driver' => $team['driver'] ?? null], (string) Str::uuid());
    }

    public function test_seats_come_from_the_package_and_driving_is_not_a_seat(): void
    {
        $need = TeamSlots::for($this->party('2026-11-07 14:00', elsa: true));
        $this->assertSame(['Animador/a 1', 'Recreador/a 1'], array_column($need['people'], 'label'));
        $this->assertSame(['Elsa'], array_column($need['costumes'], 'label'));
        $this->assertTrue($need['needs_driver']);
    }

    public function test_the_whole_weekend_is_suggested_together_and_nobody_is_in_two_places(): void
    {
        $a = $this->party('2026-11-07 14:00', elsa: true); $b = $this->party('2026-11-07 14:30');
        $s = app(SuggestWeekendTeams::class)->execute([$a, $b]);
        $people = [...array_values($s[$a->id]['people']), $s[$a->id]['costumes'] ? array_values($s[$a->id]['costumes'])[0]['wearer_id'] : null, ...array_values($s[$b->id]['people'])];
        $this->assertCount(5, array_filter($people));
        $this->assertSame(count(array_filter($people)), count(array_unique(array_filter($people))));
        $this->assertNotNull($s[$a->id]['costumes'][array_key_first($s[$a->id]['costumes'])]['unit_id']);
        $this->assertNotNull($s[$a->id]['driver']); $this->assertNotNull($s[$b->id]['driver']);
    }

    public function test_save_reserves_the_team_picks_the_only_driver_and_refuses_a_double_booking(): void
    {
        $a = $this->party('2026-11-07 14:00'); $b = $this->party('2026-11-07 14:30');
        $cesar = Resource::where('code', 'CESAR')->value('id'); $nicole = Resource::where('code', 'NICOLE-HERNANDEZ')->value('id');
        $plan = $this->save($a, ['people' => [array_column(TeamSlots::for($a)['people'], 'key')[0] => $cesar, array_column(TeamSlots::for($a)['people'], 'key')[1] => $nicole]]);
        $this->assertSame($cesar, $plan->driver_resource_id); // the only driver on the team
        $this->assertSame(2, ResourceReservation::where('event_id', $a->id)->count());
        try { $this->save($b, ['people' => [array_column(TeamSlots::for($b)['people'], 'key')[0] => $cesar]]); $this->fail('César was booked twice at the same time.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertStringContainsString('otra fiesta', $x->getMessage()); }
        // Re-saving replaces only what the plan reserved, and a stale screen is refused.
        $maybeli = Resource::where('code', 'MAYBELI')->value('id');
        $this->save($a, ['people' => [array_column(TeamSlots::for($a)['people'], 'key')[0] => $maybeli, array_column(TeamSlots::for($a)['people'], 'key')[1] => $nicole]], 1);
        $this->assertEqualsCanonicalizing([$maybeli, $nicole], ResourceReservation::where('event_id', $a->id)->pluck('resource_id')->all());
        $this->expectException(\App\Exceptions\RecordWriteRejected::class);
        $this->save($a, ['people' => []], 1);
    }

    public function test_a_wrong_skill_or_a_costume_the_person_cannot_wear_is_refused_and_external_driver_is_allowed(): void
    {
        $a = $this->party('2026-11-08 11:00', elsa: true);
        $need = TeamSlots::for($a); $seat = $need['people'][0]['key']; $c = $need['costumes'][0]['key'];
        try { $this->save($a, ['people' => [$seat => Resource::where('code', 'NICOLE-HERNANDEZ')->value('id')]]); $this->fail('Wrong skill accepted.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertStringContainsString('habilidad', $x->getMessage()); }
        $unit = Resource::where('code', 'ELSA-1')->value('id');
        try { $this->save($a, ['people' => [], 'costumes' => [$c => ['unit_id' => $unit, 'wearer_id' => Resource::where('code', 'CESAR')->value('id')]]]); $this->fail('Incompatible wearer accepted.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertStringContainsString('no puede usar', $x->getMessage()); }
        $plan = $this->save($a, ['people' => [], 'costumes' => [$c => ['unit_id' => $unit, 'wearer_id' => Resource::where('code', 'ANA-RODRIGUEZ')->value('id')]], 'driver' => 'external']);
        $this->assertTrue($plan->external_driver); $this->assertNull($plan->driver_resource_id);
    }

    public function test_owner_screen_suggests_and_saves_the_weekend(): void
    {
        $a = $this->party('2026-11-07 14:00'); $this->party('2026-11-08 10:00');
        $page = Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('Planear el fin de semana')->assertSee('Cumpleaños 1')->assertSee('Faltan');
        $page->call('suggest')->call('saveAll')->assertNotified('Equipo guardado');
        $this->assertSame(2, EventTeamPlan::count());
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('Completo');
    }

    public function test_reserved_costumes_are_prefilled_in_the_seat_of_the_requested_outfit(): void
    {
        $e = $this->party('2026-11-07 14:00', elsa: true);
        $service = $e->services()->sole(); $recipe = $service->requirements_snapshot;
        $recipe[2]['outfit'] = 'Azul';
        $recipe[] = ['kind' => 'costume', 'character_id' => $this->elsa->id, 'outfit' => 'Rosa', 'quantity' => 1];
        $service->forceFill(['requirements_snapshot' => $recipe])->save();
        $rosa = Resource::where('code', 'ELSA-1')->firstOrFail(); $rosa->update(['name' => 'A Rosa', 'outfit' => 'Rosa']);
        $azul = Resource::create(['code' => 'ELSA-2', 'name' => 'Z Azul', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->elsa->id, 'outfit' => 'Azul']);
        foreach ([$rosa, $azul] as $unit) ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $unit->id, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
        $seats = TeamSlots::for($e)['costumes'];
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)
            ->assertSet('picks.'.$e->id.'.costumes.'.$seats[0]['key'].'.unit_id', $azul->id)
            ->assertSet('picks.'.$e->id.'.costumes.'.$seats[1]['key'].'.unit_id', $rosa->id);
        $this->assertDatabaseCount('event_team_plans', 0, null);
    }

    public function test_saving_the_team_cannot_replace_the_agreed_outfit_with_another_outfit(): void
    {
        $e = $this->party('2026-11-07 14:00', elsa: true);
        $service = $e->services()->sole(); $recipe = $service->requirements_snapshot; $recipe[2]['outfit'] = 'Azul';
        $service->forceFill(['requirements_snapshot' => $recipe])->save();
        $unit = Resource::where('code', 'ELSA-1')->firstOrFail(); $unit->update(['outfit' => 'Rosa']);
        $seat = TeamSlots::for($e)['costumes'][0]['key'];
        try {
            $this->save($e, ['people' => [], 'costumes' => [$seat => ['unit_id' => $unit->id]]]);
            $this->fail('The team plan replaced Azul with Rosa.');
        } catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $this->assertDatabaseCount('event_team_plans', 0);
        $this->assertDatabaseCount('resource_reservations', 0);
    }

    public function test_each_person_sees_their_seat_in_their_staff_week(): void
    {
        $a = $this->party('2026-11-07 14:00', elsa: true);
        $need = TeamSlots::for($a); $ids = fn ($code) => Resource::where('code', $code)->value('id');
        $this->save($a, ['people' => [$need['people'][0]['key'] => $ids('CESAR'), $need['people'][1]['key'] => $ids('LUIS-PEREZ')],
            'costumes' => [$need['costumes'][0]['key'] => ['unit_id' => $ids('ELSA-1'), 'wearer_id' => $ids('ANA-RODRIGUEZ')]]]);
        $job = (new \ReflectionMethod(\App\Services\Staff\ReadStaffWeek::class, 'job'))->invoke(app(\App\Services\Staff\ReadStaffWeek::class), $a->fresh(), Resource::find($ids('ANA-RODRIGUEZ')), 'es', CarbonImmutable::now('America/Chicago'));
        $this->assertSame('Intérprete', $job['role']); $this->assertSame('Elsa', $job['character']);
        $this->assertEqualsCanonicalizing(['César', 'Luis'], array_column($job['team'], 'first_name'));
        $cesar = (new \ReflectionMethod(\App\Services\Staff\ReadStaffWeek::class, 'job'))->invoke(app(\App\Services\Staff\ReadStaffWeek::class), $a->fresh(), Resource::find($ids('CESAR')), 'es', CarbonImmutable::now('America/Chicago'));
        $this->assertSame('Animador/a', $cesar['role']); $this->assertTrue($cesar['is_driver']);
    }

    public function test_waoo_needs_someone_who_paints_in_any_seat_and_the_suggestion_finds_her(): void
    {
        // Owner 2026-11-04: «1 animador + 2 recreadoras», at least one of the three paints faces (any seat).
        $e = $this->party('2026-11-07 15:00');
        $svc = EventService::where('event_id', $e->id)->sole();
        $svc->forceFill(['requirements_snapshot' => [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'host', 'quantity' => 2],
            ['kind' => 'equipment', 'equipment_kind' => 'face_kit', 'quantity' => 1]]])->save();
        $this->assertSame(['face_painter'], TeamSlots::for($e->fresh())['team_skills']);
        // Nobody paints yet: the board says so after suggesting.
        $page = Livewire::test(\App\Filament\Pages\WeekendPlan::class)->call('suggest')->assertSee('Alguien que pinte caritas');
        // A recreadora who paints appears: the suggestion puts her in a recreadora seat.
        $painter = Resource::create(['code' => 'PINTA', 'name' => 'Sofía Herrera', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'face_painter']]);
        $team = app(SuggestWeekendTeams::class)->execute([$e->fresh()])[$e->id]['people'];
        $this->assertContains($painter->id, array_values($team));
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)->call('suggest')->assertDontSee('Alguien que pinte caritas');
    }

    public function test_a_complete_team_cannot_be_saved_without_the_required_team_skill(): void
    {
        $e = $this->party('2026-11-07 15:00');
        $e->services()->sole()->forceFill(['requirements_snapshot' => [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1],
            ['kind' => 'staff', 'skill' => 'host', 'quantity' => 2], ['kind' => 'equipment', 'equipment_kind' => 'face_kit', 'quantity' => 1]]])->save();
        $seats = array_column(TeamSlots::for($e)['people'], 'key');
        $team = ['people' => array_combine($seats, array_map(fn ($code) => Resource::where('code', $code)->value('id'), ['CESAR', 'NICOLE-HERNANDEZ', 'LUIS-PEREZ']))];
        try { $this->save($e, $team); $this->fail('A complete crew was saved without anybody who can paint.'); }
        catch (\App\Exceptions\RecordWriteRejected $x) { $this->assertSame(422, $x->status); }
        $this->assertDatabaseCount('event_team_plans', 0); $this->assertDatabaseCount('resource_reservations', 0);
        Resource::where('code', 'CESAR')->firstOrFail()->update(['skills' => ['entertainer', 'host', 'driver', 'face_painter']]);
        $plan = $this->save($e, $team);
        $this->assertCount(3, $plan->slots);
    }

    public function test_partial_team_planning_still_allows_saving_progress_without_claiming_it_complete(): void
    {
        $e = $this->party('2026-11-07 15:00');
        $service = $e->services()->sole();
        $service->forceFill(['requirements_snapshot' => [...$service->requirements_snapshot,
            ['kind' => 'equipment', 'equipment_kind' => 'face_kit', 'quantity' => 1]]])->save();
        $seat = TeamSlots::for($e)['people'][0]['key'];
        $this->save($e, ['people' => [$seat => Resource::where('code', 'CESAR')->value('id')]]);
        $this->assertDatabaseCount('event_team_plans', 1);
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('Alguien que pinte caritas');
    }
}
