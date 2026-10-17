<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\QuoteDecision;
use App\Services\Quotes\{DecideQuote, QuoteArtifacts, QuotePortalAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class QuoteController extends Controller
{
    public function show(Request $request)
    {
        return DB::transaction(function()use($request){
            [$g,$e,$q,$r,$p]=QuotePortalAccess::resolve($request->attributes->get('portal_context'));
            return response()->json(['quote'=>$p->payload,'snapshot_sha256'=>$p->snapshot_sha256,'pdf_sha256'=>$p->pdf_sha256,'consents'=>$p->consents,
                'status'=>$q->effectiveStatus(),'can_decide'=>$q->status==='sent'&&$q->valid_until->isFuture()&&$e->version===$r->event_version&&!$request->user(),
                'decision'=>QuoteDecision::where('quote_revision_id',$r->id)->first()?->only(['outcome','decided_at'])]);
        },3);
    }
    public function pdf(Request $request,QuoteArtifacts $artifacts)
    {
        return DB::transaction(function()use($request,$artifacts){
            [$g,$e,$q,$r,$p]=QuotePortalAccess::resolve($request->attributes->get('portal_context')); $bytes=$artifacts->read($p);
            DB::table('quote_pdf_views')->insertOrIgnore(['quote_presentation_id'=>$p->id,'portal_grant_id'=>$g->id,'served_at'=>now()]);
            return response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="quote-'.$q->id.'-'.$r->revision.'.pdf"']);
        },3);
    }
    public function accept(Request $request,DecideQuote $service) { return $this->decide($request,$service,'accepted'); }
    public function reject(Request $request,DecideQuote $service) { return $this->decide($request,$service,'rejected'); }
    private function decide(Request $request,DecideQuote $service,string $outcome)
    {
        $d=$service->execute($request->attributes->get('portal_context'),[...$request->all(),'outcome'=>$outcome],(string)$request->header('Idempotency-Key'));
        return response()->json(['decision'=>$d->only(['id','outcome','decided_at','snapshot_sha256','pdf_sha256'])],201);
    }
}
