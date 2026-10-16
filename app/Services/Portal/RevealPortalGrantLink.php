<?php
namespace App\Services\Portal;

use App\Exceptions\PortalLinkRejected;
use App\Models\{Event, PortalGrant, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\{DB, Log};

final class RevealPortalGrantLink
{
    public function execute(User $actor, int $eventId, int $grantId): RevealedPortalLink
    {
        Sandbox::require('sandbox_documents');
        $actor = RecordActor::authorize($actor);
        PortalContext::requireTransport(request());
        try {
            return DB::transaction(function () use ($actor, $eventId, $grantId) {
                // Same grant-first protocol as revoke/signature; never trust an earlier snapshot.
                $grant = PortalGrant::whereKey($grantId)->lockForUpdate()->first();
                if (!$grant || (int) $grant->event_id !== $eventId) throw new PortalLinkRejected(404, 'grant_not_found', 'No se encontró este enlace para el evento.');
                $event = Event::whereKey($eventId)->lockForUpdate()->first();
                if (!$event || (int) $event->customer_id !== (int) $grant->customer_id) throw new PortalLinkRejected(404, 'grant_not_found', 'No se encontró este enlace para el evento.');
                if ($grant->revoked_at || !$grant->expires_at->isFuture()) throw new PortalLinkRejected(410, 'grant_inactive', 'Este enlace venció o fue anulado. Crea uno nuevo.');
                $token = PortalTokenVault::open($grant);
                $origin = $this->origin();
                Audit::recordFor($actor, 'portal.link_revealed', $grant, [], ['event_id' => $eventId, 'grant_id' => $grantId]);
                return new RevealedPortalLink($grantId, $eventId, $origin.'/portal#token='.$token, $grant->expires_at->toIso8601String());
            }, 3);
        } catch (PortalLinkRejected $e) {
            throw $e;
        } catch (\Throwable) {
            Log::error('portal.link_reveal_unavailable');
            throw PortalLinkRejected::unavailable();
        }
    }
    private function origin(): string
    {
        try { return Sandbox::origin(); }
        catch (\Throwable) { throw PortalLinkRejected::unavailable(); }
    }
}
