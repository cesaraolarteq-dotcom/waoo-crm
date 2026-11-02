<?php
namespace App\Services\Portal;

use App\Exceptions\PortalLinkRejected;
use App\Models\PortalGrant;
use Illuminate\Support\Facades\Crypt;

// Internal storage codec; authorization and row locks belong to the caller services.
final class PortalTokenVault
{
    private static function binding(PortalGrant $grant): array
    {
        if($grant->cutover_financial_binding_id!==null){
            if($grant->quote_revision_id!==null||$grant->scopes!==['agreement.read']||!is_string($grant->opening_snapshot_sha256)||!preg_match('/^[a-f0-9]{64}$/D',$grant->opening_snapshot_sha256))throw PortalLinkRejected::unrecoverable();
            return ['version'=>3,'grant_id'=>(int)$grant->id,'event_id'=>(int)$grant->event_id,'customer_id'=>(int)$grant->customer_id,
                'expires_at'=>$grant->expires_at->toIso8601String(),'scopes'=>$grant->scopes,'cutover_financial_binding_id'=>(int)$grant->cutover_financial_binding_id,
                'opening_snapshot_sha256'=>$grant->opening_snapshot_sha256];
        }
        return ['version' => $grant->quote_revision_id === null ? 1 : 2, 'grant_id' => (int) $grant->id, 'event_id' => (int) $grant->event_id,
            'customer_id' => (int) $grant->customer_id, 'expires_at' => $grant->expires_at->toIso8601String(), 'scopes' => $grant->scopes,
            ...($grant->quote_revision_id !== null ? ['quote_revision_id'=>(int)$grant->quote_revision_id] : [])];
    }
    public static function seal(PortalGrant $grant, #[\SensitiveParameter] string $token): string
    {
        return Crypt::encryptString(json_encode([...self::binding($grant), 'token' => $token], JSON_THROW_ON_ERROR));
    }
    public static function open(#[\SensitiveParameter] PortalGrant $grant): string
    {
        if (!is_string($grant->encrypted_token) || $grant->encrypted_token === '') throw PortalLinkRejected::unrecoverable();
        try {
            $payload = json_decode(Crypt::decryptString($grant->encrypted_token), true, flags: JSON_THROW_ON_ERROR);
            $token = is_array($payload) ? ($payload['token'] ?? null) : null;
            if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) throw PortalLinkRejected::unrecoverable();
            unset($payload['token']);
            if ($payload !== self::binding($grant) || !hash_equals((string) $grant->token_hash, hash('sha256', $token))) throw PortalLinkRejected::unrecoverable();
            return $token;
        } catch (\Throwable) {
            // Deliberately discard crypto/JSON exception text and previous exception chain.
            throw PortalLinkRejected::unrecoverable();
        }
    }
}
