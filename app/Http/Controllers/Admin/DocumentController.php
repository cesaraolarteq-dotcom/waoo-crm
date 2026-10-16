<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Contract, ContractTemplateVersion, Event, PortalGrant, SignatureEvidence};
use App\Services\Documents\{ActivateTemplateVersion, ArtifactStore, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Finance\Sandbox;
use App\Services\Portal\IssuePortalGrant;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DocumentController extends Controller
{
    public function templates() { Sandbox::require('sandbox_documents'); return response()->json(ContractTemplateVersion::orderByDesc('version')->paginate(50))->header('Cache-Control', 'no-store, private'); }
    public function template(ContractTemplateVersion $template) { Sandbox::require('sandbox_documents'); return response()->json([...$template->toArray(), 'terms_html' => $template->terms_html, 'source_html' => $template->source_html])->header('Cache-Control', 'no-store, private'); }
    public function createTemplate(Request $r, CreateTemplateVersion $service) { return response()->json($service->execute($r->user(), $r->all(), (string) $r->header('Idempotency-Key')), 201)->header('Cache-Control', 'no-store, private'); }
    public function activate(Request $r, ContractTemplateVersion $template, ActivateTemplateVersion $service) { return response()->json($service->execute($r->user(), $template->id, $r->all()))->header('Cache-Control', 'no-store, private'); }
    public function create(Request $r, Event $event, CreateContract $service) { return response()->json($service->execute($r->user(), $event->id, $r->all(), (string) $r->header('Idempotency-Key')), 201)->header('Cache-Control', 'no-store, private'); }
    public function issue(Request $r, Contract $contract, IssueContract $service) { return response()->json($service->execute($r->user(), $contract->id, $r->all(), (string) $r->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private'); }
    public function show(Contract $contract) { Sandbox::require('sandbox_documents'); return response()->json($contract)->header('Cache-Control', 'no-store, private'); }
    public function pdf(Request $r, Contract $contract, ArtifactStore $artifacts)
    {
        Sandbox::require('sandbox_documents');
        $kind = $r->validate(['artifact' => 'sometimes|in:presented,final'])['artifact'] ?? 'presented';
        abort_unless($contract->{$kind.'_pdf_path'} && $contract->{$kind.'_pdf_sha256'}, 404);
        return response($artifacts->read($contract->{$kind.'_pdf_path'}, $contract->{$kind.'_pdf_sha256'}), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline; filename="contract-'.$contract->id.'.pdf"']);
    }
    public function evidence(Contract $contract)
    {
        Sandbox::require('sandbox_documents');
        $template = ContractTemplateVersion::findOrFail($contract->template_version_id);
        return response()->json(['contract' => $contract, 'template' => $template, 'signature' => SignatureEvidence::where('contract_id', $contract->id)->first(),
            'artifacts' => ['presented_html_sha256' => $contract->presented_html_sha256, 'presented_pdf_sha256' => $contract->presented_pdf_sha256, 'final_pdf_sha256' => $contract->final_pdf_sha256],
            'hash_meaning' => 'The client accepted the presented PDF bytes; the final PDF is a separate evidence artifact.'])->header('Cache-Control', 'no-store, private');
    }
    public function grant(Request $r, Event $event, IssuePortalGrant $service) { return response()->json($service->execute($r->user(), $event->id, $r->all()), 201)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer'); }
    public function revoke(Request $r, PortalGrant $grant)
    {
        Sandbox::require('sandbox_documents');
        DB::transaction(function () use ($r, $grant) {
            $locked = PortalGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at) return;
            $locked->revoked_at = now(); $locked->save();
            Audit::recordFor($r->user(), 'portal.grant_revoked', $locked, [], ['revoked_at' => $locked->revoked_at->toIso8601String()]);
        });
        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store, private');
    }
    public function retryFinal(Request $request, Contract $contract)
    {
        Sandbox::require('sandbox_documents');
        $data = \App\Services\Records\RecordInput::validate($request->all(), ['reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($request, $contract, $data) {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->state === 'signed', 409, 'Primero debe existir la aceptación de la clienta.');
            Audit::recordFor($request->user(), 'contract.final_pdf_retry_requested', $locked, [], ['reason' => $data['reason']]);
            \App\Jobs\FinalizeSignedContract::dispatch($locked->id)->afterCommit();
        });
        return response()->json(['queued' => true, 'final_pdf_ready' => (bool) $contract->final_pdf_path])->header('Cache-Control', 'no-store, private');
    }

}
