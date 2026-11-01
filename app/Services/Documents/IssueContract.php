<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, ContractTemplateVersion, Event, FinancialRevision, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class IssueContract
{
    public function execute(User $actor, string $contractId, array $input, string $key): Contract
    {
        Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['expected_version' => 'required|integer|min:1']);
        $scope = "owner:{$actor->id}:contract:{$contractId}:issue"; $hash = MutationKey::hash($data);
        $eventId = Contract::findOrFail($contractId)->event_id;
        return DB::transaction(function () use ($actor, $contractId, $eventId, $data, $key, $scope, $hash) {
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $contract = Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return Contract::findOrFail($saved->result_id);
            RecordWriteRejected::unless($contract->state === 'draft' && $contract->version === (int) $data['expected_version'] && $contract->event_version === $event->version
                && $contract->revision === (int) Contract::where('event_id', $eventId)->max('revision'), 409, 'Revisa el evento y la revisión del contrato antes de emitirlo.');
            CreateContract::assertCurrent($event, FinancialRevision::findOrFail($contract->financial_revision_id));
            \App\Services\Booking\ClientDetailsState::assertApproved($eventId, $contract->financial_revision_id);
            RecordWriteRejected::when(! \App\Models\EventChange::signedIsBeforeChange($eventId) && Contract::where('event_id', $eventId)->where('state', 'signed')->exists(), 409, 'Un contrato firmado requiere una adenda o el flujo de cambios.');
            $template = ContractTemplateVersion::whereKey($contract->template_version_id)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($template->state === 'active' && $template->approved_at, 409, 'La plantilla debe estar activa y aprobada por un administrador.');
            $snapshot = $contract->commercial_snapshot;
            RecordWriteRejected::unless(($snapshot['billing_company']??null)===$event->billing_company,409,'La empresa cambió. Genera una revisión del contrato.');
            $snapshot['payments'] = app(ContractPrintContext::class)->paymentSnapshot($event);
            $contract->commercial_snapshot = $snapshot;
            $renderer = app(PrintRenderer::class);
            $html = $renderer->html($template->source_html, $template->terms_html, app(ContractPrintContext::class)->build($contract, $template));
            $artifacts = app(ArtifactStore::class);
            $htmlFile = $artifacts->put($contract->id, 'presented', $html, 'html');
            $pdfFile = $artifacts->put($contract->id, 'presented', $renderer->pdf($html), 'pdf');
            Contract::where('event_id', $eventId)->where('state', 'issued')->where('id', '!=', $contractId)->update(['state' => 'superseded', 'superseded_by' => $contractId, 'updated_at' => now(), 'version' => DB::raw('version + 1')]);
            $contract->fill(['presented_html_path' => $htmlFile['path'], 'presented_html_sha256' => $htmlFile['sha256'], 'presented_pdf_path' => $pdfFile['path'], 'presented_pdf_sha256' => $pdfFile['sha256'],
                'state' => 'issued', 'issued_by' => $actor->id, 'issued_at' => now(), 'version' => $contract->version + 1])->save();
            Audit::recordFor($actor, 'contract.issued', $contract, ['state' => 'draft'], ['state' => 'issued', 'presented_pdf_sha256' => $pdfFile['sha256'], 'template_version_id' => $template->id]);
            MutationKey::store($scope, $key, $hash, 'contract', $contract->id);
            \App\Services\Mail\CaptureEventLinkMail::forContract($contract, null, $actor);
            return $contract->fresh();
        }, 3);
    }
}
