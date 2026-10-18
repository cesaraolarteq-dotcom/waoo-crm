<?php
namespace Tests\Feature;

use Tests\TestCase;

// STAFF-01 phone portal design preview (local/testing only): three screens ES/EN, no money anywhere,
// address only after accepting, no third-party assets, closed outside local/testing.
class StaffPortalPreviewTest extends TestCase
{
    public function test_every_screen_renders_in_both_languages_without_money_or_external_assets(): void
    {
        foreach (['inicio', 'trabajo', 'disponibilidad'] as $screen) {
            foreach (['es', 'en'] as $lang) {
                $html = $this->get("/dev/staff/{$screen}?lang={$lang}")->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                    ->assertHeader('Cache-Control', 'no-store, private')->getContent();
                $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $html, "money on {$screen}/{$lang}");
                $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:\/\/(?!localhost|maps\.google\.com)/', $html, "external asset on {$screen}/{$lang}");
            }
        }
    }

    public function test_invitation_hides_the_address_and_the_job_sheet_shows_logistics_and_who_drives(): void
    {
        $this->get('/dev/staff/inicio?lang=es')->assertSee('Te invitamos')->assertSee('Sí, voy')->assertSee('No puedo')
            ->assertSee('La dirección exacta aparece cuando aceptas')->assertDontSee('123 Sample St');
        $this->get('/dev/staff/trabajo?lang=es')->assertSee('123 Sample St, Katy, TX 77494')->assertSee('Estacionamiento y acceso')
            ->assertSee('Tú manejas en esta fiesta')->assertSee('Aparece el día de la fiesta')->assertDontSee('Mateo');
        $this->get('/dev/staff/disponibilidad?lang=en')->assertSee('When can you work?')->assertSee('This is not a commitment')->assertSee('Not set')->assertSee('value="14:00"', false);
    }

    public function test_preview_is_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/dev/staff/inicio')->assertNotFound();
    }
}
