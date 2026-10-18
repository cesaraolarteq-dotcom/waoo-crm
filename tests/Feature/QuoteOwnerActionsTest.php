<?php
namespace Tests\Feature;

use App\Filament\Pages\QuoteDetail;
use App\Models\{FinancialRevision, PortalGrant, Quote, QuoteConversion};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// QUOTE-01 B/C owner side on the quote page over Codex's SendQuote / RevokePortalGrant / ConvertAcceptedQuote (fictitious data).
class QuoteOwnerActionsTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
    }

    private function page()
    {
        return Livewire::test(QuoteDetail::class, ['quote' => $this->offer->quote_id]);
    }

    public function test_owner_prepares_the_link_once_and_copying_again_returns_the_same_active_link(): void
    {
        $page = $this->page()->assertActionVisible('send')->assertSee('Todavía no preparas el enlace')->callAction('send');
        $first = $page->get('newLink');
        $this->assertStringContainsString('/portal#token=', $first['url']);
        $this->assertStringContainsString($first['url'], $first['msg_es']);
        $this->assertStringContainsString('Aquí está tu cotización de Waoo Party', $first['msg_es']);
        $this->assertSame('sent', Quote::findOrFail($this->offer->quote_id)->status);
        $page->assertSee('Activo hasta')->assertSee('todavía no abre el PDF');
        $again = $page->callAction('send')->get('newLink');
        $this->assertSame($first['url'], $again['url']);
        $this->assertSame(1, PortalGrant::whereNotNull('quote_revision_id')->count());
    }

    public function test_copy_does_not_advance_quote_version_or_prepare_again(): void
    {
        $this->prepare(); $page = $this->page();
        $before = Quote::findOrFail($this->offer->quote_id)->version;
        $audits = \Illuminate\Support\Facades\DB::table('audit_entries')->where('action', 'quote.prepared')->count();
        $page->callAction('send');
        $this->assertSame($before, Quote::findOrFail($this->offer->quote_id)->version);
        $this->assertSame($audits, \Illuminate\Support\Facades\DB::table('audit_entries')->where('action', 'quote.prepared')->count());
    }
    public function test_copy_of_a_grant_revoked_after_the_modal_opened_never_creates_a_replacement(): void
    {
        $prepared = $this->prepare(); $page = $this->page()->mountAction('send');
        app(\App\Services\Portal\RevokePortalGrant::class)->execute($this->owner, $prepared['portal_grant_id']);
        $page->callMountedAction();
        $this->assertSame(1, PortalGrant::whereNotNull('quote_revision_id')->count());
        $page->assertSet('newLink', null)->assertNotified('No se preparó');
    }
    public function test_revoke_never_switches_to_a_replacement_link_created_after_the_modal_opened(): void
    {
        $prepared = $this->prepare(); $page = $this->page()->mountAction('revokeLink');
        app(\App\Services\Portal\RevokePortalGrant::class)->execute($this->owner, $prepared['portal_grant_id']);
        $replacement = $this->prepare();
        $page->callMountedAction();
        $this->assertNull(PortalGrant::findOrFail($replacement['portal_grant_id'])->revoked_at);
    }
    public function test_pdf_is_the_frozen_presentation_and_revoking_hides_the_link(): void
    {
        $page = $this->page()->callAction('send');
        $page->callAction('pdf')->assertFileDownloaded();
        $page->callAction('revokeLink');
        $this->assertNotNull(PortalGrant::whereNotNull('quote_revision_id')->sole()->revoked_at);
        $page->assertSee('Sin enlace activo')->assertSet('newLink', null);
    }

    public function test_missing_approved_consent_is_explained_and_nothing_is_frozen(): void
    {
        config(['waoo.quote_acceptance_consent' => []]);
        $this->page()->callAction('send')->assertNotified('No se preparó');
        $this->assertDatabaseCount('quote_presentations', 0);
        $this->assertDatabaseCount('portal_grants', 0);
    }

    public function test_accepted_quote_becomes_agreed_money_with_the_displayed_versions(): void
    {
        $this->acceptOffer();
        $page = $this->page()->assertSee('Aceptó')->assertActionVisible('convert')->callAction('convert')->assertNotified('Montos acordados fijados');
        $finance = FinancialRevision::where('event_id', $this->event->id)->sole();
        $this->assertSame(Quote::findOrFail($this->offer->quote_id)->revisions()->first()->total_cents, $finance->total_cents);
        $this->assertSame(1, QuoteConversion::count());
        $page->assertSee('Fijados')->assertActionHidden('convert');
        // Dinero explains the agreed total: services + travel − visible discount.
        $this->get(\App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $this->event->id]))->assertOk()->assertSee('− descuento');
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('resource_reservations', 0);
    }

    public function test_conversion_is_refused_when_the_event_changed_after_the_page_opened(): void
    {
        $this->acceptOffer();
        $page = $this->page();
        $this->event->increment('version'); // someone edited the draft meanwhile
        $page->callAction('convert')->assertNotified('No se fijaron los montos');
        $this->assertDatabaseCount('quote_conversions', 0);
        $this->assertSame(0, FinancialRevision::where('event_id', $this->event->id)->count());
    }
}
