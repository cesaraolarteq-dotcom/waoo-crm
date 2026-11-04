<?php
namespace Tests\Feature;

use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ResourceBlock, ResourceReservation, ServiceVariant, User};
use App\Services\Availability\WebsiteCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CapacityAllocationTest extends TestCase
{
    use DatabaseMigrations;
    private CarbonImmutable $start;
    private Character $character;
    private Resource $costume;
    private Resource $first;
    private Resource $second;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = CarbonImmutable::parse('2026-12-20T14:00:00-06:00')->utc();
        $this->character = Character::create(['name' => 'Minnie Mouse', 'active' => true, 'outfits' => ['Rosa', 'Azul']]);
        $this->character->forceFill(['costume_style' => 'head'])->save();
        $this->costume = Resource::create(['code' => 'MIN-1', 'name' => 'Minnie A', 'kind' => 'costume', 'character_id' => $this->character->id, 'outfit' => 'Rosa', 'status' => 'active']);
        $this->first = Resource::create(['code' => 'STAFF-1', 'name' => 'Sofía Méndez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'entertainer', 'driver']]);
        $this->second = Resource::create(['code' => 'STAFF-2', 'name' => 'Ana López', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $v = ServiceVariant::create(['name' => 'Fiesta Mágica', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => $this->recipe()]);
        $this->price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 40000, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    private function recipe(?string $outfit = null): array
    {
        return [['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1, ...($outfit !== null ? ['outfit' => $outfit] : [])],
            ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]];
    }

    private function capacity(): WebsiteCapacity
    {
        return new WebsiteCapacity($this->start->setTimezone('America/Chicago')->startOfDay(), []);
    }

    public function test_a_requested_outfit_cannot_use_the_only_unit_in_a_different_outfit(): void
    {
        $this->assertSame('unavailable', $this->capacity()->check($this->start, $this->start->addMinutes(90), $this->start->toDateString(), $this->recipe('Azul')));
    }

    public function test_an_existing_party_also_keeps_its_compatible_wearer(): void
    {
        DB::table('costume_compatibilities')->insert(['costume_id' => $this->costume->id, 'staff_id' => $this->first->id]);
        $customer = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $e = Event::create(['customer_id' => $customer->id, 'title' => 'Cumpleaños de Sofía', 'starts_at' => $this->start, 'ends_at' => $this->start->addHour(),
            'timezone' => 'America/Chicago', 'setup_minutes' => 0, 'breakdown_minutes' => 0]);
        $e->status = 'capacity_reserved'; $e->save();
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->price->service_variant_id, 'price_version_id' => $this->price->id,
            'requirements_snapshot' => $this->recipe(), 'duration_minutes' => 60, 'operational_ready' => true]);
        $this->assertSame('owner_approval_required', $this->capacity()->check($this->start, $this->start->addMinutes(90), $this->start->toDateString(),
            [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1]]), 'the same person cannot wear the existing costume and entertain the new party');
    }

    public function test_a_head_can_be_worn_by_someone_with_only_the_wig_skill(): void
    {
        $this->first->update(['skills' => ['performer_wig', 'driver']]);
        $this->second->update(['status' => 'inactive']);
        $this->assertSame('available', $this->capacity()->check($this->start, $this->start->addMinutes(90), $this->start->toDateString(), $this->recipe()));
    }

    public function test_a_recorded_exception_overrides_the_default_performer_label(): void
    {
        $this->character->forceFill(['costume_style' => 'wig'])->save();
        $this->first->update(['skills' => ['entertainer', 'driver']]);
        DB::table('costume_compatibilities')->insert(['costume_id' => $this->costume->id, 'staff_id' => $this->first->id]);
        $this->second->update(['status' => 'inactive']);
        $result = $this->allocate($this->capacity(), $this->recipe());
        $this->assertSame('available', $result['state']);
        $this->assertEqualsCanonicalizing([$this->costume->id, $this->first->id], array_values($result['assignment']));
    }

    public function test_a_wig_cannot_be_worn_by_a_generic_performer_without_an_exception(): void
    {
        $this->character->forceFill(['costume_style' => 'wig'])->save();
        $result = $this->allocate($this->capacity(), $this->recipe());
        $this->assertSame('owner_approval_required', $result['state']);
        $this->assertSame([], $result['assignment']);
    }

    public static function confirmedWearers(): array
    {
        return ['head accepts wig skill' => ['head', ['performer_wig', 'driver'], false, 'performer'],
            'recorded exception wins' => ['wig', ['entertainer', 'driver'], true, 'performer'],
            'wig role is the costume wearer' => ['wig', ['performer_wig', 'driver'], false, 'performer_wig']];
    }

    #[DataProvider('confirmedWearers')]
    public function test_owner_confirmation_uses_the_same_costume_wearer_authority(string $style, array $skills, bool $exception, string $role): void
    {
        $this->character->forceFill(['costume_style' => $style])->save();
        $this->first->update(['skills' => $skills]);
        if ($exception) DB::table('costume_compatibilities')->insert(['costume_id' => $this->costume->id, 'staff_id' => $this->first->id]);
        $recipe = $this->recipe('Rosa'); $recipe[1]['skill'] = $role;
        $customer = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $event = Event::create(['customer_id' => $customer->id, 'title' => 'Cumpleaños de Sofía', 'starts_at' => $this->start, 'ends_at' => $this->start->addHour(),
            'timezone' => 'America/Chicago', 'address' => '123 Example St', 'postal_code' => '77001', 'travel_fee_cents' => 0, 'setup_minutes' => 0, 'breakdown_minutes' => 0]);
        EventService::create(['event_id' => $event->id, 'service_variant_id' => $this->price->service_variant_id, 'price_version_id' => $this->price->id,
            'requirements_snapshot' => $recipe, 'duration_minutes' => 60, 'operational_ready' => true]);
        $saved = app(\App\Services\Records\ConfirmCapacity::class)->execute(User::sole(), $event->id, ['expected_version' => $event->fresh()->version,
            'resource_ids' => [$this->costume->id, $this->first->id], 'payment' => ['method' => 'external_zelle', 'reference' => 'FIXTURE-PAID-'.$event->id,
                'amount_cents' => 20000, 'received_at' => now()->subMinute()->toIso8601String()], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'transfers' => []]]);
        $this->assertSame('capacity_reserved', $saved->status);
        $this->assertEqualsCanonicalizing([$this->costume->id, $this->first->id], $saved->reservations->pluck('resource_id')->all());
    }

    public function test_private_allocation_is_one_complete_distinct_team_including_equipment_and_vehicle(): void
    {
        $speaker = Resource::create(['code' => 'SP-1', 'name' => 'Bocina A', 'kind' => 'equipment', 'equipment_kind' => 'speaker', 'status' => 'active']);
        $car = Resource::create(['code' => 'CAR-1', 'name' => 'Vehículo A', 'kind' => 'vehicle', 'seats' => 2, 'status' => 'active']);
        $requirements = [...$this->recipe('Rosa'), ['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1],
            ['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1], ['kind' => 'vehicle', 'seats' => 2, 'quantity' => 1]];
        $result = $this->allocate($this->capacity(), $requirements);
        $this->assertSame('available', $result['state']);
        $this->assertEqualsCanonicalizing([$this->costume->id, $this->first->id, $this->second->id, $speaker->id, $car->id], array_values($result['assignment']));
        $this->assertSame(count($result['assignment']), count(array_unique($result['assignment'])));
        $this->assertDatabaseCount('resource_reservations', 0);
    }

    public function test_a_candidate_pin_is_preserved_in_the_final_solution(): void
    {
        $result = $this->allocate($this->capacity(), $this->recipe(), [$this->second->id]);
        $this->assertSame('available', $result['state']);
        $this->assertContains($this->second->id, $result['assignment']);
        $this->assertNotContains($this->first->id, $result['assignment']);
    }

    public function test_a_blocked_pin_is_not_silently_replaced_by_a_free_person(): void
    {
        ResourceBlock::create(['resource_id' => $this->second->id, 'starts_at' => $this->start->subHour(), 'ends_at' => $this->start->addHours(2), 'reason' => 'No disponible']);
        $capacity = $this->capacity();
        $refused = $this->allocate($capacity, $this->recipe(), [$this->second->id]);
        $this->assertSame('owner_approval_required', $refused['state']);
        $this->assertSame([], $refused['assignment']);
        $available = $this->allocate($capacity, $this->recipe());
        $this->assertSame('available', $available['state'], 'One call must not leak candidate pins into another.');
        $this->assertContains($this->first->id, $available['assignment']);
    }

    public function test_missing_calendar_and_invalid_pins_never_return_a_witness(): void
    {
        $result = $this->allocate(new WebsiteCapacity($this->start->startOfDay(), null), $this->recipe());
        $this->assertSame(['state' => 'review_required', 'assignment' => []], $result);
        $this->assertSame(['state' => 'review_required', 'assignment' => []], $this->allocate($this->capacity(), []));
        foreach ([[$this->first->id, $this->first->id], [(string) $this->first->id], [999999], ['staff' => $this->first->id], [[]]] as $pins) {
            $this->assertSame(['state' => 'review_required', 'assignment' => []], $this->allocate($this->capacity(), $this->recipe(), $pins));
        }
    }

    public function test_a_failed_second_check_does_not_reuse_the_previous_witness(): void
    {
        $capacity = $this->capacity();
        $this->assertSame('available', $this->allocate($capacity, $this->recipe())['state']);
        $result = $this->allocate($capacity, $this->recipe('Azul'));
        $this->assertSame(['state' => 'unavailable', 'assignment' => []], $result);
    }

    public function test_the_final_witness_respects_the_real_route_and_extra_thirty_minutes(): void
    {
        $customer = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $e = Event::create(['customer_id' => $customer->id, 'title' => 'Fiesta anterior', 'starts_at' => $this->start->subHours(2), 'ends_at' => $this->start->subHour(),
            'timezone' => 'America/Chicago', 'setup_minutes' => 0, 'breakdown_minutes' => 0, 'address' => '10 Example St', 'postal_code' => '77001']);
        $e->status = 'capacity_reserved'; $e->save();
        EventService::create(['event_id' => $e->id, 'service_variant_id' => $this->price->service_variant_id, 'price_version_id' => $this->price->id,
            'requirements_snapshot' => [['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]], 'duration_minutes' => 60, 'operational_ready' => true]);
        ResourceReservation::create(['event_id' => $e->id, 'resource_id' => $this->first->id, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
        $requirements = [['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]];
        foreach ([30 => true, 31 => false] as $drive => $canShare) {
            $capacity = $this->capacity()->withTravel('77002', fn () => $drive, '20 Example St');
            $result = $this->allocate($capacity, $requirements, [$this->first->id]);
            $this->assertSame($canShare ? 'available' : 'owner_approval_required', $result['state']);
            $this->assertSame($canShare, in_array($this->first->id, $result['assignment'], true));
        }
        $separateTeam = $this->allocate($this->capacity(), $requirements);
        $this->assertSame('available', $separateTeam['state']);
        $this->assertContains($this->second->id, $separateTeam['assignment'], 'Without a proven route the final allocation must use an independent team.');
        $this->assertNotContains($this->first->id, $separateTeam['assignment']);
    }

    private function allocate(WebsiteCapacity $capacity, array $requirements, array $pins = []): array
    {
        return $capacity->allocation($this->start, $this->start->addMinutes(90), $this->start->toDateString(), $requirements, $pins);
    }
}
