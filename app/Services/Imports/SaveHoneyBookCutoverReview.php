<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{CutoverOriginalDocument, HoneyBookCutoverReview, User};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

final class SaveHoneyBookCutoverReview
{
    public function execute(User $actor, int $eventId, #[\SensitiveParameter] array $input, string $key): HoneyBookCutoverReview
    {
        $actor = CutoverAccess::actor($actor);
        $d = ImportInput::input($input, ['expected_version' => 'required|integer|min:0', 'expected_binding_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'file' => 'sometimes|file', 'original_document_id' => 'sometimes|integer|min:1',
            'owner_attests_signed_original' => 'required|accepted', 'owner_money_reviewed' => 'required|accepted',
            'source_observed_at' => ['required', 'date', new ExplicitOffset()], 'source_reference' => 'required|string|min:5|max:255',
            'owner_note' => 'required|string|min:5|max:1000', 'crm_balance_due_at' => ['present', 'nullable', 'date', new ExplicitOffset()],
            'honeybook_automations_disabled' => 'required|boolean', 'external_collections_retired' => 'required|boolean']);
        if (isset($d['file']) === isset($d['original_document_id'])) ImportInput::fail('file', 'choose_uploaded_or_existing_original');
        $bytes = isset($d['file']) ? app(CutoverDocuments::class)->upload($d['file']) : null;
        unset($d['file']);
        $d['expected_version'] = (int)$d['expected_version'];
        if (isset($d['original_document_id'])) $d['original_document_id'] = (int)$d['original_document_id'];
        $d['owner_attests_signed_original'] = $d['owner_money_reviewed'] = true;
        $d['honeybook_automations_disabled'] = (bool)$d['honeybook_automations_disabled'];
        $d['external_collections_retired'] = (bool)$d['external_collections_retired'];
        $observed = CarbonImmutable::parse($d['source_observed_at'])->utc();
        if ($observed->isFuture()) ImportInput::fail('source_observed_at', 'future_source_observation');
        $d['source_observed_at'] = $observed->toIso8601String();
        $d['crm_balance_due_at'] = $d['crm_balance_due_at'] ? CarbonImmutable::parse($d['crm_balance_due_at'])->utc()->toIso8601String() : null;
        $hash = MutationKey::hash([...$d, 'file_sha256' => $bytes === null ? null : hash('sha256', $bytes)]);
        $scope = "owner:{$actor->id}:cutover:event:{$eventId}:review";
        return app(CutoverCohort::class)->run($actor, function ($actor, $links, $events, $grants) use ($eventId, $d, $bytes, $observed, $hash, $scope, $key) {
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $saved = HoneyBookCutoverReview::whereKey($receipt->result_id)->sharedLock()->firstOrFail();
                RecordWriteRejected::unless(CutoverIntegrity::review($saved) !== null, 409, 'La revisión guardada requiere revisar su integridad.');
                return $saved;
            }
            $link = $links->firstWhere('event_id', $eventId); abort_unless($link, 404);
            $member = app(CutoverMember::class)->inspect($link, $events->get($eventId), $grants);
            RecordWriteRejected::unless($member['view']['expected_version'] === $d['expected_version'] && hash_equals($member['bindingHash'], $d['expected_binding_sha256']), 409, 'La fiesta o la revisión cambió. Vuelve a revisar.');
            RecordWriteRejected::unless(array_intersect($member['view']['missing'], ['source_integrity_invalid', 'capacity_review_required', 'responsibility_changed']) === [], 409, 'Revisa el acuerdo y la capacidad antes de registrar el corte.');
            if ($bytes !== null) {
                $sha = hash('sha256', $bytes);
                $document = CutoverOriginalDocument::where('event_id', $eventId)->where('commercial_sha256', $member['binding']['commercial_sha256'])->where('sha256', $sha)->sharedLock()->first();
                $document ??= CutoverOriginalDocument::create(['event_id' => $eventId, 'imported_project_link_id' => $link->id,
                    'commercial_sha256' => $member['binding']['commercial_sha256'], 'sha256' => $sha, 'byte_count' => strlen($bytes),
                    'document_ciphertext' => Crypt::encryptString($bytes), 'created_by' => $actor->id]);
            } else {
                $document = CutoverOriginalDocument::whereKey($d['original_document_id'])->where('event_id', $eventId)->where('imported_project_link_id', $link->id)
                    ->where('commercial_sha256', $member['binding']['commercial_sha256'])->sharedLock()->firstOrFail();
            }
            app(CutoverDocuments::class)->verify($document);
            $payload = ['binding' => $member['binding'], 'document_sha256' => $document->sha256, 'document_id' => $document->id,
                'source_observed_at' => $observed->toIso8601String(), 'reviewed_by' => $actor->id, 'review_version' => $d['expected_version'] + 1, 'owner_attests_signed_original' => true,
                'owner_money_reviewed' => true, 'source_reference' => $d['source_reference'], 'owner_note' => $d['owner_note'],
                'crm_balance_due_at' => $d['crm_balance_due_at'], 'honeybook_automations_disabled' => $d['honeybook_automations_disabled'],
                'external_collections_retired' => $d['external_collections_retired']];
            $review = HoneyBookCutoverReview::create(['event_id' => $eventId, 'version' => $d['expected_version'] + 1, 'cutover_original_document_id' => $document->id,
                'binding_sha256' => $member['bindingHash'], 'payload' => $payload, 'payload_sha256' => MutationKey::hash($payload), 'source_observed_at' => $observed, 'created_by' => $actor->id]);
            Audit::recordFor($actor, 'cutover.event_reviewed', $review, [], ['version' => $review->version, 'event_id' => $eventId, 'document_id' => $document->id, 'document_sha256' => $document->sha256, 'binding_sha256' => $member['bindingHash']]);
            MutationKey::store($scope, $key, $hash, 'honeybook_cutover_review', $review->id);
            return $review;
        });
    }
}
