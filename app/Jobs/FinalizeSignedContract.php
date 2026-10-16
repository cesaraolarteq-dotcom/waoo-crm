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
        $presentedHtml = $artifacts->read($contract->presented_html_path, $contract->presented_html_sha256);
        $renderer = app(PrintRenderer::class);
        $html = $renderer->signedHtml($presentedHtml, $signature);
        $file = $artifacts->put($contract->id, 'final', $renderer->pdf($html), 'pdf');
        DB::transaction(function () use ($contract, $file) {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->final_pdf_path) return;
            $locked->fill(['final_pdf_path' => $file['path'], 'final_pdf_sha256' => $file['sha256']])->save();
            \App\Services\Audit::recordSystem('contract.final_pdf_created', $locked, [], ['presented_pdf_sha256' => $locked->presented_pdf_sha256, 'final_pdf_sha256' => $file['sha256']]);
        });
    }
}
