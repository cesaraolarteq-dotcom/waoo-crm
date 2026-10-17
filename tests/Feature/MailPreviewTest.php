<?php
namespace Tests\Feature;

use App\Support\MailTemplatesDraft;
use Tests\TestCase;

// MAIL-01 design preview (local/testing only): every draft renders in both languages with one button,
// the owner's closing line, escaped values and a subject within 60 characters; nothing is sent.
class MailPreviewTest extends TestCase
{
    public function test_all_drafts_render_in_both_languages(): void
    {
        foreach (MailTemplatesDraft::KEYS as $key) {
            foreach (['es', 'en'] as $lang) {
                $m = MailTemplatesDraft::render($key, $lang, []);
                $this->get("/dev/mail/{$key}?lang={$lang}")->assertOk()->assertSee('— Waoo Party')->assertSee((string) $m['closing']);
            }
        }
        $this->get('/dev/mail/quote_sent?lang=es')->assertSee('Costo de transporte')->assertSee('−$25')->assertSee('Ver y aceptar mi cotización')->assertSee('¡Estamos emocionados de ser parte de tu celebración!');
        $this->get('/dev/mail/thank_you_review?lang=en')->assertSee("We'd love to celebrate with you again!");
    }

    public function test_subjects_stay_short_with_long_names_and_values_are_escaped(): void
    {
        foreach (MailTemplatesDraft::KEYS as $key) {
            foreach (['es', 'en'] as $lang) {
                // Longest real values: ES weekday + month names, EN short dates; the event time never goes in a subject.
                $long = $lang === 'es' ? 'miércoles 30 de septiembre' : 'Wed, Sep 30';
                $m = MailTemplatesDraft::render($key, $lang, ['first_name' => 'Maximiliana Guadalupe Fernández', 'event_date' => $long.($lang === 'es' ? ' a las 12:30 pm' : ' at 12:30 pm'), 'subject_date' => $long, 'due' => $long]);
                $this->assertLessThanOrEqual(60, mb_strlen($m['subject']), "$key/$lang: ".$m['subject']);
                $this->assertStringNotContainsString('12:30', $m['subject']);
            }
        }
        $this->get('/dev/mail/quote_sent?lang=es')->assertSee('Tu cotización Waoo Party para el sábado 21 de noviembre', false);
        $html = view('mail.layout', ['lang' => 'es', 'mail' => MailTemplatesDraft::render('inquiry_received', 'es', ['first_name' => '<b>x</b>']), 'totals' => [], 'logo' => '/img/waoo-logo.png'])->render();
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_closed_outside_local_and_testing(): void
    {
        $this->app['env'] = 'staging';
        $this->get('/dev/mail/quote_sent')->assertNotFound();
    }
}
