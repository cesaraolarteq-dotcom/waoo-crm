<?php
namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

// MAIL-01 settings page: read-only list of the 9 client emails with EN/ES previews in sandboxed iframes; nothing is sent.
class EmailTemplatesPageTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_sees_all_drafts_with_sandboxed_previews(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = $this->get('/admin/correos')->assertOk()
            ->assertSee('Correos a los clientes')->assertSee('Texto aprobado')
            ->assertSee('Recibimos tu solicitud, Ana')->assertSee('One week to go, Ana')
            ->assertSee('El día después de la fiesta')->getContent();
        $this->assertSame(14, substr_count($html, '<iframe'));
        $this->assertSame(14, substr_count($html, ' sandbox '));
        $this->assertStringNotContainsString('srcdoc="<!doctype', $html); // preview HTML is attribute-escaped
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin/correos')->assertRedirect();
    }
}
