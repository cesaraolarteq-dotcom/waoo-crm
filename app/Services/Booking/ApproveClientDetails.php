<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Contract, Customer, Event, User};
use App\Services\Audit;
use App\Services\Finance\{CreateFinancialRevision, MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput, SaveCustomer, SaveEventDraft};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

final class ApproveClientDetails
{
    public function execute(User $actor, int $eventId, int $submissionId, array $input, string $key): ClientDetailsReview
    {
        Sandbox::require(); Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_event_version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1',
            'travel_fee_confirmed' => 'required|accepted', 'review_note' => 'required|string|min:10|max:2000', 'requested_start_resolution' => ['sometimes','nullable', Rule::in(['keep_original'])],
            'setup_minutes' => 'sometimes|integer|min:0|max:720', 'breakdown_minutes' => 'sometimes|integer|min:0|max:720']);
        $data['expected_event_version'] = (int) $data['expected_event_version']; $data['expected_revision'] = (int) $data['expected_revision']; $data['travel_fee_confirmed'] = true;
        $scope = "owner:{$actor->id}:event:{$eventId}:details:{$submissionId}:approve"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $submissionId, $data, $scope, $hash, $key) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return ClientDetailsReview::findOrFail($saved->result_id);
            $submission = ClientDetailsSubmission::where('event_id', $eventId)->where('customer_id', $event->customer_id)->whereKey($submissionId)->firstOrFail();
            RecordWriteRejected::unless(in_array($event->status, ['draft','capacity_reserved'], true) && $event->version === $submission->event_version && $event->version === $data['expected_event_version'] && $submission->revision === $data['expected_revision']
                && (int) ClientDetailsSubmission::where('event_id', $eventId)->max('revision') === $submission->revision, 409, 'El evento o los datos del cliente cambiaron. Recarga antes de aprobar.');
            RecordWriteRejected::when(ClientDetailsReview::where('submission_id', $submission->id)->exists() || Contract::where('event_id', $eventId)->where('state', 'signed')->exists(), 409, 'Estos datos ya se aprobaron o existe un contrato firmado. Usa el flujo de cambios.');
            $values = $submission->data;
            if (!$values['start_time_confirmed'] && ($data['requested_start_resolution'] ?? null) !== 'keep_original') throw ValidationException::withMessages(['requested_start_resolution' => 'Confirma con el cliente la hora original o gestiona el cambio por separado.']);
            $customer = Customer::whereKey($event->customer_id)->lockForUpdate()->firstOrFail();
            $previousFinance = \App\Models\FinancialRevision::where('event_id', $eventId)->orderByDesc('revision')->firstOrFail();
            RecordWriteRejected::unless(MutationKey::hash($customer->only(['name','email','phone','locale'])) === MutationKey::hash($submission->base_customer_snapshot), 409, 'La ficha del cliente cambió después del envío. Solicita datos actualizados antes de aplicarlos.');
            $reserved = $event->status === 'capacity_reserved';
            foreach (['setup_minutes','breakdown_minutes'] as $field) {
                if ($event->{$field} === null && !isset($data[$field]) && \App\Models\QuickBookingRecord::where('event_id', $eventId)->exists()) {
                    throw ValidationException::withMessages([$field => 'Completa los minutos operativos antes de aprobar; no se asume cero.']);
                }
                if ($reserved && isset($data[$field])) RecordWriteRejected::unless((int) $data[$field] === $event->{$field}, 409, 'Los tiempos operativos de una reserva comprometida requieren el flujo de cambios.');
            }
            if ($reserved) {
                RecordWriteRejected::unless($event->address === $values['event_address'] && $event->postal_code === ($values['event_postal_code'] ?? $event->postal_code), 409, 'La dirección de una reserva comprometida requiere el flujo de cambios. No se reasignan recursos aquí.');
                \App\Services\Documents\CreateContract::assertCurrent($event, $previousFinance);
            }
            $beforeServices = $event->services()->with('characters')->orderBy('price_version_id')->get();
            $recipe = fn ($services) => $services->map(fn ($s) => ['price_version_id' => $s->price_version_id, 'duration_minutes' => $s->duration_minutes,
                'requirements' => $s->requirements_snapshot, 'ready' => $s->operational_ready,
                'characters' => $s->characters->map(fn ($c) => $c->only(['character_id','quantity','character_name_snapshot','outfit','note']))->all()])->values()->all();
            app(SaveCustomer::class)->execute($actor, ['expected_version' => $customer->version, 'name' => $values['first_name'].' '.$values['last_name'], 'email' => $values['email'], 'phone' => $values['phone'], 'locale' => $values['locale'] ?? $customer->locale], $customer->id);
            $updated = $event; $finance = $previousFinance;
            if (!$reserved) {
                $updated = app(SaveEventDraft::class)->execute($actor, [...$event->only(['customer_id','title','event_type','timezone','setup_minutes','breakdown_minutes','travel_fee_cents']),
                    'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(),
                    'setup_minutes' => $data['setup_minutes'] ?? $event->setup_minutes, 'breakdown_minutes' => $data['breakdown_minutes'] ?? $event->breakdown_minutes,
                    ...($event->travel_fee_evidence ? ['travel_fee_override_note' => mb_substr($data['review_note'], 0, 1000)] : []),
                    'address' => $values['event_address'], 'postal_code' => $values['event_postal_code'] ?? $event->postal_code,
                    'expected_version' => $event->version, 'services' => $beforeServices->map(fn ($s) => ['price_version_id' => $s->price_version_id,
                        'characters' => $s->characters->map(fn ($c) => $c->only(['character_id','quantity','outfit','note']))->all()])->all(),
                ], $event->id);
                RecordWriteRejected::unless($recipe($beforeServices) === $recipe($updated->services->sortBy('price_version_id')), 409, 'La receta del catálogo cambió. Revisa los servicios antes de aplicar la dirección.');
                $finance = app(CreateFinancialRevision::class)->execute($actor, $event->id, ['expected_version' => $updated->version], (string) Str::uuid());
                RecordWriteRejected::unless($previousFinance->only(['subtotal_cents','travel_fee_cents','tax_cents','customer_fee_cents','total_cents','deposit_target_cents']) === $finance->only(['subtotal_cents','travel_fee_cents','tax_cents','customer_fee_cents','total_cents','deposit_target_cents'])
                    && $previousFinance->balance_due_at->equalTo($finance->balance_due_at), 409, 'Los montos o la fecha cambiaron. Revisa la cotización antes de aprobar estos datos.');
            }
            $review = ClientDetailsReview::create(['submission_id' => $submission->id, 'event_id' => $eventId, 'event_version' => $updated->version,
                'financial_revision_id' => $finance->id, 'reviewed_by' => $actor->id, 'review_note' => $data['review_note'], 'travel_fee_confirmed' => true,
                'requested_start_resolution' => $data['requested_start_resolution'] ?? null, 'reviewed_at' => now()]);
            Contract::where('event_id', $eventId)->where('state', 'issued')->update(['state' => 'superseded', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            Audit::recordFor($actor, 'portal.details_approved', $review, [], ['submission_id' => $submission->id, 'financial_revision_id' => $finance->id, 'event_version' => $updated->version, 'review_note' => $data['review_note']]);
            MutationKey::store($scope, $key, $hash, 'client_details_review', $review->id);
            return $review;
        }, 3);
    }
}
