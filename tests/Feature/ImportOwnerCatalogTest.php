<?php
namespace Tests\Feature;

use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// The owner's catalog export becomes CRM records through the audited services: exact prices, crew rules
// (head character = a host dresses, wig = one more person, Waoo face painter is one of the team), re-runnable.
class ImportOwnerCatalogTest extends TestCase
{
    use DatabaseMigrations;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['email' => 'owner@example.test', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->file = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($this->file, json_encode([
            'characters' => ['mickey' => ['name' => 'Mickey', 'costumes' => 2, 'wig' => false], 'rumi' => ['name' => 'Rumi', 'costumes' => 1, 'wig' => true]],
            'staff' => ['s1' => ['name' => 'Ana Ficticia', 'roles' => ['host', 'face_painter', 'character'], 'rate' => 25],
                's2' => ['name' => 'Bea Ficticia', 'roles' => ['character_wig'], 'rates' => ['character_wig' => 80], 'skills' => ['glitter', 'car'], 'active' => true],
                's3' => ['name' => 'Ex Staff', 'roles' => ['host'], 'active' => false]],
            'packages' => [
                'star' => ['name' => 'Estrella (Star)', 'group' => 'Paquetes con personaje', 'price' => 275, 'duration_min' => 30, 'characters_included' => 1,
                    'crew' => ['character' => 1, 'host' => 1, 'driver' => 0], 'gear' => ['bubbles' => 1], 'description' => 'Entrada y bienvenida', 'ready' => true],
                'waoo' => ['name' => 'Waoo', 'group' => 'Paquetes con personaje', 'price' => 800, 'duration_min' => 180, 'characters_included' => 1,
                    'crew' => ['character' => 0, 'entertainer' => 1, 'host' => 2], 'gear' => ['face_kit' => 1, 'speaker' => 1],
                    'description' => 'This three-hour experience starts with face painting and the games, then the character and the show for each guest.', 'ready' => true],
                'sparklers' => ['name' => '2 chisperos', 'group' => 'Hora loca', 'price' => 300, 'duration_min' => 120, 'characters_included' => 0,
                    'crew' => ['driver' => 1, 'operator' => 1], 'gear' => ['sparkler' => 2], 'description' => '', 'ready' => true],
                'robot' => ['name' => 'Pistola de humo + Robot alto', 'group' => 'Hora loca', 'price' => 700, 'duration_min' => 60, 'characters_included' => 1,
                    'crew' => ['character' => 1, 'operator' => 1], 'gear' => ['robot_tall' => 1], 'description' => '', 'ready' => false],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_imports_exact_prices_and_crew_rules_and_is_rerunnable(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test', '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ServiceVariant::count(), 'Dry run writes nothing.');

        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame(['Mickey', 'Rumi'], Character::orderBy('name')->pluck('name')->all());
        $this->assertSame(3, Resource::where('kind', 'costume')->count());
        $this->assertSame(['host', 'face_painter', 'performer'], Resource::where('code', 'STAFF-ANA-FICTICIA')->value('skills'));
        $this->assertSame(['performer_wig', 'performer', 'glitter', 'car'], Resource::where('code', 'STAFF-BEA-FICTICIA')->value('skills'));
        $this->assertFalse(Resource::where('code', 'STAFF-EX-STAFF')->exists(), 'Inactive staff is not imported.');

        $star = ServiceVariant::where('name', 'Estrella (Star)')->sole();
        $this->assertSame('active', $star->status);
        $this->assertSame([['kind' => 'staff', 'quantity' => 1, 'skill' => 'host'], ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'bubbles']], $star->requirements);
        $this->assertNull($star->long_description, 'Spanish text never becomes the English contract description.');
        $this->assertSame(27500, PriceVersion::where('service_variant_id', $star->id)->sole()->amount_cents);

        $waoo = ServiceVariant::where('name', 'Waoo')->sole();
        $this->assertSame([['kind' => 'staff', 'quantity' => 1, 'skill' => 'entertainer'], ['kind' => 'staff', 'quantity' => 1, 'skill' => 'face_painter'],
            ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'face_kit'], ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']], $waoo->requirements);
        $rumi = Character::where('name', 'Rumi')->value('id');
        $byChar = collect($waoo->character_options)->keyBy('character_id');
        $this->assertCount(2, $byChar[Character::where('name', 'Mickey')->value('id')]['requirements'], 'Head character: a host dresses (3 people).');
        $this->assertCount(3, $byChar[$rumi]['requirements'], 'Wig character: one more person (4 people).');
        $this->assertStringStartsWith('This three-hour', $waoo->long_description);

        $robot = ServiceVariant::where('name', 'Pistola de humo + Robot alto')->sole();
        $this->assertSame('draft', $robot->status);
        $this->assertSame(0, $robot->character_quantity);
        $this->assertSame([['kind' => 'staff', 'quantity' => 2, 'skill' => 'operator'], ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'robot_suit']], $robot->requirements, 'Tall and low robots share the same suits.');

        // Re-run: nothing duplicated, no new price version, no version/audit churn when nothing changed.
        $versions = ServiceVariant::orderBy('id')->pluck('version')->all();
        $audits = \Illuminate\Support\Facades\DB::table('audit_entries')->count();
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame($versions, ServiceVariant::orderBy('id')->pluck('version')->all());
        $this->assertSame($audits, \Illuminate\Support\Facades\DB::table('audit_entries')->count());
        $this->assertSame(4, ServiceVariant::count());
        $this->assertSame(4, PriceVersion::count());
        $this->assertSame(3, Resource::where('kind', 'costume')->count());
    }

    public function test_character_groups_are_optional_audited_versioned_and_rerunnable(): void
    {
        $actor = User::sole();
        $save = app(\App\Services\Records\SaveCharacter::class);
        $character = $save->execute($actor, ['name' => 'Synthetic Mouse', 'active' => true]);
        $this->assertNull($character->group);
        $export = fn (array $extra) => file_put_contents($this->file, json_encode(['packages' => [], 'characters' => [['name' => 'Synthetic Mouse', ...$extra]]]));
        $run = fn (bool $dry = false) => $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => $actor->email, '--dry-run' => $dry])->assertSuccessful();
        $export(['category' => ' Disney ', 'audience' => 'girls', 'group' => '  Synthetic Show  ', 'outfits' => 'Classic 1']);
        $run(true);
        $this->assertNull($character->fresh()->group);
        $run();
        $character->refresh();
        $this->assertSame('Synthetic Show', $character->group);
        $this->assertSame('Disney', $character->category);
        $this->assertSame('girls', $character->audience);
        $this->assertSame(['Classic'], $character->outfits);
        $this->assertSame(2, $character->version, 'One save for group plus outfits.');
        $audits = \Illuminate\Support\Facades\DB::table('audit_entries')->count();
        $run();
        $this->assertSame(2, $character->fresh()->version);
        $this->assertSame($audits, \Illuminate\Support\Facades\DB::table('audit_entries')->count());
        $export([]); $run();
        $this->assertSame('Synthetic Show', $character->fresh()->group, 'Missing key preserves the group.');
        $export(['group' => '0']); $run();
        $this->assertSame('0', $character->fresh()->group);
        $export(['group' => '  ']); $run();
        $this->assertNull($character->fresh()->group);
        $this->assertSame('girls', $character->fresh()->audience, 'Omitted audience is preserved.');
        $export(['category' => '', 'audience' => null]); $run();
        $this->assertNull($character->fresh()->category);
        $this->assertSame('all', $character->fresh()->audience);
        $version = $character->fresh()->version;
        try {
            $save->execute($actor, ['name' => $character->name, 'active' => true, 'group' => 'Stale', 'expected_version' => 2], $character->id);
            $this->fail('Stale version must be refused.');
        } catch (\App\Exceptions\RecordWriteRejected $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame($version, $character->fresh()->version);
        $this->assertNull($character->fresh()->group);
    }

    public function test_taxonomy_api_rejects_invalid_audience_and_preserves_metadata_on_partial_edit(): void
    {
        $actor = User::sole(); $this->actingAs($actor);
        $response = $this->postJson('/admin/api/characters', ['name' => 'Synthetic categorized hero', 'active' => true, 'category' => ' Disney ', 'group' => ' Synthetic Show ', 'audience' => 'boys'])->assertCreated()
            ->assertJsonPath('category', 'Disney')->assertJsonPath('group', 'Synthetic Show')->assertJsonPath('audience', 'boys');
        $id = $response->json('id'); $url = '/admin/api/characters/'.$id;
        $base = ['name' => 'Synthetic categorized hero', 'active' => true, 'expected_version' => 1];
        foreach (['girl', ['girls'], true] as $invalid) $this->putJson($url, [...$base, 'audience' => $invalid])->assertUnprocessable();
        $this->putJson($url, [...$base, 'category' => str_repeat('x', 121)])->assertUnprocessable();
        $this->putJson($url, $base)->assertOk()->assertJsonPath('audience', 'boys')->assertJsonPath('category', 'Disney');
        $this->putJson($url, [...$base, 'audience' => 'girls'])->assertConflict();
        $this->putJson($url, [...$base, 'expected_version' => 2, 'category' => null, 'group' => null, 'audience' => null])->assertOk()
            ->assertJsonPath('audience', 'all')->assertJsonPath('category', null)->assertJsonPath('group', null);
    }

    public function test_requires_an_existing_owner(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'nobody@example.test'])->assertFailed();
        $this->assertSame(0, ServiceVariant::count());
    }

    public function test_unknown_equipment_keeps_draft_and_versions_become_units(): void
    {
        file_put_contents($this->file, json_encode([
            'characters' => ['mickey' => ['name' => 'Mickey', 'costumes' => 2, 'wig' => false, 'outfits' => 'Clásico 1, Disneyland 2']],
            'inventory' => ['speaker' => ['kind' => 'speaker', 'label' => 'Bocinas', 'count' => 2], 'robot' => ['kind' => 'robot_suit', 'label' => 'Trajes de robot', 'count' => 2], 'x' => ['kind' => 'laser', 'count' => 1]],
            'packages' => ['x' => ['name' => 'Raro', 'group' => 'Extras', 'price' => 100, 'duration_min' => 60, 'characters_included' => 0,
                'crew' => ['host' => 1], 'gear' => ['laser_show' => 1], 'description' => '', 'ready' => true]],
        ]));
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame('draft', ServiceVariant::where('name', 'Raro')->value('status'), 'Declared equipment that cannot be represented never activates.');
        $mickey = Character::where('name', 'Mickey')->sole();
        $this->assertSame(['Clásico', 'Disneyland'], $mickey->outfits);
        $this->assertSame(['Clásico' => 1, 'Disneyland' => 2], Resource::where('character_id', $mickey->id)->get()->countBy('outfit')->all());
        $this->assertSame(['robot_suit' => 2, 'speaker' => 2], Resource::where('kind', 'equipment')->get()->countBy('equipment_kind')->sortKeys()->all());
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $this->assertSame(4, Resource::where('kind', 'equipment')->count(), 'Re-run adds no units.');
    }

    // Codex review P1: an active imported speaker package cannot reserve without a speaker and competes for the same unit.
    public function test_imported_equipment_is_reserved_by_the_real_engine(): void
    {
        file_put_contents($this->file, json_encode(['packages' => ['m' => ['name' => 'Música', 'group' => 'Fiesta sin personaje', 'price' => 525, 'duration_min' => 60,
            'characters_included' => 0, 'crew' => ['host' => 1], 'gear' => ['speaker' => 1], 'description' => '', 'ready' => true]]]));
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $owner = User::sole();
        $price = PriceVersion::sole();
        $customer = \App\Models\Customer::create(['name' => 'Fixture Client', 'locale' => 'es']);
        $staff = [1, 2, 3];
        foreach ($staff as $n) Resource::create(['code' => "STF-$n", 'name' => "Host $n", 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
        $event = fn () => app(\App\Services\Records\SaveEventDraft::class)->execute($owner, ['customer_id' => $customer->id, 'title' => 'Fixture', 'starts_at' => '2026-12-19T15:00:00-06:00',
            'ends_at' => '2026-12-19T16:00:00-06:00', 'timezone' => 'America/Chicago', 'travel_fee_cents' => 2500, 'address' => 'Fixture address 1', 'postal_code' => '00001', 'setup_minutes' => 15, 'breakdown_minutes' => 15,
            'services' => [['price_version_id' => $price->id]]]);
        $reason = function (callable $f): string { try { $f(); return 'OK'; } catch (\Illuminate\Validation\ValidationException $e) { return json_encode($e->errors(), JSON_UNESCAPED_UNICODE); } catch (\Throwable $e) { return $e->getMessage(); } };
        $confirm = fn ($e, array $ids, string $ref) => app(\App\Services\Records\ConfirmCapacity::class)->execute($owner, $e->id, ['expected_version' => $e->fresh()->version, 'resource_ids' => $ids,
            'payment' => ['method' => 'external_zelle', 'reference' => $ref, 'amount_cents' => 27500, 'received_at' => now()->subHour()->toIso8601String()],
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture origin and return note.', 'transfers' => []]]);
        $a = $event();
        $why = $reason(fn () => $confirm($a, [Resource::where('code', 'STF-1')->value('id')], 'REF-A0'));
        $this->assertNotSame('OK', $why, 'Reserved without a speaker.');
        $this->assertStringContainsString('No hay una combinación', $why, 'Rejected for capacity, not for missing event data.');
        $speaker = Resource::create(['code' => 'SPK-1', 'name' => 'Bocina 1', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
        $this->assertSame('OK', $reason(fn () => $confirm($a, [Resource::where('code', 'STF-1')->value('id'), $speaker->id], 'REF-A1')));
        $b = $event();
        $this->assertNotSame('OK', $reason(fn () => $confirm($b, [Resource::where('code', 'STF-2')->value('id'), $speaker->id], 'REF-B1')), 'Same speaker reserved twice.');
        $this->assertSame('draft', $b->fresh()->status);
    }
    public function test_unknown_crew_role_never_activates_an_incomplete_package():void
    {
        file_put_contents($this->file,json_encode(['packages'=>['x'=>['name'=>'Synthetic unknown role','group'=>'Extras','price'=>100,'duration_min'=>60,'characters_included'=>0,'crew'=>['new_unknown_role'=>1],'gear'=>['speaker'=>1],'ready'=>true]]]));
        $this->artisan('waoo:import-catalog',['file'=>$this->file,'--actor'=>'owner@example.test'])->assertSuccessful();
        $this->assertSame('draft',ServiceVariant::where('name','Synthetic unknown role')->sole()->status);
    }
    public function test_zero_outfit_is_preserved_and_second_import_has_no_version_or_audit_churn():void
    {
        file_put_contents($this->file,json_encode(['characters'=>[['name'=>'Synthetic zero outfit','outfits'=>'0 1','costumes'=>1]],'packages'=>[]]));
        $this->artisan('waoo:import-catalog',['file'=>$this->file,'--actor'=>'owner@example.test'])->assertSuccessful();
        $c=Character::sole();$this->assertSame(['0'],$c->outfits);$this->assertSame('0',Resource::sole()->outfit);
        $version=$c->version;$audits=\Illuminate\Support\Facades\DB::table('audit_entries')->count();
        $this->artisan('waoo:import-catalog',['file'=>$this->file,'--actor'=>'owner@example.test'])->assertSuccessful();
        $this->assertSame($version,$c->fresh()->version);$this->assertSame($audits,\Illuminate\Support\Facades\DB::table('audit_entries')->count());$this->assertDatabaseCount('resources',1);
    }


    public function test_driver_is_one_of_the_team_not_an_extra_person(): void
    {
        $this->artisan('waoo:import-catalog', ['file' => $this->file, '--actor' => 'owner@example.test'])->assertSuccessful();
        $req = ServiceVariant::where('name', '2 chisperos')->sole()->requirements;
        $staff = collect($req)->where('kind', 'staff');
        $this->assertSame(1, $staff->sum('quantity'), 'one operator; whoever drives is that same person');
        $this->assertNull($staff->firstWhere('skill', 'driver'));
    }

    public function test_versions_without_counts_are_outfits_of_the_same_costumes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($file, json_encode(['packages' => [], 'characters' => [
            'm' => ['name' => 'Mickey', 'costumes' => 2, 'wig' => false, 'outfits' => 'Black suit, Disneyland, Carreras'],
            'a' => ['name' => 'Ariel', 'costumes' => 2, 'wig' => true, 'outfits' => 'Sirena 1, Vestido 1']]]));
        $this->artisan('waoo:import-catalog', ['file' => $file, '--actor' => 'owner@example.test'])->assertSuccessful();
        @unlink($file);
        $mickey = Character::where('name', 'Mickey')->sole();
        $this->assertSame(['Black suit', 'Disneyland', 'Carreras'], $mickey->outfits);
        $this->assertSame(2, Resource::where('character_id', $mickey->id)->count(), 'two heads, not one unit per outfit');
        $ariel = Character::where('name', 'Ariel')->sole();
        $this->assertSame(['Sirena', 'Vestido'], Resource::where('character_id', $ariel->id)->orderBy('outfit')->pluck('outfit')->all());
    }
}
