<?php
namespace Tests\Feature;

use App\Filament\Pages\StaffPay;
use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, StaffPayLine, User};
use App\Services\Team\{AssignEventTeam, StaffPaySuggestion, TeamSlots};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-03: suggested pay with the reason, editable up or down, marked paid. */
class StaffPayTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Disposable synthetic fixtures only; preserve the production rollback guard.
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('event_team_plans')) \Illuminate\Support\Facades\DB::table('event_team_plans')->delete();
        parent::tearDown();
    }


    public function test_suggestion_explains_itself_and_the_owner_can_change_and_pay(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Chicago'));
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        $v = ServiceVariant::create(['name' => 'Waoo', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => []]);
        $price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 80000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $sofia = Resource::create(['code' => 'SOFIA', 'name' => 'Sofía Méndez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
        $c = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $s = CarbonImmutable::parse('2026-11-07 14:00', 'America/Chicago')->utc();
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Waoo · María González', 'starts_at' => $s, 'ends_at' => $s->addHours(2), 'timezone' => 'America/Chicago',
            'address' => '123 Oak Street', 'setup_minutes' => 30, 'breakdown_minutes' => 30, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $v->id, 'price_version_id' => $price->id, 'duration_minutes' => 120, 'operational_ready' => true,
            'requirements_snapshot' => [['kind' => 'staff', 'skill' => 'host', 'quantity' => 1]]]);
        $e->status = 'capacity_reserved'; $e->save();
        app(AssignEventTeam::class)->execute($owner, $e->id, ['expected_plan_version' => 0, 'people' => [TeamSlots::for($e)['people'][0]['key'] => $sofia->id], 'costumes' => [], 'driver' => null], (string) Str::uuid());

        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'America/Chicago')); // the Monday after
        $page = Livewire::test(StaffPay::class)->assertSee('Sofía Méndez')->assertSee('falta la tarifa de este puesto')->assertSee('Falta tarifa');
        $page->set('rates.host.amount', '15')->set('rates.host.per', 'hour')->set('rates.driving.amount', '20')->set('rates.driving.per', 'party')->call('saveRates')->assertNotified('Tarifas guardadas');
        $week = app(StaffPaySuggestion::class)->week(CarbonImmutable::parse('2026-11-02', 'America/Chicago'));
        $this->assertSame(4500 + 2000, $week[0]["total_cents"]); // 3 h × $15 + driving $20
        $this->assertStringContainsString('3 h (30 min montaje + 2 h fiesta + 30 min desmontaje) × $15/h = $45', $week[0]['lines'][0]['why'][0]);
        Livewire::test(StaffPay::class)->call('edit', $e->id, $sofia->id, 6500, null)->set("edits.{$e->id}-{$sofia->id}.amount", '70')->set("edits.{$e->id}-{$sofia->id}.note", 'Se quedó más tiempo')
            ->call('saveLine', $e->id, $sofia->id, 6500)->assertNotified('Monto guardado')->assertSee('Cambiado por ti')->assertSee('sugerido $65')
            ->call('markPaid', $sofia->id)->assertNotified('Marcado como pagado')->assertSee('Pagado');
        $this->assertSame(7000, StaffPayLine::sole()->amount_cents);
        $this->assertNotNull(StaffPayLine::sole()->paid_at);
    }
}
