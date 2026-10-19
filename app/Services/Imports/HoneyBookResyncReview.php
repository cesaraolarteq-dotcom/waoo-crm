<?php

namespace App\Services\Imports;

use App\Models\{Customer, Event, ImportBatch, ImportedLedgerLine, ImportedProjectLink, ImportedProjectRevision, ImportRow};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;

/** Private current-read context shared by the diff and the same-route commit. Caller holds importer/batch locks. */
final class HoneyBookResyncReview
{
    public const MONEY_FIELDS = ['amount_paid', 'balance', 'payments', 'balance_due_date'];

    public static function commercialHash(array $source): string
    {
        return MutationKey::hash(array_diff_key($source, array_flip(self::MONEY_FIELDS)));
    }

    public function context(ImportBatch $batch, int $rowId): array
    {
        // Row lookup discovers the event while the importer singleton serializes row writers.
        $row = ImportRow::whereKey($rowId)->where('import_batch_id', $batch->id)->sharedLock()->firstOrFail();
        // Discovery only: never hold a link lock before the event (capacity locks event -> link).
        // A snapshot missing a just-created project is conservatively new, never authority to update it.
        $hint = ImportedProjectLink::where('project_key', $row->source_key)->value('event_id');
        $event = $hint ? Event::whereKey($hint)->lockForUpdate()->firstOrFail() : null;
        $link = $event ? ImportedProjectLink::where('project_key', $row->source_key)->lockForUpdate()->firstOrFail() : null;
        $revision = $link ? ImportedProjectRevision::whereKey($link->current_revision_id)->sharedLock()->firstOrFail() : null;
        $customer = $link ? Customer::whereKey($link->customer_id)->sharedLock()->firstOrFail() : null;
        $blocked = [];
        $block = function (string $field, string $code) use (&$blocked): void { $blocked[] = compact('field', 'code'); };
        if (!in_array($batch->status, ['prepared', 'needs_review', 'partially_committed'], true)
            || ($batch->profile['source_format'] ?? null) !== 'honeybook_future_v1') $block('batch', 'source_row_invalid');
        if ($row->status === 'skipped' || array_diff(array_column($row->errors, 'code'), ['owner_status_review_required', 'package_equation_mismatch']) !== []) {
            $block('row', 'source_row_invalid');
        }
        if (($row->normalized['source_format'] ?? null) !== 'honeybook_future_v1'
            || !hash_equals($row->normalized['source_sha256'] ?? '', MutationKey::hash($row->source))) $block('row', 'source_integrity_invalid');

        $view = ['batch' => ['id' => $batch->id, 'version' => $batch->version],
            'row' => ['id' => $row->id, 'version' => $row->version, 'preview_sha256' => $row->preview_sha256],
            'current' => null, 'kind' => 'new', 'changes' => [], 'blocked' => [], 'source_difference' => null];
        if ($link) {
            $operations = ImportedAgreement::operations($event, true);
            $reservationSnapshot = $event->reservations()->orderBy('id')->sharedLock()->get()
                ->map(fn ($r) => $r->only(['id', 'resource_id', 'starts_at', 'ends_at']))->all();
            $bookingSnapshot = \App\Models\BookingEvidence::where('event_id', $event->id)->orderBy('id')->sharedLock()->get()
                ->map(fn ($e) => $e->only(['id', 'imported_revision_id', 'staff_assignment_snapshot']))->all();
            $serviceSnapshot = $event->services()->with(['characters' => fn ($q) => $q->orderBy('id')->sharedLock()])->orderBy('id')->sharedLock()->get();
            $view['current'] = ['link_id' => $link->id, 'link_version' => $link->version, 'revision_id' => $revision->id,
                'event_id' => $event->id, 'event_version' => $event->version, 'customer_id' => $customer->id, 'customer_version' => $customer->version,
                'source_sha256' => $revision->source_sha256, 'operations_sha256' => MutationKey::hash(['services' => $operations,
                    'reservations' => $reservationSnapshot, 'booking' => $bookingSnapshot,
                    'selections' => $serviceSnapshot->map(fn ($s) => ['id' => $s->id, 'characters' => $s->characters->toArray()])->all()]), 'responsible_system' => $event->responsible_system];
            if ($event->responsible_system !== 'honeybook') $block('responsible_system', 'responsibility_changed');
            if ($link->event_id !== $event->id) $block('event', 'source_integrity_invalid');
            if ($event->customer_id !== $link->customer_id) $block('customer', 'customer_link_changed');
            if (!ImportedAgreement::capacityMatches($event, $revision, true)) $block('capacity', 'event_not_confirmed');
            if ($revision->imported_project_link_id !== $link->id
                || !hash_equals($revision->source_sha256, MutationKey::hash($revision->source_snapshot))
                || ($revision->normalized['source_sha256'] ?? null) !== $revision->source_sha256) $block('revision', 'source_integrity_invalid');

            foreach (HoneyBookFutureSource::HEADERS as $field) {
                if (!in_array($field, self::MONEY_FIELDS, true) && ($row->source[$field] ?? null) !== ($revision->source_snapshot[$field] ?? null)) {
                    $block($field, 'source_field_changed');
                }
            }
            $n = $revision->normalized;
            $m = $revision->mapping;
            $sameOperations = hash_equals($m['operations_sha256'] ?? '', $operations)
                && $event->title === trim($revision->source_snapshot['project_name'])
                && $event->address === trim($revision->source_snapshot['event_address'])
                && $event->postal_code === trim($revision->source_snapshot['event_zip'])
                && $event->timezone === 'America/Chicago'
                && $event->starts_at->equalTo(CarbonImmutable::parse($n['starts_at']))
                && $event->ends_at->equalTo(CarbonImmutable::parse($n['ends_at']))
                && $event->setup_minutes === $m['setup_minutes'] && $event->breakdown_minutes === $m['breakdown_minutes']
                && $event->travel_fee_cents === $n['travel_cents'];
            if (!$sameOperations) $block('operations', 'operations_changed');
            // Character notes are operational selections too, and the original operations hash predates notes.
            foreach ($serviceSnapshot as $service) {
                $selection = collect($m['intent'])->firstWhere('price_version_id', $service->price_version_id);
                foreach ($service->characters as $character) {
                    $chosen = collect($selection['characters'] ?? [])->first(fn ($c) => (int)$c['character_id'] === $character->character_id && ($c['outfit'] ?? null) === $character->outfit);
                    if (($chosen['note'] ?? null) !== $character->note) $block('character_note', 'operations_changed');
                }
            }
            // Do not trust a corrupted/missing source ledger merely because its net happens to match.
            $lines = ImportedLedgerLine::where('imported_project_revision_id', $revision->id)->orderBy('source_position')->sharedLock()->get();
            $actual = $lines->map(fn ($p) => ['source_position' => $p->source_position, 'received_date' => $p->received_date->format('Y-m-d'),
                'amount_cents' => $p->amount_cents, 'method' => $p->method, 'source' => $p->source, 'verified' => false])->all();
            if (MutationKey::hash($actual) !== MutationKey::hash($n['payments']) || (int)$lines->sum('amount_cents') !== $n['paid_cents']) {
                $block('payments', 'source_integrity_invalid');
            }
            foreach (['paid_cents', 'balance_cents', 'balance_due_date', 'payments'] as $field) {
                if (($n[$field] ?? null) !== ($row->normalized[$field] ?? null)) {
                    $view['changes'][] = ['field' => $field, 'from' => $n[$field] ?? null, 'to' => $row->normalized[$field] ?? null];
                }
            }
            if (in_array('package_equation_mismatch', array_column($row->errors, 'code'), true)) {
                $old = new ImportRow(['normalized' => $n]);
                $canRetain = HoneyBookSourceDifference::covers($old, $revision)
                    && self::commercialHash($row->source) === self::commercialHash($revision->source_snapshot);
                $view['source_difference'] = ['can_retain' => $canRetain, 'revision_id' => $revision->id,
                    'commercial_sha256' => self::commercialHash($row->source)];
                if (!$canRetain) $block('total', 'source_difference_review_required');
            }
            $view['kind'] = hash_equals($revision->source_sha256, $row->normalized['source_sha256'] ?? '') ? 'unchanged' : 'money_only';
        }
        $view['blocked'] = $blocked;
        if ($blocked !== []) $view['kind'] = 'blocked';
        $view['diff_sha256'] = MutationKey::hash($view);
        return compact('batch', 'row', 'event', 'link', 'revision', 'customer', 'view');
    }
}
