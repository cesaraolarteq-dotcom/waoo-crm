<?php
namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Mensajes automáticos (owner 2026-11-23): one sandboxed preview of the selected message; nothing is sent by opening it.
class EmailTemplatesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_the_journey_with_one_sandboxed_preview(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = $this->get('/admin/correos')->assertOk()
            ->assertSee('Mensajes automáticos')->assertSee('Recibimos tu solicitud, María')
            ->assertSee('Gracias + reseña')->assertSee('El día después de la fiesta')->getContent();
        $this->assertSame(1, substr_count($html, '<iframe'));
        $this->assertSame(1, substr_count($html, ' sandbox '));
        $this->assertStringNotContainsString('srcdoc="<!doctype', $html); // preview HTML is attribute-escaped
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/correos')->assertRedirect();
    }
}
