<?php
namespace App\Services\Staff;

use App\Exceptions\RecordWriteRejected;
use App\Models\{StaffAccessGrant, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

// Shared transaction/lock protocol. Public entry points have the API's exact service names.
final class MutateStaffGrant
{
    public function execute(string $operation, User $actor, int $target, array $input, string $key): array
    {
        RecordWriteRejected::unless(in_array($operation,['issue','rotate','revoke'],true),422,'Operación no válida.');
        $actor = StaffGuard::owner($actor);
        $version = StaffGuard::version($input);
        return DB::transaction(function () use ($operation,$actor,$target,$version,$key) {
            StaffGuard::owner($actor);
            if ($operation === 'issue') { [$identity,$resource] = StaffGuard::identity($target); $grant = null; }
            else [$grant,$identity,$resource] = StaffGuard::grant($target,false);
            $scope = 'owner:'.$actor->id.':staff:'.$operation.':'.$target;
            $hash = MutationKey::hash(['expected_version'=>$version]);
            if ($receipt = MutationKey::find($scope,$key,$hash)) {
                $saved = StaffAccessGrant::whereKey($receipt->result_id)->lockForUpdate()->firstOrFail();
                if ($operation === 'revoke') return ['id'=>$saved->id,'version'=>$saved->version,'revoked'=>true];
                RecordWriteRejected::unless($identity->active && $resource->status === 'active',409,'Esta persona está desactivada.');
                return StaffGuard::envelope($saved,$identity);
            }
            RecordWriteRejected::unless($identity->active && $resource->status === 'active',409,'Esta persona está desactivada.');
            if ($operation === 'issue') {
                RecordWriteRejected::unless($identity->version === $version,409,'La identidad cambió; recarga.');
                RecordWriteRejected::when(StaffAccessGrant::where('active_staff_id',$identity->id)->exists(),409,'Ya existe un enlace. Cópialo o cámbialo explícitamente.');
                $result = StaffGuard::create($identity,$actor);
            } else {
                StaffGuard::live($grant);
                RecordWriteRejected::unless($grant->version === $version,409,'El enlace cambió; recarga.');
                StaffGuard::revoke($grant);
                $result = $operation === 'rotate' ? StaffGuard::create($identity,$actor) : $grant;
            }
            $identity->version++;
            $identity->save();
            Audit::recordFor($actor,'staff.grant_'.$operation,$result,[],['staff_id'=>$identity->id,'version'=>$result->version]);
            MutationKey::store($scope,$key,$hash,'staff_grant',$result->id);
            return $operation === 'revoke' ? ['id'=>$result->id,'version'=>$result->version,'revoked'=>true] : StaffGuard::envelope($result,$identity);
        },3);
    }
}
