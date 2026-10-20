<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Customer, Event, PortalGrant};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Livewire\Livewire;
use Tests\Support\EffectiveOpeningScenario;
use Tests\TestCase;

// C3 B3b owner action "Enlace del acuerdo original" (fictitious parties; the effective switch-over is a test fixture only).
class OriginalAgreementLinkScreenTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;

    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario();
        config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true]); Http::preventStrayRequests(); Mail::fake();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (['opening_portal_bindings', 'portal_grants', 'opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings', 'cutover_transfers',
                'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents',
                'payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }

    public function test_hidden_without_the_bridge_flag(): void
    {
        $link = $this->openingMember();
        config(['waoo.sandbox_cutover_bridge' => false]);
        Livewire::test(ViewEvent::class, ['record' => $link->event_id])->assertActionHidden('originalAgreementLink');
    }

    public function test_before_the_switch_it_explains_why_and_creates_nothing(): void
    {
        $link = $this->openingMember();
        Livewire::test(ViewEvent::class, ['record' => $link->event_id])->assertActionVisible('originalAgreementLink')
            ->mountAction('originalAgreementLink')->assertMountedActionModalSee('Todavía no se puede crear')->assertSet('origPin', null)
            ->callMountedAction();
        $this->assertDatabaseCount('portal_grants', 0);
    }

    public function test_effective_agreement_issues_one_read_only_link_with_the_pinned_values(): void
    {
        $link = $this->openingMember(); $this->seedFutureActivation($this->prepareOpeningSet());
        $page = Livewire::test(ViewEvent::class, ['record' => $link->event_id])->mountAction('originalAgreementLink')
            ->assertMountedActionModalSee('Solo consulta')->setActionData(['days' => 7])->callMountedAction()->assertNotified('Enlace listo (solo consulta)');
        $g = PortalGrant::sole();
        $this->assertSame(['agreement.read'], $g->scopes);
        $this->assertTrue($g->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $page->assertSet('origPin', null)->assertSet('origKey', '');
        foreach (['contracts', 'signature_evidence', 'mail_occurrences', 'payment_intents', 'ledger_entries'] as $t) $this->assertSame(0, DB::table($t)->count());
        Mail::assertNothingSent();
    }

    public function test_a_change_after_opening_the_modal_is_refused_instead_of_using_fresh_values(): void
    {
        $link = $this->openingMember(); $this->seedFutureActivation($this->prepareOpeningSet());
        $page = Livewire::test(ViewEvent::class, ['record' => $link->event_id])->mountAction('originalAgreementLink');
        $c = Customer::findOrFail(Event::findOrFail($link->event_id)->customer_id);
        $c->forceFill(['phone' => '+12025550199', 'version' => $c->version + 1])->save(); // contact changed under the owner
        $page->setActionData(['days' => 14])->callMountedAction()->assertNotified('No se creó el enlace');
        $this->assertDatabaseCount('portal_grants', 0);
    }
}
