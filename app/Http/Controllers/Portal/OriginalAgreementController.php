<?php
namespace App\Http\Controllers\Portal;
use App\Services\Portal\{ReadOpeningPortalViewModel,ReadOpeningPortalDocument};
use Illuminate\Http\Request;
final class OriginalAgreementController
{
    public function show(Request $r,ReadOpeningPortalViewModel $service)
    {$d=$r->validate(['lang'=>'sometimes|in:es,en']);return response()->json($service->read($r->attributes->get('portal_context'),$d['lang']??'es'));}
    public function pdf(Request $r,ReadOpeningPortalDocument $service)
    {return response($service->read($r->attributes->get('portal_context')),200,['Content-Type'=>'application/pdf',
        'Content-Disposition'=>'attachment; filename="original-agreement.pdf"','Content-Security-Policy'=>"default-src 'none'; sandbox",'X-Content-Type-Options'=>'nosniff']);}
}
