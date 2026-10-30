<?php

namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch, ImportFile, ImportedLedgerLine, ImportedProjectLink, ImportedProjectRevision, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;

/** Invoked only by CommitHoneyBookEvent's explicit resync branch, never a second public import route. */
final class ResyncHoneyBookMoney
{
    public function execute(User $actor, int $batchId, int $rowId, #[\SensitiveParameter] array $input, string $key): ImportedProjectLink
    {
        $actor = ImportInput::actor($actor);
        $d = ImportInput::input($input, [
            'expected_batch_version' => 'required|integer|min:1', 'expected_row_version' => 'required|integer|min:1',
            'expected_preview_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'resync' => 'required|array:expected_link_version,expected_revision_id,expected_event_version,expected_customer_version,expected_diff_sha256,owner_money_reviewed,owner_note,retain_source_difference_revision_id',
            'resync.expected_link_version' => 'required|integer|min:1', 'resync.expected_revision_id' => 'required|integer|min:1',
            'resync.expected_event_version' => 'required|integer|min:1', 'resync.expected_customer_version' => 'required|integer|min:1',
            'resync.expected_diff_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'resync.owner_money_reviewed' => 'required|accepted',
            'resync.owner_note' => 'required|string|min:5|max:1000', 'resync.retain_source_difference_revision_id' => 'sometimes|integer|min:1',
        ]);
        foreach (['expected_batch_version', 'expected_row_version'] as $f) $d[$f] = (int)$d[$f];
        foreach (['expected_link_version', 'expected_revision_id', 'expected_event_version', 'expected_customer_version', 'retain_source_difference_revision_id'] as $f) {
            if (isset($d['resync'][$f])) $d['resync'][$f] = (int)$d['resync'][$f];
        }
        $d['resync']['owner_money_reviewed'] = true;
        $hash = MutationKey::hash($d);
        $scope = "owner:{$actor->id}:import:{$batchId}:row:{$rowId}:commit";
        return ImportPersistence::transaction(function () use ($actor, $batchId, $rowId, $d, $hash, $scope, $key) {
            \App\Services\Scheduling\DailyCapacity::lock();
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ImportInput::actor($actor);
            ImportInput::lock();
            $batch = ImportBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            // Current receipt read after serialization, even if authorization established an older RR snapshot.
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                return ImportedProjectLink::whereKey($receipt->result_id)->sharedLock()->firstOrFail();
            }
            $c = app(HoneyBookResyncReview::class)->context($batch, $rowId);
            ['row' => $row, 'link' => $link, 'revision' => $old, 'event' => $event, 'view' => $view] = $c;
            $r = $d['resync'];
            RecordWriteRejected::unless($link && in_array($view['kind'], ['unchanged', 'money_only'], true), 409,
                'Esta actualización requiere revisar el acuerdo o la operación; no se cambiaron los pagos.');
            RecordWriteRejected::unless($batch->version === $d['expected_batch_version'] && $row->version === $d['expected_row_version']
                && hash_equals($row->preview_sha256, $d['expected_preview_sha256'])
                && $link->version === $r['expected_link_version'] && $old->id === $r['expected_revision_id']
                && $event->version === $r['expected_event_version'] && $c['customer']->version === $r['expected_customer_version']
                && hash_equals($view['diff_sha256'], $r['expected_diff_sha256']), 409,
                'La revisión de pagos cambió. Vuelve a revisar antes de guardar.');
            if ($view['source_difference'] !== null) {
                RecordWriteRejected::unless(($r['retain_source_difference_revision_id'] ?? null) === $old->id, 409,
                    'Confirma conservar la evidencia original de la diferencia de total.');
            } elseif (isset($r['retain_source_difference_revision_id'])) {
                ImportInput::fail('resync.retain_source_difference_revision_id', 'source_difference_not_present');
            }
            if ($view['kind'] === 'unchanged') {
                MutationKey::store($scope, $key, $hash, 'imported_project_link', $link->id);
                return $link;
            }
            $file = ImportFile::where('import_batch_id', $batch->id)->where('role', 'projects')->sharedLock()->sole();
            app(ImportArtifacts::class)->read($file);
            $mapping = $old->mapping;
            if ($view['source_difference'] !== null) {
                $mapping['source_difference']['retained_from_revision_id'] = $mapping['source_difference']['retained_from_revision_id'] ?? $old->id;
                $mapping['source_difference']['commercial_sha256'] = $view['source_difference']['commercial_sha256'];
            }
            $mapping['money_resync'] = ['policy_version' => 'honeybook-money-resync-v1', 'previous_revision_id' => $old->id,
                'capacity_revision_id' => $old->mapping['money_resync']['capacity_revision_id'] ?? $old->id,
                'diff_sha256' => $view['diff_sha256'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'owner_note' => $r['owner_note']];
            $revision = ImportedProjectRevision::create(['imported_project_link_id' => $link->id, 'revision' => $link->version + 1,
                'import_batch_id' => $batch->id, 'import_row_id' => $row->id, 'event_version' => $event->version,
                'source_snapshot' => $row->source, 'normalized' => $row->normalized, 'mapping' => $mapping,
                'source_sha256' => $row->normalized['source_sha256'], 'file_sha256' => $file->sha256, 'created_by' => $actor->id]);
            foreach ($row->normalized['payments'] as $p) {
                ImportedLedgerLine::create(['imported_project_revision_id' => $revision->id, 'source_position' => $p['source_position'],
                    'amount_cents' => $p['amount_cents'], 'received_date' => $p['received_date'], 'method' => $p['method'], 'source' => 'honeybook']);
            }
            $link->forceFill(['version' => $link->version + 1, 'current_revision_id' => $revision->id,
                'domain_sha256' => MutationKey::hash(['source_sha256' => $revision->source_sha256, 'operations_sha256' => $mapping['operations_sha256'], 'customer_id' => $link->customer_id])])->save();
            $batch->forceFill(['version' => $batch->version + 1, 'status' => 'partially_committed'])->save();
            Audit::recordFor($actor, 'import.money_resynced', $link, [], ['import_batch_id' => $batch->id, 'previous_revision_id' => $old->id,
                'imported_revision_id' => $revision->id, 'source_sha256' => $revision->source_sha256, 'diff_sha256' => $view['diff_sha256']]);
            MutationKey::store($scope, $key, $hash, 'imported_project_link', $link->id);
            return $link;
        });
    }
}
