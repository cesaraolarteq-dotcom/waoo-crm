<?php
namespace App\Services\Quotes;

use App\Exceptions\{PortalLinkRejected, RecordWriteRejected};
use App\Models\{Event, PortalGrant, Quote, QuotePresentation, QuoteRevision, User};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\{PortalContext, PortalTokenVault};
use Illuminate\Support\Facades\DB;

final class SendQuote
{
    public function execute(User $actor,int $quoteId,array $input,string $key): array
    {
        $actor=QuoteInput::actor($actor); QuoteProtocol::portalGate(); PortalContext::requireTransport(request());
        $data=QuoteProtocol::input($input,['expected_version'=>'required|integer|min:1','expected_revision'=>'required|integer|min:1']);
        $data=array_map('intval',$data); $scope="owner:{$actor->id}:quote:{$quoteId}:send"; $hash=MutationKey::hash($data);
        $eventId=Quote::findOrFail($quoteId)->event_id;
        return DB::transaction(function()use($actor,$quoteId,$eventId,$data,$scope,$hash,$key){
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); QuoteInput::actor($actor);
            QuoteProtocol::lockGrants($quoteId); $e=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $q=Quote::whereKey($quoteId)->lockForUpdate()->firstOrFail();
            if ($receipt=MutationKey::find($scope,$key,$hash)) return $this->result($q,PortalGrant::findOrFail($receipt->result_id));
            $r=QuoteRevision::where('quote_id',$quoteId)->where('revision',$q->current_revision)->firstOrFail();
            QuoteProtocol::assertSource($e,$q,$r);
            RecordWriteRejected::unless(in_array($q->status,['draft','sent'],true)&&$q->valid_until->isFuture()&&$q->version===$data['expected_version']&&$r->revision===$data['expected_revision'],409,'La cotización cambió o venció.');
            $p=QuotePresentation::where('quote_revision_id',$r->id)->first();
            if (!$p) {
                $consents=QuoteProtocol::consents($q->locale); $payload=QuoteProtocol::payload($q,$r);
                $artifact=app(QuoteArtifacts::class)->create($r->id,$payload);
                $p=QuotePresentation::create(['quote_revision_id'=>$r->id,'payload'=>$payload,'snapshot_sha256'=>MutationKey::hash($payload),
                    'revision_sha256'=>QuoteProtocol::revisionHash($r),'consents'=>$consents,'issued_by'=>$actor->id,...$artifact]);
            }
            app(QuoteArtifacts::class)->read($p);
            $grant=PortalGrant::where('quote_revision_id',$r->id)->whereNull('revoked_at')->where('expires_at','>',now())->orderByDesc('id')->first();
            if (!$grant) {
                // Reuse authenticated encrypted storage without giving generic event-grant issuance quote scopes.
                try {
                    $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                    $grant=new PortalGrant(['event_id'=>$e->id,'customer_id'=>$q->customer_id,'token_hash'=>hash('sha256',$token),
                        'scopes'=>['quote.read','quote.accept'],'expires_at'=>$q->valid_until->min(now()->addDays(30)),'issued_by'=>$actor->id]);
                    $grant->forceFill(['quote_revision_id'=>$r->id])->save();
                    $grant->forceFill(['encrypted_token'=>PortalTokenVault::seal($grant,$token)])->save();
                } catch (\Throwable) { throw PortalLinkRejected::unavailable(); }
                Audit::recordFor($actor,'portal.grant_issued',$grant,[],['event_id'=>$e->id,'customer_id'=>$q->customer_id,
                    'quote_revision_id'=>$r->id,'scopes'=>$grant->scopes,'expires_at'=>$grant->expires_at->toIso8601String()]);
            }
            // A new explicit preparation advances the aggregate version, including replacement links.
            // Same-key retries return above. This also serializes two owners replacing an expired/revoked link.
            $q->forceFill(['status'=>'sent','version'=>$q->version+1])->save();
            Audit::recordFor($actor,'quote.prepared',$q,[],['revision'=>$r->revision,'presentation_id'=>$p->id,'portal_grant_id'=>$grant->id]);
            MutationKey::store($scope,$key,$hash,'portal_grant',$grant->id);
            app(\App\Services\Mail\CaptureMailOccurrence::class)->record('quote_sent', $r->id, ['event_id'=>$e->id, 'customer_id'=>$q->customer_id, 'quote_id'=>$q->id, 'quote_revision_id'=>$r->id, 'presentation_id'=>$p->id, 'portal_grant_id'=>$grant->id], $actor);
            return $this->result($q,$grant);
        },3);
    }
    private function result(Quote $q,PortalGrant $g): array
    {
        if ($g->revoked_at||!$g->expires_at->isFuture()) throw new PortalLinkRejected(410,'grant_inactive','Este enlace venció o fue anulado. Prepara otro enlace explícitamente.');
        $token=PortalTokenVault::open($g);
        return ['quote'=>$q,'presentation_id'=>QuotePresentation::where('quote_revision_id',$g->quote_revision_id)->value('id'),
            'portal_grant_id'=>$g->id,'url'=>Sandbox::origin().'/portal#token='.$token,'expires_at'=>$g->expires_at->toIso8601String()];
    }
}
