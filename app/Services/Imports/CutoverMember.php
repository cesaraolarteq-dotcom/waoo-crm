<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence, Customer, CutoverOriginalDocument, Event, HoneyBookCutoverReview, ImportedLedgerLine, ImportedProjectLink, ImportedProjectRevision, ImportRow};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CutoverMember
{
    public function inspect(ImportedProjectLink $link, Event $event, Collection $grants): array
    {
        $r = ImportedProjectRevision::whereKey($link->current_revision_id)->sharedLock()->firstOrFail();
        $customer = Customer::whereKey($link->customer_id)->sharedLock()->firstOrFail();
        $n = $r->normalized; $m = $r->mapping; $missing = [];
        $lines = ImportedLedgerLine::where('imported_project_revision_id', $r->id)->orderBy('source_position')->sharedLock()->get();
        $actual = $lines->map(fn ($p) => ['source_position' => $p->source_position, 'received_date' => $p->received_date->format('Y-m-d'),
            'amount_cents' => $p->amount_cents, 'method' => $p->method, 'source' => $p->source, 'verified' => false])->all();
        $valid = $r->imported_project_link_id === $link->id && ($n['source_format'] ?? null) === 'honeybook_future_v1'
            && hash_equals($r->source_sha256, MutationKey::hash($r->source_snapshot)) && ($n['source_sha256'] ?? null) === $r->source_sha256
            && is_int($n['total_cents'] ?? null) && is_int($n['paid_cents'] ?? null) && is_int($n['balance_cents'] ?? null)
            && $n['total_cents'] - $n['paid_cents'] === $n['balance_cents'] && (int)$lines->sum('amount_cents') === $n['paid_cents']
            && MutationKey::hash($actual) === MutationKey::hash($n['payments'] ?? []);
        if (!$valid) $missing[] = 'source_integrity_invalid';
        if ($valid && HoneyBookSourceDifference::delta($n) !== 0 && !HoneyBookSourceDifference::covers(new ImportRow(['normalized' => $n]), $r)) $missing[] = 'source_integrity_invalid';
        $services = $event->services()->with(['characters' => fn ($q) => $q->orderBy('id')->sharedLock()])->orderBy('id')->sharedLock()->get();
        $operations = ImportedAgreement::operations($event, true);
        $reservations = $event->reservations()->orderBy('id')->sharedLock()->get();
        $booking = BookingEvidence::where('event_id', $event->id)->orderBy('id')->sharedLock()->get();
        $resources = \App\Models\Resource::whereIn('id', $reservations->pluck('resource_id'))->orderBy('id')->sharedLock()
            ->get(['id', 'version', 'status', 'kind', 'skills', 'character_id', 'outfit']);
        $capacity = ImportedAgreement::capacityMatches($event, $r, true);
        try {
            $capacity = $capacity && hash_equals($m['operations_sha256'] ?? '', $operations)
                && $event->customer_id === $link->customer_id && $event->title === trim($r->source_snapshot['project_name'])
                && $event->address === trim($r->source_snapshot['event_address']) && $event->postal_code === trim($r->source_snapshot['event_zip'])
                && $event->timezone === 'America/Chicago' && $event->starts_at->equalTo(CarbonImmutable::parse($n['starts_at']))
                && $event->ends_at->equalTo(CarbonImmutable::parse($n['ends_at'])) && $event->setup_minutes === $m['setup_minutes']
                && $event->breakdown_minutes === $m['breakdown_minutes'] && $event->travel_fee_cents === $n['travel_cents'];
        } catch (\Throwable) { $capacity = false; }
        if (!$capacity || $resources->count() !== $reservations->count() || $resources->contains(fn ($resource) => $resource->status !== 'active')) $missing[] = 'capacity_review_required';
        if ($event->responsible_system !== 'honeybook') $missing[] = 'responsibility_changed';
        $collections = app(CutoverCollections::class)->inspect($event->id, $grants);
        $missing = [...$missing, ...$collections['missing']];
        $source = ['revision_id' => $r->id, 'link_version' => $link->version, 'event_version' => $event->version, 'customer_version' => $customer->version,
            'total_cents' => $valid ? $n['total_cents'] : null, 'gross_received_cents' => $valid ? (int)$lines->where('amount_cents', '>', 0)->sum('amount_cents') : null,
            'refund_cents' => $valid ? -(int)$lines->where('amount_cents', '<', 0)->sum('amount_cents') : null,
            'net_paid_cents' => $valid ? $n['paid_cents'] : null, 'balance_cents' => $valid ? $n['balance_cents'] : null,
            'balance_due_date' => $n['balance_due_date'] ?? null, 'contract_signed' => $n['contract_signed'] ?? null, 'funds_verified' => false];
        $binding = ['event' => $event->only(['id', 'version', 'status', 'responsible_system', 'customer_id', 'starts_at', 'ends_at', 'setup_minutes', 'breakdown_minutes', 'address', 'postal_code']),
            'link' => $link->only(['id', 'version', 'current_revision_id']), 'source_sha256' => $r->source_sha256,
            'commercial_sha256' => HoneyBookResyncReview::commercialHash($r->source_snapshot),
            'customer_sha256' => MutationKey::hash($customer->only(['id', 'version', 'name', 'phone', 'email', 'locale'])), 'source' => $source,
            'operations_sha256' => MutationKey::hash(['services' => $services->toArray(), 'reservations' => $reservations->toArray(), 'booking' => $booking->toArray(), 'resources' => $resources->toArray()]),
            'collections_sha256' => $collections['hash']];
        // Keep personal fields only as a hash in the encrypted snapshot too; never emit the address.
        $binding['event'] = ['id' => $event->id, 'version' => $event->version, 'sha256' => MutationKey::hash($binding['event'])];
        $bindingHash = MutationKey::hash($binding);
        $review = HoneyBookCutoverReview::where('event_id', $event->id)->orderByDesc('version')->sharedLock()->first();
        $document = $review ? CutoverOriginalDocument::whereKey($review->cutover_original_document_id)->where('event_id', $event->id)
            ->where('commercial_sha256', $binding['commercial_sha256'])->sharedLock()->first(['id', 'event_id', 'imported_project_link_id', 'commercial_sha256', 'sha256', 'byte_count']) : null;
        $payload = null;
        if ($review) {
            $payload = CutoverIntegrity::review($review);
            if ($payload === null || !$document || ($payload['document_sha256'] ?? null) !== $document->sha256) $missing[] = 'review_stale';
        }
        if (!$review) $missing[] = 'owner_review_required';
        elseif (!hash_equals($review->binding_sha256, $bindingHash)) $missing[] = 'review_stale';
        if (!$document) $missing[] = 'original_document_required';
        else { try { app(CutoverDocuments::class)->verify($document); } catch (RecordWriteRejected) { $missing[] = 'document_integrity_invalid'; } }
        $age = CutoverAccess::maxAge();
        if ($age === null) $missing[] = 'freshness_policy_required';
        if ($review && ($review->source_observed_at->isFuture() || ($age !== null && $review->source_observed_at->addMinutes($age)->lessThanOrEqualTo(CarbonImmutable::now())))) $missing[] = 'source_reconciliation_stale';
        if ($review) {
            if (!($payload['honeybook_automations_disabled'] ?? false)) $missing[] = 'honeybook_automations_active';
            if (!($payload['external_collections_retired'] ?? false)) $missing[] = 'external_collection_not_retired';
            if (($n['balance_cents'] ?? 1) > 0 && empty($payload['crm_balance_due_at'])) $missing[] = 'source_due_time_review_required';
        }
        $view = ['event_id' => $event->id, 'binding_sha256' => $bindingHash, 'expected_version' => $review?->version ?? 0, 'source' => $source,
            'document' => $document ? ['id' => $document->id, 'sha256' => $document->sha256, 'byte_count' => $document->byte_count, 'owner_attests_signed_original' => true] : null,
            'review' => $review ? ['id' => $review->id, 'version' => $review->version, 'source_observed_at' => $review->source_observed_at->toIso8601String(),
                'expires_at' => $age ? $review->source_observed_at->addMinutes($age)->toIso8601String() : null,
                'crm_balance_due_at' => $payload['crm_balance_due_at'] ?? null, 'honeybook_automations_disabled' => $payload['honeybook_automations_disabled'] ?? false,
                'external_collections_retired' => $payload['external_collections_retired'] ?? false, 'current' => hash_equals($review->binding_sha256, $bindingHash) && !in_array('review_stale', $missing, true)] : null,
            'missing' => array_values(array_unique($missing))];
        return compact('view', 'binding', 'bindingHash', 'review', 'document', 'link', 'r', 'event');
    }
}
