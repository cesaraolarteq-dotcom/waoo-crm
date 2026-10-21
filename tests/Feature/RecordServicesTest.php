<?php

namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\User;
use App\Services\Records\AddPriceVersion;
use App\Services\Records\BlockResource;
use App\Services\Records\LinkCostumeCompatibility;
use App\Services\Records\ReleaseBlock;
use App\Services\Records\SaveCharacter;
use App\Services\Records\SaveCustomer;
use App\Services\Records\SaveEventDraft;
use App\Services\Records\SaveResource;
use App\Services\Records\SaveVariant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RecordServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create([
            'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function equipment(array $extra = []): array
    {
        return [...['code' => 'FIXTURE-SPEAKER', 'name' => 'Fixture speaker', 'kind' => 'equipment',
            'status' => 'active', 'skills' => [], 'equipment_kind' => 'speaker'], ...$extra];
    }

    private function draft(int $priceId): array
    {
        $customer = app(SaveCustomer::class)->execute($this->owner, ['name' => 'Fixture client', 'locale' => 'es']);
        return ['customer_id' => $customer->id, 'title' => 'Fixture party',
            'starts_at' => '2027-06-12T16:00:00-05:00', 'ends_at' => '2027-06-12T17:00:00-05:00',
            'timezone' => 'America/Chicago', 'travel_fee_cents' => null, 'postal_code' => '00501',
            'services' => [['price_version_id' => $priceId]]];
    }

    private function variant(): array
    {
        return ['name' => 'Fixture service', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']]];
    }

    public function test_direct_services_use_explicit_actor_and_normalize_like_http(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);
        $customer = app(SaveCustomer::class)->execute($this->owner, [
            'name' => '  Fixture client  ', 'email' => '  ', 'phone' => '', 'locale' => 'es', 'actor_id' => $other->id,
        ]);
        $this->assertSame('Fixture client', $customer->name);
        $this->assertNull($customer->email);
        $this->assertNull($customer->phone);
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $this->owner->id, 'action' => 'customers.created', 'subject_id' => $customer->id]);
        $this->assertDatabaseMissing('audit_entries', ['actor_id' => $other->id]);
        $this->actingAs($this->owner)->postJson('/admin/api/customers', ['name' => '  HTTP fixture  ', 'email' => ' ', 'locale' => 'es'])
            ->assertCreated()->assertJsonPath('name', 'HTTP fixture')->assertJsonPath('email', null);
        $this->assertDatabaseCount('audit_entries', 2);
    }

    public function test_all_services_reload_actor_permissions_before_validating_or_writing(): void
    {
        $calls = [
            fn () => app(SaveCustomer::class)->execute($this->owner, []),
            fn () => app(SaveCharacter::class)->execute($this->owner, []),
            fn () => app(SaveResource::class)->execute($this->owner, []),
            fn () => app(SaveVariant::class)->execute($this->owner, []),
            fn () => app(AddPriceVersion::class)->execute($this->owner, 12345, []),
            fn () => app(LinkCostumeCompatibility::class)->execute($this->owner, 12345, 67890),
            fn () => app(BlockResource::class)->execute($this->owner, 12345, []),
            fn () => app(ReleaseBlock::class)->execute($this->owner, 12345, 67890, ''),
            fn () => app(SaveEventDraft::class)->execute($this->owner, []),
        ];
        foreach ([['active' => false], ['role' => 'staff'], ['two_factor_secret' => null], ['two_factor_confirmed_at' => null]] as $revocation) {
            User::whereKey($this->owner->id)->update([...['active' => true, 'role' => 'owner_admin',
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()], ...$revocation]);
            foreach ($calls as $call) {
                try {
                    $call();
                    $this->fail('A stale User must not authorize a record write.');
                } catch (AuthorizationException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        }
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_direct_and_http_validation_share_field_errors(): void
    {
        $input = $this->equipment(['seats' => 0, 'kind' => 'vehicle', 'equipment_kind' => null]);
        try {
            app(SaveResource::class)->execute($this->owner, $input);
            $this->fail('Invalid direct input was accepted.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        $this->actingAs($this->owner)->postJson('/admin/api/resources', $input)->assertUnprocessable()->assertJsonPath('errors', $errors);
        $this->assertDatabaseCount('resources', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_audit_failure_rolls_back_the_business_write(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_entries`')) {
                throw new RuntimeException('Fixture audit failure');
            }
        });
        try {
            app(SaveCustomer::class)->execute($this->owner, ['name' => 'Must roll back', 'locale' => 'es']);
            $this->fail('Audit failure must escape the service.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fixture audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_resource_and_variant_updates_reject_stale_versions_without_second_audit(): void
    {
        $resource = app(SaveResource::class)->execute($this->owner, $this->equipment());
        $variant = app(SaveVariant::class)->execute($this->owner, $this->variant());
        foreach ([[SaveResource::class, $resource, $this->equipment()], [SaveVariant::class, $variant, $this->variant()]] as [$service, $record, $input]) {
            $input['expected_version'] = $record->version;
            $updated = app($service)->execute($this->owner, $input, $record->id);
            $this->assertSame($record->version + 1, $updated->version);
            try {
                app($service)->execute($this->owner, $input, $record->id);
                $this->fail('Stale update was accepted.');
            } catch (RecordWriteRejected $e) {
                $this->assertSame(409, $e->status);
                $this->assertSame(409, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('audit_entries', 4);
    }

    public function test_character_update_requires_the_displayed_version(): void
    {
        $character = app(SaveCharacter::class)->execute($this->owner, ['name' => 'Fixture character', 'active' => true]);
        $updated = app(SaveCharacter::class)->execute($this->owner, ['name' => 'Renamed fixture', 'active' => false, 'expected_version' => $character->version], $character->id);
        $this->assertSame('Renamed fixture', $updated->name);
        $this->assertFalse($updated->active);
        $this->assertDatabaseHas('audit_entries', ['action' => 'characters.updated', 'actor_id' => $this->owner->id]);
    }

    public function test_prices_compatibility_and_release_keep_explicit_actor_without_a_session(): void
    {
        $this->assertNull(auth()->user());
        $variant = app(SaveVariant::class)->execute($this->owner, $this->variant());
        $price = app(AddPriceVersion::class)->execute($this->owner, $variant->id, ['amount_cents' => 12345, 'currency' => 'USD', 'created_by' => 999]);
        $this->assertSame($this->owner->id, $price->created_by);
        $character = app(SaveCharacter::class)->execute($this->owner, ['name' => 'Fixture', 'active' => true]);
        $staff = app(SaveResource::class)->execute($this->owner, $this->equipment(['code' => 'STAFF', 'kind' => 'staff', 'skills' => ['performer'], 'equipment_kind' => null]));
        $costume = app(SaveResource::class)->execute($this->owner, $this->equipment(['code' => 'COSTUME', 'kind' => 'costume', 'character_id' => $character->id, 'equipment_kind' => null]));
        $this->assertSame(['staff_id' => $staff->id, 'costume_id' => $costume->id], app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id));
        $block = app(BlockResource::class)->execute($this->owner, $staff->id, ['starts_at' => '2027-06-12T16:00:00-05:00', 'ends_at' => '2027-06-12T17:00:00-05:00', 'reason' => 'Fixture absence']);
        $released = app(ReleaseBlock::class)->execute($this->owner, $staff->id, $block->id, '  Fixture absence resolved  ');
        $this->assertSame($this->owner->id, $released->released_by);
        $this->assertSame('Fixture absence resolved', $released->release_reason);
        $count = DB::table('audit_entries')->count();
        app(ReleaseBlock::class)->execute($this->owner, $staff->id, $block->id, 'Fixture absence resolved');
        $this->assertDatabaseCount('audit_entries', $count);
        try {
            app(ReleaseBlock::class)->execute($this->owner, $staff->id, $block->id, 'Different fixture reason');
            $this->fail('Changed release reason was accepted.');
        } catch (RecordWriteRejected $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(0, DB::table('audit_entries')->where('actor_id', '!=', $this->owner->id)->count());
    }

    public function test_failed_draft_update_restores_services_version_and_audit(): void
    {
        $variant = app(SaveVariant::class)->execute($this->owner, $this->variant());
        $price = app(AddPriceVersion::class)->execute($this->owner, $variant->id, ['amount_cents' => 12345, 'currency' => 'USD']);
        $input = $this->draft($price->id);
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        $this->assertTrue($event->relationLoaded('services'));
        $this->assertNull($event->travel_fee_cents);
        $this->assertSame('00501', $event->postal_code);
        $this->assertSame('21:00', $event->starts_at->format('H:i'));
        $this->assertDatabaseCount('resource_reservations', 0);
        $snapshot = $event->services->first()->getAttributes();
        $variant->status = 'retired';
        $variant->save();
        $count = DB::table('audit_entries')->count();
        try {
            app(SaveEventDraft::class)->execute($this->owner, [...$input, 'title' => 'Must roll back', 'expected_version' => $event->version], $event->id);
            $this->fail('A retired variant was accepted.');
        } catch (RecordWriteRejected $e) {
            $this->assertSame(422, $e->status);
        }
        $fresh = $event->fresh('services');
        $this->assertSame($event->title, $fresh->title);
        $this->assertSame($event->version, $fresh->version);
        $this->assertSame($snapshot, $fresh->services->first()->getAttributes());
        $this->assertDatabaseCount('audit_entries', $count);
    }

    public function test_block_release_cannot_target_a_different_unit(): void
    {
        $a = app(SaveResource::class)->execute($this->owner, $this->equipment());
        $b = app(SaveResource::class)->execute($this->owner, $this->equipment(['code' => 'OTHER']));
        $block = app(BlockResource::class)->execute($this->owner, $a->id, ['starts_at' => '2027-06-12T16:00:00-05:00', 'ends_at' => '2027-06-12T17:00:00-05:00', 'reason' => 'Fixture absence']);
        try {
            app(ReleaseBlock::class)->execute($this->owner, $b->id, $block->id, 'Fixture reason for release');
            $this->fail('Block released from another unit.');
        } catch (ModelNotFoundException $e) {
            $this->assertNull($block->fresh()->released_at);
        }
        $this->assertDatabaseCount('audit_entries', 3);
    }
}
