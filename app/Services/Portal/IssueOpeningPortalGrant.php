<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{PortalGrant,OpeningPortalBinding,User};
use App\Rules\ExplicitOffset;
use App\Services\Agreements\ConfirmedAgreement;
use App\Services\Audit;
use App\Services\Finance\{MutationKey,OpeningReceiptAccess,Sandbox};
use App\Services\Imports\{ImportInput,ImportPersistence};
use App\Services\Records\RecordActor;
use Carbon\CarbonImmutable;

final class IssueOpeningPortalGrant
{
    public function execute(User $actor,int $eventId,array $input,string $key):array
    {
        Sandbox::require('sandbox_documents');OpeningReceiptAccess::gates();$actor=RecordActor::authorize($actor);
        $d=ImportInput::input($input,['customer_id'=>'required|integer|min:1','expected_event_version'=>'required|integer|min:1',
            'financial_revision_id'=>'required|integer|min:1','expected_snapshot_sha256'=>'required|string|regex:/^[a-f0-9]{64}$/D',
            'expires_at'=>['required','date','after:now','before_or_equal:'.now()->addDays(30)->toIso8601String(),new ExplicitOffset]]);
        foreach(['customer_id','expected_event_version','financial_revision_id'] as $field)$d[$field]=(int)$d[$field];
        $d['expires_at']=CarbonImmutable::parse($d['expires_at'])->utc()->startOfSecond()->toIso8601String();
        $scope='owner:'.$actor->id.':event:'.$eventId.':original-portal';$hash=MutationKey::hash($d);
        return ImportPersistence::transaction(function()use($actor,$eventId,$d,$scope,$hash,$key){
            RecordActor::authorize($actor,true); // SH owner before grant/event: compatible with finance and existing portal locks.
            $saved=MutationKey::find($scope,$key,$hash,true);
            $g=$saved?PortalGrant::whereKey($saved->result_id)->lockForUpdate()->firstOrFail():null;
            if($g)RecordWriteRejected::unless($saved->result_type==='portal_grant'&&$g->event_id===$eventId,409,'La solicitud no corresponde a este enlace.');
            $a=$g?OpeningPortalAccess::current($g):ConfirmedAgreement::lock($eventId);$e=$a['event'];$f=$a['finance'];
            RecordWriteRejected::unless($a['origin']==='honeybook_opening'&&$e->customer_id===$d['customer_id']
                &&$e->version===$d['expected_event_version']&&$f->id===$d['financial_revision_id']&&hash_equals($a['snapshot_sha256'],$d['expected_snapshot_sha256']),
                409,'Revisa la clienta y el acuerdo original antes de crear el enlace.');
            if(!$g){
                $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                $g=PortalGrant::create(['event_id'=>$eventId,'customer_id'=>$e->customer_id,'token_hash'=>hash('sha256',$token),
                    'scopes'=>['agreement.read'],'expires_at'=>$d['expires_at'],'issued_by'=>$actor->id]);
                $g->forceFill(['cutover_financial_binding_id'=>$a['opening']['binding']->id,'opening_snapshot_sha256'=>$a['snapshot_sha256']]);
                $g->forceFill(['encrypted_token'=>PortalTokenVault::seal($g,$token)])->save();
                $facts=OpeningPortalBinding::facts($g);OpeningPortalBinding::create(['portal_grant_id'=>$g->id,'cutover_financial_binding_id'=>$g->cutover_financial_binding_id,'payload'=>$facts,'payload_sha256'=>MutationKey::hash($facts)]);
                Audit::recordFor($actor,'portal.original_grant_issued',$g,[],['event_id'=>$eventId,'financial_revision_id'=>$f->id,'snapshot_sha256'=>$a['snapshot_sha256']]);
                MutationKey::store($scope,$key,$hash,'portal_grant',$g->id);
            }
            OpeningPortalAccess::current($g);
            return ['id'=>$g->id,'event_version'=>$e->version,'financial_revision_id'=>$f->id,'expires_at'=>$g->expires_at->toIso8601String(),
                'scopes'=>$g->scopes,'link'=>Sandbox::origin().'/portal#token='.PortalTokenVault::open($g)];
        });
    }
}
