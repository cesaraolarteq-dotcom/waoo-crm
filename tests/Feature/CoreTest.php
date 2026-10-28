<?php

namespace Tests\Feature;

use App\Models\{User, Customer, Resource, Event, ServiceVariant, PriceVersion, EventService, ResourceReservation};
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class CoreTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
    }
    private function resource(string $kind = 'equipment', array $extra = []): Resource
    {
        return Resource::create([...['code' => fake()->unique()->bothify('R-####??'), 'name' => 'Fictitious resource', 'kind' => $kind, 'status' => 'active', 'skills' => [], 'equipment_kind' => $kind === 'equipment' ? 'speaker' : null], ...$extra]);
    }
    private function event(?CarbonImmutable $start = null, array $requirements = [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 1]]): Event
    {
        $start ??= CarbonImmutable::now()->addDays(20)->startOfHour();
        $customer = Customer::create(['name' => 'Fictitious client', 'email' => 'client@example.test', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious service', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => $requirements]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 20000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $event = Event::create(['customer_id' => $customer->id, 'title' => 'Fictitious event', 'starts_at' => $start, 'ends_at' => $start->addHour(), 'timezone' => 'America/Chicago', 'address' => 'Test venue ' . $customer->id, 'setup_minutes' => 10, 'breakdown_minutes' => 10, 'travel_fee_cents' => 0]);
        EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
        return $event->fresh();
    }
    private function payload(Event $event, array $ids): array
    {
        return ['expected_version' => $event->version, 'resource_ids' => $ids, 'payment' => ['method' => 'external_zelle', 'reference' => 'fixture-' . $event->id, 'amount_cents' => 10000, 'received_at' => now()->subHour()->toIso8601String()], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fictitious origin, loading, passenger and return checks completed.', 'transfers' => []]];
    }
    private function confirm(Event $event, array $payload)
    {
        return $this->postJson('/admin/api/events/' . $event->id . '/confirm-capacity', $payload);
    }
    public function test_drafts_do_not_reserve_and_one_speaker_cannot_cover_overlapping_events(): void
    {
        $resource = $this->resource();
        $a = $this->event();
        $b = $this->event($a->starts_at);
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->confirm($a, $this->payload($a, [$resource->id]))->assertOk()->assertJsonPath('status', 'capacity_reserved');
        $this->confirm($b, $this->payload($b, [$resource->id]))->assertUnprocessable();
        $this->assertDatabaseCount('resource_reservations', 1);
        $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertSame('draft', $b->fresh()->status);
    }
    public function test_two_independent_units_can_cover_simultaneous_events(): void
    {
        $a = $this->event();
        $b = $this->event($a->starts_at);
        $this->confirm($a, $this->payload($a, [$this->resource()->id]))->assertOk();
        $this->confirm($b, $this->payload($b, [$this->resource()->id]))->assertOk();
        $this->assertDatabaseCount('resource_reservations', 2);
    }
    public function test_repeating_confirmation_is_idempotent_and_history_is_recorded(): void
    {
        $e = $this->event();
        $p = $this->payload($e, [$this->resource()->id]);
        $this->confirm($e, $p)->assertOk();
        $this->confirm($e, $p)->assertOk();
        $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertDatabaseCount('operational_reviews', 1);
        $this->assertDatabaseHas('audit_entries', ['action' => 'booking.capacity_reserved', 'actor_id' => $this->owner->id]);
        $p['payment']['amount_cents']++;
        $this->confirm($e, $p)->assertConflict();
    }
    public function test_payment_reference_cannot_be_reused_for_another_event(): void
    {
        $a = $this->event();
        $b = $this->event();
        $p = $this->payload($a, [$this->resource()->id]);
        $this->confirm($a, $p)->assertOk();
        $q = $this->payload($b, [$this->resource()->id]);
        $q['payment']['reference'] = $p['payment']['reference'];
        $this->confirm($b, $q)->assertUnprocessable();
        $this->assertDatabaseCount('booking_evidence', 1);
    }
    public function test_one_multiskilled_person_does_not_satisfy_two_simultaneous_roles(): void
    {
        $e = $this->event(null, [['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'face_painter', 'quantity' => 1]]);
        $person = $this->resource('staff', ['skills' => ['entertainer', 'face_painter', 'driver']]);
        $this->confirm($e, $this->payload($e, [$person->id]))->assertUnprocessable();
        $second = $this->resource('staff', ['skills' => ['face_painter']]);
        $this->confirm($e, $this->payload($e, [$person->id, $second->id]))->assertOk();
    }
    public function test_costume_requires_a_compatible_performer(): void
    {
        $char = \App\Models\Character::create(['name' => 'Fictitious character', 'active' => true]);
        $e = $this->event(null, [['kind' => 'costume', 'character_id' => $char->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]]);
        $costume = $this->resource('costume', ['character_id' => $char->id]);
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $p = $this->payload($e, [$costume->id, $staff->id]);
        $this->confirm($e, $p)->assertUnprocessable();
        $this->postJson('/admin/api/costume-compatibilities', ['staff_id' => $staff->id, 'costume_id' => $costume->id])->assertOk();
        $this->confirm($e, $p)->assertOk();
    }
    public function test_maintenance_and_calendar_blocks_prevent_booking(): void
    {
        $e = $this->event();
        $r = $this->resource('equipment', ['status' => 'maintenance']);
        $p = $this->payload($e, [$r->id]);
        $this->confirm($e, $p)->assertUnprocessable();
        $r->status = 'active';
        $r->save();
        $this->postJson('/admin/api/resources/' . $r->id . '/blocks', ['starts_at' => $e->starts_at->subHour()->toIso8601String(), 'ends_at' => $e->ends_at->addHour()->toIso8601String(), 'reason' => 'Maintenance test'])->assertCreated();
        $this->confirm($e, $p)->assertUnprocessable();
    }
    public function test_a_resource_with_future_commitments_cannot_be_disabled_silently(): void
    {
        $e = $this->event();
        $r = $this->resource();
        $this->confirm($e, $this->payload($e, [$r->id]))->assertOk();
        $this->putJson('/admin/api/resources/' . $r->id, [...$r->only(['code', 'name', 'kind', 'status', 'skills', 'equipment_kind']), 'status' => 'maintenance', 'expected_version' => 1])->assertConflict();
        $this->assertSame('active', $r->fresh()->status);
    }
    public function test_short_notice_requires_explicit_admin_reason(): void
    {
        $e = $this->event(CarbonImmutable::now()->addDays(2));
        $p = $this->payload($e, [$this->resource()->id]);
        $this->confirm($e, $p)->assertUnprocessable();
        $p['review']['short_notice_reason'] = 'Staff and preparation checked by owner.';
        $this->confirm($e, $p)->assertOk();
    }
    public static function houstonNoticeBoundaries(): array
    {
        return [
            'nine days owner confirmation needs no reason' => ['2026-10-26T12:00:00-05:00', '2026-11-04T12:00:00-06:00', false],
            'fall before' => ['2026-10-26T12:00:00-05:00', '2026-11-02T11:59:59-06:00', true],
            'fall equal' => ['2026-10-26T12:00:00-05:00', '2026-11-02T12:00:00-06:00', false],
            'fall after' => ['2026-10-26T12:00:00-05:00', '2026-11-02T12:00:01-06:00', false],
            'spring before' => ['2027-03-08T12:00:00-06:00', '2027-03-15T11:59:59-05:00', true],
            'spring equal' => ['2027-03-08T12:00:00-06:00', '2027-03-15T12:00:00-05:00', false],
            'spring after' => ['2027-03-08T12:00:00-06:00', '2027-03-15T12:00:01-05:00', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('houstonNoticeBoundaries')]
    public function test_http_owner_confirmation_uses_seven_houston_calendar_days_and_audits_owner_exception(string $clock, string $start, bool $needsApproval): void
    {
        // The server/event storage is UTC; the owner's notice policy uses Houston's local clock.
        config(['app.timezone' => 'UTC']);
        $this->travelTo(CarbonImmutable::parse($clock));
        $event = $this->event(CarbonImmutable::parse($start)->utc());
        $payload = $this->payload($event, [$this->resource()->id]);
        if ($needsApproval) {
            $this->confirm($event, $payload)->assertUnprocessable()->assertJsonValidationErrors('booking');
            $this->assertSame('draft', $event->fresh()->status);
            foreach (['resource_reservations', 'booking_evidence', 'operational_reviews', 'audit_entries'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $payload['review']['short_notice_reason'] = 'Owner explicitly checked crew and preparation for this fictional party.';
        }
        $this->confirm($event, $payload)->assertOk()->assertJsonPath('status', 'capacity_reserved');
        $this->assertDatabaseHas('booking_evidence', ['event_id' => $event->id, 'verified_by' => $this->owner->id]);
        $review = \App\Models\OperationalReview::where('event_id', $event->id)->sole();
        $this->assertSame($payload['review'], $review->evidence);
        $this->assertSame($this->owner->id, $review->reviewed_by);
        // An already-confirmed exact retry is not reclassified as a new booking as time advances.
        $this->travel(6)->hours();
        $this->confirm($event, $payload)->assertOk();
        $this->assertDatabaseCount('resource_reservations', 1);
        $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertDatabaseCount('operational_reviews', 1);
    }
    public function test_travel_unknown_and_incomplete_catalog_never_become_zero_or_ready(): void
    {
        $e = $this->event();
        $e->travel_fee_cents = null;
        $e->save();
        $p = $this->payload($e, [$this->resource()->id]);
        $this->confirm($e, $p)->assertUnprocessable();
        $e->travel_fee_cents = 0;
        $e->save();
        $e->services()->update(['operational_ready' => false]);
        $this->confirm($e, $p)->assertUnprocessable();
    }
    public function test_travel_requires_versioned_evidence_and_45_minutes_above_driving(): void
    {
        $r = $this->resource();
        $a = $this->event();
        $this->confirm($a, $this->payload($a, [$r->id]))->assertOk();
        $a->refresh();
        $b = $this->event($a->ends_at->addMinutes(60));
        $p = $this->payload($b, [$r->id]);
        $this->confirm($b, $p)->assertUnprocessable();
        $p['review']['transfers'] = [['from_event_id' => $a->id, 'to_event_id' => $b->id, 'from_version' => $a->version, 'to_version' => $b->version, 'travel_minutes' => 28, 'contingency_minutes' => 10, 'departure_at' => $a->ends_at->addMinutes(10)->toIso8601String(), 'note' => 'Reviewed traffic for the planned date and departure time.']];
        $this->confirm($b, $p)->assertUnprocessable();
        // 28 + 45 > 60
        $b->starts_at = $a->ends_at->addMinutes(75);
        $b->ends_at = $b->starts_at->addHour();
        $b->save();
        $this->confirm($b, $p)->assertOk();
    }
    public function test_deposit_below_target_requires_reason_without_changing_amount(): void
    {
        $e = $this->event();
        $p = $this->payload($e, [$this->resource()->id]);
        $p['payment']['amount_cents'] = 9990;
        $this->confirm($e, $p)->assertUnprocessable();
        $p['review']['deposit_exception_reason'] = 'Owner approves shortfall and keeps exact received amount.';
        $this->confirm($e, $p)->assertOk();
        $this->assertDatabaseHas('booking_evidence', ['event_id' => $e->id, 'amount_cents' => 9990]);
    }
    public function test_vehicle_needs_driver_and_enough_seats(): void
    {
        $e = $this->event(null, [['kind' => 'vehicle', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1]]);
        $v = $this->resource('vehicle', ['seats' => 2]);
        $s = $this->resource('staff', ['skills' => ['entertainer']]);
        $p = $this->payload($e, [$v->id, $s->id]);
        $this->confirm($e, $p)->assertUnprocessable();
        $s->skills = ['entertainer', 'driver'];
        $s->save();
        $this->confirm($e, $p)->assertOk();
    }
    public function test_optimistic_version_prevents_stale_resource_edits(): void
    {
        $r = $this->resource();
        $data = [...$r->only(['code', 'name', 'kind', 'status', 'skills', 'equipment_kind']), 'expected_version' => 1];
        $this->putJson('/admin/api/resources/' . $r->id, $data)->assertOk()->assertJsonPath('version', 2);
        $this->putJson('/admin/api/resources/' . $r->id, $data)->assertConflict();
    }
    public function test_dates_without_offset_are_rejected_and_price_versions_are_immutable(): void
    {
        $e = $this->event();
        $price = PriceVersion::first();
        $this->postJson('/admin/api/events', ['customer_id' => $e->customer_id, 'title' => 'Test', 'starts_at' => '2026-11-01T01:30:00', 'ends_at' => '2026-11-01T02:30:00', 'timezone' => 'America/Chicago', 'services' => []])->assertUnprocessable();
        $this->postJson('/admin/api/variants/' . $price->service_variant_id . '/prices', ['amount_cents' => 30000, 'currency' => 'USD'])->assertCreated();
        $this->assertSame(20000, $price->fresh()->amount_cents);
        $this->assertSame($price->id, $e->services()->first()->price_version_id);
    }
    public function test_four_speakers_cover_four_overlaps_and_a_fifth_is_refused(): void
    {
        $start = CarbonImmutable::now()->addDays(20)->startOfHour();
        $units = [];
        for ($i = 0; $i < 4; $i++) {
            $units[] = $this->resource();
            $event = $this->event($start);
            $this->confirm($event, $this->payload($event, [$units[$i]->id]))->assertOk();
        }
        $fifth = $this->event($start);
        $this->confirm($fifth, $this->payload($fifth, [$units[0]->id]))->assertUnprocessable();
        $this->assertDatabaseCount('resource_reservations', 4);
    }
    public function test_costume_wearer_cannot_also_cover_face_painting(): void
    {
        $character = \App\Models\Character::create(['name' => 'Fictitious character', 'active' => true]);
        $e = $this->event(null, [['kind' => 'costume', 'character_id' => $character->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'face_painter', 'quantity' => 1]]);
        $costume = $this->resource('costume', ['character_id' => $character->id]);
        $person = $this->resource('staff', ['skills' => ['performer', 'face_painter', 'driver']]);
        DB::table('costume_compatibilities')->insert(['staff_id' => $person->id, 'costume_id' => $costume->id]);
        $this->confirm($e, $this->payload($e, [$costume->id, $person->id]))->assertUnprocessable();
        $performer = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        DB::table('costume_compatibilities')->insert(['staff_id' => $performer->id, 'costume_id' => $costume->id]);
        $this->confirm($e, $this->payload($e, [$costume->id, $person->id, $performer->id]))->assertOk();
    }
    public function test_matching_finds_a_valid_crew_instead_of_spending_the_only_compatible_performer_on_another_role(): void
    {
        $character = \App\Models\Character::create(['name' => 'Fictitious character', 'active' => true]);
        $e = $this->event(null, [['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'face_painter', 'quantity' => 1], ['kind' => 'costume', 'character_id' => $character->id, 'quantity' => 1]]);
        $onlyWearer = $this->resource('staff', ['skills' => ['performer', 'face_painter', 'driver']]);
        $painter = $this->resource('staff', ['skills' => ['face_painter']]);
        $otherPerformer = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $costume = $this->resource('costume', ['character_id' => $character->id]);
        DB::table('costume_compatibilities')->insert(['staff_id' => $onlyWearer->id, 'costume_id' => $costume->id]);
        $this->confirm($e, $this->payload($e, [$onlyWearer->id, $painter->id, $otherPerformer->id, $costume->id]))->assertOk();
    }
    public function test_committed_event_cannot_be_edited_or_blocked_without_change_workflow(): void
    {
        $e = $this->event();
        $r = $this->resource();
        $this->confirm($e, $this->payload($e, [$r->id]))->assertOk();
        $this->putJson('/admin/api/events/' . $e->id, [...$e->only(['customer_id', 'title', 'timezone', 'address', 'setup_minutes', 'breakdown_minutes', 'travel_fee_cents']), 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'expected_version' => 2, 'services' => [['price_version_id' => $e->services()->first()->price_version_id]]])->assertConflict();
        $this->postJson('/admin/api/resources/' . $r->id . '/blocks', ['starts_at' => $e->starts_at->subMinutes(5)->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'reason' => 'Cannot silently replace commitment'])->assertConflict();
        $this->getJson('/admin/api/events/' . $e->id . '/audit')->assertOk()->assertJsonPath('data.0.action', 'booking.capacity_reserved')->assertJsonPath('data.0.actor_id', $this->owner->id);
    }
    public function test_idempotent_retry_cannot_change_payment_method_or_review(): void
    {
        $e = $this->event();
        $p = $this->payload($e, [$this->resource()->id]);
        $this->confirm($e, $p)->assertOk();
        $changed = $p;
        $changed['payment']['method'] = 'external_cash';
        $this->confirm($e, $changed)->assertConflict();
        $changed = $p;
        $changed['review']['origin_return_note'] = 'Changed logistics must use a new reviewed revision.';
        $this->confirm($e, $changed)->assertConflict();
        $this->assertDatabaseCount('booking_evidence', 1);
    }
    public function test_vehicle_seats_include_the_driver_and_all_selected_staff(): void
    {
        $e = $this->event(null, [['kind' => 'vehicle', 'quantity' => 1], ['kind' => 'staff', 'skill' => 'entertainer', 'quantity' => 1]]);
        $v = $this->resource('vehicle', ['seats' => 1]);
        $driver = $this->resource('staff', ['skills' => ['driver', 'entertainer']]);
        $passenger = $this->resource('staff', ['skills' => ['performer']]);
        $this->confirm($e, $this->payload($e, [$v->id, $driver->id, $passenger->id]))->assertUnprocessable();
        $v->seats = 2;
        $v->save();
        $this->confirm($e, $this->payload($e, [$v->id, $driver->id, $passenger->id]))->assertOk();
    }
    public function test_draft_api_preserves_price_and_requirement_snapshots_across_catalog_changes(): void
    {
        $fixture = $this->event();
        $service = $fixture->services()->first();
        $variant = ServiceVariant::findOrFail($service->service_variant_id);
        $payload = [...$fixture->only(['customer_id', 'title', 'timezone', 'address', 'setup_minutes', 'breakdown_minutes', 'travel_fee_cents']), 'starts_at' => $fixture->starts_at->toIso8601String(), 'ends_at' => $fixture->ends_at->toIso8601String(), 'services' => [['price_version_id' => $service->price_version_id]]];
        $created = $this->postJson('/admin/api/events', $payload)->assertCreated();
        $id = $created->json('id');
        $this->putJson('/admin/api/variants/' . $variant->id, ['name' => 'Revised fictitious recipe', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'equipment', 'equipment_kind' => 'speaker', 'quantity' => 2]], 'expected_version' => 1])->assertOk();
        $this->getJson('/admin/api/events/' . $id)->assertOk()->assertJsonPath('services.0.requirements_snapshot.0.quantity', 1)->assertJsonPath('services.0.price_version_id', $service->price_version_id);
        $this->putJson('/admin/api/events/' . $id, [...$payload, 'expected_version' => 1])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('services.0.requirements_snapshot.0.quantity', 2);
        $this->putJson('/admin/api/events/' . $id, [...$payload, 'expected_version' => 1])->assertConflict();
    }
    public function test_explicit_daylight_saving_offsets_are_stored_as_distinct_instants(): void
    {
        $fixture = $this->event();
        $payload = [...$fixture->only(['customer_id', 'title', 'timezone']), 'services' => [], 'starts_at' => '2026-11-01T01:15:00-05:00', 'ends_at' => '2026-11-01T01:15:00-06:00'];
        $created = $this->postJson('/admin/api/events', $payload)->assertCreated();
        $event = Event::findOrFail($created->json('id'));
        $this->assertSame(60.0, $event->starts_at->diffInMinutes($event->ends_at));
        $this->assertSame('06:15', $event->starts_at->format('H:i'));
        $this->assertSame('07:15', $event->ends_at->format('H:i'));
    }
    public function test_existing_resource_block_during_driving_prevents_the_next_booking(): void
    {
        $r = $this->resource();
        $a = $this->event();
        $this->confirm($a, $this->payload($a, [$r->id]))->assertOk();
        $a->refresh();
        $b = $this->event($a->ends_at->addMinutes(75));
        $p = $this->payload($b, [$r->id]);
        $this->postJson('/admin/api/resources/' . $r->id . '/blocks', ['starts_at' => $a->ends_at->addMinutes(20)->toIso8601String(), 'ends_at' => $a->ends_at->addMinutes(25)->toIso8601String(), 'reason' => 'Unavailable during planned driving'])->assertCreated();
        $p['review']['transfers'] = [['from_event_id' => $a->id, 'to_event_id' => $b->id, 'from_version' => $a->version, 'to_version' => $b->version, 'travel_minutes' => 28, 'contingency_minutes' => 10, 'departure_at' => $a->ends_at->addMinutes(10)->toIso8601String(), 'note' => 'Reviewed traffic for this date and departure.']];
        $this->confirm($b, $p)->assertUnprocessable();
        $this->assertSame('draft', $b->fresh()->status);
        $blockId = \App\Models\ResourceBlock::where('resource_id', $r->id)->value('id');
        $this->postJson('/admin/api/resources/'.$r->id.'/blocks/'.$blockId.'/release', ['reason' => 'Owner confirmed the resource can now travel.'])->assertOk();
        $this->confirm($b, $p)->assertOk();
    }
    public function test_new_block_cannot_interrupt_a_committed_driving_interval(): void
    {
        $r = $this->resource();
        $a = $this->event();
        $this->confirm($a, $this->payload($a, [$r->id]))->assertOk();
        $a->refresh();
        $b = $this->event($a->ends_at->addMinutes(75));
        $p = $this->payload($b, [$r->id]);
        $p['review']['transfers'] = [['from_event_id' => $a->id, 'to_event_id' => $b->id, 'from_version' => $a->version, 'to_version' => $b->version, 'travel_minutes' => 28, 'contingency_minutes' => 10, 'departure_at' => $a->ends_at->addMinutes(10)->toIso8601String(), 'note' => 'Reviewed traffic for this date and departure.']];
        $this->confirm($b, $p)->assertOk();
        $this->postJson('/admin/api/resources/' . $r->id . '/blocks', ['starts_at' => $a->ends_at->addMinutes(20)->toIso8601String(), 'ends_at' => $a->ends_at->addMinutes(25)->toIso8601String(), 'reason' => 'Cannot interrupt committed driving'])->assertConflict();
        $this->assertDatabaseCount('resource_blocks', 0);
    }
    public function test_releasing_a_block_preserves_history_and_does_not_ignore_other_active_blocks(): void
    {
        $event = $this->event(); $resource = $this->resource();
        $data = ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'reason' => 'Fictitious maintenance'];
        $first = $this->postJson('/admin/api/resources/'.$resource->id.'/blocks', $data)->assertCreated()->json('id');
        $second = $this->postJson('/admin/api/resources/'.$resource->id.'/blocks', [...$data, 'reason' => 'Independent absence'])->assertCreated()->json('id');
        $payload = $this->payload($event, [$resource->id]);
        $this->confirm($event, $payload)->assertUnprocessable();
        $path = '/admin/api/resources/'.$resource->id.'/blocks/'.$first.'/release';
        $release = ['reason' => 'Maintenance completed and the unit was checked.'];
        $this->postJson($path, $release)->assertOk()->assertJsonPath('released_by', $this->owner->id)->assertJsonPath('reason', $data['reason']);
        $this->postJson($path, $release)->assertOk();
        $this->postJson($path, ['reason' => 'A different reason must not overwrite history.'])->assertConflict();
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'resource.block_released')->count());
        $this->confirm($event, $payload)->assertUnprocessable();
        $this->postJson('/admin/api/resources/'.$resource->id.'/blocks/'.$second.'/release', ['reason' => 'The absence was cancelled by the owner.'])->assertOk();
        $this->confirm($event, $payload)->assertOk();
        $this->assertDatabaseCount('resource_blocks', 2);
        $this->assertDatabaseHas('resource_blocks', ['id' => $first, 'reason' => $data['reason'], 'released_by' => $this->owner->id, 'release_reason' => $release['reason']]);
        $this->getJson('/admin/api/resources/'.$resource->id.'/blocks')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/admin/api/resources/'.$resource->id.'/blocks?state=released')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/admin/api/resources/'.$resource->id.'/blocks?state=all')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_releasing_a_block_does_not_activate_a_unit_in_maintenance(): void
    {
        $event = $this->event(); $resource = $this->resource('equipment', ['status' => 'maintenance']);
        $block = $this->postJson('/admin/api/resources/'.$resource->id.'/blocks', ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'reason' => 'Fictitious resource block'])->assertCreated()->json('id');
        $this->postJson('/admin/api/resources/'.$resource->id.'/blocks/'.$block.'/release', ['reason' => 'Calendar block cleared; maintenance remains.'])->assertOk();
        $this->assertSame('maintenance', $resource->fresh()->status);
        $this->confirm($event, $this->payload($event, [$resource->id]))->assertUnprocessable();
        $this->assertDatabaseCount('resource_reservations', 0);
    }

    public function test_block_release_is_scoped_to_its_unit_and_requires_an_owner_with_a_reason(): void
    {
        $event = $this->event(); $resource = $this->resource(); $other = $this->resource();
        $block = $this->postJson('/admin/api/resources/'.$resource->id.'/blocks', ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'reason' => 'Fictitious resource block'])->assertCreated()->json('id');
        $body = ['reason' => 'Owner reviewed and lifted this block.'];
        $this->postJson('/admin/api/resources/'.$other->id.'/blocks/'.$block.'/release', $body)->assertNotFound();
        $path = '/admin/api/resources/'.$resource->id.'/blocks/'.$block.'/release';
        $this->postJson($path, ['reason' => ''])->assertUnprocessable();
        $staff = User::factory()->create(['role' => 'staff', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($staff)->postJson($path, $body)->assertForbidden();
        $this->getJson('/admin/api/resources/'.$resource->id.'/blocks')->assertForbidden();
        $this->postJson('/logout')->assertSuccessful();
        $this->postJson($path, $body)->assertUnauthorized();
        $this->assertDatabaseHas('resource_blocks', ['id' => $block, 'released_at' => null]);
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'resource.block_released')->count());
    }

    public function test_block_listing_filters_by_actual_overlap_and_explicit_offsets(): void
    {
        $event = $this->event(); $resource = $this->resource();
        $this->postJson('/admin/api/resources/'.$resource->id.'/blocks', ['starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(), 'reason' => 'Fictitious resource block'])->assertCreated();
        $base = '/admin/api/resources/'.$resource->id.'/blocks';
        $inside = http_build_query(['from' => $event->starts_at->addMinutes(10)->toIso8601String(), 'to' => $event->ends_at->toIso8601String()]);
        $this->getJson($base.'?'.$inside)->assertOk()->assertJsonPath('total', 1);
        $touch = http_build_query(['from' => $event->ends_at->toIso8601String()]);
        $this->getJson($base.'?'.$touch)->assertOk()->assertJsonPath('total', 0);
        $this->getJson($base.'?'.http_build_query(['to' => $event->starts_at->toIso8601String()]))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($base.'?'.http_build_query(['from' => $event->ends_at->toIso8601String(), 'to' => $event->starts_at->toIso8601String()]))->assertUnprocessable();
        $this->getJson($base.'?from=2026-11-01T01:30:00')->assertUnprocessable();
        $this->getJson($base.'?state=unknown')->assertUnprocessable();
    }

    public function test_postal_code_preserves_leading_zeros_and_does_not_invent_travel_pricing(): void
    {
        $fixture = $this->event();
        $payload = [...$fixture->only(['customer_id', 'title', 'timezone', 'address', 'setup_minutes', 'breakdown_minutes']),
            'starts_at' => $fixture->starts_at->toIso8601String(), 'ends_at' => $fixture->ends_at->toIso8601String(),
            'services' => [['price_version_id' => $fixture->services()->first()->price_version_id]], 'postal_code' => '00001'];
        $created = $this->postJson('/admin/api/events', $payload)->assertCreated()->assertJsonPath('postal_code', '00001')->assertJsonPath('travel_fee_cents', null);
        $event = Event::findOrFail($created->json('id'));
        $this->confirm($event, $this->payload($event, [$this->resource()->id]))->assertUnprocessable();
        $this->putJson('/admin/api/events/'.$event->id, [...$payload, 'postal_code' => '00001-1234', 'expected_version' => 1])->assertOk()->assertJsonPath('postal_code', '00001-1234');
        $this->postJson('/admin/api/events', [...$payload, 'postal_code' => '001'])->assertUnprocessable();
        $this->postJson('/admin/api/events', [...$payload, 'postal_code' => 12345])->assertUnprocessable();
    }

    public function test_two_real_processes_competing_for_one_resource_only_reserve_once(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency test requires pcntl; do not silently skip.');
        $resource = $this->resource();
        $a = $this->event();
        $b = $this->event($a->starts_at);
        $actor = $this->owner->id;
        $dir = sys_get_temp_dir() . '/waoo-race-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $children = [];
        DB::disconnect();
        foreach ([$a, $b] as $event) {
            $payload = $this->payload($event, [$resource->id]);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                auth()->loginUsingId($actor);
                while (!file_exists($dir . '/go')) {
                    usleep(1000);
                }
                try {
                    app(ConfirmBooking::class)->execute($event->id, $payload);
                    $out = 'reserved';
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $out = 'conflict';
                } catch (\Throwable $e) {
                    $out = 'error:' . get_class($e) . ':' . $e->getMessage();
                }
                file_put_contents($dir . '/' . $event->id, $out);
                exit(0);
            }
            $children[] = $pid;
        }
        touch($dir . '/go');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge();
        $results = [file_get_contents($dir . '/' . $a->id), file_get_contents($dir . '/' . $b->id)];
        sort($results);
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
        $this->assertSame(['conflict', 'reserved'], $results);
        $this->assertDatabaseCount('resource_reservations', 1);
        $this->assertDatabaseCount('booking_evidence', 1);
    }
}
