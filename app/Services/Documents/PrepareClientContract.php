<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, ContractTemplateVersion};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Prepares immutable artifacts; the caller controls the separately gated HTTP rollout. */
final class PrepareClientContract
{
    public function execute(PortalContext $client, array $input, string $key): Contract
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        PortalContext::requireTransport(request());
        if ($client->adminAuthenticated) throw new AuthorizationException('Usa el acceso de la clienta para preparar su contrato.');
        RecordInput::validate(['input' => $input], ['input' => 'required|array:expected_event_version,expected_revision']);
        $data = RecordInput::validate($input, ['expected_event_version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1']);
        $data = array_map('intval', $data);
        $scope = "customer:{$client->customerId}:event:{$client->eventId}:client-contract";
        $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($client, $data, $key, $scope, $hash) {
            [$event, $customer, $finance, $submission, $values, $provenance] = ClientContractSource::read($client, $data);
            RecordWriteRejected::when(Contract::where('event_id', $event->id)->where('state', 'signed')->exists(), 409, 'Un contrato firmado requiere el flujo de cambios.');
            $saved = MutationKey::find($scope, $key, $hash);
            $existing = $saved ? Contract::whereKey($saved->result_id)->firstOrFail()
                : Contract::where('event_id', $event->id)->where('state', 'issued')->orderByDesc('revision')->first();
            if ($existing && ($existing->commercial_snapshot['client_details'] ?? null) === $provenance) {
                RecordWriteRejected::unless($existing->state === 'issued' && $existing->event_id === $event->id
                    && $existing->financial_revision_id === $finance->id && $existing->event_version === $event->version
                    && $existing->signer_customer_id === $customer->id && $existing->signer_email === $values['email'], 409, 'El contrato cambió. Recarga la página.');
                $artifacts = app(ArtifactStore::class);
                $artifacts->read($existing->presented_html_path, $existing->presented_html_sha256);
                $artifacts->read($existing->presented_pdf_path, $existing->presented_pdf_sha256);
                if (!$saved) MutationKey::store($scope, $key, $hash, 'contract', $existing->id);
                return $existing;
            }
            RecordWriteRejected::when($saved !== null || Contract::where('event_id', $event->id)->where('state', 'signed')->exists(), 409, 'Un contrato firmado o cambiado requiere revisión del dueño.');
            $registry = DB::table('contract_template_registry')->where('id', 1)->lockForUpdate()->first();
            $template = $registry?->active_version_id ? ContractTemplateVersion::whereKey($registry->active_version_id)->lockForUpdate()->first() : null;
            RecordWriteRejected::unless($template && $template->state === 'active' && $template->approved_at && $template->approved_by,
                409, 'El dueño debe aprobar una plantilla vigente. Tus datos siguen guardados.');
            $snapshot = [...ClientContractSource::snapshot($event, $finance, $values, $provenance),
                'payments' => app(ContractPrintContext::class)->paymentSnapshot($event)];
            $contract = Contract::create(['id' => (string) Str::uuid(), 'event_id' => $event->id, 'financial_revision_id' => $finance->id,
                'event_version' => $event->version, 'template_version_id' => $template->id, 'revision' => (int) Contract::where('event_id', $event->id)->max('revision') + 1,
                'state' => 'draft', 'signer_customer_id' => $customer->id, 'signer_email' => $values['email'], 'commercial_snapshot' => $snapshot]);
            $renderer = app(PrintRenderer::class); $artifacts = app(ArtifactStore::class);
            $html = $renderer->html($template->source_html, $template->terms_html, app(ContractPrintContext::class)->build($contract, $template));
            $htmlFile = $artifacts->put($contract->id, 'presented', $html, 'html');
            $pdfFile = $artifacts->put($contract->id, 'presented', $renderer->pdf($html), 'pdf');
            Contract::where('event_id', $event->id)->where('state', 'issued')->update(['state' => 'superseded', 'superseded_by' => $contract->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $contract->fill(['presented_html_path' => $htmlFile['path'], 'presented_html_sha256' => $htmlFile['sha256'],
                'presented_pdf_path' => $pdfFile['path'], 'presented_pdf_sha256' => $pdfFile['sha256'], 'state' => 'issued', 'issued_by' => null,
                'issued_at' => now(), 'version' => 2])->save();
            Audit::recordSystem('contract.client_prepared', $contract, [], ['event_id' => $event->id, 'customer_id' => $customer->id,
                'portal_grant_id' => $client->grantId, 'client_details' => $provenance, 'financial_revision_id' => $finance->id,
                'template_version_id' => $template->id, 'presented_pdf_sha256' => $pdfFile['sha256']]);
            MutationKey::store($scope, $key, $hash, 'contract', $contract->id);
            return $contract->fresh();
        }, 3);
    }
}
