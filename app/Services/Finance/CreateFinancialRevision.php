<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, PriceVersion, User};
use App\Services\Audit;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateFinancialRevision
{
    public function execute(User $actor, int $eventId, array $input, string $key): FinancialRevision
    {
        Sandbox::require(); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1']);
        $data['expected_version'] = (int) $data['expected_version'];
        $scope = "owner:{$actor->id}:event:{$eventId}:finance"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return FinancialRevision::findOrFail($saved->result_id);
            RecordWriteRejected::unless($event->version === $data['expected_version'], 409, 'El evento cambió. Actualiza antes de fijar sus importes.');
            // Changing an already committed agreement belongs to CHANGE-A, not this initial snapshot service.
            RecordWriteRejected::unless($event->status === 'draft', 409, 'Un evento reservado requiere el flujo de cambios financieros.');
            $services = $event->services()->with('characters')->get();
            if ($services->isEmpty() || $event->travel_fee_cents === null) throw ValidationException::withMessages(['finance' => 'Completa los servicios y el costo de transporte; no se asume cero.']);
            $lines = []; $subtotal = 0;
            foreach ($services as $service) {
                $price = PriceVersion::findOrFail($service->price_version_id);
                if ($price->currency !== 'USD') throw ValidationException::withMessages(['finance' => 'La moneda debe ser USD.']);
                $characterSnapshot = $service->characters->map(fn ($row) => ['character_id' => $row->character_id, 'quantity' => $row->quantity, 'name' => $row->character_name_snapshot])->all();
                if ($characterSnapshot === []) {
                    foreach ($service->requirements_snapshot as $requirement) {
                        if ($requirement['kind'] === 'costume') {
                            $character = \App\Models\Character::findOrFail($requirement['character_id']);
                            $characterSnapshot[] = ['character_id' => $character->id, 'quantity' => (int) $requirement['quantity'], 'name' => $character->name];
                        }
                    }
                }
                $lines[] = ['event_service_id' => $service->id, 'service_variant_id' => $service->service_variant_id, 'price_version_id' => $price->id, 'amount_cents' => $price->amount_cents, 'name' => \App\Models\ServiceVariant::findOrFail($service->service_variant_id)->name, 'duration_minutes' => $service->duration_minutes, 'requirements_snapshot' => $service->requirements_snapshot, 'characters' => $characterSnapshot];
                $subtotal += $price->amount_cents;
            }
            $total = $subtotal + $event->travel_fee_cents;
            $revision = FinancialRevision::create(['event_id' => $event->id, 'revision' => (int) FinancialRevision::where('event_id', $event->id)->max('revision') + 1,
                'event_version' => $event->version, 'currency' => 'USD', 'lines_snapshot' => $lines, 'subtotal_cents' => $subtotal,
                'travel_fee_cents' => $event->travel_fee_cents, 'tax_cents' => 0, 'customer_fee_cents' => 0, 'total_cents' => $total,
                'deposit_target_cents' => intdiv($total, 2) + ($total % 2), 'balance_due_at' => $event->starts_at->subHours(24), 'created_by' => $actor->id]);
            SupersedeCollections::forEvent($eventId, 'financial_revision_changed', $actor);
            Audit::recordFor($actor, 'finance.revision_created', $revision, [], $revision->getAttributes());
            MutationKey::store($scope, $key, $hash, 'financial_revision', $revision->id);
            return $revision;
        }, 3);
    }
}
