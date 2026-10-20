<?php
namespace App\Services\Tips;

use App\Exceptions\RecordWriteRejected;
use App\Models\User;
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Imports\ImportPersistence;

final class RevealTipGrantLink
{
    public function execute(User $actor, int $grantId, array $input, string $key): array
    {
        $actor = TipGuard::owner($actor);
        $d = TipGuard::input($input, ['expected_version' => 'required|integer|min:1',
            'expected_snapshot_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        $d['expected_version'] = (int)$d['expected_version']; $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':tip-reveal:'.$grantId;
        return ImportPersistence::transaction(function () use ($actor, $grantId, $d, $hash, $scope, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); TipGuard::owner($actor, true);
            [$g] = TipGuard::grant($grantId);
            RecordWriteRejected::unless($g->version === $d['expected_version'] && hash_equals($g->snapshot_sha256, $d['expected_snapshot_sha256']),
                409, 'El enlace o el acuerdo cambió. Revisa antes de copiar.');
            $link = TipGuard::link($g); // Verify the envelope on every attempt, including exact replay.
            if (!TipGuard::receipt($scope, $key, $hash)) {
                Audit::recordFor($actor, 'tip.grant_link_revealed', $g, [], ['event_id' => $g->event_id, 'version' => $g->version,
                    'snapshot_sha256' => $g->snapshot_sha256]);
                MutationKey::store($scope, $key, $hash, 'tip_grant', $g->id);
            }
            return ['id' => $g->id, 'version' => $g->version, 'expires_at' => $g->expires_at->toIso8601String(), 'link' => $link];
        });
    }
}
