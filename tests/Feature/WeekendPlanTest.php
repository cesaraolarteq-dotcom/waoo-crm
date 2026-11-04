<?php
namespace Tests\Feature;

use App\Models\{BookingEvidence, Character, Customer, Event, EventService, EventTeamPlan, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Team\{AssignEventTeam, SuggestWeekendTeams, TeamSlots};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-02: plan the weekend's teams at once; seats come from the package; someone on the team drives. */
class WeekendPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $owner; private ServiceVariant $waoo; private PriceVersion $price; private Character $elsa; private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00', 'America/Chicago')); // Tuesday: planning day
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
}
