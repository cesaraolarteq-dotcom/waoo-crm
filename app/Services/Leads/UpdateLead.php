<?php
namespace App\Services\Leads;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Lead, User};
use App\Services\Audit;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

final class UpdateLead
{
    public function execute(User $actor, int $leadId, array $input): Lead
    {
        $actor = LeadAccess::owner($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'status' => ['sometimes', Rule::in(['new','contacted','quoted','lost'])], 'owner_note' => 'sometimes|nullable|string|max:2000']);
        if (!array_key_exists('status', $data) && !array_key_exists('owner_note', $data)) throw ValidationException::withMessages(['lead' => 'Indica un cambio.']);
        return DB::transaction(function () use ($actor, $leadId, $data) {
            $lead = Lead::whereKey($leadId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($lead->version === (int) $data['expected_version'], 409, 'El prospecto cambió. Recarga antes de guardar.');
            RecordWriteRejected::when($lead->booked_event_id !== null && isset($data['status']), 409, 'La reserva vinculada requiere su propio flujo de cambios.');
            $before = $lead->only(['status','owner_note','version']);
            $lead->fill(array_diff_key($data, ['expected_version' => true])); $lead->version++; $lead->save();
            Audit::recordFor($actor, 'lead.updated', $lead, $before, $lead->only(['status','owner_note','version']));
            return $lead;
        }, 3);
    }
}
