<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, PriceVersion, ServiceVariant, User};
use App\Services\Records\{PackageTimingDefaults, SaveEventDraft, SaveVariant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Queue, Schema, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageTimingTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private ?string $catalogFile = null;
    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); Storage::fake('local');
        $this->owner = User::factory()->create(['name' => 'Ana López', 'email' => 'ana.lopez@example.test', 'role' => 'owner_admin',
            'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
    }
    protected function tearDown(): void
    {
        if ($this->catalogFile) @unlink($this->catalogFile);
        parent::tearDown();
    }
    private function variantInput(array $extra = []): array
    {
        return ['name' => 'Música y juegos', 'status' => 'active', 'duration_minutes' => 120,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'host']], ...$extra];
    }
    private function package(array $extra = []): PriceVersion
    {
        $variant = app(SaveVariant::class)->execute($this->owner, $this->variantInput($extra));
        return PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 30000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
    }
    private function eventInput(array $prices, array $extra = []): array
    {
        $customer = Customer::first() ?? Customer::create(['name' => 'María González', 'email' => 'maria.gonzalez@example.test', 'phone' => '+13465550142', 'locale' => 'es']);
        $start = now('America/Chicago')->addDays(20)->setTime(12, 0)->utc();
        return ['customer_id' => $customer->id, 'title' => 'Cumpleaños de Valentina', 'event_type' => 'birthday',
            'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(2)->toIso8601String(), 'timezone' => 'America/Chicago',
            'address' => '123 Avenida del Parque', 'postal_code' => '77433', 'travel_fee_cents' => 0,
            'services' => array_map(fn ($p) => ['price_version_id' => $p->id], $prices), ...$extra];
    }
    public function test_variant_times_are_nullable_versioned_audited_and_distinguish_zero_and_omission(): void
    {
        $v = app(SaveVariant::class)->execute($this->owner, $this->variantInput());
        $this->assertNull($v->setup_minutes); $this->assertNull($v->breakdown_minutes);
        $this->putJson('/admin/api/variants/'.$v->id, $this->variantInput(['expected_version' => 1,
            'setup_minutes' => '30', 'breakdown_minutes' => 0]))->assertOk()->assertJsonPath('setup_minutes', 30)->assertJsonPath('breakdown_minutes', 0)->assertJsonPath('version', 2);
        $audit = DB::table('audit_entries')->where('action', 'variants.updated')->first();
        $this->assertSame(30, json_decode($audit->after, true)['setup_minutes']);
        $this->putJson('/admin/api/variants/'.$v->id, $this->variantInput(['expected_version' => 1, 'setup_minutes' => 99]))->assertConflict();
        $this->putJson('/admin/api/variants/'.$v->id, $this->variantInput(['expected_version' => 2, 'setup_minutes' => null]))->assertOk()->assertJsonPath('version', 3)->assertJsonPath('setup_minutes', null)->assertJsonPath('breakdown_minutes', 0);
        $this->getJson('/admin/api/variants')->assertOk()->assertJsonPath('data.0.breakdown_minutes', 0);
    }
    public static function invalidTimings(): array { return [[-1], [721], [1.5], ['1.5'], [true], [false], ['tomorrow']]; }
    #[DataProvider('invalidTimings')]
    public function test_invalid_catalog_times_are_not_saved_or_audited(mixed $value): void
    {
        $this->postJson('/admin/api/variants', $this->variantInput(['setup_minutes' => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('service_variants', 0); $this->assertDatabaseCount('audit_entries', 0);
    }
    public function test_package_defaults_use_max_per_field_not_sum_and_keep_unknown_values(): void
    {
        $a = $this->package(['setup_minutes' => 30, 'breakdown_minutes' => 10]);
        $b = $this->package(['name' => 'Burbujas', 'setup_minutes' => 15, 'breakdown_minutes' => 25]);
        $this->assertSame(['setup_minutes' => 30, 'breakdown_minutes' => 25], PackageTimingDefaults::forPriceIds([$a->id, (string) $b->id]));
        $this->assertSame(['setup_minutes' => null, 'breakdown_minutes' => null], PackageTimingDefaults::forPriceIds([]));
        $this->assertSame(['setup_minutes' => null, 'breakdown_minutes' => null], PackageTimingDefaults::forPriceIds([$a->id, 999999]));
        $unknown = $this->package(['name' => 'Pintacaritas', 'breakdown_minutes' => 0]);
        $this->assertSame(['setup_minutes' => null, 'breakdown_minutes' => 10], PackageTimingDefaults::forPriceIds([$a->id, $unknown->id]));
        $event = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$a, $b]));
        $this->assertSame(30, $event->setup_minutes); $this->assertSame(25, $event->breakdown_minutes);
        $unknownEvent = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$a, $unknown]));
        $this->assertNull($unknownEvent->setup_minutes); $this->assertSame(10, $unknownEvent->breakdown_minutes);
    }
    public function test_owner_overrides_and_saved_event_times_survive_catalog_changes_and_draft_edits(): void
    {
        $price = $this->package(['setup_minutes' => 30, 'breakdown_minutes' => 10]);
        $e = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$price], ['setup_minutes' => 0, 'breakdown_minutes' => 45]));
        $this->assertSame(0, $e->setup_minutes); $this->assertSame(45, $e->breakdown_minutes);
        $v = ServiceVariant::findOrFail($price->service_variant_id);
        app(SaveVariant::class)->execute($this->owner, $this->variantInput(['expected_version' => $v->version, 'setup_minutes' => 60, 'breakdown_minutes' => 15]), $v->id);
        $edited = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$price], ['expected_version' => $e->version]), $e->id);
        $this->assertSame(0, $edited->setup_minutes); $this->assertSame(45, $edited->breakdown_minutes);
        $cleared = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$price], ['expected_version' => $edited->version, 'setup_minutes' => null]), $e->id);
        $this->assertNull($cleared->setup_minutes); $this->assertSame(45, $cleared->breakdown_minutes);
        $this->assertSame(1, PriceVersion::count());
    }
    public function test_real_quick_book_uses_package_defaults_without_changing_money_or_claiming_resource_capacity(): void
    {
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true]);
        $price = $this->package(['setup_minutes' => 30, 'breakdown_minutes' => 0]);
        $input = $this->eventInput([$price]); unset($input['customer_id']);
        $booking = app(\App\Services\Booking\QuickBook::class)->execute($this->owner, [...$input,
            'new_customer' => ['name' => 'María González', 'phone' => '+13465550142', 'locale' => 'es'],
            'deposit' => ['method' => 'zelle', 'amount_cents' => 15000, 'reference' => 'MARIA-DEPOSIT-1',
                'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true]], (string) \Illuminate\Support\Str::uuid());
        $this->assertSame(30, $booking->event->setup_minutes); $this->assertSame(0, $booking->event->breakdown_minutes);
        $this->assertSame('draft', $booking->event->status); $this->assertSame(15000, $booking->receipt->amount_cents);
        $this->assertSame(30000, $booking->financial_revision->total_cents); $this->assertDatabaseCount('resource_reservations', 0);
    }
    private function export(array $extra): void
    {
        $this->catalogFile ??= tempnam(sys_get_temp_dir(), 'waoo-synthetic-timing-');
        file_put_contents($this->catalogFile, json_encode(['packages' => ['music' => ['name' => 'Música y juegos', 'price' => 300,
            'duration_min' => 120, 'characters_included' => 0, 'crew' => ['host' => 1], 'gear' => [], 'ready' => true, ...$extra]]]));
    }
    private function import(bool $dry = false): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->catalogFile, '--actor' => $this->owner->email, '--dry-run' => $dry])->assertSuccessful();
    }
    public function test_imported_times_are_validated_in_dry_run_and_repeat_without_version_audit_or_price_churn(): void
    {
        $this->export(['setup_min' => '30', 'breakdown_min' => '0']); $this->import(true); $this->assertDatabaseCount('service_variants', 0);
        $this->import(); $v = ServiceVariant::sole(); $this->assertSame(30, $v->setup_minutes); $this->assertSame(0, $v->breakdown_minutes);
        $audits = DB::table('audit_entries')->count(); $this->import(); $this->assertSame($v->version, $v->fresh()->version); $this->assertSame($audits, DB::table('audit_entries')->count());
        $this->export([]); $this->import(); $this->assertSame(30, $v->fresh()->setup_minutes); $this->assertSame(0, $v->fresh()->breakdown_minutes);
        $this->export(['setup_min' => 40]); $this->import(); $this->assertSame($v->version + 1, $v->fresh()->version); $this->assertSame(40, $v->fresh()->setup_minutes);
        $this->assertSame(0, $v->fresh()->breakdown_minutes); $this->assertDatabaseCount('price_versions', 1);
        $this->export(['setup_min' => '', 'breakdown_min' => null]); $this->import(); $this->assertNull($v->fresh()->setup_minutes); $this->assertNull($v->fresh()->breakdown_minutes);
    }
    public function test_invalid_import_does_not_create_half_a_package_or_price_even_on_dry_run(): void
    {
        foreach ([false, true] as $dry) {
            $this->export(['setup_min' => 1.5]);
            $this->artisan('waoo:import-catalog', ['file' => $this->catalogFile, '--actor' => $this->owner->email, '--dry-run' => $dry])->expectsOutputToContain('NO importado')->assertSuccessful();
            $this->assertDatabaseCount('service_variants', 0); $this->assertDatabaseCount('price_versions', 0);
        }
    }
    public function test_migration_keeps_legacy_packages_unknown_and_existing_event_buffers_untouched(): void
    {
        $p = $this->package(); $e = app(SaveEventDraft::class)->execute($this->owner, $this->eventInput([$p], ['setup_minutes' => 17, 'breakdown_minutes' => 12]));
        $migration = require database_path('migrations/2026_10_20_520000_add_package_operational_minutes.php');
        $migration->down(); $this->assertFalse(Schema::hasColumn('service_variants', 'setup_minutes')); $migration->up();
        $this->assertNull(ServiceVariant::findOrFail($p->service_variant_id)->setup_minutes);
        $this->assertSame(17, $e->fresh()->setup_minutes); $this->assertSame(12, $e->fresh()->breakdown_minutes);
        $this->assertSame(30000, $p->fresh()->amount_cents); $this->assertDatabaseCount('resource_reservations', 0);
    }
    public function test_timing_only_update_and_price_are_atomic_when_the_import_is_invalid(): void
    {
        $this->export(['setup_min' => 30, 'breakdown_min' => 10]); $this->import();
        $v = ServiceVariant::sole(); $auditCount = DB::table('audit_entries')->count();
        $this->export(['setup_min' => 30.5, 'price' => 450]); $this->import();
        $this->assertSame($v->version, $v->fresh()->version); $this->assertSame(30, $v->fresh()->setup_minutes);
        $this->assertSame(30000, PriceVersion::sole()->amount_cents); $this->assertSame($auditCount, DB::table('audit_entries')->count());
    }
    public function test_audit_failure_rolls_back_defaulted_event_and_recipe_together(): void
    {
        $p = $this->package(['setup_minutes' => 30, 'breakdown_minutes' => 10]); $input = $this->eventInput([$p]);
        DB::listen(function ($query) {
            if (str_starts_with(strtolower($query->sql), 'insert into ') && str_contains(strtolower($query->sql), 'audit_entries') && in_array('event.created', $query->bindings, true))
                throw new \RuntimeException('Synthetic audit failure');
        });
        try { app(SaveEventDraft::class)->execute($this->owner, $input); $this->fail('Committed a partially audited default'); }
        catch (\RuntimeException $e) { $this->assertSame('Synthetic audit failure', $e->getMessage()); }
        $this->assertDatabaseCount('events', 0); $this->assertDatabaseCount('event_services', 0); $this->assertDatabaseCount('service_variants', 1);
    }

}
