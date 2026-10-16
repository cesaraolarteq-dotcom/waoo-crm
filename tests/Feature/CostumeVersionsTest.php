<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character, Contract, Customer, Event, PriceVersion, Resource, ServiceVariant, User};
use App\Services\ConfirmBooking;
use App\Services\Documents\{ActivateTemplateVersion, CreateContract, CreateTemplateVersion, ContractPrintContext, IssueContract};
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\{AddPriceVersion, LinkCostumeCompatibility, SaveCharacter, SaveEventDraft, SaveResource, SaveVariant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class CostumeVersionsTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private Character $hero;
    private PriceVersion $price;
    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'app.url' => 'http://localhost']);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        $this->hero = app(SaveCharacter::class)->execute($this->owner, ['name' => 'Fixture hero', 'active' => true, 'outfits' => [' Classic ', 'Racing', '0']]);
        $variant = app(SaveVariant::class)->execute($this->owner, ['name' => 'Fixture costume service', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [],
            'character_quantity' => 1, 'character_options' => [['character_id' => $this->hero->id, 'requirements' => [
                ['kind' => 'costume', 'character_id' => $this->hero->id, 'quantity' => 1], ['kind' => 'staff', 'skill' => 'performer', 'quantity' => 1]]]]]);
        $this->price = app(AddPriceVersion::class)->execute($this->owner, $variant->id, ['amount_cents' => 10000, 'currency' => 'USD']);
        $customer = Customer::create(['name' => 'Fixture Client', 'email' => 'fiction@example.test', 'locale' => 'es']);
        $start = now()->addDays(20)->startOfHour();
        $this->input = ['customer_id' => $customer->id, 'title' => 'Fixture party', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => 'Fixture venue', 'setup_minutes' => 10, 'breakdown_minutes' => 10, 'travel_fee_cents' => 0,
            'services' => [['price_version_id' => $this->price->id, 'characters' => [['character_id' => $this->hero->id, 'quantity' => 1, 'outfit' => 'Racing', 'note' => 'PRIVATE fictional owner note']]]]];
    }
    protected function tearDown(): void
    {
        // Production rollback correctly refuses collapsing two outfit rows. Remove only test selections before DatabaseMigrations rollback.
        DB::table('event_service_characters')->delete();
        parent::tearDown();
    }
    private function unit(?string $outfit, array $extra = []): Resource
    {
        return app(SaveResource::class)->execute($this->owner, [...['code' => 'FIX-'.Str::random(10), 'name' => 'Fixture costume', 'kind' => 'costume', 'status' => 'active', 'character_id' => $this->hero->id, 'skills' => [], 'outfit' => $outfit], ...$extra]);
    }
    private function performer(Resource $costume): Resource
    {
        $staff = Resource::create(['code' => 'STAFF-'.Str::random(10), 'name' => 'Fixture performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        app(LinkCostumeCompatibility::class)->execute($this->owner, $staff->id, $costume->id);
        return $staff;
    }
    private function booking(Event $event, array $ids): array
    {
        return ['expected_version' => $event->version, 'resource_ids' => $ids, 'payment' => ['method' => 'external_zelle', 'reference' => 'fixture-'.$event->id, 'amount_cents' => 5000, 'received_at' => now()->subHour()->toIso8601String()],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture departure and return checked', 'transfers' => []]];
    }
    public function test_catalog_normalization_distinctness_and_active_reference_guard(): void
    {
        $this->assertSame(['Classic','Racing','0'], $this->hero->outfits);
        $this->putJson('/admin/api/characters/'.$this->hero->id, ['expected_version' => 1, 'name' => 'Fixture hero', 'active' => true, 'outfits' => ['Classic',' classic ']])->assertUnprocessable();
        $unit = $this->unit('Racing');
        foreach ([null, ['Classic'], ['Classic','racing']] as $versions) {
            $this->putJson('/admin/api/characters/'.$this->hero->id, ['expected_version' => 1, 'name' => 'Fixture hero', 'active' => true, 'outfits' => $versions])->assertConflict();
        }
        $this->putJson('/admin/api/characters/'.$this->hero->id, ['expected_version' => 1, 'name' => 'Fixture hero renamed', 'active' => true])->assertOk()->assertJsonPath('outfits.1', 'Racing');
        $unit->update(['status' => 'inactive']);
        $this->putJson('/admin/api/characters/'.$this->hero->id, ['expected_version' => 2, 'name' => 'Fixture hero', 'active' => true, 'outfits' => null])->assertOk();
        $this->assertSame('Racing', $unit->fresh()->outfit, 'Retired units keep history.');
    }
    public function test_only_costumes_accept_exact_current_versions_and_character_changes_revalidate(): void
    {
        foreach (['racing','Unknown'] as $outfit) {
            try { $this->unit($outfit); $this->fail('Expected invalid version'); } catch (ValidationException $e) { $this->assertArrayHasKey('outfit', $e->errors()); }
        }
        $this->assertNull($this->unit(null)->outfit);
        $this->postJson('/admin/api/resources', ['code' => 'STAFF-invalid', 'name' => 'Fixture', 'kind' => 'staff', 'status' => 'active', 'skills' => [], 'outfit' => 'Racing'])->assertUnprocessable();
        $unit = $this->unit('Racing');
        $other = Character::create(['name' => 'Other fixture', 'active' => true]);
        $this->putJson('/admin/api/resources/'.$unit->id, [...$unit->only(['code','name','kind','status','skills']), 'character_id' => $other->id, 'expected_version' => 1])->assertUnprocessable();
        $this->assertSame($this->hero->id, (int) $unit->fresh()->character_id);
    }
    public function test_two_versions_are_distinct_rows_and_counts_still_limit_the_included_quantity(): void
    {
        $variant = ServiceVariant::findOrFail($this->price->service_variant_id); $variant->update(['character_quantity' => 2]);
        $input = $this->input;
        $input['services'][0]['characters'][] = ['character_id' => $this->hero->id, 'quantity' => 1, 'outfit' => 'Classic'];
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        $this->assertSame(['Racing','Classic'], $event->services->sole()->characters->pluck('outfit')->all());
        $requirements = collect($event->services->sole()->requirements_snapshot)->where('kind','costume');
        $this->assertSame(['Racing','Classic'], $requirements->pluck('outfit')->all());
        $duplicate = $input; $duplicate['services'][0]['characters'][1]['outfit'] = 'Racing';
        $this->postJson('/admin/api/events', $duplicate)->assertUnprocessable();
        $input['services'][0]['characters'][1]['quantity'] = 2;
        $this->postJson('/admin/api/events', $input)->assertUnprocessable();
        $this->assertDatabaseCount('events', 1);
    }
    public function test_old_draft_update_preserves_choices_and_notes_but_retired_version_rejects_new_save(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        $update = [...$this->input, 'expected_version' => $event->version]; unset($update['services'][0]['characters']);
        $saved = app(SaveEventDraft::class)->execute($this->owner, $update, $event->id);
        $this->assertSame('Racing', $saved->services->sole()->characters->sole()->outfit);
        $this->assertSame('PRIVATE fictional owner note', $saved->services->sole()->characters->sole()->note);
        app(SaveCharacter::class)->execute($this->owner, ['name' => 'Fixture hero', 'active' => true, 'outfits' => ['Classic'], 'expected_version' => 1], $this->hero->id);
        $update['expected_version'] = $saved->version;
        $this->putJson('/admin/api/events/'.$event->id, $update)->assertUnprocessable()->assertJsonValidationErrors('services.0.characters.0.outfit');
        $this->assertSame('Racing', $saved->services->sole()->characters->sole()->fresh()->outfit);
    }
    public function test_generic_and_wrong_outfits_cannot_replace_selected_version(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        foreach ([null,'Classic'] as $outfit) {
            $costume = $this->unit($outfit); $staff = $this->performer($costume);
            $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$costume->id, $staff->id]))->assertConflict()->assertSee('Racing');
        }
        $this->assertDatabaseCount('resource_reservations', 0);
        $costume = $this->unit('Racing'); $staff = $this->performer($costume);
        $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$costume->id, $staff->id]))->assertOk();
        $this->putJson('/admin/api/resources/'.$costume->id, [...$costume->only(['code','name','kind','status','skills','character_id']), 'outfit' => 'Classic', 'expected_version' => 1])->assertConflict();
    }
    public function test_legacy_any_unit_and_string_zero_version_are_not_treated_as_missing(): void
    {
        foreach ([null, '0'] as $outfit) {
            $input = $this->input; $input['services'][0]['characters'][0]['outfit'] = $outfit;
            $event = app(SaveEventDraft::class)->execute($this->owner, $input);
            $unit = $this->unit($outfit === null ? 'Classic' : '0'); $staff = $this->performer($unit);
            $this->postJson('/admin/api/events/'.$event->id.'/confirm-capacity', $this->booking($event, [$unit->id, $staff->id]))->assertOk();
        }
    }
    public function test_two_real_competing_confirmations_reserve_the_only_version_once(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $a = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        $b = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        $costume = $this->unit('Racing'); $staffA = $this->performer($costume); $staffB = $this->performer($costume);
        $worker = fn ($event, $staff) => function () use ($event, $staff, $costume) {
            try { app(ConfirmBooking::class)->execute($event->id, $this->booking($event, [$costume->id, $staff->id]), $this->owner); return 'reserved'; }
            catch (RecordWriteRejected $e) { return $e->status === 409 && str_contains($e->getMessage(), 'Racing') ? 'conflict' : 'unexpected'; }
        };
        $results = ConcurrentWorkers::run([$worker($a,$staffA), $worker($b,$staffB)]); sort($results);
        $this->assertSame(['conflict','reserved'], $results);
        $this->assertDatabaseCount('resource_reservations', 2); $this->assertDatabaseCount('booking_evidence',1);
    }
    public function test_version_removal_and_unit_creation_serialize_on_the_same_character(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) $this->markTestSkipped('Requires MariaDB and pcntl');
        $remove = function () {
            try { app(SaveCharacter::class)->execute($this->owner, ['name' => 'Fixture hero','active' => true,'outfits' => ['Classic'],'expected_version' => 1], $this->hero->id); return 'removed'; }
            catch (RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
        };
        $create = function () {
            try { $this->unit('Racing'); return 'created'; }
            catch (ValidationException $e) { return 'invalid'; }
        };
        $results = ConcurrentWorkers::run([$remove,$create]);
        $this->assertContains($results, [['removed','invalid'],['conflict','created']]);
        $unit = Resource::where('outfit','Racing')->first();
        $this->assertSame($unit !== null, in_array('Racing', $this->hero->fresh()->outfits, true));
    }
    public function test_requested_version_requires_a_costume_recipe_and_owner_notes_are_bounded(): void
    {
        $variant = ServiceVariant::findOrFail($this->price->service_variant_id);
        $options = $variant->character_options; $options[0]['requirements'] = [['kind' => 'staff','skill' => 'performer','quantity' => 1]];
        $variant->update(['character_options' => $options]);
        $this->postJson('/admin/api/events', $this->input)->assertUnprocessable()->assertJsonValidationErrors('services.0.characters.0.outfit');
        $input = $this->input; $input['services'][0]['characters'][0]['outfit'] = null; $input['services'][0]['characters'][0]['note'] = str_repeat('n',201);
        $this->postJson('/admin/api/events', $input)->assertUnprocessable()->assertJsonValidationErrors('services.0.characters.0.note');
        $input['services'][0]['characters'][0]['note'] = null;
        $this->postJson('/admin/api/events', $input)->assertCreated();
    }
    public function test_mariadb_populated_legacy_roundtrip_preserves_selection_and_character(): void
    {
        $input = $this->input; unset($input['services'][0]['characters'][0]['outfit'], $input['services'][0]['characters'][0]['note']);
        $event = app(SaveEventDraft::class)->execute($this->owner, $input);
        $migration = require database_path('migrations/2026_10_16_280000_add_optional_costume_versions.php');
        $migration->down(); $this->assertDatabaseHas('characters',['name' => 'Fixture hero']);
        $this->assertDatabaseHas('event_service_characters',['event_service_id' => $event->services->sole()->id,'character_id' => $this->hero->id]);
        $migration->up(); $this->assertNull($this->hero->fresh()->outfits);
        $this->assertNull($event->services->sole()->characters->sole()->fresh()->outfit);
    }
    public function test_finance_contract_pdf_context_and_both_portal_projections_exclude_owner_note(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->input);
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $event->id, ['expected_version' => $event->version], (string) Str::uuid());
        $this->assertSame('Racing', $finance->lines_snapshot[0]['characters'][0]['outfit']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($finance->lines_snapshot));
        $t = app(CreateTemplateVersion::class)->execute($this->owner, ['terms_html' => '<p>Fixture terms only.</p>', 'change_note' => 'Fixture version test'], (string) Str::uuid());
        app(ActivateTemplateVersion::class)->execute($this->owner, $t->id, ['expected_source_sha256' => $t->source_sha256, 'approval_note' => 'Fixture approval only']);
        $contract = app(CreateContract::class)->execute($this->owner, $event->id, ['expected_version' => $event->version, 'financial_revision_id' => $finance->id, 'template_version_id' => $t->id,
            'client' => ['first_name' => 'Fixture','last_name' => 'Client','email' => 'fiction@example.test','phone' => '555-0100','address' => 'Fixture billing']], (string) Str::uuid());
        $this->assertStringNotContainsString('PRIVATE', json_encode($contract->commercial_snapshot));
        app(IssueContract::class)->execute($this->owner, $contract->id, ['expected_version' => $contract->version], (string) Str::uuid());
        $context = app(ContractPrintContext::class)->build($contract->fresh(), $t);
        $this->assertSame('1 × Fixture hero (Racing)', $context['event']['characters']);
        $grant = app(IssuePortalGrant::class)->execute($this->owner, $event->id, ['customer_id' => $event->customer_id, 'expires_at' => now()->addDays(7)->toIso8601String()]);
        $this->postJson('/portal/api/session', ['token' => $grant->token])->assertOk();
        $this->getJson('/portal/api/event')->assertOk()->assertSee('Fixture hero (Racing)')->assertDontSee('PRIVATE');
        $this->get('/portal')->assertOk()->assertSee('Fixture hero (Racing)')->assertDontSee('PRIVATE');
    }
}
