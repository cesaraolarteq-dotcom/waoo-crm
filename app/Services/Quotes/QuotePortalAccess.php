<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, Quote, QuotePresentation, QuoteRevision};
use App\Services\Finance\MutationKey;
use App\Services\Portal\PortalContext;
use Illuminate\Auth\Access\AuthorizationException;

final class QuotePortalAccess
{
    // Caller holds a DB transaction: grant -> event -> quote, including read/PDF operations.
    public static function resolve(PortalContext $client,string $scope='quote.read'): array
    {
        QuoteProtocol::portalGate(); $g=$client->authorize($client->eventId,$scope,true);
        if (!$g->quote_revision_id || $g->scopes!==['quote.read','quote.accept']) throw new AuthorizationException('Este enlace no permite ver esta cotización.');
        $event=Event::whereKey($client->eventId)->lockForUpdate()->firstOrFail();
        $r=QuoteRevision::findOrFail($g->quote_revision_id);
        $q=Quote::whereKey($r->quote_id)->lockForUpdate()->firstOrFail();
        RecordWriteRejected::unless($q->event_id===$event->id && $q->customer_id===$client->customerId && $event->customer_id===$client->customerId
            && $q->current_revision===$r->revision,409,'Este enlace no corresponde a la cotización vigente.');
        $p=QuotePresentation::where('quote_revision_id',$r->id)->firstOrFail();
        RecordWriteRejected::unless(hash_equals($p->revision_sha256,QuoteProtocol::revisionHash($r)) && hash_equals($p->snapshot_sha256,MutationKey::hash($p->payload)),409,'No se pudo verificar la cotización.');
        foreach(['accepted','rejected'] as $kind){
            $consent=$p->consents[$kind]??[];
            RecordWriteRejected::unless(is_string($consent['text']??null)&&is_string($consent['sha256']??null)
                &&hash_equals($consent['sha256'],hash('sha256',$consent['text'])),409,'No se pudo verificar el consentimiento.');
        }
        return [$g,$event,$q,$r,$p];
    }
}
