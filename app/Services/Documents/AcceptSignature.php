<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Jobs\FinalizeSignedContract;
use App\Models\{Contract, Event, SignatureEvidence};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AcceptSignature
{
    public function execute(PortalContext $client, string $contractId, array $input, SignatureRequestContext $request, string $key): SignatureEvidence
    {
        Sandbox::require('sandbox_documents');
        if ($request->adminAuthenticated) throw new AuthorizationException('Usa el acceso del cliente; una sesión administrativa no puede firmar por el cliente.');
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1', 'presented_pdf_sha256' => ['required','string','regex:/^[a-f0-9]{64}$/'],
            'signer_name' => 'required|string|min:2|max:255', 'consent' => 'required|accepted', 'intent_to_sign' => 'required|accepted', 'consent_version' => ['required', Rule::in(['esign-consent-v1', InHouseSignatureProvider::CONSENT_VERSION, 'esign-consent-v2-2026-10-20-es'])]]);
        $data['expected_version'] = (int) $data['expected_version']; $data['consent'] = true; $data['intent_to_sign'] = true;
        $scope = "customer:{$client->customerId}:contract:{$contractId}:sign"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($client, $contractId, $data, $request, $key, $scope, $hash) {
            $client->authorize($client->eventId, 'contract.sign', true);
            $event = Event::whereKey($client->eventId)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contractId)->where('event_id', $client->eventId)->where('signer_customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return SignatureEvidence::findOrFail($saved->result_id);
            InHouseSignatureProvider::consentForVersion($data['consent_version']);
            CreateContract::assertCurrent($event, \App\Models\FinancialRevision::findOrFail($contract->financial_revision_id));
            if (array_key_exists('client_details', $contract->commercial_snapshot)) ClientContractCurrent::check($client, $contract);
            else \App\Services\Booking\ClientDetailsState::assertApproved($contract->event_id, $contract->financial_revision_id);
            RecordWriteRejected::unless($contract->state === 'issued' && $contract->version === $data['expected_version'] && hash_equals($contract->presented_pdf_sha256, $data['presented_pdf_sha256']), 409, 'El documento cambió o ya no admite esta firma. Revisa la versión vigente.');
            app(ArtifactStore::class)->read($contract->presented_pdf_path, $contract->presented_pdf_sha256);
            if (! DB::table('contract_presentations')->where('contract_id', $contract->id)->where('portal_grant_id', $client->grantId)->where('presented_pdf_sha256', $contract->presented_pdf_sha256)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['contract' => 'Abre el documento completo antes de aceptar y firmar.']);
            }
            $signature = app(SignatureProvider::class)->capture($contract, $client, $data, $request);
            $contract->fill(['state' => 'signed', 'signed_at' => $signature->accepted_at, 'version' => $contract->version + 1])->save();
            // Client evidence is not a users-table owner; retain grant/customer provenance in the client audit payload.
            Audit::recordSystem('contract.client_signed', $contract, ['state' => 'issued'], ['state' => 'signed', 'signature_evidence_id' => $signature->id, 'customer_id' => $client->customerId, 'portal_grant_id' => $client->grantId, 'presented_pdf_sha256' => $contract->presented_pdf_sha256]);
            MutationKey::store($scope, $key, $hash, 'signature_evidence', $signature->id);
            FinalizeSignedContract::dispatch($contract->id)->afterCommit();
            return $signature;
        }, 3);
    }
}
