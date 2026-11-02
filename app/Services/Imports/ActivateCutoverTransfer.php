<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverFinancialBinding, CutoverOpeningSet, CutoverReconciliation, CutoverTransfer, Event, FinancialRevision, ImportedProjectRevision, User};
use App\Services\Audit;
use App\Services\Finance\{EffectiveOpeningAgreement, MutationKey, Sandbox};
use Illuminate\Support\Facades\DB;

/**
 * C3 B4 (Claude, covering Codex 2026-11-02; docs/CUTOVER-TRANSFER-API.md): the owner's single "Pasar al CRM" action. Under the
 * cohort lock protocol it re-proves the current reconciliation and prepared opening set, then for EVERY member in one
 * transaction materializes the original-source FinancialRevision (adapter shape required by EffectiveOpeningAgreement),
 * seals one CutoverTransfer, writes the bindings and active slots and moves responsibility to the CRM. Each member is then
 * validated with EffectiveOpeningAgreement itself; any failure rolls the whole group back. No partial group, no event
 * version change, no grant/message/charge is created, and nothing is sent to HoneyBook (the owner attested it is off).
 */
final class ActivateCutoverTransfer
{
    public function execute(User $actor, array $input, string $key): array
    {
        CutoverAccess::actor($actor); Sandbox::require('sandbox_cutover_bridge'); Sandbox::require('sandbox_cutover_transfer');
        $d = ImportInput::input($input, ['opening_set_id' => 'required|integer|min:1', 'expected_opening_set_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'owner_confirms_switch' => 'required|accepted']);
        $d = ['opening_set_id' => (int) $d['opening_set_id'], 'expected_opening_set_sha256' => $d['expected_opening_set_sha256'], 'owner_confirms_switch' => true];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':cutover:transfer';
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($d, $hash, $key, $scope) {
            Sandbox::require('sandbox_cutover_transfer');
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                return self::result(CutoverTransfer::whereKey($receipt->result_id)->sharedLock()->firstOrFail());
            }
            $set = CutoverOpeningSet::whereKey($d['opening_set_id'])->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless(hash_equals($set->payload_sha256, $d['expected_opening_set_sha256']), 409, 'La apertura en pantalla ya no es la vigente. Recarga.');
            RecordWriteRejected::unless(!CutoverTransfer::where('cutover_opening_set_id', $set->id)->sharedLock()->exists(), 409, 'Este grupo ya se pasó al CRM.');
            RecordWriteRejected::unless($set->expires_at->isFuture(), 409, 'Pasaron más de 4 horas desde la última revisión de pagos. Revisa los pagos de nuevo.');
            // The same proof as preparation, on current rows: complete reconciliation, nothing missing, same members.
            $reconciliation = CutoverReconciliation::whereKey($set->cutover_reconciliation_id)->sharedLock()->firstOrFail();
            $s = app(ReadHoneyBookCutoverReadiness::class)->snapshot($links, $events, $grants);
            RecordWriteRejected::unless(CutoverIntegrity::reconciliation($reconciliation) && $reconciliation->expires_at->isFuture() && $s['missing'] === []
                && hash_equals($reconciliation->cohort_sha256, $s['view']['cohort_sha256']) && hash_equals($set->cohort_sha256, $reconciliation->cohort_sha256),
                409, 'El grupo cambió o falta evidencia. Vuelve a revisar antes de pasarlo al CRM.');
            $saved = OpeningSetIntegrity::load($set);
            RecordWriteRejected::unless($saved['manifest']['members'] === OpeningAgreementFacts::hashes(OpeningAgreementFacts::cohort($links, $events, $grants)),
                409, 'La evidencia de una fiesta cambió desde la preparación. Prepara la apertura de nuevo.');
            $ids = array_map('intval', array_keys($saved['facts']));
            RecordWriteRejected::unless($ids !== [] && !DB::table('cutover_active_openings')->whereIn('event_id', $ids)->sharedLock()->exists(), 409, 'Una fiesta ya tiene un acuerdo activo.');
            $facts = [];
            foreach ($saved['rows'] as $opening) {
                $o = $saved['facts'][(string) $opening->event_id];
                $event = $events->get($o['event_id']);
                RecordWriteRejected::unless($event && $event->responsible_system === 'honeybook' && $event->version === $o['event_version'], 409, 'Una fiesta cambió. Vuelve a revisar.');
                // Only rows from an earlier switch that was undone may exist; the new one follows them.
                $prior = FinancialRevision::where('event_id', $event->id)->orderBy('revision')->sharedLock()->get();
                $reversed = CutoverFinancialBinding::whereIn('financial_revision_id', $prior->pluck('id'))
                    ->whereIn('cutover_transfer_id', DB::table('cutover_reversals')->select('cutover_transfer_id'))->count();
                RecordWriteRejected::unless($reversed === $prior->count(), 409, 'Una fiesta importada ya tiene montos del CRM. Requiere revisión.');
                $source = ImportedProjectRevision::whereKey($o['source_revision_id'])->sharedLock()->firstOrFail(); $sn = $source->source_snapshot; $n = $source->normalized;
                // Exact source adapter (docs/CUTOVER-RECEIPTS-API.md): original texts and components; no new tax or card fee.
                $finance = FinancialRevision::create(['event_id' => $event->id, 'revision' => ((int) $prior->max('revision')) + 1, 'event_version' => $o['event_version'], 'currency' => 'USD',
                    'lines_snapshot' => [['source' => 'honeybook', 'source_revision_id' => $source->id, 'name' => $sn['services'], 'characters_text' => $sn['characters']]],
                    'subtotal_cents' => $n['package_cents'], 'travel_fee_cents' => $n['travel_cents'], 'discount_cents' => $n['discount_cents'], 'tax_cents' => 0, 'customer_fee_cents' => 0,
                    'total_cents' => $o['original_total_cents'], 'deposit_target_cents' => $o['deposit_target_cents'],
                    'balance_due_at' => $o['crm_balance_due_at'], 'created_by' => $actor->id])->fresh();
                $facts[(string) $event->id] = ['event_id' => $event->id, 'opening_id' => $opening->id, 'opening_sha256' => MutationKey::hash($o),
                    'financial_revision_id' => $finance->id, 'financial_sha256' => MutationKey::hash(\App\Services\Integrity\EvidenceV1::model($finance, 'financial'))];
            }
            ksort($facts);
            // Effective after every bound financial row exists (the reader refuses a transfer earlier than its rows).
            $effective = now()->startOfSecond();
            RecordWriteRejected::unless($effective->greaterThanOrEqualTo($set->source_observed_at) && $effective->greaterThanOrEqualTo($set->created_at)
                && $effective->lessThan($set->expires_at), 409, 'La hora del servidor no es válida para el cambio.');
            $p = ['schema_version' => 1, 'opening_set_id' => $set->id, 'opening_set_sha256' => $set->payload_sha256, 'effective_at' => $effective->toIso8601String(),
                'created_by' => $actor->id, 'members' => array_map(fn ($f) => MutationKey::hash($f), $facts)];
            $transfer = CutoverTransfer::create(['cutover_opening_set_id' => $set->id, 'effective_at' => $effective, 'created_by' => $actor->id, 'payload' => $p, 'payload_sha256' => MutationKey::hash($p)]);
            foreach ($facts as $f) {
                $body = ['transfer_id' => $transfer->id, 'facts' => $f];
                $binding = CutoverFinancialBinding::create(['cutover_transfer_id' => $transfer->id, 'event_id' => $f['event_id'], 'cutover_opening_agreement_id' => $f['opening_id'],
                    'financial_revision_id' => $f['financial_revision_id'], 'payload' => $body, 'payload_sha256' => MutationKey::hash($body)]);
                DB::table('cutover_active_openings')->insert(['event_id' => $f['event_id'], 'cutover_financial_binding_id' => $binding->id]);
                $event = $events->get($f['event_id']);
                DB::table('events')->where('id', $event->id)->update(['responsible_system' => 'crm']); // no version bump: operations are unchanged
                Audit::recordFor($actor, 'cutover.event_transferred', $event, ['responsible_system' => 'honeybook'], ['responsible_system' => 'crm', 'transfer_id' => $transfer->id, 'binding_id' => $binding->id]);
            }
            // Self-check with the reader every collection path uses: one invalid member undoes the whole group.
            foreach (array_keys($facts) as $eventId) {
                try { $c = EffectiveOpeningAgreement::find(Event::whereKey((int) $eventId)->firstOrFail()); }
                catch (\Throwable) { $c = null; }
                RecordWriteRejected::unless($c !== null, 409, 'La validación final de una fiesta falló. No se pasó ninguna fiesta.');
            }
            Audit::recordFor($actor, 'cutover.transfer_activated', $transfer, [], ['opening_set_id' => $set->id, 'event_count' => count($facts),
                'effective_at' => $transfer->payload['effective_at'], 'payload_sha256' => $transfer->payload_sha256]);
            MutationKey::store($scope, $key, $hash, 'cutover_transfer', $transfer->id);
            return self::result($transfer);
        });
    }

    public static function result(CutoverTransfer $t): array
    {
        return ['transfer_id' => $t->id, 'opening_set_id' => $t->cutover_opening_set_id, 'effective_at' => $t->payload['effective_at'],
            'event_count' => count($t->payload['members']), 'reversed' => DB::table('cutover_reversals')->where('cutover_transfer_id', $t->id)->exists()];
    }
}
