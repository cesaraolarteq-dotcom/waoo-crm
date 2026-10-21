<?php
namespace App\Services\History;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, ExternalCustomerLink, HistoricalEvent, HistoricalEventRevision, HistoryImportDecision, HistoryImportRow, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Imports\{HoneyBookProjectIdentity, ImportInput, ImportPersistence};
use App\Services\Records\SaveCustomer;

final class CommitHistoricalRow
{
    public function execute(User $actor, int $batchId, int $rowId, #[\SensitiveParameter] array $input, string $key): array
    {
        $actor = HistoryAccess::actor($actor);
        $d = ImportInput::input($input, [
            'expected_batch_version' => 'required|integer|min:1', 'expected_row_version' => 'required|integer|min:1',
            'expected_preview_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'expected_match_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'customer' => 'required|array:mode,id,expected_version,name,email,phone,locale',
            'customer.mode' => 'required|in:link,create',
            'customer.id' => 'required_if:customer.mode,link|prohibited_unless:customer.mode,link|integer|min:1',
            'customer.expected_version' => 'required_if:customer.mode,link|prohibited_unless:customer.mode,link|integer|min:1',
            'customer.name' => 'required_if:customer.mode,create|prohibited_unless:customer.mode,create|string|max:255',
            'customer.email' => 'sometimes|nullable|prohibited_unless:customer.mode,create|email|max:255',
            'customer.phone' => 'sometimes|nullable|prohibited_unless:customer.mode,create|string|max:40',
            'customer.locale' => 'required_if:customer.mode,create|prohibited_unless:customer.mode,create|in:es,en',
            'source_status_confirmation' => 'required|in:completed,cancelled,archived_unknown',
            'event_type_confirmation' => 'required|in:birthday,baby_shower,gender_reveal,corporate,other,unknown',
            'expected_history_version' => 'sometimes|integer|min:1', 'expected_revision_id' => 'sometimes|integer|min:1',
            'expected_diff_sha256' => 'sometimes|string|regex:/^[a-f0-9]{64}$/D',
            'owner_note' => 'sometimes|nullable|string|min:5|max:1000',
        ]);
        foreach (['expected_batch_version', 'expected_row_version'] as $f) $d[$f] = (int)$d[$f];
        foreach (['expected_history_version','expected_revision_id'] as $f) if(isset($d[$f]))$d[$f]=(int)$d[$f];
        foreach (['id', 'expected_version'] as $f) if (isset($d['customer'][$f])) $d['customer'][$f] = (int)$d['customer'][$f];
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':history:'.$batchId.':row:'.$rowId.':commit';
        return ImportPersistence::transaction(function () use ($actor, $batchId, $rowId, $d, $key, $hash, $scope) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            ImportInput::lock(); HistoryAccess::actor($actor);
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) return $this->replay(HistoryImportDecision::whereKey($receipt->result_id)->lockForUpdate()->firstOrFail(), $batchId, $rowId, $hash);
            if ($decision = HistoryImportDecision::where('history_import_batch_id', $batchId)->where('staged_row_id', $rowId)->lockForUpdate()->first()) {
                RecordWriteRejected::unless(hash_equals($decision->request_sha256, $hash), 409, 'Esta fila ya tiene una decisión. No se modificó el historial.');
                $result = $this->replay($decision, $batchId, $rowId, $hash); MutationKey::store($scope, $key, $hash, 'history_import_decision', $decision->id); return $result;
            }
            $batch = ReadHistoricalImport::batch($batchId); $manifest = HistoryEnvelope::batch($batch);
            $row = HistoryImportRow::where('history_import_batch_id', $batchId)->whereKey($rowId)->lockForUpdate()->firstOrFail();
            $p = HistoryEnvelope::row($row, $manifest);
            RecordWriteRejected::unless($batch->version === $d['expected_batch_version'] && $row->version === $d['expected_row_version']
                && hash_equals($row->preview_sha256, $d['expected_preview_sha256']), 409, 'La fila o el lote cambió. Revisa antes de importar.');
            RecordWriteRejected::unless($batch->state === 'reviewing', 409, 'El lote ya está cerrado.');
            $this->past($p);
            $projectKey = hash('sha256', $p['source']['project_id']); HoneyBookProjectIdentity::history($projectKey);
            $customers = HistoricalCustomerMatch::customers();
            $match = HistoricalCustomerMatch::read($p['source'], $customers);
            RecordWriteRejected::unless(hash_equals($match['expected_match_sha256'], $d['expected_match_sha256']), 409, 'Las coincidencias de clienta cambiaron. Revisa antes de importar.');
            $customer = $this->customer($actor, $d, $p['source'], $customers, $match);
            $history = HistoricalEvent::where('project_key', $projectKey)->lockForUpdate()->first();
            $facts = [...$p['normalized'], 'status' => $d['source_status_confirmation'], 'event_type' => $d['event_type_confirmation']];
            $sourceHash = MutationKey::hash($p['source']); $factsHash = MutationKey::hash($facts);
            $state = 'imported'; $createRevision = true; $previousRevisionId = null;
            if ($history) {
                $revision = HistoricalEventRevision::whereKey($history->current_revision_id)->lockForUpdate()->firstOrFail();
                HistoricalIntegrity::revision($history, $revision);
                RecordWriteRejected::unless($history->customer_id === $customer->id,409,'El proyecto pertenece a otra clienta; no se reasignó.');
                if($revision->source_sha256 === $sourceHash && $revision->facts_sha256 === $factsHash){$state='unchanged';$createRevision=false;}
                else {
                    RecordWriteRejected::unless(($d['expected_history_version']??null)===$history->version && ($d['expected_revision_id']??null)===$revision->id
                        && isset($d['expected_diff_sha256']) && hash_equals(ReadHistoricalRow::diffHash($revision,$p['source'],$p['normalized']),$d['expected_diff_sha256'])
                        && mb_strlen(trim($d['owner_note']??''))>=5,409,'Este proyecto cambió. Revisa la diferencia, fija la versión y escribe el motivo.');
                    $previousRevisionId=$revision->id;$history->version++;
                }
            } else $history = HistoricalEvent::create(['project_key' => $projectKey, 'customer_id' => $customer->id, 'version' => 1]);
            if($createRevision){
                $payload = ['history_id' => $history->id, 'customer_id' => $customer->id, 'version' => $history->version, 'created_by' => $actor->id,
                    'source' => $p['source'], 'facts' => $facts, 'source_issues' => $p['issue_codes'], 'owner_note' => $d['owner_note'] ?? null,
                    'previous_revision_id'=>$previousRevisionId];
                $revision = HistoricalEventRevision::create(['historical_event_id' => $history->id, 'customer_id' => $customer->id,
                    'version' => $history->version, 'source_sha256' => $sourceHash, 'facts_sha256' => $factsHash, 'payload' => $payload,
                    'payload_sha256' => MutationKey::hash($payload), 'created_by' => $actor->id]);
                $history->current_revision_id = $revision->id; $history->save();
            }
            $this->contact($actor, $p['source']['contact_id'], $customer->id);
            $batch->version++; $row->version++;
            $result = ['state' => $state, 'history_id' => $history->id, 'history_version' => $history->version,
                'revision_id' => $revision->id, 'customer_id' => $customer->id, 'batch_version' => $batch->version, 'row_version' => $row->version];
            $decisionPayload = ['batch_id' => $batchId, 'row_id' => $rowId, 'line' => $row->source_line,
                'created_by' => $actor->id, 'input' => $d, 'result' => $result];
            $decision = HistoryImportDecision::create(['history_import_batch_id' => $batchId, 'staged_row_id' => $rowId,
                'source_line' => $row->source_line, 'request_sha256' => $hash, 'payload' => $decisionPayload,
                'payload_sha256' => MutationKey::hash($decisionPayload), 'created_by' => $actor->id]);
            $p['version'] = $row->version; $p['decision'] = ['id' => $decision->id, ...$result];
            $row->payload = $p; $row->preview_sha256 = MutationKey::hash($p); $row->save();
            $manifest['version'] = $batch->version; $manifest['rows'][(string)$rowId] = $row->preview_sha256;
            $manifest['counts']['imported']++; $manifest['counts'][$state === 'unchanged' ? 'unchanged' : 'new']++;
            $batch->manifest = $manifest; $batch->coverage_sha256 = MutationKey::hash($manifest); $batch->save();
            Audit::recordFor($actor, 'history.row_committed', $history, [], ['batch_id' => $batchId, 'decision_id' => $decision->id,
                'revision_id' => $revision->id, 'customer_id' => $customer->id, 'state' => $state]);
            MutationKey::store($scope, $key, $hash, 'history_import_decision', $decision->id);
            return $result;
        });
    }

    private function past(array $p): void
    {
        $blocking = array_filter($p['issue_codes'], fn ($c) => in_array($c, ['project_id_required', 'event_date_required', 'event_date_invalid',
            'end_date_invalid', 'duplicate_project_id', 'start_time_invalid', 'end_time_invalid', 'start_time_nonexistent', 'end_time_nonexistent',
            'start_time_ambiguous', 'end_time_ambiguous', 'end_not_after_start', 'not_safely_past'], true));
        $n = $p['normalized'];
        if (($n['record_kind'] ?? 'event') === 'prospect' && !$blocking) return;
        $today = now('America/Chicago')->toDateString();
        $past = $n['time_precision'] === 'date' ? ($n['source_date'] < $today && $n['end_date'] < $today)
            : ($n['end_at'] && \Carbon\CarbonImmutable::parse($n['end_at'])->lessThanOrEqualTo(now()));
        RecordWriteRejected::unless(!$blocking && $past, 409, 'Revisa la identidad y la fecha u horario de esta fiesta antes de archivarla.');
    }

    private function customer(User $actor, array $d, array $source, $customers, array $match): Customer
    {
        $input = $d['customer'];
        if ($input['mode'] === 'link') {
            $c = $customers->firstWhere('id', $input['id']);
            RecordWriteRejected::unless($c && $c->version === $input['expected_version'], 409, 'La clienta cambió o no existe.');
            RecordWriteRejected::unless(!$match['linked_customer_id'] || $match['linked_customer_id'] === $c->id, 409, 'El contacto de origen ya tiene otra clienta vinculada.');
            if (in_array($match['state'], ['ambiguous', 'conflicting'], true) || !in_array($c->id, array_column($match['candidates'], 'id'), true)) {
                ImportInput::input(['owner_note' => $d['owner_note'] ?? null], ['owner_note' => 'required|string|min:5|max:1000']);
            }
            return $c;
        }
        $proposed = [...$source, 'contact_id' => '', 'client_email' => $input['email'] ?? '', 'client_phone' => $input['phone'] ?? ''];
        $newMatch = HistoricalCustomerMatch::read($proposed, $customers);
        RecordWriteRejected::unless($match['candidate_count'] === 0 && $newMatch['candidate_count'] === 0, 409, 'Ya hay posibles coincidencias. Elige una clienta existente sin duplicarla.');
        unset($input['mode']);
        return Audit::metadataOnly(fn () => app(SaveCustomer::class)->execute($actor, $input));
    }

    private function contact(User $actor, string $sourceId, int $customerId): void
    {
        if ($sourceId === '') return;
        $key = hash('sha256', $sourceId);
        $existing = ExternalCustomerLink::where('source_system', 'honeybook')->where('contact_key', $key)->lockForUpdate()->first();
        if ($existing) {
            HistoricalIntegrity::contact($existing);
            RecordWriteRejected::unless($existing->customer_id === $customerId, 409, 'El contacto de origen ya tiene otra clienta vinculada.'); return;
        }
        $payload = ['contact_id' => $sourceId, 'customer_id' => $customerId, 'version' => 1, 'created_by' => $actor->id];
        ExternalCustomerLink::create(['source_system' => 'honeybook', 'contact_key' => $key, 'customer_id' => $customerId,
            'version' => 1, 'payload' => $payload, 'payload_sha256' => MutationKey::hash($payload), 'created_by' => $actor->id]);
    }

    private function replay(HistoryImportDecision $decision, int $batchId, int $rowId, string $hash): array
    {
        RecordWriteRejected::unless($decision->history_import_batch_id === $batchId && $decision->staged_row_id === $rowId
            && hash_equals($decision->request_sha256, $hash), 409, 'La evidencia de esta solicitud requiere revisión.');
        $p = HistoricalIntegrity::decision($decision); $r = $p['result'];
        RecordWriteRejected::unless(in_array($r['state'], ['imported','unchanged'], true), 409, 'La fila fue excluida.');
        $history = HistoricalEvent::whereKey($r['history_id'])->lockForUpdate()->firstOrFail();
        $revision = HistoricalEventRevision::whereKey($r['revision_id'])->lockForUpdate()->firstOrFail();
        HistoricalIntegrity::revision($history, $revision, false);
        RecordWriteRejected::unless($history->customer_id === $r['customer_id'] && $revision->version === $r['history_version'], 409, 'La evidencia histórica requiere revisión.');
        return $r;
    }
}
