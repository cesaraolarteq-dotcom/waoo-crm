<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, ContractTemplateVersion, Event, FinancialRevision, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateContract
{
    public static function assertCurrent(Event $event, FinancialRevision $finance): void
    {
        $linked = DB::table('capacity_payment_links')->where('event_id', $event->id)->where('financial_revision_id', $finance->id)->exists();
        RecordWriteRejected::unless(in_array($event->status, ['draft','capacity_reserved'], true) && ($event->version === $finance->event_version || ($event->status === 'capacity_reserved' && $linked))
            && (int) FinancialRevision::where('event_id', $event->id)->max('revision') === $finance->revision, 409, 'Revisa el evento y la revisión financiera vigente antes de emitir el contrato.');
    }
    public function execute(User $actor, int $eventId, array $input, string $key): Contract
    {
        Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'financial_revision_id' => 'required|integer|min:1', 'template_version_id' => 'required|integer|min:1',
            'client' => 'required|array:first_name,last_name,address,email,phone', 'client.first_name' => 'required|string|max:120', 'client.last_name' => 'required|string|max:120', 'client.address' => 'required|string|max:255', 'client.email' => 'required|email|max:255', 'client.phone' => 'required|string|max:40']);
        $scope = "owner:{$actor->id}:event:{$eventId}:contract"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $data, $key, $scope, $hash) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
            if ($saved = MutationKey::find($scope, $key, $hash)) return Contract::findOrFail($saved->result_id);
            RecordWriteRejected::unless($event->version === (int) $data['expected_version'], 409, 'El evento cambió. Actualiza la ficha.');
            $finance = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->firstOrFail(); self::assertCurrent($event, $finance);
            \App\Services\Booking\ClientDetailsState::assertApproved($eventId, $finance->id);
            $template = ContractTemplateVersion::findOrFail($data['template_version_id']);
            if (! $event->address || ! $event->customer?->email || strcasecmp($event->customer->email, $data['client']['email']) !== 0 || $event->services()->where('operational_ready', false)->exists()) {
                throw ValidationException::withMessages(['contract' => 'Completa dirección, identidad/correo de la clienta y selecciones operativas antes de generar el contrato.']);
            }
            $snapshot = ['client' => $data['client'], 'event' => ['date_long' => $event->starts_at->setTimezone('America/Chicago')->locale('en')->translatedFormat('D, M j, Y'),
                'start_time' => $event->starts_at->setTimezone('America/Chicago')->format('g:i a'), 'end_time' => $event->ends_at->setTimezone('America/Chicago')->format('g:i a'), 'address' => $event->address],
                'services' => $finance->lines_snapshot, 'travel_fee_cents' => $finance->travel_fee_cents, 'total_cents' => $finance->total_cents,
                'discounts'=>array_map(fn($d)=>['label'=>$d['label'],'amount_cents'=>$d['amount_cents']],$finance->discounts_snapshot??[]),
                'balance_due_at' => $finance->balance_due_at->toIso8601String()];
            foreach ($snapshot['services'] as $line) {
                if (empty($line['name']) || !isset($line['duration_minutes'])) throw ValidationException::withMessages(['contract' => 'La revisión financiera necesita descripciones de servicio completas.']);
            }
            $contract = Contract::create(['id' => (string) Str::uuid(), 'event_id' => $eventId, 'financial_revision_id' => $finance->id, 'event_version' => $event->version, 'template_version_id' => $template->id,
                'revision' => (int) Contract::where('event_id', $eventId)->max('revision') + 1, 'state' => 'draft', 'signer_customer_id' => $event->customer_id, 'signer_email' => $data['client']['email'], 'commercial_snapshot' => $snapshot]);
            Audit::recordFor($actor, 'contract.drafted', $contract, [], ['financial_revision_id' => $finance->id, 'template_version_id' => $template->id, 'event_version' => $event->version]);
            MutationKey::store($scope, $key, $hash, 'contract', $contract->id);
            return $contract->fresh();
        }, 3);
    }
}
