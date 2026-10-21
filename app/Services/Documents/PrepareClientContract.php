<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsSubmission, Contract, ContractTemplateVersion, Customer, Event, FinancialRevision};
use App\Services\Audit;
use App\Services\Booking\QuickCostumeHolds;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal only until the details/signature HTTP flow validates this provenance end to end. */
final class PrepareClientContract
{
    public function execute(PortalContext $client, array $input, string $key): Contract
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        PortalContext::requireTransport(request());
        if ($client->adminAuthenticated) throw new AuthorizationException('Usa el acceso de la clienta para preparar su contrato.');
        $data = RecordInput::validate($input, ['expected_event_version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1']);
        $data = array_map('intval', $data);
        $scope = "customer:{$client->customerId}:event:{$client->eventId}:client-contract";
        $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($client, $data, $key, $scope, $hash) {
            foreach (['details.submit','contract.read','contract.sign'] as $scopeName) $client->authorize($client->eventId, $scopeName, true);
            $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
            $customer = Customer::whereKey($event->customer_id)->lockForUpdate()->firstOrFail();
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($event->id);
            $quick = QuickCostumeHolds::record($event);
            $finance = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->firstOrFail();
            CreateContract::assertCurrent($event, $finance);
            $submission = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
            RecordWriteRejected::unless($submission && $submission->customer_id === $customer->id
                && $submission->portal_grant_id === $client->grantId && $submission->revision === $data['expected_revision']
                && $submission->event_version === $event->version && $event->version === $data['expected_event_version']
                && $finance->id === $quick->initial_financial_revision_id && $finance->currency === 'USD', 409, 'La reserva o los datos cambiaron. Recarga antes de preparar el contrato.');
            RecordWriteRejected::unless(MutationKey::hash($customer->only(['name','email','phone','locale'])) === MutationKey::hash($submission->base_customer_snapshot),
                409, 'La ficha de la clienta cambió. Envía nuevamente sus datos.');
            $values = $submission->data;
            RecordWriteRejected::unless(filled($event->address) && $event->address === ($values['event_address'] ?? null)
                && $event->postal_code === ($values['event_postal_code'] ?? null) && ($values['start_time_confirmed'] ?? false) === true
                && empty($values['requested_start_at']), 409, 'La dirección o la hora requieren revisión del dueño. Tus datos siguen guardados.');
            $services = $event->services()->with('characters')->get();
            RecordWriteRejected::unless($services->isNotEmpty() && !$services->contains(fn ($s) => !$s->operational_ready)
                && count($finance->lines_snapshot) === $services->count(), 409, 'Revisa los servicios acordados antes de preparar el contrato.');
            foreach ($finance->lines_snapshot as $line) {
                $service = $services->firstWhere('id', $line['event_service_id'] ?? null);
                $characters = $service?->characters->map(fn ($row) => ['character_id' => $row->character_id, 'quantity' => $row->quantity,
                    'name' => $row->character_name_snapshot, ...($row->outfit !== null ? ['outfit' => $row->outfit] : [])])->all();
                RecordWriteRejected::unless($service && filled($line['name'] ?? null)
                    && (int) ($line['price_version_id'] ?? 0) === $service->price_version_id
                    && (int) ($line['duration_minutes'] ?? 0) === $service->duration_minutes
                    && ($line['requirements_snapshot'] ?? null) === $service->requirements_snapshot
                    && ($characters === [] || $characters === ($line['characters'] ?? null)), 409, 'La receta acordada requiere revisión.');
            }
            $provenance = ClientSubmissionFingerprint::capture($submission);
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
            $snapshot = ['client' => ['first_name' => $values['first_name'], 'last_name' => $values['last_name'], 'address' => $values['billing_address'],
                'email' => $values['email'], 'phone' => $values['phone']], 'client_details' => $provenance,
                'event' => ['date_long' => $event->starts_at->setTimezone('America/Chicago')->locale('en')->translatedFormat('D, M j, Y'),
                    'start_time' => $event->starts_at->setTimezone('America/Chicago')->format('g:i a'), 'end_time' => $event->ends_at->setTimezone('America/Chicago')->format('g:i a'), 'address' => $event->address],
                'services' => $finance->lines_snapshot, 'travel_fee_cents' => $finance->travel_fee_cents, 'total_cents' => $finance->total_cents,
                'discounts' => array_map(fn ($d) => ['label' => $d['label'], 'amount_cents' => $d['amount_cents']], $finance->discounts_snapshot ?? []),
                'balance_due_at' => $finance->balance_due_at?->toIso8601String(), 'payments' => app(ContractPrintContext::class)->paymentSnapshot($event)];
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
