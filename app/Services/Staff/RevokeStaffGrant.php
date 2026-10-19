<?php
namespace App\Services\Staff;
use App\Models\User;
final class RevokeStaffGrant
{
    public function execute(User $actor, int $id, array $input, string $key): array
    {
        return app(MutateStaffGrant::class)->execute('revoke', $actor, $id, $input, $key);
    }
}
