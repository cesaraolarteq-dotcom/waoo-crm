<?php
namespace Tests\Feature;

use App\Filament\Pages\CostumeExceptions;
use App\Models\{Character, Resource, User};
use App\Support\CostumeWearers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner 2026-11-03: mark only the costumes that just some people can wear; everything else keeps the head/wig rule. */
class CostumeExceptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_marks_who_can_wear_a_costume_and_goes_back_to_the_normal_rule(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        $mickey = Character::create(['name' => 'Mickey', 'active' => true]);
        DB::table('characters')->where('id', $mickey->id)->update(['costume_style' => 'head']);
        $suit = Resource::create(['code' => 'MICKEY-S', 'name' => 'Mickey talla S', 'kind' => 'costume', 'status' => 'active', 'character_id' => $mickey->id]);
        $sofia = Resource::create(['code' => 'SOFIA', 'name' => 'Sofía Méndez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        $laura = Resource::create(['code' => 'LAURA', 'name' => 'Laura Pérez', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer_wig']]);
        Resource::create(['code' => 'ANA', 'name' => 'Ana Ruiz', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host']]);
        $compat = fn () => DB::table('costume_compatibilities')->get();

        $this->assertTrue(CostumeWearers::allows($suit, $laura, $mickey->fresh(), $compat()));

        Livewire::test(CostumeExceptions::class)->assertSee('Ninguno')
            ->set('costumeId', $suit->id)->assertSee('es de cabeza')->assertSee('Sofía Méndez')->assertSee('Laura Pérez')->assertDontSee('Ana Ruiz')
            ->set('people', [$sofia->id])->call('save')->assertNotified('Guardado')->assertSee('Solo: Sofía Méndez');
        $this->assertTrue(CostumeWearers::allows($suit, $sofia, $mickey->fresh(), $compat()));
        $this->assertFalse(CostumeWearers::allows($suit, $laura, $mickey->fresh(), $compat()));

        Livewire::test(CostumeExceptions::class)->call('pick', $suit->id)->assertSet('people', [$sofia->id])
            ->call('clear')->assertNotified('Vuelve a la regla normal')->assertSee('Ninguno');
        $this->assertTrue(CostumeWearers::allows($suit, $laura, $mickey->fresh(), $compat()));
    }
}
