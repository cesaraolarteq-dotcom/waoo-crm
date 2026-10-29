<?php
namespace App\Jobs;

use App\Models\{Contract, ContractTemplateVersion, SignatureEvidence};
use App\Services\Documents\{ArtifactStore, ContractPrintContext, PrintRenderer};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class FinalizeSignedContract implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 40;
    public function __construct(public string $contractId) {}
    public function handle(): void
    {
        $contract = Contract::findOrFail($this->contractId);
        if ($contract->state !== 'signed' || $contract->final_pdf_path) return;
        $signature = SignatureEvidence::where('contract_id', $contract->id)->firstOrFail();
        $artifacts = app(ArtifactStore::class);
        $artifacts->read($contract->presented_pdf_path, $signature->presented_pdf_sha256);
        $artifacts->read($contract->presented_html_path, $contract->presented_html_sha256);
        $template = ContractTemplateVersion::findOrFail($contract->template_version_id);
        if (!hash_equals($signature->presented_pdf_sha256, $contract->presented_pdf_sha256)
            || !hash_equals($signature->template_source_sha256, hash('sha256', $template->source_html))
            || !hash_equals($signature->template_terms_sha256, hash('sha256', $template->terms_html))) {
            throw new \RuntimeException('Signed template evidence does not match the original presentation.');
        }
        $renderer = app(PrintRenderer::class);
        $context = app(ContractPrintContext::class);
        $unsigned = $renderer->html($template->source_html, $template->terms_html, $context->build($contract, $template));
        if (!hash_equals($contract->presented_html_sha256, hash('sha256', $unsigned))) {
            throw new \RuntimeException('Frozen contract cannot reproduce its original presentation.');
        }
        $signed = $renderer->html($template->source_html, $template->terms_html, $context->build($contract, $template, $signature));
        $html = $renderer->signedHtml($signed, $signature, true);
        $file = $artifacts->put($contract->id, 'final', $renderer->pdf($html), 'pdf');
        DB::transaction(function () use ($contract, $file) {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->final_pdf_path) return;
            $locked->fill(['final_pdf_path' => $file['path'], 'final_pdf_sha256' => $file['sha256']])->save();
            \App\Services\Audit::recordSystem('contract.final_pdf_created', $locked, [], ['presented_pdf_sha256' => $locked->presented_pdf_sha256, 'final_pdf_sha256' => $file['sha256']]);
        });
    }
}
