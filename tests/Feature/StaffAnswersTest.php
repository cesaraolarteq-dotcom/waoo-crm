<?php
namespace Tests\Feature;

use App\Models\{BookingEvidence, Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, StaffAvailability, StaffJobAnswer, User};
use App\Services\Staff\{IssueStaffGrant, SaveStaffIdentity, StaffContext};
use App\Services\Team\{AssignEventTeam, SuggestWeekendTeams, TeamSlots};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Http, Mail};
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-03: staff confirm their party and say on Monday which days they can work (Saturday first); the plan uses it. */
class StaffAnswersTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        // Disposable synthetic fixtures only; preserve the production rollback guard.
        if ($this->app && \Illuminate\Support\Facades\Schema::hasTable('event_team_plans')) \Illuminate\Support\Facades\DB::table('event_team_plans')->delete();
        parent::tearDown();
    }


    private User $owner; private ServiceVariant $waoo; private PriceVersion $price; private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'America/Chicago')); // Monday: the day staff answer
        config(['waoo.sandbox_staff' => true, 'app.url' => 'https://localhost']);
        Http::preventStrayRequests(); Mail::fake(); $this->withCredentials();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->waoo = ServiceVariant::create(['name' => 'Waoo', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => []]);
        $this->price = PriceVersion::create(['service_variant_id' => $this->waoo->id, 'amount_cents' => 80000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        foreach ([['María González', ['entertainer', 'driver']], ['Sofía Méndez', ['entertainer']], ['Luis Pérez', ['host']]] as [$name, $skills])
            Resource::create(['code' => Str::upper(Str::slug($name)), 'name' => $name, 'kind' => 'staff', 'status' => 'active', 'skills' => $skills]);
    }

    private function party(string $local): Event
    {
        $c = Customer::create(['name' => 'Ana Rodríguez '.(++$this->n), 'locale' => 'es']);
        $s = CarbonImmutable::parse($local, 'America/Chicago')->utc();
        $e = Event::create(['customer_id' => $c->id, 'title' => 'Cumpleaños '.$this->n, 'starts_at' => $s, 'ends_at' => $s->addHours(2), 'timezone' => 'America/Chicago',
            'address' => '123 Oak Street', 'setup_minutes' => 30, 'breakdown_minutes' => 30, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->waoo->id, 'price_version_id' => $this->price->id, 'duration_minutes' => 120, 'operational_ready' => true,
            'requirements_snapshot' => [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'host', 'quantity' => 1]]]);
        $e->status = 'capacity_reserved'; $e->save();
        BookingEvidence::create(['event_id' => $e->id, 'method' => 'external_zelle', 'reference' => 'Z-'.$e->id, 'amount_cents' => 40000, 'received_at' => now(), 'verified_by' => $this->owner->id,
            'staff_assignment_snapshot' => ['staff_mode' => 'later', 'assignments' => []]]);
        return $e->fresh();
    }

    private function id(string $code): int { return Resource::where('code', $code)->value('id'); }

    private function login(int $resourceId): string
    {
        $identity = app(SaveStaffIdentity::class)->execute($this->owner, ['resource_id' => $resourceId, 'expected_resource_version' => Resource::find($resourceId)->version, 'locale' => 'es', 'active' => true, 'phone' => '+13465550100'], (string) Str::uuid());
        $issued = app(IssueStaffGrant::class)->execute($this->owner, $identity['staff_id'], ['expected_version' => $identity['version']], (string) Str::uuid());
        auth()->logout();
        return $this->postJson('/staff/session', ['token' => parse_url($issued['link'], PHP_URL_FRAGMENT)])->assertOk()->getCookie(StaffContext::COOKIE)->getValue();
    }

    public function test_monday_availability_puts_saturday_first_and_sugerir_skips_who_said_no(): void
    {
        $sat = $this->party('2026-11-07 14:00');
        $cookie = $this->login($this->id('MARIA-GONZALEZ'));
        $this->withCookie(StaffContext::COOKIE, $cookie)->get('/staff')->assertOk()->assertSeeInOrder(['¿Qué días puedes trabajar?', 'Sábado 7 de noviembre', '1 fiesta', 'El sábado es el día con más fiestas', 'Viernes 6 de noviembre']);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        // Saturday is required.
        $this->withCookie(StaffContext::COOKIE, $cookie)->post('/staff/disponibilidad', ['week' => '2026-11-02', 'days' => ['fri' => 'yes']])->assertSessionHasErrors('sat');
        $this->withCookie(StaffContext::COOKIE, $cookie)->post('/staff/disponibilidad', ['week' => '2026-11-02', 'days' => ['sat' => 'no', 'fri' => 'yes', 'sun' => 'yes']])->assertRedirect('/staff');
        $this->assertSame('no', StaffAvailability::for($this->id('MARIA-GONZALEZ'), $sat->starts_at));
        $s = app(SuggestWeekendTeams::class)->execute([$sat]);
        $this->assertSame($this->id('SOFIA-MENDEZ'), array_values($s[$sat->id]['people'])[0], 'María said she cannot work Saturday.');
        $this->actingAs($this->owner);
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('María González · dijo que no')->assertSee('0</b> pueden', false);
    }

    public function test_staff_confirm_or_decline_and_the_owner_replaces(): void
    {
        $sat = $this->party('2026-11-07 14:00');
        $need = TeamSlots::for($sat);
        app(AssignEventTeam::class)->execute($this->owner, $sat->id, ['expected_plan_version' => 0, 'people' => [$need['people'][0]['key'] => $this->id('MARIA-GONZALEZ'), $need['people'][1]['key'] => $this->id('LUIS-PEREZ')], 'costumes' => [], 'driver' => null], (string) Str::uuid());
        $cookie = $this->login($this->id('MARIA-GONZALEZ'));
        $this->withCookie(StaffContext::COOKIE, $cookie)->get('/staff')->assertOk()->assertSee('¿Confirmas esta fiesta?')->assertSee('Confirmo');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withCookie(StaffContext::COOKIE, $cookie)->post('/staff/respuesta', ['event_id' => $sat->id, 'answer' => 'no', 'note' => 'Tengo examen'])->assertRedirect('/staff');
        $this->withCookie(StaffContext::COOKIE, $cookie)->get('/staff')->assertSee('Dijiste que no puedes');
        // Someone not on the team cannot answer for that party.
        $other = $this->login($this->id('SOFIA-MENDEZ'));
        $this->withCookie(StaffContext::COOKIE, $other)->post('/staff/respuesta', ['event_id' => $sat->id, 'answer' => 'yes'])->assertNotFound();

        $this->actingAs($this->owner);
        $this->get('/admin')->assertSee('Staff que dijo «No puedo»');
        $page = Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('No puede: Tengo examen')->assertSee('Reemplazar')
            ->call('replace', $sat->id, $need['people'][0]['key'])->assertNotified('Propuesto: Sofía Méndez');
        $page->call('saveAll')->assertNotified('Equipo guardado');
        $this->assertSame($this->id('SOFIA-MENDEZ'), collect(\App\Models\EventTeamPlan::where('event_id', $sat->id)->first()->slots)->firstWhere('key', $need['people'][0]['key'])['resource_id']);
        // A moved party asks again: the old answer no longer counts.
        $sat->starts_at = $sat->starts_at->addHour(); $sat->ends_at = $sat->ends_at->addHour(); $sat->save();
        $this->assertNull(StaffJobAnswer::current($sat->fresh(), $this->id('MARIA-GONZALEZ')));
    }

    public function test_weekday_parties_show_in_the_plan(): void
    {
        $this->party('2026-11-04 17:00'); // Wednesday
        Livewire::test(\App\Filament\Pages\WeekendPlan::class)->assertSee('Cumpleaños 1')->assertSee('2 nov.');
    }
}
