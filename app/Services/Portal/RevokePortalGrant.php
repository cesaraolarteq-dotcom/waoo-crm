<?php
namespace App\Services\Portal;

use App\Models\{PortalGrant, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

final class RevokePortalGrant
{
    public function execute(User $actor, int $grantId): PortalGrant
    {
        Sandbox::require('sandbox_documents');
        $actor = RecordActor::authorize($actor);
        return DB::transaction(function () use ($actor, $grantId) {
            RecordActor::authorize($actor, true);
            // Signature acceptance locks this same row before the event/contract.
            $grant = PortalGrant::whereKey($grantId)->lockForUpdate()->firstOrFail();
            if ($grant->revoked_at) {
                if ($grant->encrypted_token !== null) $grant->forceFill(['encrypted_token' => null])->save();
                return $grant;
            }
            $grant->forceFill(['encrypted_token' => null]);
            $grant->revoked_at = now();
            $grant->save();
            Audit::recordFor($actor, 'portal.grant_revoked', $grant, [], ['revoked_at' => $grant->revoked_at->toIso8601String()]);
            return $grant;
        }, 3);
    }
}
