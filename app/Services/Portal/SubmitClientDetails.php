<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsSubmission, Contract, Customer, Event};
use App\Rules\ExplicitOffset;
use App\Support\ChicagoTime;
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\RecordInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

final class SubmitClientDetails
{
    public function execute(PortalContext $client, array $input, string $key): ClientDetailsSubmission
    {
        Sandbox::require('sandbox_documents');
        if ($client->adminAuthenticated) throw new AuthorizationException('Usa el acceso del cliente para completar sus datos.');
        $data = RecordInput::validate($input, [
            'expected_event_version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:0', 'first_name' => 'required|string|max:120', 'last_name' => 'required|string|max:120',
            'phone' => 'required|string|max:40', 'email' => 'required|email|max:255', 'billing_address' => 'required|string|max:255', 'event_address' => 'required|string|max:255',
            'event_postal_code' => ['required','string','regex:/^[0-9]{5}(?:-[0-9]{4})?$/'], 'locale' => ['sometimes', Rule::in(['es','en'])],
            'start_time_confirmed' => 'required|boolean', 'requested_start_at' => ['nullable','date', new ExplicitOffset],
            'requested_local_time' => 'nullable|date_format:H:i', 'requested_time_choice' => ['nullable', Rule::in(['cdt', 'cst'])],
            'child_name' => 'nullable|string|max:120', 'child_age' => 'nullable|integer|min:0|max:120', 'theme' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000',
            // Owner's HoneyBook questions (2026-10-20); optional here so older links and API clients keep working.
            'event_language' => ['nullable', Rule::in(['es','en','bilingual'])], 'setup_area' => ['nullable', Rule::in(['indoor','outdoor'])], 'children_count' => 'nullable|integer|min:1|max:500',
            'sms_consent' => 'sometimes|boolean', 'sms_consent_version' => 'nullable|required_if:sms_consent,true|string|max:60',
        ]);
        $data['expected_revision'] = (int) $data['expected_revision'];
        $data['expected_event_version'] = (int) $data['expected_event_version'];
        $data['start_time_confirmed'] = (bool) $data['start_time_confirmed'];
        $data['sms_consent'] = (bool) ($data['sms_consent'] ?? false);
        if (! $data['start_time_confirmed']) {
            $hasIso = filled($data['requested_start_at'] ?? null);
            $hasLocal = filled($data['requested_local_time'] ?? null);
            if ($hasIso === $hasLocal) throw ValidationException::withMessages(['requested_local_time' => 'Indica una sola hora solicitada: local de Houston o con zona horaria. / Provide one requested time: Houston local time or an explicit offset.']);
        }
        $scope = "customer:{$client->customerId}:event:{$client->eventId}:details"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($client, $data, $key, $scope, $hash) {
            $client->authorize($client->eventId, 'details.submit', true);
            $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return ClientDetailsSubmission::findOrFail($saved->result_id);
            RecordWriteRejected::unless($event->version === $data['expected_event_version'], 409, 'Los datos del evento cambiaron. Recarga y confirma la hora vigente.');
            RecordWriteRejected::unless(in_array($event->status, ['draft','capacity_reserved'], true), 409, 'Esta reserva requiere revisión del administrador.');
            RecordWriteRejected::when(Contract::where('event_id', $event->id)->where('state', 'signed')->exists(), 409, 'Los cambios de un contrato firmado requieren revisión del administrador.');
            $revision = (int) ClientDetailsSubmission::where('event_id', $event->id)->max('revision');
            RecordWriteRejected::unless($revision === $data['expected_revision'], 409, 'Tus datos cambiaron. Recarga y revisa la versión actual antes de enviarlos.');
            $customer = Customer::whereKey($event->customer_id)->lockForUpdate()->firstOrFail();
            $payload = $data; unset($payload['expected_revision'], $payload['expected_event_version'], $payload['requested_local_time'], $payload['requested_time_choice']);
            if (! $data['start_time_confirmed'] && filled($data['requested_local_time'] ?? null)) {
                $date = $event->starts_at->setTimezone(ChicagoTime::TZ)->format('Y-m-d');
                $candidates = ChicagoTime::candidates($date, $data['requested_local_time']);
                if ($candidates === []) throw ValidationException::withMessages(['requested_local_time' => 'Esa hora no existe ese día en Houston. Elige otra. / That time does not exist in Houston on this date. Choose another.']);
                if (count($candidates) > 1 && ! isset($candidates[$data['requested_time_choice'] ?? ''])) throw ValidationException::withMessages(['requested_time_choice' => 'Esa hora ocurre dos veces. Elige la primera o la segunda. / That time occurs twice. Choose the first or second occurrence.']);
                $payload['requested_start_at'] = count($candidates) === 1 ? reset($candidates) : $candidates[$data['requested_time_choice']];
            }
            $payload['sms_consent_record'] = null;
            if ($data['sms_consent']) {
                $approved = config('waoo.sms_consent_versions', []);
                $text = $approved[$data['sms_consent_version']] ?? null;
                if (!is_string($text) || $text === '') throw ValidationException::withMessages(['sms_consent' => 'El consentimiento SMS no está habilitado. Puedes continuar sin marcarlo.']);
                $payload['sms_consent_record'] = ['version' => $data['sms_consent_version'], 'text' => $text, 'sha256' => hash('sha256', $text), 'accepted_at' => now()->toIso8601String()];
            }
            if ($data['start_time_confirmed']) $payload['requested_start_at'] = null;
            $submission = ClientDetailsSubmission::create(['event_id' => $event->id, 'customer_id' => $customer->id, 'portal_grant_id' => $client->grantId,
                'revision' => $revision + 1, 'event_version' => $event->version, 'data' => $payload, 'base_customer_snapshot' => $customer->only(['name','email','phone','locale']),
                'ip' => $client->ip, 'user_agent' => $client->userAgent, 'submitted_at' => now()]);
            Audit::recordSystem('portal.details_submitted', $submission, [], ['customer_id' => $customer->id, 'portal_grant_id' => $client->grantId, 'event_id' => $event->id, 'revision' => $submission->revision]);
            MutationKey::store($scope, $key, $hash, 'client_details_submission', $submission->id);
            return $submission;
        }, 3);
    }
}
