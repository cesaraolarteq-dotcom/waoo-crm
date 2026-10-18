<?php
namespace Tests\Feature;

use App\Support\MailTemplatesDraft;
use Tests\TestCase;

// TIP-01 design preview (local/testing only). Owner 2026-10-18: card only, 5/10/15 % of the party total or another amount,
// 100 % to the party's staff, card fees never mentioned; the thank-you email carries a second "leave a tip" button.
class TipPagePreviewTest extends TestCase
{
    public function test_open_tip_page_offers_five_ten_fifteen_percent_and_other_without_fees_or_zelle(): void
    {
        $html = $this->get('/dev/portal/tip?lang=es')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('El 100 % es para el staff')->assertSee('Te acompañaron: Lucía, Carlos')
            ->assertSee('5 %')->assertSee('$31')->assertSee('10 %')->assertSee('$62')->assertSee('15 %')->assertSee('$92')->assertSee('Otro monto')
            ->assertSee('Porcentajes del total de tu fiesta ($615)')->getContent();
        foreach (['comisión', 'Comisión', 'Zelle', 'Stripe', 'fee'] as $word) $this->assertStringNotContainsString($word, $html);
        $this->get('/dev/portal/tip?lang=en')->assertSee('100% goes to the staff')->assertSee('Other amount')->assertDontSee('fee');
    }

    public function test_paid_and_closed_states(): void
    {
        $this->get('/dev/portal/tip-thanks?lang=es')->assertSee('¡Gracias por tu propina!')->assertSee('Dejar una reseña en Google')->assertDontSee('id="tip-form"', false);
        $this->get('/dev/portal/tip-closed?lang=en')->assertSee('This link is no longer active')->assertDontSee('id="tip-form"', false);
        $this->get('/dev/portal/tip-other')->assertNotFound();
    }

    public function test_thank_you_email_has_review_and_tip_buttons_and_no_zelle(): void
    {
        $html = MailTemplatesDraft::preview('thank_you_review', 'es');
        $this->assertStringContainsString('Dejar una reseña', $html);
        $this->assertStringContainsString('Dejar una propina', $html);
        $this->assertStringContainsString('/dev/portal/tip?lang=es', $html);
        $this->assertStringNotContainsString('Zelle', $html);
        $this->assertStringContainsString('Leave a tip', MailTemplatesDraft::preview('thank_you_review', 'en'));
    }
}
