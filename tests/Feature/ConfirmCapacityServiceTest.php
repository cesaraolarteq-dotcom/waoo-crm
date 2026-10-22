<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use App\Services\Records\ConfirmCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmCapacityServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_explicit_actor_owns_evidence_review_and_audit_even_with_another_session_user(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => 'staff']));
        $customer = Customer::create(['name' => 'Fixture client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture service', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1]]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $owner->id]);
        $event = Event::create(['customer_id' => $customer->id, 'title' => 'Fixture event', 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour(), 'timezone' => 'America/Chicago', 'address' => 'Fixture venue', 'travel_fee_cents' => 0, 'setup_minutes' => 10, 'breakdown_minutes' => 10])->fresh();
        EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        $speaker = Resource::create(['code' => 'FIXTURE', 'name' => 'Fixture speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
        $input = ['expected_version' => 1, 'resource_ids' => [$speaker->id], 'payment' => ['method' => 'external_zelle', 'reference' => 'FIXTURE-EXPLICIT-ACTOR', 'amount_cents' => 5000, 'received_at' => now()->subHour()->toIso8601String()],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return checked.', 'transfers' => []]];
        $saved = app(ConfirmCapacity::class)->execute($owner, $event->id, $input);
        $this->assertSame('capacity_reserved', $saved->status);
        $this->assertDatabaseHas('booking_evidence', ['event_id' => $event->id, 'verified_by' => $owner->id]);
        $this->assertDatabaseHas('operational_reviews', ['event_id' => $event->id, 'reviewed_by' => $owner->id]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'booking.capacity_reserved', 'actor_id' => $owner->id]);
        $this->assertCount(1, $saved->reservations);
    }
    public function test_direct_service_validates_input_without_http_controller(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        try { app(ConfirmCapacity::class)->execute($owner, 123456, []); $this->fail('Invalid direct call was accepted.'); }
        catch (ValidationException $e) {
            $this->assertArrayHasKey('payment.reference', $e->errors());
            $this->assertArrayHasKey('review.origin_return_note', $e->errors());
        }
        $this->assertDatabaseCount('booking_evidence', 0);
    }
}
