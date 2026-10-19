<?php
namespace Tests\Feature;

use App\Models\{Quote, QuotePresentation};
use App\Services\Quotes\ReviseQuote;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// QUOTE-01 B real client page /portal/quote over Codex's ReadQuoteViewModel + DecideQuote (fictitious fixtures only).
// The page pins every decision value from one server read; the test submits exactly what the page carries.
class QuotePortalPageTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    /** @return array<string,string> data-* attributes of the first element matching $id or the outcome fieldset */
    private function attrs(string $html, string $needle): array
    {
        $this->assertSame(1, preg_match('/<[a-z]+[^>]*'.preg_quote($needle, '/').'[^>]*>/s', $html, $m), "missing $needle");
        preg_match_all('/data-([a-z-]+)="([^"]*)"/', $m[0], $a, PREG_SET_ORDER);

        return collect($a)->mapWithKeys(fn ($x) => [$x[1] => html_entity_decode($x[2], ENT_QUOTES)])->all();
    }

    private function page(int $grant, string $lang = 'es')
    {
        return $this->withSession(['portal_grant_id' => $grant])->get('/portal/quote?lang='.$lang);
    }

    public function test_page_pins_revision_hashes_and_each_outcome_frozen_consent(): void
    {
        $p = $this->prepare(); auth()->guard('web')->logout();
        $pres = QuotePresentation::findOrFail($p['presentation_id']);
        $html = $this->page($p['portal_grant_id'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('$100.00')->assertSee('Costo de transporte')->assertSee('Visible adjustment')
            ->assertSee('Fixture ES accept this recorded quote.')->assertSee('Fixture ES reject this recorded quote.')
            ->assertSee('Primero abre el PDF completo')->assertDontSee('name="reason"', false)->getContent();
        foreach (['PRIVATE_', 'client@example.test', '346-555-0100'] as $secret) $this->assertStringNotContainsString($secret, $html);
        $form = $this->attrs($html, 'id="quote-decide"');
        $this->assertSame([$pres->snapshot_sha256, $pres->pdf_sha256, '0', (string) $pres->payload['revision']], [$form['snapshot'], $form['pdf'], $form['pdf-opened'], $form['revision']]);
        foreach (['accepted', 'rejected'] as $outcome) {
            $o = $this->attrs($html, 'data-outcome="'.$outcome.'"');
            $this->assertSame([$pres->consents[$outcome]['version'], $pres->consents[$outcome]['sha256']], [$o['consent-version'], $o['consent-sha']]);
            $this->assertSame('/portal/api/quote/'.($outcome === 'accepted' ? 'accept' : 'reject'), $o['action']);
        }
        // The script namespaces its retry key by quote number + revision + outcome and keeps the exact payload with it.
        $this->assertStringContainsString("'waoo-quote:' + f.dataset.number + ':' + f.dataset.revision + ':' + outcome", $html);
        $this->assertStringContainsString('saved.body === json', $html);
    }

    public function test_accepting_with_the_pinned_values_records_the_decision_and_shows_thanks(): void
    {
        $p = $this->prepare(); auth()->guard('web')->logout();
        $this->withSession(['portal_grant_id' => $p['portal_grant_id']]);
        $html = $this->page($p['portal_grant_id'])->getContent();
        $form = $this->attrs($html, 'id="quote-decide"'); $o = $this->attrs($html, 'data-outcome="accepted"');
        $body = ['expected_revision' => (int) $form['revision'], 'snapshot_sha256' => $form['snapshot'], 'pdf_sha256' => $form['pdf'],
            'consent_version' => $o['consent-version'], 'consent_sha256' => $o['consent-sha'], 'name' => 'Fictional Client', 'email' => 'client@example.test', 'consent' => true];
        // Before the PDF was opened through this link the server refuses (the page also keeps the button disabled).
        $this->postJson($o['action'], $body, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->get('/portal/api/quote/pdf')->assertOk();
        $this->assertSame('1', $this->attrs($this->page($p['portal_grant_id'])->getContent(), 'id="quote-decide"')['pdf-opened']);
        $key = (string) Str::uuid();
        $this->postJson($o['action'], $body, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('decision.outcome', 'accepted');
        $this->postJson($o['action'], $body, ['Idempotency-Key' => $key])->assertCreated(); // uncertain retry: same key + same payload
        $this->assertDatabaseCount('quote_decisions', 1);
        $this->page($p['portal_grant_id'])->assertOk()->assertSee('¡Gracias! Recibimos tu respuesta')->assertDontSee('id="quote-decide"', false);
        $this->assertDatabaseCount('ledger_entries', 0); $this->assertDatabaseCount('resource_reservations', 0);
    }

    public function test_rejecting_needs_its_own_frozen_consent_and_identity(): void
    {
        $p = $this->prepare(); auth()->guard('web')->logout();
        $this->withSession(['portal_grant_id' => $p['portal_grant_id']]);
        $this->get('/portal/api/quote/pdf')->assertOk();
        $html = $this->page($p['portal_grant_id'], 'en')->assertSee('Fixture ES reject this recorded quote.')->getContent();
        $form = $this->attrs($html, 'id="quote-decide"'); $o = $this->attrs($html, 'data-outcome="rejected"');
        $this->postJson($o['action'], ['expected_revision' => (int) $form['revision'], 'reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($o['action'], ['expected_revision' => (int) $form['revision'], 'snapshot_sha256' => $form['snapshot'], 'pdf_sha256' => $form['pdf'],
            'consent_version' => $o['consent-version'], 'consent_sha256' => $o['consent-sha'], 'name' => 'Fictional Client', 'email' => 'client@example.test', 'consent' => true],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('decision.outcome', 'rejected');
        $this->page($p['portal_grant_id'], 'en')->assertSee('We got your answer')->assertDontSee('data-outcome', false);
    }

    public function test_admin_session_sees_the_offer_but_no_decision_controls(): void
    {
        $p = $this->prepare(); // owner still logged in
        $this->page($p['portal_grant_id'])->assertOk()->assertSee('$100.00')->assertSee('id="quote-locked"', false)->assertDontSee('data-outcome', false);
    }

    public function test_event_portal_sends_a_quote_link_to_its_page_and_revoked_links_show_nothing(): void
    {
        $p = $this->prepare(); auth()->guard('web')->logout();
        $this->withSession(['portal_grant_id' => $p['portal_grant_id']])->get('/portal')->assertRedirect('/portal/quote');
        $this->actingAs($this->owner);
        app(ReviseQuote::class)->execute($this->owner, $this->offer->quote_id, ['expected_version' => Quote::findOrFail($this->offer->quote_id)->version, 'expected_event_version' => $this->event->version, 'discounts' => []], (string) Str::uuid());
        auth()->guard('web')->logout();
        $this->page($p['portal_grant_id'])->assertOk()->assertSee('Abriendo tu reserva')->assertDontSee('$100.00')->assertDontSee('data-snapshot', false);
    }

    public function test_page_is_closed_without_the_quote_sandbox(): void
    {
        config(['waoo.sandbox_quotes' => false]);
        $this->get('/portal/quote')->assertNotFound();
    }
}
