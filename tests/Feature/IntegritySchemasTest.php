<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence, Customer, Event, FinancialRevision, LedgerEntry, OperationalReview, PortalGrant, Resource, ResourceBlock, TipGrant};
use App\Services\Agreements\{ConfirmedAgreement, ReadConfirmedAgreement};
use App\Services\Finance\{ConfirmPaidCapacity, CreateFinancialRevision, FinanceSummary, MutationKey, OpeningReceiptContext, OpeningReceiptProof, RecordZellePayment};
use App\Services\Imports\{PrepareHoneyBookCutoverReconciliation, ReadHoneyBookCutoverReadiness};
use App\Services\Integrity\EvidenceV1;
use App\Services\Records\SaveEventDraft;
use App\Services\Tips\{IssueTipGrant, TipGuard};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Illuminate\Support\Str;
use Tests\Support\EffectiveOpeningScenario;
use Tests\TestCase;

class IntegritySchemasTest extends TestCase
{
    use DatabaseMigrations, EffectiveOpeningScenario;
    private array $extended = [];
    protected function setUp(): void
    {
        parent::setUp(); $this->futureScenario(); config(['waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true, 'waoo.sandbox_tips' => true]);
        Http::preventStrayRequests(); Mail::fake();
    }
    protected function tearDown(): void
    {
        if ($this->app) {
            $this->app->detectEnvironment(fn () => 'testing');
            foreach ($this->extended as $t) Schema::table($t, fn (Blueprint $b) => $b->dropColumn('extra_display_label'));
            foreach (['tip_grants', 'opening_receipt_allocations', 'cutover_active_openings', 'cutover_financial_bindings', 'cutover_transfers',
                'cutover_opening_agreements', 'cutover_opening_sets', 'cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents',
                'payment_exceptions', 'capacity_payment_links', 'ledger_entries', 'financial_revisions'] as $t) if (Schema::hasTable($t)) DB::table($t)->delete();
            if (Schema::hasTable('booking_evidence')) DB::table('booking_evidence')->where('method', 'honeybook_import')->delete();
        }
        parent::tearDown();
    }
    private function member(): int
    {
        return $this->openingMember('celebracion-sofia', ['project_name' => 'Cumpleaños de Sofía', 'client_first_name' => 'María',
            'client_last_name' => 'González', 'client_email' => 'maria.gonzalez@example.test'])->event_id;
    }
    private function extend(array $tables): void
    {
        foreach ($tables as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('extra_display_label')->nullable()->default('Nuevo formato'));
            $this->extended[] = $table;
        }
    }
    private function native(): array
    {
        $c = Customer::create(['name' => 'Daniela Pérez', 'email' => 'daniela.perez@example.test', 'phone' => '+13465550142', 'locale' => 'es']);
        $e = app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $c->id, 'title' => 'Cumpleaños de Mateo',
            'starts_at' => now()->addDays(20)->toIso8601String(), 'ends_at' => now()->addDays(20)->addHour()->toIso8601String(),
            'timezone' => 'America/Chicago', 'address' => '123 Calle Roble, Katy, TX 77433', 'postal_code' => '77433', 'setup_minutes' => 15,
            'breakdown_minutes' => 15, 'travel_fee_cents' => 0, 'travel_fee_override_note' => 'Transporte incluido por decisión del dueño.', 'services' => [['price_version_id' => $this->price->id]]]);
        $f = app(CreateFinancialRevision::class)->execute($this->owner, $e->id, ['expected_version' => $e->version], (string)Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $e->id, ['financial_revision_id' => $f->id, 'amount_cents' => $f->deposit_target_cents,
            'currency' => 'USD', 'reference' => 'reserva-mateo', 'received_at' => now()->toIso8601String(), 'verified_received' => true], (string)Str::uuid());
        $staff = Resource::create(['code' => 'ana-lopez', 'name' => 'Ana López', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']]);
        $speaker = Resource::create(['code' => 'bocina-azul', 'name' => 'Bocina azul', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
        $e = app(ConfirmPaidCapacity::class)->execute($this->owner, $e->id, ['expected_version' => $e->version, 'financial_revision_id' => $f->id,
            'resource_ids' => [$staff->id, $speaker->id], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Ruta y regreso revisados.', 'transfers' => []]]);
        $this->travelTo($e->ends_at->addHour());
        return [$e, $f->fresh()];
    }
    public function test_v1_preserves_legacy_operating_and_preparation_bytes_without_resealing(): void
    {
        $id = $this->member(); $e = Event::findOrFail($id);
        $services = $e->services()->with('characters')->orderBy('id')->get(); $reservations = $e->reservations()->orderBy('id')->get();
        $booking = BookingEvidence::where('event_id', $id)->orderBy('id')->get();
        $resources = Resource::whereIn('id', $reservations->pluck('resource_id'))->orderBy('id')->get(['id', 'version', 'status', 'kind', 'skills', 'character_id', 'outfit']);
        $legacy = ['services' => $services->toArray(), 'reservations' => $reservations->toArray(), 'booking' => $booking->toArray(), 'resources' => $resources->toArray()];
        $this->assertSame(MutationKey::hash($legacy), MutationKey::hash(EvidenceV1::operations($services, $reservations, $booking, $resources)));
        $op = OperationalReview::where('event_id', $id)->sole();
        $this->assertSame(MutationKey::hash($op->toArray()), MutationKey::hash(EvidenceV1::model($op, 'operational_review')));
        $view = app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner);
        $reviewBytes = DB::table('honeybook_cutover_reviews')->get()->toJson();
        $this->extend(['event_services', 'event_service_characters', 'resource_reservations', 'booking_evidence', 'operational_reviews', 'resources', 'customers', 'events']);
        $fresh = app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner);
        $this->assertSame($view, $fresh); $this->assertTrue($fresh['readiness']['evidence_ready']);
        $this->assertSame($reviewBytes, DB::table('honeybook_cutover_reviews')->get()->toJson());
        app(PrepareHoneyBookCutoverReconciliation::class)->execute($this->owner, ['expected_cohort_sha256' => $view['cohort_sha256'], 'source_scope_reviewed' => true], (string)Str::uuid());
        $this->assertDatabaseCount('cutover_reconciliations', 1);
    }
    public function test_existing_effective_binding_allocation_and_agreement_survive_real_unrelated_columns(): void
    {
        $id = $this->member(); $this->seedFutureActivation($this->prepareOpeningSet()); $e = Event::findOrFail($id); $f = FinancialRevision::sole();
        $this->assertSame(MutationKey::hash($f->toArray()), MutationKey::hash(EvidenceV1::model($f, 'financial')));
        app(RecordZellePayment::class)->execute($this->owner, $id, $this->sourcePaymentInput($id), (string)Str::uuid());
        $entry = LedgerEntry::sole(); $audit = DB::table('audit_entries')->where('action', 'payment.zelle_received')->where('subject_id', (string)$entry->id)->sole();
        $context = DB::transaction(fn () => OpeningReceiptContext::current(Event::whereKey($id)->lockForUpdate()->firstOrFail()));
        $proof = DB::transaction(fn () => OpeningReceiptProof::inspect($entry, $context));
        $this->assertSame(MutationKey::hash($entry->toArray()), $proof['ledger_sha256']);
        $this->assertSame(MutationKey::hash(['ledger' => $entry->toArray(), 'audit' => (array)$audit]), $proof['receipt_sha256']);
        $basis = app(ReadConfirmedAgreement::class)->execute($this->owner, $id); $money = app(FinanceSummary::class)->forEvent($e);
        $immutable = [];
        foreach (['cutover_financial_bindings', 'cutover_transfers', 'opening_receipt_allocations'] as $t) $immutable[$t] = DB::table($t)->get()->toJson();
        $this->extend(['financial_revisions', 'ledger_entries', 'audit_entries', 'booking_evidence', 'event_services', 'event_service_characters', 'operational_reviews']);
        $this->assertSame($basis, app(ReadConfirmedAgreement::class)->execute($this->owner, $id));
        $after = app(FinanceSummary::class)->forEvent($e); $this->assertSame(8000, $after['net_received_cents']); $this->assertSame(4000, $after['balance_cents']);
        $this->assertFalse($after['collection_blocked']); $this->assertSame($money['opening_collection_sha256'], $after['opening_collection_sha256']);
        foreach ($immutable as $t => $bytes) $this->assertSame($bytes, DB::table($t)->get()->toJson(), $t);
        DB::table('ledger_entries')->where('id', $entry->id)->update(['note' => 'Cambió un dato protegido']);
        $bad = app(FinanceSummary::class)->forEvent($e); $this->assertNull($bad['balance_cents']); $this->assertTrue($bad['collection_blocked']);
    }
    public function test_legacy_native_tip_hash_and_encrypted_link_survive_marker_migration_and_new_finance_column(): void
    {
        [$e, $f] = $this->native();
        $legacy = MutationKey::hash(['event_id' => $e->id, 'event_version' => $e->version, 'customer_id' => $e->customer_id,
            'start' => $e->starts_at->toIso8601String(), 'end' => $e->ends_at->toIso8601String(), 'finance' => $f->getAttributes()]);
        $this->assertSame($legacy, ConfirmedAgreement::nativeFingerprint($e, $f));
        $issued = app(IssueTipGrant::class)->execute($this->owner, $e->id, ['expected_event_version' => $e->version, 'financial_revision_id' => $f->id, 'locale' => 'es'], (string)Str::uuid());
        $g = TipGrant::sole(); $old = $g->getAttributes(); $this->assertSame($legacy, $g->snapshot_sha256);
        $migration = require database_path('migrations/2026_10_20_490000_version_tip_snapshot_schema.php');
        $migration->down(); $this->assertArrayNotHasKey('snapshot_schema_version', (array)DB::table('tip_grants')->sole());
        $migration->up(); $this->assertSame($old, TipGrant::sole()->getAttributes());
        $this->extend(['financial_revisions']); $this->assertSame($legacy, ConfirmedAgreement::nativeFingerprint($e->fresh(), $f->fresh()));
        $this->assertNotSame($legacy, MutationKey::hash(['event_id' => $e->id, 'event_version' => $e->version, 'customer_id' => $e->customer_id,
            'start' => $e->starts_at->toIso8601String(), 'end' => $e->ends_at->toIso8601String(), 'finance' => $f->fresh()->getAttributes()]));
        DB::transaction(function () use ($g, $issued) { [$valid] = TipGuard::grant($g->id); $this->assertSame($issued['link'], TipGuard::link($valid)); });
        $this->assertSame($old, TipGrant::sole()->getAttributes());
        DB::table('tip_grants')->where('id', $g->id)->update(['snapshot_schema_version' => 2]);
        try { $migration->down(); $this->fail('Unsupported TIP version lost its marker.'); } catch (\RuntimeException $ex) { $this->assertStringContainsString('forward migration', $ex->getMessage()); }
        try { DB::transaction(fn () => TipGuard::grant($g->id)); $this->fail('Unsupported interpretation accepted.'); } catch (RecordWriteRejected $ex) { $this->assertSame(409, $ex->status); }
        DB::table('tip_grants')->where('id', $g->id)->update(['snapshot_schema_version' => 1]);
        $e->forceFill(['version' => $e->version + 1])->save();
        $this->expectException(RecordWriteRejected::class); DB::transaction(fn () => TipGuard::grant($g->id));
    }
    public function test_casts_accessors_appends_and_extra_relations_do_not_change_frozen_finance_projection(): void
    {
        [$e, $f] = $this->native(); $expected = EvidenceV1::model($f, 'financial');
        $display = new class extends FinancialRevision {
            protected $table = 'financial_revisions';
            protected $appends = ['display_label'];
            protected function casts(): array { return [...parent::casts(), 'total_cents' => 'string']; }
            public function getCurrencyAttribute($value) { return 'Moneda: '.$value; }
            public function getDisplayLabelAttribute() { return 'Cumpleaños de Mateo'; }
        };
        $display->setRawAttributes($f->getRawOriginal(), true); $display->setRelation('unrelated', collect([$e]));
        $this->assertNotSame($f->toArray(), $display->toArray());
        $this->assertSame($expected, EvidenceV1::model($display, 'financial')); $this->assertSame(EvidenceV1::financialRaw($f), EvidenceV1::financialRaw($display));
    }
    public function test_partial_or_wrong_model_cannot_silently_omit_required_evidence(): void
    {
        [$e, $f] = $this->native();
        foreach ([fn () => EvidenceV1::model(FinancialRevision::select('id')->findOrFail($f->id), 'financial'),
            fn () => EvidenceV1::model($e->services()->firstOrFail(), 'service'), fn () => EvidenceV1::model($e, 'customer')] as $read) {
            try { $read(); $this->fail('Incomplete evidence accepted.'); } catch (RecordWriteRejected $ex) { $this->assertSame(409, $ex->status); }
        }
    }
    public function test_nonempty_character_outfit_zero_and_selected_portal_profile_match_legacy_bytes(): void
    {
        [$e] = $this->native(); $service = $e->services()->firstOrFail();
        $character = \App\Models\Character::create(['name' => 'Elsa', 'active' => true, 'outfits' => ['0']]);
        $service->characters()->create(['character_id' => $character->id, 'quantity' => 1, 'character_name_snapshot' => 'Elsa', 'outfit' => '0', 'note' => 'Vestuario elegido por María.']);
        $service->load('characters'); $shape = EvidenceV1::model($service, 'service');
        $this->assertSame('0', $shape['characters'][0]['outfit']);
        $this->assertSame(MutationKey::hash($service->toArray()), MutationKey::hash($shape));
        $g = PortalGrant::create(['event_id' => $e->id, 'customer_id' => $e->customer_id, 'scopes' => ['payments.read'],
            'token_hash' => hash('sha256', 'enlace-daniela'), 'expires_at' => now()->addDay(), 'issued_by' => $this->owner->id]);
        $selected = PortalGrant::select(['id', 'event_id', 'updated_at', 'scopes', 'revoked_at', 'expires_at'])->findOrFail($g->id);
        $this->assertSame(MutationKey::hash($selected->toArray()), MutationKey::hash(EvidenceV1::model($selected, 'portal')));
        $service->characters()->update(['quantity' => 2]); $service->load('characters');
        $this->assertNotSame(MutationKey::hash($shape), MutationKey::hash(EvidenceV1::model($service, 'service')));
        $g->delete();
    }
    public function test_empty_relative_and_invalid_dates_cannot_become_an_invented_current_timestamp(): void
    {
        [, $f] = $this->native();
        foreach (['', 'tomorrow', '2026-02-31 12:00:00'] as $date) {
            $copy = new FinancialRevision; $copy->setRawAttributes([...$f->getRawOriginal(), 'balance_due_at' => $date], true);
            try { EvidenceV1::model($copy, 'financial'); $this->fail('An invalid date was normalized into evidence.'); }
            catch (RecordWriteRejected $ex) { $this->assertSame(409, $ex->status); }
        }
    }
}
