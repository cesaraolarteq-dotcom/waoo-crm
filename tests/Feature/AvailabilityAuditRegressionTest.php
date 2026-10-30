<?php
namespace Tests\Feature;

use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Availability\CheckWebsiteCandidates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Audit regressions: correct outcomes evaluated against PR159 head08e638d; failures document outstanding defects. */
class AvailabilityAuditRegressionTest extends TestCase
{
    use DatabaseMigrations;
    private Character $character;
    private ServiceVariant $variant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01T09:00:00-06:00'));
        $this->app->instance('waoo.availability.busy', fn () => []);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $this->character = Character::create(['name' => 'Audit Character', 'active' => true]);
        $this->variant = ServiceVariant::create(['name' => 'Audit character service', 'status' => 'active',
            'duration_minutes' => 60, 'setup_minutes' => 15, 'breakdown_minutes' => 15, 'character_quantity' => 1,
            'requirements' => [], 'character_options' => [['character_id' => $this->character->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1],
                ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        for ($i = 1; $i <= 6; $i++) {
            Resource::create(['code' => 'AUDIT-STAFF-'.$i, 'name' => 'Synthetic staff '.$i, 'kind' => 'staff',
                'status' => 'active', 'skills' => ['performer', 'driver']]);
        }
        for ($i = 1; $i <= 5; $i++) {
            Resource::create(['code' => 'AUDIT-COSTUME-'.$i, 'name' => 'Synthetic costume '.$i, 'kind' => 'costume',
                'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        }
    }

    private function evaluate(ServiceVariant $variant, array $characters): array
    {
        return app(CheckWebsiteCandidates::class)->execute(['date' => '2026-11-21', 'timezone' => 'America/Chicago',
            'service_variant_id' => $variant->id, 'characters' => $characters,
            'candidate_starts' => [['local' => '2026-11-21T14:00', 'offset_choice' => null]]]);
    }

    public function test_five_characters_require_owner_approval_even_with_enough_inventory(): void
    {
        $result = $this->evaluate($this->variant, [['character_id' => $this->character->id, 'quantity' => 5, 'outfit' => null]]);
        $this->assertSame('owner_approval_required', $result['slots'][0]['state'] ?? null,
            'The documented owner rule requires review for five or more characters even when pools suffice.');
    }

    public function test_required_vehicle_cannot_be_ignored_when_no_vehicle_exists(): void
    {
        $variant = ServiceVariant::create(['name' => 'Audit vehicle service', 'status' => 'active',
            'duration_minutes' => 60, 'setup_minutes' => 15, 'breakdown_minutes' => 15,
            'requirements' => [['kind' => 'vehicle', 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $result = $this->evaluate($variant, []);
        $this->assertSame('unavailable', $result['unresolved'][0]['code'] ?? null,
            'No physical vehicle exists for an explicitly required vehicle slot.');
    }

    public function test_unverified_costume_wearer_compatibility_cannot_be_instantly_available(): void
    {
        $this->assertSame(0, DB::table('costume_compatibilities')->count());
        $result = $this->evaluate($this->variant, [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => null]]);
        $this->assertNotContains('available', array_column($result['slots'], 'state'),
            'Generic performer skills do not prove anyone can wear the selected physical costume.');
    }

    public function test_a_fixed_staff_assignment_is_not_reassigned_to_free_a_specialist(): void
    {
        DB::table('resources')->where('kind', 'staff')->delete();
        $specialist = Resource::create(['code' => 'AUDIT-FIXED-A', 'name' => 'Synthetic A', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'performer']]);
        Resource::create(['code' => 'AUDIT-FIXED-B', 'name' => 'Synthetic B', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host']]);
        foreach (['C', 'D'] as $suffix) Resource::create(['code' => 'AUDIT-DRIVER-'.$suffix, 'name' => 'Synthetic '.$suffix, 'kind' => 'staff', 'status' => 'active', 'skills' => ['driver']]);
        $variant = ServiceVariant::create(['name' => 'Audit performer service', 'status' => 'active',
            'duration_minutes' => 60, 'setup_minutes' => 15, 'breakdown_minutes' => 15,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $customer = Customer::create(['name' => 'Synthetic fixed-assignment customer', 'locale' => 'es']);
        $start = CarbonImmutable::parse('2026-11-21T14:00:00-06:00')->utc();
        $event = Event::create(['customer_id' => $customer->id, 'title' => 'Synthetic committed party', 'starts_at' => $start,
            'ends_at' => $start->addHour(), 'timezone' => 'America/Chicago', 'setup_minutes' => 15, 'breakdown_minutes' => 15]);
        $event->status = 'capacity_reserved'; $event->save();
        EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id,
            'operational_ready' => true, 'duration_minutes' => 60, 'requirements_snapshot' => [['kind' => 'staff', 'skill' => 'host', 'quantity' => 1]]]);
        DB::table('resource_reservations')->insert(['event_id' => $event->id, 'resource_id' => $specialist->id,
            'starts_at' => $start, 'ends_at' => $start->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->evaluate($variant, []);
        $this->assertNotContains('available', array_column($result['slots'], 'state'),
            'A is already assigned to the first party; hypothetical reassignment to B must not free A for the candidate.');
    }

    public function test_head_and_wig_style_decide_who_can_wear_without_a_list_and_a_recorded_list_wins(): void
    {
        // César 2026-10-30: head → anyone with Personaje; wig → only Personaje con peluca; a recorded list (sizes) wins.
        $one = [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => null]];
        $this->character->forceFill(['costume_style' => 'head'])->save();
        $this->assertSame('available', $this->evaluate($this->variant, $one)['slots'][0]['state']);
        $this->character->forceFill(['costume_style' => 'wig'])->save();
        $this->assertSame('owner_approval_required', $this->evaluate($this->variant, $one)['slots'][0]['state'], 'nobody has Personaje con peluca');
        Resource::where('code', 'AUDIT-STAFF-1')->update(['skills' => json_encode(['performer', 'performer_wig', 'driver'])]);
        $this->assertSame('available', $this->evaluate($this->variant, $one)['slots'][0]['state']);
        // A recorded list for every unit names only staff 2, who cannot play wig characters: the list wins.
        foreach (Resource::where('kind', 'costume')->pluck('id') as $cid)
            DB::table('costume_compatibilities')->insert(['staff_id' => Resource::where('code', 'AUDIT-STAFF-2')->value('id'), 'costume_id' => $cid]);
        $this->assertSame('available', $this->evaluate($this->variant, $one)['slots'][0]['state'], 'recorded list allows staff 2');
        $this->character->forceFill(['costume_style' => null])->save();
        $this->assertSame('available', $this->evaluate($this->variant, $one)['slots'][0]['state'], 'a recorded list needs no style');
        DB::table('costume_compatibilities')->delete();
        $this->assertSame('owner_approval_required', $this->evaluate($this->variant, $one)['slots'][0]['state'], 'no list and no style: owner');
    }

    public function test_character_styles_command_marks_head_and_wig(): void
    {
        $elsa = Character::create(['name' => 'Elsa', 'active' => true]);
        $file = tempnam(sys_get_temp_dir(), 'styles');
        file_put_contents($file, json_encode(['Elsa' => 'peluca', 'Audit Character' => 'cabeza']));
        $this->artisan('waoo:character-styles', ['file' => $file])->assertSuccessful();
        $this->assertSame(['wig', 'head'], [$elsa->fresh()->costume_style, $this->character->fresh()->costume_style]);
        file_put_contents($file, json_encode(['Nadie' => 'peluca']));
        $this->artisan('waoo:character-styles', ['file' => $file])->assertFailed();
    }
}
