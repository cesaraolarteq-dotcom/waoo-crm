<?php
namespace Tests\Support;

use App\Models\{CutoverFinancialBinding, CutoverOpeningSet, CutoverTransfer, Event, FinancialRevision, ImportRow, ImportedProjectLink, ImportedProjectRevision, Resource};
use App\Services\Finance\{EffectiveOpeningAgreement, MutationKey};
use App\Services\Imports\{CommitHoneyBookEvent, ConfirmImportedCapacity, OpeningSetIntegrity, PrepareCutoverOpeningAgreements,
    PrepareHoneyBookCutoverReconciliation, ReadHoneyBookCutoverReadiness, ReadHoneyBookCutoverReview, SaveHoneyBookCutoverReview};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Future activation fixture only. No production activation service or API is implemented by this helper. */
trait EffectiveOpeningScenario
{
    use HoneyBookFutureScenario;
    private function openingMember(string $project = 'RECEIPT-FAKE-1', array $change = [], array $review = [], array $commit = []): ImportedProjectLink
    {
        $b = $this->stageFuture(['honeybook_project_id' => $project, ...$change]); $r = ImportRow::where('import_batch_id', $b->id)->sole();
        $link = app(CommitHoneyBookEvent::class)->execute($this->owner, $b->id, $r->id, $this->commitInput($b, $r, $commit), (string)Str::uuid());
        $ids = [Resource::create(['code' => $project.'-HOST', 'name' => 'Fictional host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['host', 'driver']])->id,
            Resource::create(['code' => $project.'-SPKR', 'name' => 'Fictional speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []])->id];
        app(ConfirmImportedCapacity::class)->execute($this->owner, $link->event_id, ['expected_version' => Event::findOrFail($link->event_id)->version,
            'imported_revision_id' => $link->current_revision_id, 'resource_ids' => $ids,
            'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'PRIVATE_OPERATIONAL_NOTE fixture original capacity.', 'transfers' => []]], (string)Str::uuid());
        $v = app(ReadHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id);
        app(SaveHoneyBookCutoverReview::class)->execute($this->owner, $link->event_id, ['expected_version' => $v['expected_version'],
            'expected_binding_sha256' => $v['binding_sha256'], 'file' => UploadedFile::fake()->createWithContent('PRIVATE_SOURCE.pdf', "%PDF-1.4\n% FICTIONAL_ORIGINAL\n%%EOF\n"),
            'owner_attests_signed_original' => true, 'owner_money_reviewed' => true, 'source_observed_at' => now()->subMinute()->toIso8601String(),
            'source_reference' => 'PRIVATE_SOURCE_REFERENCE fixture only', 'owner_note' => 'PRIVATE_OWNER_NOTE fixture source review',
            'crm_balance_due_at' => '2026-12-19T21:00:00+00:00', 'honeybook_automations_disabled' => true, 'external_collections_retired' => true, ...$review], (string)Str::uuid());
        return $link->fresh();
    }
    private function prepareOpeningSet(): CutoverOpeningSet
    {
        $v = app(ReadHoneyBookCutoverReadiness::class)->execute($this->owner);
        $r = app(PrepareHoneyBookCutoverReconciliation::class)->execute($this->owner, ['expected_cohort_sha256' => $v['cohort_sha256'], 'source_scope_reviewed' => true], (string)Str::uuid());
        $s = app(PrepareCutoverOpeningAgreements::class)->execute($this->owner, ['reconciliation_id' => $r->id, 'expected_cohort_sha256' => $r->cohort_sha256], (string)Str::uuid());
        return CutoverOpeningSet::findOrFail($s['opening_set_id']);
    }
    private function seedFutureActivation(CutoverOpeningSet $set): CutoverTransfer
    {
        return DB::transaction(function () use ($set) {
            $saved = OpeningSetIntegrity::load($set); $facts = [];
            foreach ($saved['rows'] as $opening) {
                $o = $saved['facts'][(string)$opening->event_id];
                $source = ImportedProjectRevision::findOrFail($o['source_revision_id']); $s = $source->source_snapshot; $n = $source->normalized;
                $finance = FinancialRevision::create(['event_id' => $o['event_id'], 'revision' => 1, 'event_version' => $o['event_version'], 'currency' => 'USD',
                    'lines_snapshot' => [['source' => 'honeybook', 'source_revision_id' => $source->id, 'name' => $s['services'], 'characters_text' => $s['characters']]],
                    'subtotal_cents' => $n['package_cents'], 'travel_fee_cents' => $n['travel_cents'], 'discount_cents' => $n['discount_cents'], 'tax_cents' => 0, 'customer_fee_cents' => 0,
                    'total_cents' => $o['original_total_cents'], 'deposit_target_cents' => $o['deposit_target_cents'],
                    'balance_due_at' => $o['crm_balance_due_at'], 'created_by' => $this->owner->id])->fresh();
                $facts[(string)$o['event_id']] = ['event_id' => $o['event_id'], 'opening_id' => $opening->id,
                    'opening_sha256' => MutationKey::hash($o), 'financial_revision_id' => $finance->id, 'financial_sha256' => MutationKey::hash(\App\Services\Integrity\EvidenceV1::model($finance, 'financial'))];
            }
            ksort($facts);
            $effective = now()->toIso8601String();
            $p = ['schema_version' => 1, 'opening_set_id' => $set->id, 'opening_set_sha256' => $set->payload_sha256,
                'effective_at' => $effective, 'created_by' => $this->owner->id, 'members' => array_map(fn ($f) => MutationKey::hash($f), $facts)];
            $transfer = CutoverTransfer::create(['cutover_opening_set_id' => $set->id, 'effective_at' => $effective, 'created_by' => $this->owner->id,
                'payload' => $p, 'payload_sha256' => MutationKey::hash($p)]);
            foreach ($facts as $f) {
                $body = ['transfer_id' => $transfer->id, 'facts' => $f];
                $binding = CutoverFinancialBinding::create(['cutover_transfer_id' => $transfer->id, 'event_id' => $f['event_id'],
                    'cutover_opening_agreement_id' => $f['opening_id'], 'financial_revision_id' => $f['financial_revision_id'], 'payload' => $body, 'payload_sha256' => MutationKey::hash($body)]);
                DB::table('cutover_active_openings')->insert(['event_id' => $f['event_id'], 'cutover_financial_binding_id' => $binding->id]);
                Event::findOrFail($f['event_id'])->forceFill(['responsible_system' => 'crm'])->save();
            }
            return $transfer;
        });
    }
    private function sourcePaymentInput(int $eventId, array $change = []): array
    {
        $c = EffectiveOpeningAgreement::find(Event::findOrFail($eventId));
        return ['financial_revision_id' => $c['finance']->id, 'opening_id' => $c['binding']->cutover_opening_agreement_id,
            'expected_collection_sha256' => $c['collection_sha256'], 'post_cutover_received' => true,
            'amount_cents' => 2000, 'currency' => 'USD', 'reference' => 'PRIVATE_BANK_REFERENCE_fixture',
            'received_at' => now()->toIso8601String(), 'verified_received' => true, 'note' => 'PRIVATE_BANK_NOTE_fixture', ...$change];
    }
}
