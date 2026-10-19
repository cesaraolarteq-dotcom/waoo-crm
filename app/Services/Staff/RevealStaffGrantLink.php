<?php
namespace App\Services\Staff;
use App\Exceptions\RecordWriteRejected;
use App\Models\User;
use Illuminate\Support\Facades\DB;
final class RevealStaffGrantLink
{
    public function execute(User $actor, int $grantId, array $input): array
    {
        StaffGuard::owner($actor); $version = StaffGuard::version($input);
        return DB::transaction(function () use ($actor,$grantId,$version) {
            StaffGuard::owner($actor);
            [$grant,$identity] = StaffGuard::grant($grantId);
            RecordWriteRejected::unless($grant->version === $version,409,'El enlace cambió; recarga.');
            return StaffGuard::envelope($grant,$identity);
        },3);
    }
}
