<?php
namespace Tests\Feature;

use Tests\TestCase;

// STAFF-01 staff page design preview (local/testing only). Owner 2026-10-18: read-only "my week" behind one personal
// link that does not change; no accept/decline and no availability. No money, no teammates' phones, no external assets.
class StaffPortalPreviewTest extends TestCase
{
    public function test_week_renders_in_both_languages_without_money_or_external_assets(): void
    {
        foreach (['semana', 'vacia'] as $screen) {
            foreach (['es', 'en'] as $lang) {
                $html = $this->get("/dev/staff/{$screen}?lang={$lang}")->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                    ->assertHeader('Cache-Control', 'no-store, private')->getContent();
                $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', $html, "money on {$screen}/{$lang}");
                $this->assertDoesNotMatchRegularExpression('/(src|href)="https?:\/\/(?!localhost|maps\.google\.com)/', $html, "external asset on {$screen}/{$lang}");
            }
        }
    }

    public function test_week_shows_each_party_with_logistics_team_and_driver_but_no_answers_or_availability(): void
    {
        $this->get('/dev/staff/semana?lang=es')->assertSee('Tus fiestas de esta semana')->assertSee('Semana del lun. 19 oct. al dom. 25 oct.')
            ->assertSee('123 Sample St, Katy, TX 77494')->assertSee('456 Example Ave')->assertSee('Abrir en Maps')
            ->assertSee('Tú manejas en esta fiesta')->assertSee('Carlos (maneja)')->assertSee('Personaje de ejemplo (Gala)')
            ->assertSee('Aparece el día de la fiesta')->assertSee('guárdalo en tu celular')
            ->assertDontSee('Sí, voy')->assertDontSee('No puedo ir')->assertDontSee('disponibilidad')->assertDontSee('type="checkbox"', false);
        $this->get('/dev/staff/vacia?lang=en')->assertSee('You have no parties assigned this week');
    }

    public function test_preview_is_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/dev/staff/semana')->assertNotFound();
    }
}
