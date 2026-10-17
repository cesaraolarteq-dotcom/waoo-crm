<?php
namespace Tests\Feature;

use Tests\TestCase;

// QUOTE-01 B client page (design preview, local/testing only): whitelisted fields, both languages, every state,
// accept disabled without consent text, nothing owner-private rendered.
class QuotePortalPreviewTest extends TestCase
{
    public function test_open_quote_shows_lines_discount_total_and_accept_form(): void
    {
        $this->get('/dev/portal/quote?lang=es')->assertOk()->assertSee('Cotización Q-2026-000123 · versión 2')
            ->assertSee('Costo de transporte')->assertSee('Descuento por reservar hoy')->assertSee('−$25', false)->assertSee('$615')
            ->assertSee('Depósito del 50 %: $307.50')->assertSee('id="quote-decide"', false)->assertSee('Aceptar no cobra nada')
            ->assertDontSee('Motivo')->assertDontSee('reason":', false);
        $this->get('/dev/portal/quote?lang=en')->assertOk()->assertSee('Travel fee')->assertSee('Accept quote');
    }

    public function test_final_states_show_no_form(): void
    {
        foreach (['quote-accepted' => '¡Gracias! Aceptaste tu cotización', 'quote-expired' => 'Esta cotización ya venció',
            'quote-superseded' => 'Hay una versión más nueva', 'quote-rejected' => 'Recibimos tu respuesta'] as $step => $title) {
            $this->get("/dev/portal/{$step}?lang=es")->assertOk()->assertSee($title)->assertDontSee('id="quote-decide"', false);
        }
    }

    public function test_preview_is_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/dev/portal/quote')->assertNotFound();
    }
}
