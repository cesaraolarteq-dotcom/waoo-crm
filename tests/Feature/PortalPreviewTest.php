<?php
namespace Tests\Feature;

use Tests\TestCase;

// Portal design preview: every step in ES/EN, no third-party resources, no referrer, closed outside local/testing.
class PortalPreviewTest extends TestCase
{
    public function test_every_step_renders_in_both_languages_without_third_party_assets(): void
    {
        foreach (['details' => ['Tus datos', 'Your details'], 'contract' => ['Firmar contrato', 'Sign contract'], 'pay' => ['Zelle (sin cargo)', 'Zelle (no fee)'], 'done' => ['¡Todo listo!', 'All set!']] as $step => [$es, $en]) {
            $r = $this->get("/dev/portal/{$step}")->assertOk()->assertSee($es)->assertSee('no-referrer', false)->assertHeader('Referrer-Policy', 'no-referrer');
            $this->get("/dev/portal/{$step}?lang=en")->assertOk()->assertSee($en);
            preg_match_all('/(?:src|href)="(https?:[^"]+)"/', $r->getContent(), $m);
            foreach ($m[1] as $url) {
                $this->assertStringStartsWith(url('/'), $url, "Third-party resource on the portal: {$url}");
            }
            $this->assertStringNotContainsString('fonts.googleapis', $r->getContent());
        }
        $this->get('/dev/portal/done')->assertDontSee('p. m..');
        $this->get('/dev/portal/pay')->assertSee('Waoo Party LLC · 346-216-8739')->assertSee('$62.50');
        $this->get('/dev/portal/details')->assertSee('Costo de transporte')->assertSee('Responde STOP');
        $this->get('/dev/portal/details?lang=en')->assertSee('Travel fee')->assertSee('Reply STOP');
    }

    public function test_preview_is_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        $this->get('/dev/portal/details')->assertNotFound();
    }
}
