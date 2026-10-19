<?php
namespace Tests\Feature;

use App\Filament\Pages\StaffLinks;
use App\Filament\Support\StaffLinksSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

// STAFF-01 v2 owner screen before Codex's services exist: labelled sample, no token anywhere, every action refused
// without writing. Wiring to the real services keeps the same keys (docs/STAFF-01-API.md).
class StaffLinksPageTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
    }

    public function test_sample_lists_people_with_link_state_and_the_right_buttons(): void
    {
        $this->assertFalse(StaffLinksSource::live());
        Livewire::test(StaffLinks::class)->assertSee('Vista previa con personas de ejemplo')
            ->assertSee('Lucía (ejemplo)')->assertSee('Enlace activo')->assertSee('Lo abrió')
            ->assertSee('Carlos (ejemplo)')->assertSee('Todavía no lo abre')
            ->assertSee('Andrea (ejemplo)')->assertSee('Sin enlace')
            ->assertSee('Copiar enlace')->assertSee('Cambiar enlace')->assertSee('Apagar')->assertSee('Crear enlace')
            ->assertDontSee('token')->assertDontSee('Aceptó')->assertDontSee('disponibilidad');
    }

    public function test_actions_are_refused_in_sample_mode_and_show_no_link(): void
    {
        Livewire::test(StaffLinks::class)->callAction('reveal', arguments: ['staff' => 1])
            ->assertNotified('No se hizo el cambio')->assertSet('newLink', null)
            ->callAction('issue', arguments: ['staff' => 3])->assertSet('newLink', null);
    }

    public function test_message_tells_the_link_does_not_change_in_both_languages(): void
    {
        $this->assertStringContainsString('siempre es el mismo', StaffLinksSource::message('es', 'Lucía Pérez', 'https://x.test/staff#t'));
        $this->assertStringStartsWith('¡Hola Lucía!', StaffLinksSource::message('es', 'Lucía Pérez', 'u'));
        $this->assertStringContainsString('it stays the same', StaffLinksSource::message('en', 'Carlos', 'u'));
    }

    public function test_page_is_registered_in_the_panel(): void
    {
        $this->get('/admin/staff-enlaces')->assertOk()->assertSee('Enlaces del staff');
    }

    public function test_live_owner_flow_configure_issue_copy_rotate_revoke_with_real_services(): void
    {
        config(['waoo.sandbox_staff' => true]);
        $this->assertTrue(StaffLinksSource::live());
        $res = \App\Models\Resource::create(['code' => 'LNK-1', 'name' => 'Fixture Person', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host']]);
        $page = Livewire::test(StaffLinks::class)->assertDontSee('Vista previa con personas de ejemplo')->assertSee('Staff sin configurar (1)')->assertSee('Fixture Person')
            ->callAction('configure', ['locale' => 'en', 'phone' => '(713) 555-0100', 'active' => true], ['resource' => $res->id])->assertNotified('Datos guardados')
            ->assertDontSee('Staff sin configurar')->assertSee('Sin enlace');
        $staffId = \App\Models\StaffIdentity::where('resource_id', $res->id)->value('id');
        $page->callAction('issue', arguments: ['staff' => $staffId])->assertSee('Enlace de Fixture Person')->assertSee('/staff#')
            ->assertSee('Abrir WhatsApp con el mensaje')->assertSee('Enlace activo');
        $this->assertSame('(713) 555-0100', $page->get('newLink')['phone'], 'owner-only phone from the envelope prefills WhatsApp');
        $first = $page->get('newLink')['url'];
        $page->callAction('reveal', arguments: ['staff' => $staffId]);
        $this->assertSame($first, $page->get('newLink')['url'], 'copy returns the same link');
        $page->callAction('rotate', arguments: ['staff' => $staffId]);
        $this->assertNotSame($first, $page->get('newLink')['url'], 'rotate creates a new link');
        $page->callAction('revoke', arguments: ['staff' => $staffId])->assertNotified('Enlace apagado')->assertSet('newLink', null)->assertSee('Sin enlace');
        // Editing without a phone keeps the stored one.
        $page->callAction('configure', ['locale' => 'es', 'phone' => '', 'active' => true], ['staff' => $staffId])->assertNotified('Datos guardados');
        $this->assertSame('(713) 555-0100', \App\Models\StaffIdentity::find($staffId)->phone);
    }

    public function test_closed_outside_local_and_testing_until_the_services_exist(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/admin/staff-enlaces')->assertForbidden();
    }
}
