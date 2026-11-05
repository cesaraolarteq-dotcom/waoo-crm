<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOpeningSet, CutoverTransfer, Event, FinancialRevision, LedgerEntry};
use App\Services\Finance\{EffectiveOpeningAgreement, FinanceSummary, RecordZellePayment};
use App\Services\Imports\{ActivateCutoverTransfer, ReverseCutoverTransfer};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use Tests\Support\EffectiveOpeningScenario;
use Tests\TestCase;

/** C3 B4: the owner's whole-group switch ("Pasar al CRM") and its reversal, sandbox only. */
class CutoverTransferTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;

    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario();
        config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true, 'waoo.sandbox_cutover_transfer' => true]);
        Http::preventStrayRequests(); Mail::fake();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach (['cutover_reversals', 'opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings', 'cutover_transfers',
                'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            foreach (['opening_stripe_evidence', 'payment_exceptions', 'ledger_entries', 'payment_intents', 'financial_revisions'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }

    private function activate(CutoverOpeningSet $set, ?string $key = null): array
    {
        return app(ActivateCutoverTransfer::class)->execute($this->owner, ['opening_set_id' => $set->id, 'expected_opening_set_sha256' => $set->payload_sha256,
            'owner_confirms_switch' => true], $key ?? (string) Str::uuid());
    }

    private function reverse(CutoverTransfer $t, ?string $key = null): array
    {
        return app(ReverseCutoverTransfer::class)->execute($this->owner, ['transfer_id' => $t->id, 'expected_transfer_sha256' => $t->payload_sha256,
            'reason' => 'Synthetic owner rollback check.'], $key ?? (string) Str::uuid());
    }

    public function test_the_whole_group_moves_at_once_and_every_member_is_a_valid_effective_agreement(): void
    {
        $a = $this->openingMember('TRANSFER-A'); $b = $this->openingMember('TRANSFER-B');
        $versions = Event::whereIn('id', [$a->event_id, $b->event_id])->pluck('version', 'id')->all();
        $set = $this->prepareOpeningSet(); $key = (string) Str::uuid();
        $r = $this->activate($set, $key);
        $this->assertSame(2, $r['event_count']); $this->assertFalse($r['reversed']);
        foreach ([$a->event_id, $b->event_id] as $id) {
            $e = Event::findOrFail($id);
            $this->assertSame('crm', $e->responsible_system);
            $this->assertSame($versions[$id], $e->version); // operations unchanged
            $this->assertNotNull(EffectiveOpeningAgreement::find($e));
            $this->assertSame('honeybook_opening', app(FinanceSummary::class)->forEvent($e)['source']);
        }
        $this->assertDatabaseCount('ledger_entries', 0); // source money never becomes a CRM receipt
        $this->assertSame(1, CutoverTransfer::count());
        // Exact replay returns the same transfer; a second switch of the same set is refused.
        $this->assertSame($r['transfer_id'], $this->activate($set, $key)['transfer_id']);
        try { $this->activate($set); $this->fail('Second switch accepted.'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
    }

    public function test_two_owners_switching_the_same_group_create_exactly_one_complete_transfer(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('Real row-lock concurrency requires MariaDB.');
        $a = $this->openingMember('RACE-A'); $b = $this->openingMember('RACE-B');
        $versions = Event::whereIn('id', [$a->event_id, $b->event_id])->pluck('version', 'id')->all();
        $set = $this->prepareOpeningSet();
        $other = \App\Models\User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $input = ['opening_set_id' => $set->id, 'expected_opening_set_sha256' => $set->payload_sha256, 'owner_confirms_switch' => true];
        $workers = [];
        foreach ([$this->owner->id, $other->id] as $actorId) {
            $key = (string) Str::uuid();
            $workers[] = static function () use ($actorId, $input, $key) {
                try { app(ActivateCutoverTransfer::class)->execute(\App\Models\User::findOrFail($actorId), $input, $key); return 'transferred'; }
                catch (RecordWriteRejected $e) { return $e->status === 409 ? 'conflict' : 'unexpected'; }
            };
        }
        $results = \Tests\Support\ConcurrentWorkers::run($workers); sort($results);
        $this->assertSame(['conflict', 'transferred'], $results);
        $this->assertDatabaseCount('cutover_transfers', 1);
        $this->assertDatabaseCount('cutover_financial_bindings', 2);
        $this->assertDatabaseCount('cutover_active_openings', 2);
        $this->assertSame($versions, Event::whereIn('id', array_keys($versions))->pluck('version', 'id')->all());
        foreach (array_keys($versions) as $id) {
            $this->assertSame('crm', Event::findOrFail($id)->responsible_system);
            $this->assertNotNull(EffectiveOpeningAgreement::find(Event::findOrFail($id)));
            $this->assertSame(1, FinancialRevision::where('event_id', $id)->count());
        }
        $this->assertSame(0, LedgerEntry::count(), 'source money is never duplicated as a CRM receipt');
    }

    public function test_a_stale_screen_or_an_expired_review_refuses_without_partial_writes(): void
    {
        $this->openingMember('TRANSFER-C'); $set = $this->prepareOpeningSet();
        try {
            app(ActivateCutoverTransfer::class)->execute($this->owner, ['opening_set_id' => $set->id, 'expected_opening_set_sha256' => str_repeat('a', 64),
                'owner_confirms_switch' => true], (string) Str::uuid());
            $this->fail('Stale screen accepted.');
        } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->travel(241)->minutes();
        try { $this->activate($set); $this->fail('Expired review accepted.'); } catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); }
        $this->assertDatabaseCount('cutover_transfers', 0);
        $this->assertDatabaseCount('financial_revisions', 0);
        $this->assertSame(0, Event::where('responsible_system', 'crm')->count());
    }

    public function test_the_switch_is_off_unless_its_own_flag_is_on(): void
    {
        $this->openingMember('TRANSFER-D'); $set = $this->prepareOpeningSet();
        config(['waoo.sandbox_cutover_transfer' => false]);
        $this->expectException(\Throwable::class);
        $this->activate($set);
    }

    public function test_reversal_returns_the_whole_group_only_while_the_crm_did_nothing(): void
    {
        $a = $this->openingMember('TRANSFER-E'); $set = $this->prepareOpeningSet();
        $transfer = CutoverTransfer::findOrFail($this->activate($set)['transfer_id']);
        $r = $this->reverse($transfer);
        $this->assertTrue($r['reversed']);
        $e = Event::findOrFail($a->event_id);
        $this->assertSame('honeybook', $e->responsible_system);
        $this->assertNull(EffectiveOpeningAgreement::find($e));
        $this->assertSame(1, CutoverTransfer::count()); // history kept
        $this->assertSame(1, FinancialRevision::where('event_id', $e->id)->count());
        try { $this->reverse($transfer); $this->fail('Second reversal accepted.'); } catch (RecordWriteRejected $x) { $this->assertSame(409, $x->status); }
    }

    public function test_reversal_is_refused_after_the_crm_recorded_money_for_any_member(): void
    {
        $a = $this->openingMember('TRANSFER-F'); $this->openingMember('TRANSFER-G'); $set = $this->prepareOpeningSet();
        $transfer = CutoverTransfer::findOrFail($this->activate($set)['transfer_id']);
        $this->travel(1)->minutes();
        app(RecordZellePayment::class)->execute($this->owner, $a->event_id, $this->sourcePaymentInput($a->event_id), (string) Str::uuid());
        try { $this->reverse($transfer); $this->fail('Reversal after CRM money accepted.'); }
        catch (RecordWriteRejected $e) { $this->assertSame(409, $e->status); $this->assertStringContainsString('pagos', $e->getMessage()); }
        $this->assertSame(2, Event::where('responsible_system', 'crm')->count());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_owner_screen_switches_and_undoes_the_group(): void
    {
        $a = $this->openingMember('TRANSFER-UI'); $set = $this->prepareOpeningSet();
        $page = \Livewire\Livewire::test(\App\Filament\Pages\HoneyBookCutover::class);
        $page->assertSee('Pasar 1 fiestas al CRM');
        $page->call('activateTransfer', $set->id, $set->payload_sha256)->assertNotified('1 fiestas ahora están a cargo del CRM');
        $this->assertSame('crm', Event::findOrFail($a->event_id)->responsible_system);
        $t = CutoverTransfer::sole();
        \Livewire\Livewire::test(\App\Filament\Pages\HoneyBookCutover::class)->assertSee('Deshacer el cambio')
            ->call('reverseTransfer', $t->id, $t->payload_sha256)->assertNotified('Las fiestas volvieron a HoneyBook');
        $this->assertSame('honeybook', Event::findOrFail($a->event_id)->responsible_system);
    }
}
