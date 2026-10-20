<?php
namespace App\Services\Portal;

use App\Exceptions\RecordWriteRejected;
use App\Models\{PortalGrant,OpeningPortalBinding};
use App\Services\Finance\MutationKey;
use App\Services\Agreements\ConfirmedAgreement;
use App\Services\Finance\Sandbox;
use Illuminate\Support\Facades\DB;

final class OpeningPortalAccess
{
    public static function knownSource(PortalGrant $g):bool
    {
        return $g->cutover_financial_binding_id!==null||OpeningPortalBinding::whereKey($g->id)->sharedLock()->first()!==null;
    }
    public static function resolve(PortalContext $client):array
    {
        $g=$client->authorize($client->eventId,'agreement.read',true);
        return self::current($g);
    }
    /** Caller holds the grant lock before asking for the current event/evidence. */
    public static function current(PortalGrant $g):array
    {
        Sandbox::require('sandbox_documents');PortalContext::requireTransport(request());
        RecordWriteRejected::unless(DB::transactionLevel()>0,409,'La consulta requiere una transacción.');
        RecordWriteRejected::unless($g->cutover_financial_binding_id&&$g->quote_revision_id===null&&$g->scopes===['agreement.read']
            &&!$g->revoked_at&&$g->expires_at->isFuture(),409,'Este enlace original venció o no está disponible.');
        try {
            $registry=OpeningPortalBinding::whereKey($g->id)->sharedLock()->firstOrFail();$p=$registry->payload;
            RecordWriteRejected::unless(is_array($p)&&$registry->portal_grant_id===$g->id
                &&(int)$registry->cutover_financial_binding_id===(int)$g->cutover_financial_binding_id
                &&hash_equals($registry->payload_sha256,MutationKey::hash($p))&&$p===OpeningPortalBinding::facts($g),409,'La evidencia del enlace original requiere revisión.');
        } catch(\Illuminate\Database\QueryException $e){throw $e;}
        catch(\Throwable){throw new RecordWriteRejected(409,'La evidencia del enlace original requiere revisión.');}
        PortalTokenVault::open($g);
        $a=ConfirmedAgreement::lock($g->event_id);$e=$a['event'];
        RecordWriteRejected::unless($a['origin']==='honeybook_opening'&&$e->customer_id===$g->customer_id
            &&$a['opening']['binding']->id===(int)$g->cutover_financial_binding_id
            &&is_string($g->opening_snapshot_sha256)&&hash_equals($a['snapshot_sha256'],$g->opening_snapshot_sha256),
            409,'El acuerdo original cambió. Solicita un enlace nuevo.');
        return ['grant'=>$g,...$a];
    }
}
