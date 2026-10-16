<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\{Character, Customer, User};
use App\Services\Records\{SaveCharacter, SaveCustomer};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class RecordVersionsTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public static function records(): array
    {
        return [
            'customers' => ['customers', SaveCustomer::class, Customer::class, EditCustomer::class, ['name' => 'Fixture Client', 'locale' => 'es']],
            'characters' => ['characters', SaveCharacter::class, Character::class, EditCharacter::class, ['name' => 'Fixture Character', 'active' => true]],
        ];
    }

    #[DataProvider('records')]
    public function test_api_requires_the_displayed_version_and_rejects_stale_updates(string $entity, string $service, string $model, string $page, array $input): void
    {
        $created = $this->postJson('/admin/api/'.$entity, [...$input, 'version' => 999])->assertCreated()->assertJsonPath('version', 1);
        $id = $created->json('id');
        $this->getJson('/admin/api/'.$entity)->assertOk()->assertJsonPath('data.0.version', 1);
        $this->putJson('/admin/api/'.$entity.'/'.$id, $input)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/admin/api/'.$entity.'/'.$id, [...$input, 'name' => 'First winner', 'expected_version' => 1])->assertOk()->assertJsonPath('version', 2);
        $auditCount = DB::table('audit_entries')->count();
        $this->putJson('/admin/api/'.$entity.'/'.$id, [...$input, 'name' => 'Stale loser', 'expected_version' => 1, 'version' => 2])->assertConflict();
        $this->assertSame('First winner', $model::findOrFail($id)->name);
        $this->assertSame($auditCount, DB::table('audit_entries')->count());
        $this->putJson('/admin/api/'.$entity.'/'.$id, [...$input, 'name' => 'Reloaded edit', 'expected_version' => '2', 'version' => 999])->assertOk()->assertJsonPath('version', 3);
    }

    #[DataProvider('records')]
    public function test_direct_services_require_versions_and_rollback_version_on_audit_failure(string $entity, string $service, string $model, string $page, array $input): void
    {
        $record = app($service)->execute($this->owner, $input);
        try {
            app($service)->execute($this->owner, $input, $record->id);
            $this->fail('Update without expected_version was accepted.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('expected_version', $e->errors());
        }
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) {
            if (str_starts_with(strtolower($query->sql), 'insert into `audit_entries`')) throw new \RuntimeException('Fixture audit failure');
        });
        try {
            app($service)->execute($this->owner, [...$input, 'name' => 'Must roll back', 'expected_version' => 1], $record->id);
            $this->fail('Audit failure did not escape.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fixture audit failure', $e->getMessage());
        }
        $this->assertSame(1, $record->fresh()->version);
        $this->assertSame($input['name'], $record->fresh()->name);
        $this->assertSame(1, DB::table('audit_entries')->count());
    }

    #[DataProvider('records')]
    public function test_two_real_admin_processes_cannot_overwrite_the_same_version(string $entity, string $service, string $model, string $page, array $input): void
    {
        $record = app($service)->execute($this->owner, $input);
        $other = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $workers = [];
        foreach ([$this->owner, $other] as $i => $actor) {
            $workers[] = function () use ($service, $record, $actor, $input, $i) {
                try {
                    app($service)->execute($actor, [...$input, 'name' => 'Fixture editor '.$i, 'expected_version' => 1], $record->id);
                    return 'saved';
                } catch (RecordWriteRejected $e) {
                    return $e->status === 409 ? 'conflict' : 'unexpected';
                }
            };
        }
        $results = ConcurrentWorkers::run($workers); sort($results);
        $this->assertSame(['conflict', 'saved'], $results);
        $this->assertSame(2, $record->fresh()->version);
        $this->assertSame(1, DB::table('audit_entries')->where('action', $entity.'.updated')->count());
        $saved = app($service)->execute($other, [...$input, 'name' => 'Explicitly reloaded', 'expected_version' => 2], $record->id);
        $this->assertSame(3, $saved->version);
        $this->assertSame($other->id, (int) DB::table('audit_entries')->where('action', $entity.'.updated')->orderByDesc('id')->value('actor_id'));
    }

    #[DataProvider('records')]
    public function test_real_edit_form_keeps_its_stale_version_and_unsaved_inputs(string $entity, string $service, string $model, string $page, array $input): void
    {
        $record = app($service)->execute($this->owner, $input);
        $editor = Livewire::test($page, ['record' => $record->id])->assertSet('displayedVersion', 1);
        app($service)->execute($this->owner, [...$input, 'name' => 'Other editor saved', 'expected_version' => 1], $record->id);
        $before = DB::table('audit_entries')->count();
        $editor->fillForm(['name' => 'Unsaved local edit'])->call('save')->assertNotified('No se guardó')->assertNoRedirect()
            ->assertSet('displayedVersion', 1)->assertFormSet(['name' => 'Unsaved local edit']);
        $this->assertSame('Other editor saved', $record->fresh()->name);
        $this->assertSame($before, DB::table('audit_entries')->count());
        $freshEditor = Livewire::test($page, ['record' => $record->id])->assertSet('displayedVersion', 2);
        $freshEditor->fillForm(['name' => 'Reviewed fresh edit'])->call('save')->assertHasNoFormErrors()->assertSet('displayedVersion', 3);
        $freshEditor->fillForm(['name' => 'Second fresh edit'])->call('save')->assertHasNoFormErrors()->assertSet('displayedVersion', 4);
        $this->assertSame('Second fresh edit', $record->fresh()->name);
    }

    #[DataProvider('records')]
    public function test_browser_cannot_replace_the_locked_record_version(string $entity, string $service, string $model, string $page, array $input): void
    {
        $record = app($service)->execute($this->owner, $input);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test($page, ['record' => $record->id])->set('displayedVersion', 999);
    }
}
