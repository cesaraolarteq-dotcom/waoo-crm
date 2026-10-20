<?php
namespace Tests\Feature;

use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ConfirmCapacity;
use App\Models\{Character, PriceVersion, Resource, ServiceVariant, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// Owner screens over Codex's character taxonomy + generic wardrobe (CHARACTER-GROUP-API V2, OUTFIT-01-GENERIC-UNITS):
// edit category/show/audience, pickers grouped "Categoría · Show" with a girls/boys filter that never drops the
// current selection, and the optional wardrobe for generic heads only.
class CharacterTaxonomyScreensTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_owner_edits_category_show_and_audience(): void
    {
        $c = Character::create(['name' => 'Fixture Princess', 'active' => true]);
        Livewire::test(EditCharacter::class, ['record' => $c->id])
            ->fillForm(['category' => ' Disney ', 'group' => 'Princesas Disney', 'audience' => 'girls'])->call('save')->assertHasNoFormErrors();
        $c->refresh();
        $this->assertSame(['Disney', 'Princesas Disney', 'girls', 2], [$c->category, $c->group, $c->audience, $c->version]);
        Livewire::test(EditCharacter::class, ['record' => $c->id])->fillForm(['category' => '', 'group' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertSame([null, null], [$c->fresh()->category, $c->fresh()->group]);
    }

    public function test_picker_groups_by_category_and_show_and_filters_by_audience_keeping_the_selection(): void
    {
        $mk = fn ($n, $cat, $g, $a) => Character::create(['name' => $n, 'active' => true, 'category' => $cat, 'group' => $g, 'audience' => $a]);
        $elsa = $mk('Elsa', 'Disney', 'Princesas Disney', 'girls'); $mickey = $mk('Mickey', 'Disney', 'Mickey y sus amigos', 'all');
        $spidey = $mk('Spidey', 'Superhéroes', 'Spidey y sus amigos', 'boys'); $teddy = Character::create(['name' => 'Teddy', 'active' => true]);
        $v = ServiceVariant::create(['name' => 'Fixture Star', 'status' => 'active', 'duration_minutes' => 30, 'requirements' => [], 'character_quantity' => 1,
            'character_options' => collect([$elsa, $mickey, $spidey, $teddy])->map(fn ($c) => ['character_id' => $c->id, 'requirements' => [['kind' => 'costume', 'quantity' => 1, 'character_id' => $c->id]]])->all()]);
        $price = PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => 27500, 'currency' => 'USD', 'created_by' => auth()->id()]);
        $all = EventResource::characterOptions((string) $price->id);
        $this->assertSame(['Disney · Mickey y sus amigos', 'Disney · Princesas Disney', 'Superhéroes · Spidey y sus amigos', 'Sin grupo'], array_keys($all));
        $girls = EventResource::characterOptions((string) $price->id, 'girls');
        $this->assertSame([$mickey->id, $elsa->id, $teddy->id], array_merge(...array_map('array_keys', array_values($girls))), 'girls = girls + all');
        $boys = EventResource::characterOptions((string) $price->id, 'boys', $elsa->id);
        $this->assertContains($elsa->id, array_merge(...array_map('array_keys', array_values($boys))), 'the selected character never disappears');
        $this->assertContains($spidey->id, array_merge(...array_map('array_keys', array_values($boys))));
    }

    public function test_wardrobe_rows_only_for_generic_heads_with_outfits_and_only_chosen_rows_are_sent(): void
    {
        $mickey = Character::create(['name' => 'Mickey', 'active' => true, 'outfits' => ['Black suit', 'Carreras']]);
        $ariel = Character::create(['name' => 'Ariel', 'active' => true, 'outfits' => ['Sirena', 'Vestido']]);
        $head1 = Resource::create(['code' => 'TRAJE-MICKEY-1', 'name' => 'Traje Mickey 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $head2 = Resource::create(['code' => 'TRAJE-MICKEY-2', 'name' => 'Traje Mickey 2', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id, 'skills' => []]);
        $fixed = Resource::create(['code' => 'TRAJE-ARIEL-SIRENA-1', 'name' => 'Traje Ariel Sirena 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $ariel->id, 'outfit' => 'Sirena', 'skills' => []]);
        $rows = ConfirmCapacity::wardrobeRows([$head1->id, $head2->id, $fixed->id], [['resource_id' => $head2->id, 'outfit' => 'Carreras']]);
        $this->assertSame([$head1->id, $head2->id], array_column($rows, 'resource_id'), 'fixed-version units get no control');
        $this->assertSame([null, 'Carreras'], array_column($rows, 'outfit'), 'no preselection; earlier choice kept');
        $this->assertSame([['resource_id' => $head2->id, 'outfit' => 'Carreras', 'expected_character_version' => $mickey->fresh()->version]], ConfirmCapacity::costumeOutfits($rows));
    }
}
