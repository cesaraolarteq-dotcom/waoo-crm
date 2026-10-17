<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\QuoteDecision;
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Portal\PortalContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

final class DecideQuote
{
    public function execute(PortalContext $client,array $input,string $key): QuoteDecision
    {
        QuoteProtocol::portalGate();
        if ($client->adminAuthenticated) throw new AuthorizationException('Una sesión administrativa no puede decidir por la clienta.');
        $hex=['required','string','regex:/^[a-f0-9]{64}$/D'];
        $data=QuoteProtocol::input($input,['expected_revision'=>'required|integer|min:1','snapshot_sha256'=>$hex,'pdf_sha256'=>$hex,'consent_sha256'=>$hex,
            'consent_version'=>'required|string|max:100','name'=>'required|string|min:2|max:255','email'=>'required|email|max:255',
            'consent'=>'required|accepted','outcome'=>['required',Rule::in(['accepted','rejected'])]]);
        $data['expected_revision']=(int)$data['expected_revision']; $data['consent']=true; $data['email']=mb_strtolower($data['email']);
        return DB::transaction(function()use($client,$data,$key){
            [$g,$e,$q,$r,$p]=QuotePortalAccess::resolve($client,'quote.accept');
            $scope="customer:{$client->customerId}:quote:{$q->id}:decision"; $hash=MutationKey::hash($data);
            if ($receipt=MutationKey::find($scope,$key,$hash)) return QuoteDecision::findOrFail($receipt->result_id);
            if ($old=QuoteDecision::where('quote_id',$q->id)->where('quote_revision_id',$r->id)->first()) {
                RecordWriteRejected::unless(hash_equals($old->decision_sha256,$hash),409,'Ya se registró otra decisión para esta versión.');
                MutationKey::store($scope,$key,$hash,'quote_decision',$old->id); return $old;
            }
            QuoteProtocol::assertSource($e,$q,$r); $consent=$p->consents[$data['outcome']];
            RecordWriteRejected::unless($q->status==='sent'&&$q->valid_until->isFuture()&&$data['expected_revision']===$r->revision
                &&hash_equals($p->snapshot_sha256,$data['snapshot_sha256'])&&hash_equals($p->pdf_sha256,$data['pdf_sha256'])
                &&$consent['version']===$data['consent_version']&&hash_equals($consent['sha256'],$data['consent_sha256']),409,'Revisa la versión y el texto vigentes de la cotización.');
            if (filled($r->customer_snapshot['email']??null)&&strcasecmp($r->customer_snapshot['email'],$data['email'])!==0) throw ValidationException::withMessages(['email'=>'Usa el correo de esta cotización.']);
            app(QuoteArtifacts::class)->read($p);
            if (!DB::table('quote_pdf_views')->where(['quote_presentation_id'=>$p->id,'portal_grant_id'=>$g->id])->exists()) throw ValidationException::withMessages(['quote'=>'Abre el PDF completo antes de registrar tu decisión.']);
            $decision=QuoteDecision::create(['quote_id'=>$q->id,'quote_revision_id'=>$r->id,'quote_presentation_id'=>$p->id,'portal_grant_id'=>$g->id,
                'customer_id'=>$client->customerId,'outcome'=>$data['outcome'],'name'=>$data['name'],'email'=>$data['email'],
                'decision_sha256'=>$hash,'snapshot_sha256'=>$p->snapshot_sha256,'pdf_sha256'=>$p->pdf_sha256,
                'consent_version'=>$consent['version'],'consent_text'=>$consent['text'],'consent_sha256'=>$consent['sha256'],
                'ip'=>$client->ip,'user_agent'=>$client->userAgent,'decided_at'=>now()]);
            $q->forceFill(['status'=>$data['outcome'],'version'=>$q->version+1])->save();
            Audit::recordSystem('quote.client_decided',$q,[],['decision_id'=>$decision->id,'outcome'=>$data['outcome'],'portal_grant_id'=>$g->id,
                'snapshot_sha256'=>$p->snapshot_sha256,'pdf_sha256'=>$p->pdf_sha256]);
            MutationKey::store($scope,$key,$hash,'quote_decision',$decision->id); return $decision;
        },3);
    }
}
