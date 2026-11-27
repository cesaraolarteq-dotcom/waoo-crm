<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, EventService, User};
use App\Services\Records\{AddPriceVersion, SaveEventDraft, SaveVariant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** Owner 2026-11-25: a custom quote can sell a service for less time than the catalog (Photo Booth 2 h instead of 3). */
class CustomDurationTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner; private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $variant = app(SaveVariant::class)->execute($this->owner, ['name' => 'Fixture Photo Booth', 'status' => 'active', 'duration_minutes' => 180, 'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'photo_booth', 'quantity' => 1]]]);
        $price = app(AddPriceVersion::class)->execute($this->owner, $variant->id, ['amount_cents' => 35000, 'currency' => 'USD']);
        $customer = Customer::create(['name' => 'Sofía Ramírez', 'email' => 'sofia.ramirez@example.test', 'locale' => 'es']);
        $start = now('America/Chicago')->addDays(20)->setTime(12, 0)->utc();
        $this->input = ['customer_id' => $customer->id, 'title' => 'Custom quote party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHours(2)->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => 'Fixture venue', 'setup_minutes' => 10, 'breakdown_minutes' => 10, 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $price->id, 'duration_minutes' => 120]]];
    }

    public function test_a_shorter_special_duration_is_kept_for_that_party_only(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        $this->assertSame(120, $event->services->sole()->duration_minutes);
        $this->assertSame(120, EventService::partyMinutes($event->services));

        $normal = $this->input; unset($normal['services'][0]['duration_minutes']);
        $this->assertSame(180, app(SaveEventDraft::class)->execute($this->owner, $normal)->services->sole()->duration_minutes);
    }

    public function test_a_special_duration_can_never_be_longer_than_the_catalog(): void
    {
        $longer = $this->input; $longer['services'][0]['duration_minutes'] = 240;
        $this->expectException(RecordWriteRejected::class);
        app(SaveEventDraft::class)->execute($this->owner, $longer);
    }
}
