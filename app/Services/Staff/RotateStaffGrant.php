<?php
namespace App\Services\Staff;
use App\Models\User;
final class RotateStaffGrant
{
    public function execute(User $actor, int $id, array $input, string $key): array
    {
        return app(MutateStaffGrant::class)->execute('rotate', $actor, $id, $input, $key);
    }
}
