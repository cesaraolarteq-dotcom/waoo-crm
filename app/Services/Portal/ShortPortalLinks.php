<?php
namespace App\Services\Portal;

use App\Exceptions\PortalLinkRejected;
use App\Models\{Event, PortalGrant};
use App\Services\Finance\Sandbox;
use Illuminate\Support\Facades\{Crypt, DB};

/** Internal codec/resolver. Issuance requires a grant already locked by an authorized owner operation. */
final class ShortPortalLinks
{
    public function forLockedGrant(#[\SensitiveParameter] PortalGrant $grant): ?string
    {
        if (!config('waoo.short_portal_links', false)) return null;
        Sandbox::require('sandbox_documents');
        if (DB::transactionLevel() < 1) throw PortalLinkRejected::unavailable();
        $this->active($grant);
        $row = DB::table('short_portal_links')->where('portal_grant_id', $grant->id)->first();
        if (!$row) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
                $digest = hash('sha256', $code);
                try {
                    DB::table('short_portal_links')->insert(['portal_grant_id'=>$grant->id, 'code_hash'=>$digest,
                        'encrypted_code'=>Crypt::encryptString(json_encode(['version'=>1, 'grant_id'=>(int)$grant->id, 'code_hash'=>$digest, 'code'=>$code], JSON_THROW_ON_ERROR)),
                        'created_at'=>now(), 'updated_at'=>now()]);
                    $row = DB::table('short_portal_links')->where('portal_grant_id', $grant->id)->first();
                    break;
                } catch (\Illuminate\Database\QueryException $e) {
                    if (($e->errorInfo[1] ?? null) !== 1062) throw PortalLinkRejected::unavailable();
                    // The caller's grant lock prevents same-grant races. Retry random-code collisions only.
                    if (DB::table('short_portal_links')->where('portal_grant_id', $grant->id)->exists()) throw PortalLinkRejected::unavailable();
                }
            }
        }
        if (!$row) throw PortalLinkRejected::unavailable();
        return Sandbox::origin().'/r/'.$this->openCode($row, $grant);
    }

    private function openCode(#[\SensitiveParameter] object $row, #[\SensitiveParameter] PortalGrant $grant): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($row->encrypted_code), true, flags: JSON_THROW_ON_ERROR);
            $code = $payload['code'] ?? null;
            if (!is_string($code) || !preg_match('/^[A-Za-z0-9_-]{12}$/D', $code)
                || $payload !== ['version'=>1,'grant_id'=>(int)$grant->id,'code_hash'=>$row->code_hash,'code'=>$code]
                || !hash_equals($row->code_hash, hash('sha256', $code))) throw PortalLinkRejected::unavailable();
            return $code;
        } catch (\Throwable) { throw PortalLinkRejected::unavailable(); }
    }

    public function destination(#[\SensitiveParameter] string $code): string
    {
        Sandbox::require('sandbox_documents');
        if (!config('waoo.short_portal_links', false) || !preg_match('/^[A-Za-z0-9_-]{12}$/D', $code)) throw PortalLinkRejected::unavailable();
        return DB::transaction(function () use ($code) {
            $row = DB::table('short_portal_links')->where('code_hash', hash('sha256', $code))->first();
            $grant = $row ? PortalGrant::whereKey($row->portal_grant_id)->lockForUpdate()->first() : null;
            if (!$grant) throw PortalLinkRejected::unavailable();
            if (!hash_equals($code, $this->openCode($row, $grant))) throw PortalLinkRejected::unavailable();
            $this->active($grant);
            $token = PortalTokenVault::open($grant);
            return Sandbox::origin().($grant->quote_revision_id === null ? '/portal' : '/portal/quote').'#token='.$token;
        }, 3);
    }

    private function active(#[\SensitiveParameter] PortalGrant $grant): void
    {
        if ($grant->revoked_at || !$grant->expires_at->isFuture()) throw PortalLinkRejected::unavailable();
        $event = Event::whereKey($grant->event_id)->lockForUpdate()->first();
        if (!$event || (int)$event->customer_id !== (int)$grant->customer_id) throw PortalLinkRejected::unavailable();
        if ($grant->quote_revision_id !== null) {
            \App\Services\Quotes\QuoteProtocol::portalGate();
            $revision = \App\Models\QuoteRevision::find($grant->quote_revision_id);
            $quote = $revision ? \App\Models\Quote::whereKey($revision->quote_id)->lockForUpdate()->first() : null;
            if (!$quote || $quote->event_id !== $grant->event_id || $quote->customer_id !== $grant->customer_id
                || $quote->current_revision !== $revision->revision) throw PortalLinkRejected::unavailable();
        }
        if (array_intersect(['payments.read','checkout.create'], $grant->scopes)) {
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($grant->event_id);
        }
    }
}
