<?php
namespace App\Services\Quotes;

use App\Models\QuotePresentation;
use App\Services\Documents\PrintRenderer;
use Illuminate\Support\Facades\{Storage, View};

final class QuoteArtifacts
{
    public function create(int $revisionId,array $payload): array
    {
        $bytes=app(PrintRenderer::class)->pdf(View::make('pdf.quote',['p'=>$payload])->render());
        $sha=hash('sha256',$bytes); $path="quotes/{$revisionId}/{$sha}.pdf";
        if (!Storage::disk('local')->put($path,$bytes,['visibility'=>'private'])) throw new \RuntimeException('Private quote artifact unavailable.');
        return ['pdf_path'=>$path,'pdf_sha256'=>$sha];
    }
    public function read(QuotePresentation $p): string
    {
        if (!preg_match('#^quotes/[1-9][0-9]*/[a-f0-9]{64}\.pdf$#D',$p->pdf_path)) throw new \RuntimeException('Private quote artifact unavailable.');
        $bytes=Storage::disk('local')->get($p->pdf_path);
        if (!hash_equals($p->pdf_sha256,hash('sha256',$bytes))) throw new \RuntimeException('Private quote artifact unavailable.');
        return $bytes;
    }
}
