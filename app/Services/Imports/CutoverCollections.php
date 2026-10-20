<?php
namespace App\Services\Imports;

use App\Services\Finance\MutationKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CutoverCollections
{
    public function inspect(int $eventId, Collection $grants): array
    {
        $read = fn ($table, $columns) => DB::table($table)->where('event_id', $eventId)->orderBy('id')->sharedLock()->get($columns);
        $payments = $read('payment_intents', ['id', 'status', 'collection_slot', 'stripe_checkout_session_id']);
        $ledger = $read('ledger_entries', ['id', 'kind', 'amount_cents']);
        $exceptions = $read('payment_exceptions', ['id', 'state', 'kind']);
        $tips = $read('tip_checkout_intents', ['id', 'status', 'active_event_id', 'active_grant_id']);
        $tipGrants = $read('tip_grants', ['id', 'revoked_at', 'expires_at']);
        $tipReceipts = $read('tip_receipts', ['id', 'gross_cents']);
        $tipReconciliations = DB::table('tip_reconciliations')->where(fn ($q) => $q->whereIn('tip_checkout_intent_id', $tips->pluck('id'))->orWhereNull('tip_checkout_intent_id'))
            ->orderBy('id')->sharedLock()->get(['id', 'status']);
        $messages = DB::table('outbound_messages')->where('event_id', $eventId)->orderBy('id')->sharedLock()->pluck('id');
        $states = DB::table('outbound_message_states')->whereIn('outbound_message_id', $messages)->orderBy('id')->sharedLock()->get(['id', 'outbound_message_id', 'version', 'status']);
        $occurrences = DB::table('mail_occurrences')->where('event_id', $eventId)->orderBy('id')->sharedLock()->pluck('id');
        $occurrenceStates = DB::table('mail_occurrence_states')->whereIn('mail_occurrence_id', $occurrences)->orderBy('id')->sharedLock()->get(['id', 'mail_occurrence_id', 'outbound_message_id', 'version', 'status']);
        $portal = $grants->where('event_id', $eventId)->values(); $missing = [];
        if ($payments->contains(fn ($p) => !in_array($p->status, ['expired', 'failed'], true) || $p->collection_slot !== null
            || ($p->status === 'failed' && $p->stripe_checkout_session_id !== null))
            || $portal->contains(fn ($g) => !$g->revoked_at && $g->expires_at->isFuture())) $missing[] = 'crm_collection_in_flight';
        if ($ledger->isNotEmpty() || $exceptions->contains(fn ($e) => $e->state !== 'resolved')) $missing[] = 'crm_money_requires_reconciliation';
        if ($tips->contains(fn ($t) => !in_array($t->status, ['expired', 'failed'], true) || $t->active_event_id !== null || $t->active_grant_id !== null)
            || $tipGrants->contains(fn ($g) => $g->revoked_at === null && \Carbon\CarbonImmutable::parse($g->expires_at)->isFuture())
            || $tipReceipts->isNotEmpty() || $tipReconciliations->contains(fn ($r) => $r->status !== 'resolved')) $missing[] = 'tip_collection_or_reconciliation_open';
        if ($messages->count() !== $states->count() || $states->contains(fn ($s) => !in_array($s->status, ['blocked', 'cancelled'], true))
            || $occurrences->count() !== $occurrenceStates->count() || $occurrenceStates->contains(fn ($s) => !in_array($s->status, ['blocked', 'skipped', 'materialized'], true)
                || ($s->status === 'materialized' && !$messages->contains($s->outbound_message_id)))) $missing[] = 'message_outcome_unknown';
        $hash = MutationKey::hash(['payments' => $payments->all(), 'ledger' => $ledger->all(), 'exceptions' => $exceptions->all(),
            'tips' => $tips->all(), 'tip_grants' => $tipGrants->all(), 'tip_receipts' => $tipReceipts->all(), 'tip_reconciliations' => $tipReconciliations->all(),
            'portal' => \App\Services\Integrity\EvidenceV1::many($portal, 'portal'), 'messages' => $messages->all(), 'states' => $states->all(), 'occurrences' => $occurrences->all(), 'occurrence_states' => $occurrenceStates->all()]);
        return compact('hash', 'missing');
    }
}
