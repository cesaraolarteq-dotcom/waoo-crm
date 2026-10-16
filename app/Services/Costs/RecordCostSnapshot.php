<?php
namespace App\Services\Costs;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, EventCostSnapshot, FinancialRevision, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RecordCostSnapshot
{
    public function execute(User $actor, int $eventId, array $input, string $key): EventCostSnapshot
    {
        Sandbox::require(); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['kind' => ['required', Rule::in(['estimate','actual'])], 'expected_event_version' => 'required|integer|min:1',
            'financial_revision_id' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:0', 'coverage_confirmed' => 'required|boolean',
            'note' => 'required|string|min:10|max:2000', 'lines' => 'present|array|max:100', 'lines.*' => 'array:label,category,amount_cents,event_service_id',
            'lines.*.label' => 'required|string|max:120', 'lines.*.category' => ['required', Rule::in(['staff_labor','staff_transport','materials','card_processing','other'])],
            'lines.*.amount_cents' => 'present|nullable|integer|min:0|max:100000000', 'lines.*.event_service_id' => 'sometimes|nullable|integer|min:1']);
        foreach (['expected_event_version','financial_revision_id','expected_revision'] as $field) $data[$field] = (int) $data[$field];
        $data['coverage_confirmed'] = (bool) $data['coverage_confirmed'];
        $data['lines'] = array_values(array_map(fn ($line) => [...$line, 'amount_cents' => $line['amount_cents'] === null ? null : (int) $line['amount_cents'],
            'event_service_id' => isset($line['event_service_id']) ? (int) $line['event_service_id'] : null], $data['lines']));
        $scope = "owner:{$actor->id}:event:{$eventId}:cost:{$data['kind']}"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return EventCostSnapshot::findOrFail($saved->result_id);
            RecordWriteRejected::unless($event->version === $data['expected_event_version'], 409, 'El evento cambió. Revisa los costos antes de guardar.');
            $finance = FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->firstOrFail();
            RecordWriteRejected::unless($finance->currency === 'USD' && $finance->id === $data['financial_revision_id'] && ($event->status !== 'draft' || $finance->event_version === $event->version), 409, 'Revisa primero la revisión financiera vigente.');
            $revision = (int) EventCostSnapshot::where('event_id', $eventId)->where('kind', $data['kind'])->max('revision');
            RecordWriteRejected::unless($revision === $data['expected_revision'], 409, 'Otra persona guardó costos. Actualiza antes de reemplazarlos.');
            $serviceIds = array_column($finance->lines_snapshot, 'event_service_id');
            foreach ($data['lines'] as $line) {
                RecordWriteRejected::when($line['event_service_id'] !== null && !in_array($line['event_service_id'], $serviceIds, true), 422, 'El costo debe pertenecer a un servicio de este acuerdo.');
            }
            $unknown = count(array_filter($data['lines'], fn ($line) => $line['amount_cents'] === null));
            $snapshot = EventCostSnapshot::create(['event_id' => $eventId, 'financial_revision_id' => $finance->id, 'event_version' => $event->version,
                'kind' => $data['kind'], 'revision' => $revision + 1, 'currency' => $finance->currency, 'lines_snapshot' => $data['lines'],
                'known_cost_cents' => array_sum(array_column($data['lines'], 'amount_cents')), 'unknown_line_count' => $unknown,
                'coverage_confirmed' => $data['coverage_confirmed'], 'complete' => $data['coverage_confirmed'] && $unknown === 0,
                'note' => $data['note'], 'created_by' => $actor->id]);
            Audit::recordFor($actor, 'cost.snapshot_recorded', $snapshot, [], $snapshot->getAttributes());
            MutationKey::store($scope, $key, $hash, 'event_cost_snapshot', $snapshot->id);
            return $snapshot;
        }, 3);
    }
}
