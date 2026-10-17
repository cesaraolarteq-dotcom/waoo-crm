<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Customer, Event, PriceVersion, Resource, ResourceReservation, ServiceVariant, User};
use App\Services\ConfirmBooking;
use App\Services\Records\{AddPriceVersion, LinkCostumeCompatibility, SaveEventDraft, SaveVariant, UnlinkCostumeCompatibility};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordExtensionsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Character $hero;
    private Character $princess;
    private ServiceVariant $variant;
    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->hero = Character::create(['name' => 'Fixture hero', 'active' => true]);
        $this->princess = Character::create(['name' => 'Fixture princess', 'active' => true]);
        $this->variant = app(SaveVariant::class)->execute($this->owner, [
            'name' => 'Fixture choice service', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']],
            'character_quantity' => 1, 'character_options' => [
                ['character_id' => $this->hero->id, 'requirements' => [
                    ['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'],
                    ['kind' => 'costume', 'quantity' => 1, 'character_id' => $this->hero->id]]],
                ['character_id' => $this->princess->id, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]],
            ],
        ]);
        $this->price = app(AddPriceVersion::class)->execute($this->owner, $this->variant->id, ['amount_cents' => 12000, 'currency' => 'USD']);
    }

    private function input(?Character $character = null): array
    {
        $customer = Customer::create(['name' => 'Fixture client', 'locale' => 'es']);
        $start = now()->addDays(20)->startOfHour();
        return ['customer_id' => $customer->id, 'title' => 'Fixture event', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => 'Fixture venue', 'setup_minutes' => 10, 'breakdown_minutes' => 10, 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $this->price->id, 'characters' => $character ? [['character_id' => $character->id, 'quantity' => 1]] : []]]];
    }

    private function resource(string $kind, array $extra = []): Resource
    {
        return Resource::create([...['code' => fake()->unique()->bothify('FIX-####??'), 'name' => 'Fixture resource', 'kind' => $kind, 'status' => 'active', 'skills' => [], 'equipment_kind' => $kind === 'equipment' ? 'speaker' : null], ...$extra]);
    }

    private function booking(Event $event, array $ids): array
    {
        return ['expected_version' => $event->version, 'resource_ids' => $ids, 'payment' => ['method' => 'external_zelle', 'reference' => 'fixture-'.$event->id, 'amount_cents' => 6000, 'received_at' => now()->subHour()->toIso8601String()], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origins and return reviewed.', 'transfers' => []]];
    }

    public function test_selected_costume_character_is_part_of_real_capacity_checks(): void
    {
        $response = $this->postJson('/admin/api/events', $this->input($this->hero))->assertCreated()->assertJsonPath('event_type', 'birthday')
            ->assertJsonPath('services.0.characters.0.character_name_snapshot', $this->hero->name);
        $event = Event::findOrFail($response->json('id'));
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $speaker = $this->resource('equipment');
        $wrong = $this->resource('costume', ['character_id' => $this->princess->id]);
        $right = $this->resource('costume', ['character_id' => $this->hero->id]);
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $wrong->id);
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $right->id);
        $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$staff->id, $speaker->id, $wrong->id]))->assertUnprocessable();
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$staff->id, $speaker->id, $right->id]))->assertOk();
        $this->assertDatabaseCount('resource_reservations', 3);
    }

    public function test_non_costume_character_needs_its_declared_staff_but_no_costume(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input($this->princess));
        $this->assertFalse(collect($event->services->first()->requirements_snapshot)->contains('kind', 'costume'));
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $speaker = $this->resource('equipment');
        $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$staff->id, $speaker->id]))->assertOk();
    }

    public function test_missing_choices_stay_incomplete_and_cannot_reserve(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input());
        $this->assertFalse($event->services->first()->operational_ready);
        $speaker = $this->resource('equipment');
        $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$speaker->id]))->assertUnprocessable();
        $this->assertDatabaseCount('resource_reservations', 0);
        $this->assertDatabaseCount('booking_evidence', 0);
    }

    public function test_omitted_choices_and_type_survive_old_form_updates_and_explicit_empty_clears_choices(): void
    {
        $input = $this->input($this->hero);
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        unset($input['event_type'], $input['services'][0]['characters']);
        $updated = app(SaveEventDraft::class)->execute($this->owner, [...$input, 'expected_version' => 1], $event->id);
        $this->assertSame('birthday', $updated->event_type);
        $this->assertSame($this->hero->id, $updated->services->first()->characters->first()->character_id);
        $this->assertSame(1, $updated->services->first()->characters->first()->quantity);
        $cleared = app(SaveEventDraft::class)->execute($this->owner, [...$input, 'event_type' => null, 'services' => [['price_version_id' => $this->price->id, 'characters' => []]], 'expected_version' => 2], $event->id);
        $this->assertNull($cleared->event_type);
        $this->assertCount(0, $cleared->services->first()->characters);
        $this->assertFalse($cleared->services->first()->operational_ready);
    }

    public function test_invalid_selection_rolls_back_previous_event_and_children(): void
    {
        $input = $this->input($this->hero);
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        $original = $event->services->first()->getKey();
        $count = DB::table('audit_entries')->count();
        $input['services'][0]['characters'][0]['quantity'] = 2;
        $this->putJson('/admin/api/events/'.$event->id, [...$input, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors(['services.0.characters']);
        $this->assertSame(1, $event->fresh()->version);
        $this->assertSame($original, $event->services()->first()->id);
        $this->assertDatabaseCount('event_service_characters', 1);
        $this->assertDatabaseCount('audit_entries', $count);
        $this->postJson('/admin/api/events', [...$input, 'event_type' => 'invented_type'])->assertUnprocessable()->assertJsonValidationErrors(['event_type']);
    }

    public function test_catalog_rejects_mismatched_costume_and_old_catalog_forms_preserve_configuration(): void
    {
        $input = ['name' => 'Fixture renamed', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => $this->variant->requirements, 'expected_version' => 1];
        $saved = app(SaveVariant::class)->execute($this->owner, $input, $this->variant->id);
        $this->assertSame(1, $saved->character_quantity);
        $this->assertCount(2, $saved->character_options);
        $options = $saved->character_options;
        $options[0]['requirements'][1]['character_id'] = $this->princess->id;
        $this->putJson('/admin/api/variants/'.$saved->id, [...$input, 'expected_version' => 2, 'character_options' => $options])->assertUnprocessable()
            ->assertJsonValidationErrors(['character_options.0.requirements.1.character_id']);
        $this->assertSame(2, $saved->fresh()->version);
    }

    public function test_unlink_is_audited_idempotent_authorized_and_requires_reason(): void
    {
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $costume = $this->resource('costume', ['character_id' => $this->hero->id]);
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id);
        $payload = ['staff_id' => $staff->id, 'costume_id' => $costume->id, 'reason' => 'Fixture staff leaving company'];
        $url = '/admin/api/costume-compatibilities/unlink';
        $this->postJson($url, [...$payload, 'reason' => 'short'])->assertUnprocessable();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('removed', true);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('removed', false);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'costume.compatibility_removed')->count());
        $this->assertDatabaseHas('audit_entries', ['action' => 'costume.compatibility_removed', 'actor_id' => $this->owner->id]);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->postJson($url, $payload)->assertForbidden();
    }

    public function test_unlink_cannot_invalidate_a_shared_future_commitment(): void
    {
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $costume = $this->resource('costume', ['character_id' => $this->hero->id]);
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input($this->hero));
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id);
        foreach ([$staff, $costume] as $resource) ResourceReservation::create(['resource_id' => $resource->id, 'event_id' => $event->id, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
        try {
            app(UnlinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id, 'Fixture staff leaving company');
            $this->fail('A committed pairing was removed.');
        } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseHas('costume_compatibilities', ['staff_id' => $staff->id, 'costume_id' => $costume->id]);
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'costume.compatibility_removed')->count());
    }

    public function test_unlink_audit_failure_restores_the_link(): void
    {
        $staff = $this->resource('staff');
        $costume = $this->resource('costume', ['character_id' => $this->hero->id]);
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id);
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_entries`')) throw new \RuntimeException('Fixture audit failure');
        });
        try {
            app(UnlinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id, 'Fixture staff leaving company');
            $this->fail('Audit failure was swallowed.');
        } catch (\RuntimeException $e) { $this->assertSame('Fixture audit failure', $e->getMessage()); }
        $this->assertDatabaseHas('costume_compatibilities', ['staff_id' => $staff->id, 'costume_id' => $costume->id]);
    }
    public function test_unlink_racing_capacity_confirmation_cannot_leave_an_invalid_reservation(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'));
        $staff = $this->resource('staff', ['skills' => ['performer', 'driver']]);
        $costume = $this->resource('costume', ['character_id' => $this->hero->id]);
        $speaker = $this->resource('equipment');
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id);
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input($this->hero));
        $payload = $this->booking($event, [$staff->id, $costume->id, $speaker->id]);
        $dir = sys_get_temp_dir().'/waoo-unlink-race-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach (['unlink', 'reserve'] as $operation) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::purge();
                    auth()->loginUsingId($this->owner->id);
                    $deadline = microtime(true) + 8;
                    while (!file_exists($dir.'/go') && microtime(true) < $deadline) usleep(1000);
                    try {
                        if ($operation === 'unlink') {
                            app(UnlinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id, 'Fixture staff leaving company');
                            $out = 'unlinked';
                        } else {
                            app(ConfirmBooking::class)->execute($event->id, $payload);
                            $out = 'reserved';
                        }
                    } catch (ValidationException $e) { $out = 'booking_conflict'; }
                    catch (RecordWriteRejected $e) { $out = $e->status === 409 ? 'unlink_conflict' : 'error'; }
                    catch (\Throwable $e) { $out = 'error:'.get_class($e); }
                    file_put_contents($dir.'/'.$operation, $out);
                    DB::disconnect();
                    exit(0);
                }
                $children[] = $pid;
            }
            touch($dir.'/go');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            DB::purge();
            $result = [file_get_contents($dir.'/unlink'), file_get_contents($dir.'/reserve')];
            $this->assertTrue(in_array($result, [['unlinked', 'booking_conflict'], ['unlink_conflict', 'reserved']], true), json_encode($result));
            $reserved = $result[1] === 'reserved';
            $this->assertDatabaseCount('resource_reservations', $reserved ? 3 : 0);
            $this->assertDatabaseCount('booking_evidence', $reserved ? 1 : 0);
            $this->assertSame($reserved, DB::table('costume_compatibilities')->where(['staff_id' => $staff->id, 'costume_id' => $costume->id])->exists());
        } finally {
            touch($dir.'/go');
            foreach ($children as $pid) pcntl_waitpid($pid, $status);
            DB::purge();
            foreach (glob($dir.'/*') as $file) unlink($file);
            rmdir($dir);
        }
    }

}
