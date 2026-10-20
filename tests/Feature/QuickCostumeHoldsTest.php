<?php
namespace Tests\Feature;
use App\Models\{Character, Event, LedgerEntry, PriceVersion, QuickCostumeHold, QuickCostumeHoldEvent, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\Booking\{HoldQuickBookingCostumes, QuickBook, QuickCostumeHolds, ReadQuickBookingCostumeHold, ReleaseQuickBookingCostumes};
use App\Services\Finance\ConfirmPaidCapacity;
use App\Services\Records\{SaveCharacter, SaveEventDraft};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use Illuminate\Support\Str;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;
class QuickCostumeHoldsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner; private Character $character; private Resource $unit; private PriceVersion $price; private int $sequence = 0;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]); Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['name' => 'Ana López', 'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]); $this->actingAs($this->owner);
        $this->character = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['0','Clásico','Golden']]);
        $this->unit = Resource::create(['code' => 'MICKEY-1', 'name' => 'Cabeza de Mickey', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'skills' => []]);
        $v = ServiceVariant::create(['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120, 'character_quantity' => 1, 'requirements' => [],
            'character_options' => [['character_id' => $this->character->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->character->id, 'quantity' => 1], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]]]);
        $this->price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 30000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }
    protected function tearDown(): void
    {
        // Disposable duplicate-outfit selections must not trip the production rollback history safeguard.
        DB::table('event_service_characters')->delete();
        parent::tearDown();
    }
    private function input(array $extra = []): array
    {
        $start = now('America/Chicago')->addDays(20)->setTime(12,0)->utc();
        return ['new_customer' => ['name' => 'María González', 'phone' => '+13465550142', 'locale' => 'es'], 'title' => 'Cumpleaños de Valentina', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Avenida del Parque', 'postal_code' => '77433', 'travel_fee_cents' => 0, 'setup_minutes' => 30, 'breakdown_minutes' => 20,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => '0']]]],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 15000, 'reference' => 'MARIA-DEPOSIT-'.(++$this->sequence), 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], ...$extra];
    }
    private function book(array $extra = [], ?string $key = null) { return app(QuickBook::class)->execute($this->owner, $this->input($extra), $key ?? (string)Str::uuid()); }
    private function read(int $id): array { return app(ReadQuickBookingCostumeHold::class)->execute($this->owner, $id); }
    private function pins(array $v): array { return ['expected_event_version' => $v['event_version'], 'expected_hold_version' => $v['version'], 'characters' => $v['characters']]; }
    private function release(int $id, ?string $key = null): array
    {
        $v = $this->read($id); return app(ReleaseQuickBookingCostumes::class)->execute($this->owner, $id, ['expected_event_version' => $v['event_version'],
            'expected_hold_version' => $v['version'], 'reason' => 'La dueña revisó otra unidad para esta fiesta.'], $key ?? (string)Str::uuid());
    }
    private function draftInput(Event $e, array $extra = []): array
    {
        return [...$e->only(['customer_id','title','event_type','timezone','address','postal_code','travel_fee_cents','setup_minutes','breakdown_minutes']),
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'expected_version' => $e->version,
            'services' => $this->input()['services'], ...$extra];
    }
    public function test_quick_book_holds_only_the_physical_unit_and_keeps_exact_wardrobe_zero(): void
    {
        $b = $this->book(); $v = $b->costume_hold;
        $this->assertSame('held', $v['state']); $this->assertSame('0', $v['rows'][0]['outfit']); $this->assertFalse($v['staff_confirmed']);
        $this->assertSame('draft', $b->event->status); $this->assertSame(15000, $b->receipt->amount_cents);
        $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('booking_evidence', 0);
        $this->getJson('/admin/api/events/'.$b->event->id.'/costume-hold')->assertOk()->assertJsonPath('state', 'held');
    }
    public function test_unknown_buffers_still_hold_known_window_and_are_review_required(): void
    {
        $b = $this->book(['setup_minutes' => null, 'breakdown_minutes' => null]);
        $this->assertSame('review_required', $b->costume_hold['state']); $this->assertContains('timing_unknown', $b->costume_hold['issues']);
        $this->assertDatabaseCount('resource_reservations', 1); $p = QuickCostumeHold::sole()->payload;
        $this->assertNull($p['window']['setup_minutes']); $this->assertSame($p['window']['party_start'], $p['window']['busy_start']);
        $this->assertSame($p['window']['party_end'], $p['window']['busy_end']);
    }
    public function test_inventory_conflict_does_not_erase_second_real_deposit_or_create_partial_hold(): void
    {
        $a = $this->book(); $b = $this->book(); $this->assertSame('held', $a->costume_hold['state']); $this->assertSame('blocked', $b->costume_hold['state']);
        $this->assertContains('costume_unavailable', $b->costume_hold['issues']); $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('quick_bookings', 2); $this->assertDatabaseCount('resource_reservations', 1);
        $this->assertSame([], $b->costume_hold['rows']);
    }
    public function test_hold_storage_failure_rolls_back_inventory_but_keeps_committed_money(): void
    {
        $fail = true; DB::listen(function ($q) use (&$fail) { if ($fail && str_starts_with(strtolower($q->sql), 'insert into ') && str_contains($q->sql, 'quick_costume_hold_events')) throw new \RuntimeException('Synthetic storage failure'); });
        $b = $this->book(); $fail = false;
        $this->assertSame('review_required', $b->costume_hold['state']); $this->assertContains('hold_unavailable', $b->costume_hold['issues']);
        $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('resource_reservations', 0); $this->assertDatabaseCount('quick_costume_holds', 0);
        $v = $this->read($b->event->id); $r = app(HoldQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $this->pins($v), (string)Str::uuid());
        $this->assertSame('held', $r['state']);
    }
    public function test_release_is_versioned_replay_safe_and_core_replay_does_not_recreate_hold(): void
    {
        $input = $this->input(); $key = (string)Str::uuid(); $b = app(QuickBook::class)->execute($this->owner, $input, $key);
        $v = $b->costume_hold; $release = ['expected_event_version' => $v['event_version'], 'expected_hold_version' => $v['version'], 'reason' => 'Revisado con la dueña, liberar este apartado.']; $rk = (string)Str::uuid();
        $one = app(ReleaseQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $release, $rk);
        $this->assertSame('released', $one['state']); $this->assertSame($one, app(ReleaseQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $release, $rk));
        $this->assertSame('released', app(QuickBook::class)->execute($this->owner, $input, $key)->costume_hold['state']);
        $this->assertDatabaseCount('resource_reservations', 0); $this->assertDatabaseCount('ledger_entries', 1); $this->assertDatabaseCount('quick_costume_hold_events', 2);
        $v = $this->read($b->event->id); $pk = (string)Str::uuid(); $args = $this->pins($v);
        $a = app(HoldQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $args, $pk);
        $this->assertSame($a, app(HoldQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $args, $pk)); $this->assertDatabaseCount('resource_reservations', 1);
    }
    public function test_unknown_buffer_expansion_is_atomic_and_cannot_overlap_another_party(): void
    {
        $b = $this->book(['setup_minutes' => null, 'breakdown_minutes' => null]);
        $start = $b->event->ends_at->addMinutes(10); $this->book(['starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(), 'setup_minutes' => 0, 'breakdown_minutes' => 0]);
        try { app(SaveEventDraft::class)->execute($this->owner, $this->draftInput($b->event, ['setup_minutes' => 0, 'breakdown_minutes' => 20]), $b->event->id); $this->fail('Expanded into a held unit'); }
        catch (\App\Exceptions\RecordWriteRejected) {}
        $this->assertNull($b->event->fresh()->breakdown_minutes); $this->assertSame(1, QuickCostumeHold::where('event_id', $b->event->id)->value('version'));
    }
    public function test_known_timing_resolution_and_address_only_edit_preserve_same_unit(): void
    {
        $b = $this->book(['setup_minutes' => null, 'breakdown_minutes' => null]); $row = $b->costume_hold['rows'][0];
        $e = app(SaveEventDraft::class)->execute($this->owner, $this->draftInput($b->event, ['setup_minutes' => 15, 'breakdown_minutes' => 10, 'address' => '456 Avenida del Parque']), $b->event->id);
        $v = $this->read($e->id); $this->assertSame('held', $v['state']); $this->assertSame($row, $v['rows'][0]);
        $this->assertSame(2, $v['version']); $this->assertDatabaseCount('resource_reservations', 1);
    }
    public function test_complete_confirmation_promotes_hold_without_self_conflict_or_duplicate_reservation(): void
    {
        $b = $this->book(); $staff = Resource::create(['code' => 'ANA-1', 'name' => 'Ana López', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer','driver']]);
        DB::table('costume_compatibilities')->insert(['staff_id' => $staff->id, 'costume_id' => $this->unit->id]);
        $input = ['expected_version' => $b->event->version, 'financial_revision_id' => $b->financial_revision->id, 'resource_ids' => [$this->unit->id, $staff->id],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Recogida y regreso revisados con la dueña.', 'transfers' => []]];
        $saved = app(ConfirmPaidCapacity::class)->execute($this->owner, $b->event->id, $input);
        $this->assertSame('capacity_reserved', $saved->status); $this->assertSame('consumed', $this->read($saved->id)['state']);
        $this->assertDatabaseCount('resource_reservations', 2); $this->assertDatabaseCount('booking_evidence', 1);
        $this->assertSame('0', \App\Models\BookingEvidence::sole()->staff_assignment_snapshot['costume_assignments'][0]['outfit']);
        app(ConfirmPaidCapacity::class)->execute($this->owner, $b->event->id, $input); $this->assertDatabaseCount('resource_reservations', 2);
        $this->postJson('/admin/api/events/'.$saved->id.'/costume-hold/release', ['expected_event_version' => $saved->version, 'expected_hold_version' => 2, 'reason' => 'No liberar una reserva confirmada por accidente.'], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
    }
    public function test_held_wardrobe_cannot_be_removed_and_stale_release_is_refused(): void
    {
        $b = $this->book();
        try { app(SaveCharacter::class)->execute($this->owner, ['expected_version' => 1, 'name' => 'Mickey', 'active' => true, 'outfits' => ['Golden']], $this->character->id); $this->fail('Removed a held wardrobe'); }
        catch (\App\Exceptions\RecordWriteRejected) {}
        $this->postJson('/admin/api/events/'.$b->event->id.'/costume-hold/release', ['expected_event_version' => 1, 'expected_hold_version' => 999, 'reason' => 'Revisión del apartado de esta fiesta.'], ['Idempotency-Key' => (string)Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('resource_reservations', 1);
    }
    public function test_signed_history_and_owned_reservation_tampering_never_claim_a_valid_hold(): void
    {
        $b = $this->book(); DB::table('quick_costume_hold_events')->update(['payload_sha256' => str_repeat('0',64)]);
        $v = $this->read($b->event->id); $this->assertSame('review_required', $v['state']); $this->assertContains('integrity_invalid', $v['issues']); $this->assertSame([], $v['rows']);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_two_owners_cannot_both_hold_the_last_head_but_both_payments_survive(): void
    {
        if (DB::getDriverName() !== 'mysql') $this->markTestSkipped('Real MariaDB workers');
        $a = $this->owner; $other = $a->replicate(); $other->email = 'daniela.perez@example.test'; $other->save();
        $one = $this->input(); $two = $this->input();
        $results = ConcurrentWorkers::run([fn () => app(QuickBook::class)->execute($a, $one, (string)Str::uuid())->costume_hold['state'],
            fn () => app(QuickBook::class)->execute($other, $two, (string)Str::uuid())->costume_hold['state']]);
        sort($results); $this->assertSame(['blocked','held'], $results); $this->assertDatabaseCount('ledger_entries', 2); $this->assertDatabaseCount('resource_reservations', 1);
    }
    public function test_last_staff_failure_preserves_the_original_costume_hold_and_deposit(): void
    {
        $b = $this->book();
        try { app(ConfirmPaidCapacity::class)->execute($this->owner, $b->event->id, ['expected_version' => 1, 'financial_revision_id' => $b->financial_revision->id,
            'resource_ids' => [$this->unit->id], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Salida y regreso revisados, falta personal.', 'transfers' => []]]); $this->fail('Confirmed without staff'); }
        catch (\Illuminate\Validation\ValidationException) {}
        $this->assertSame('held', $this->read($b->event->id)['state']); $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_cancellation_hook_is_transaction_only_and_releases_inventory_without_deleting_money(): void
    {
        $b = $this->book();
        try { QuickCostumeHolds::releaseForCancellation($this->owner, $b->event, 'Cancelación revisada por la dueña.'); $this->fail('Released without cancellation'); }
        catch (\App\Exceptions\RecordWriteRejected) {}
        DB::transaction(function () use ($b) { $e = Event::whereKey($b->event->id)->lockForUpdate()->firstOrFail(); $e->status = 'cancelled'; $e->save(); QuickCostumeHolds::releaseForCancellation($this->owner, $e, 'Cancelación revisada por la dueña.'); });
        $this->assertDatabaseCount('resource_reservations', 0); $this->assertDatabaseCount('ledger_entries', 1); $this->assertSame('released', QuickCostumeHold::sole()->state);
        $this->assertDatabaseCount('quick_costume_hold_events', 2);
    }
    public function test_generic_and_fixed_outfits_use_maximum_matching_and_do_not_double_consume_a_head(): void
    {
        Resource::create(['code' => 'MICKEY-CLASSIC', 'name' => 'Mickey clásico', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->character->id, 'outfit' => 'Clásico', 'skills' => []]);
        $v = ServiceVariant::findOrFail($this->price->service_variant_id); $v->character_quantity = 2; $v->save();
        $b = $this->book(['services' => [['price_version_id' => $this->price->id, 'characters' => [
            ['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => 'Clásico'], ['character_id' => $this->character->id, 'quantity' => 1, 'outfit' => 'Golden']]]]]);
        $this->assertSame('held', $b->costume_hold['state']); $this->assertCount(2, $b->costume_hold['rows']);
        $this->assertSame(2, count(array_unique(array_column($b->costume_hold['rows'], 'resource_id'))));
    }
    public function test_owner_access_and_closed_inputs_do_not_change_inventory(): void
    {
        $b = $this->book(); $url = '/admin/api/events/'.$b->event->id.'/costume-hold';
        $this->postJson($url, [...$this->pins($b->costume_hold), 'resource_ids' => [999]], ['Idempotency-Key' => (string)Str::uuid()])->assertUnprocessable();
        $this->owner->forceFill(['two_factor_confirmed_at' => null])->save(); $this->getJson($url)->assertForbidden();
        $this->assertDatabaseCount('resource_reservations', 1);
    }

    public function test_resource_blocks_and_maintenance_cannot_erase_an_unknown_timing_hold(): void
    {
        $b = $this->book(['setup_minutes' => null, 'breakdown_minutes' => null]);
        try { app(\App\Services\Records\BlockResource::class)->execute($this->owner, $this->unit->id, [
            'starts_at' => $b->event->starts_at->toIso8601String(), 'ends_at' => $b->event->ends_at->toIso8601String(), 'reason' => 'Mantenimiento revisado por la dueña.']); $this->fail('Blocked a held unit'); }
        catch (\App\Exceptions\RecordWriteRejected) {}
        try { app(\App\Services\Records\SaveResource::class)->execute($this->owner, ['expected_version' => 1, 'code' => $this->unit->code,
            'name' => $this->unit->name, 'kind' => 'costume', 'status' => 'maintenance', 'character_id' => $this->character->id, 'skills' => []], $this->unit->id); $this->fail('Changed held unit'); }
        catch (\App\Exceptions\RecordWriteRejected) {}
        $this->assertSame('active', $this->unit->fresh()->status); $this->assertDatabaseCount('resource_blocks', 0); $this->assertDatabaseCount('resource_reservations', 1);
    }

    public function test_incomplete_original_recipe_does_not_become_complete_when_the_catalog_later_removes_characters(): void
    {
        $b = $this->book(['services' => [['price_version_id' => $this->price->id]]]);
        $this->assertSame('needs_character', $b->costume_hold['state']);
        DB::table('service_variants')->where('id', $this->price->service_variant_id)->update(['character_quantity' => 0]);
        $v = $this->read($b->event->id); $result = app(HoldQuickBookingCostumes::class)->execute($this->owner, $b->event->id, $this->pins($v), (string)Str::uuid());
        $this->assertSame('needs_character', $result['state']); $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
    }
    public function test_changed_positive_receipt_is_detected_without_erasing_money_or_reservations(): void
    {
        $b = $this->book(); DB::table('ledger_entries')->where('id', $b->receipt->id)->update(['amount_cents' => 14000]);
        $v = $this->read($b->event->id); $this->assertSame('review_required', $v['state']); $this->assertContains('integrity_invalid', $v['issues']);
        $this->assertSame([], $v['rows']); $this->assertDatabaseCount('resource_reservations', 1); $this->assertDatabaseCount('ledger_entries', 1);
    }

}
