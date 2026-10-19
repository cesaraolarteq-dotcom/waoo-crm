<?php
namespace App\Services\Staff;
use App\Models\User;
final class IssueStaffGrant
{
    public function execute(User $actor, int $id, array $input, string $key): array
    {
        return app(MutateStaffGrant::class)->execute('issue', $actor, $id, $input, $key);
    }
}
