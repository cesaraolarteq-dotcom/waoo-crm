<?php
namespace App\Services\Finance;

use App\Models\{BookingEvidence, Customer, CutoverOriginalDocument, Event, FinancialRevision, HoneyBookCutoverReview, ImportedProjectLink, ImportedProjectRevision, OperationalReview, Resource, ResourceBlock};
use App\Services\Imports\{CutoverDocuments, CutoverIntegrity, HoneyBookFutureSource};

final class OpeningCollectionBinding
{
    public static function inspect(Event $e, array $context): array
    {
        $o = $context['opening']; $missing = [];
        $review = HoneyBookCutoverReview::whereKey($o['review_id'])->sharedLock()->firstOrFail(); $r = CutoverIntegrity::review($review);
        $source = ImportedProjectRevision::whereKey($o['source_revision_id'])->sharedLock()->firstOrFail();
        $link = ImportedProjectLink::where('event_id', $e->id)->sharedLock()->firstOrFail(); $customer = Customer::whereKey($e->customer_id)->sharedLock()->firstOrFail();
        $shape = \App\Services\Integrity\EvidenceV1::model($e, 'event');
        $shape['responsible_system'] = 'honeybook';
        if (!$r || $review->payload_sha256 !== $o['review_payload_sha256'] || MutationKey::hash($shape) !== ($r['binding']['event']['sha256'] ?? null)
            || $e->responsible_system !== 'crm' || $e->status !== 'capacity_reserved' || $e->version !== $o['event_version']) $missing[] = 'opening_event_changed';
        if ($source->source_sha256 !== $o['source_sha256'] || MutationKey::hash($source->source_snapshot) !== $o['source_sha256']
            || $link->id !== $o['imported_project_link_id'] || $link->current_revision_id !== $source->id || $link->customer_id !== $e->customer_id
            || $e->title !== trim($source->source_snapshot['project_name']) || $e->travel_fee_cents !== HoneyBookFutureSource::cents($source->source_snapshot['travel_fee'])) $missing[] = 'opening_source_changed';
        $customerHash = MutationKey::hash(\App\Services\Integrity\EvidenceV1::model($customer, 'customer'));
        if ($customerHash !== $o['customer_sha256']) $missing[] = 'opening_customer_changed';
        $services = $e->services()->with(['characters' => fn ($q) => $q->orderBy('id')->sharedLock()])->orderBy('id')->sharedLock()->get();
        $reservations = $e->reservations()->orderBy('id')->sharedLock()->get();
        $booking = BookingEvidence::where('event_id', $e->id)->orderBy('id')->sharedLock()->get();
        $resources = Resource::whereIn('id', $reservations->pluck('resource_id'))->orderBy('id')->sharedLock()->get(['id', 'version', 'status', 'kind', 'skills', 'character_id', 'outfit']);
        $operations = MutationKey::hash(\App\Services\Integrity\EvidenceV1::operations($services, $reservations, $booking, $resources));
        $operational = OperationalReview::whereKey($o['operational_review_id'])->sharedLock()->first();
        $operationalHash = $operational ? MutationKey::hash(\App\Services\Integrity\EvidenceV1::model($operational, 'operational_review')) : null;
        if ($operations !== $o['operations_sha256'] || $operationalHash !== $o['operational_review_sha256']) $missing[] = 'opening_capacity_changed';
        $blocks = ResourceBlock::whereIn('resource_id', $resources->pluck('id'))->whereNull('released_at')
            ->where('starts_at', '<', $e->ends_at->addMinutes($e->breakdown_minutes))->where('ends_at', '>', $e->starts_at->subMinutes($e->setup_minutes))
            ->orderBy('id')->sharedLock()->get(['id', 'resource_id', 'starts_at', 'ends_at']);
        if ($blocks->isNotEmpty()) $missing[] = 'opening_capacity_blocked';
        $document = CutoverOriginalDocument::whereKey($o['document_id'])->sharedLock()->first();
        $documentValid = $document && $document->event_id === $e->id && $document->imported_project_link_id === $link->id
            && $document->commercial_sha256 === $o['commercial_sha256'] && $document->sha256 === $o['document_sha256']
            && ($r['document_id'] ?? null) === $document->id && ($r['document_sha256'] ?? null) === $document->sha256;
        if ($documentValid) {
            try { app(CutoverDocuments::class)->verify($document); }
            catch (\App\Exceptions\RecordWriteRejected) { $documentValid = false; }
        }
        if (!$documentValid) $missing[] = 'opening_original_document_invalid';
        $latest = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->sharedLock()->first()?->id;
        if ($latest !== $context['finance']->id) $missing[] = 'opening_financial_revision_changed';
        return ['collection_current' => $missing === [], 'missing' => $missing,
            'collection_sha256' => MutationKey::hash(['transfer' => $context['transfer']->payload_sha256, 'binding' => $context['binding']->payload_sha256,
                'event' => $shape, 'responsible_system' => $e->responsible_system, 'title' => $e->title, 'travel' => $e->travel_fee_cents,
                'customer' => $customerHash, 'operations' => $operations, 'operational' => $operationalHash, 'original_document_valid' => (bool)$documentValid, 'blocks' => \App\Services\Integrity\EvidenceV1::many($blocks, 'block'), 'latest_finance' => $latest])];
    }
}
