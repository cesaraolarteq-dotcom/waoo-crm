<?php
namespace App\Services\Staff;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Resource, StaffIdentity, StaffAccessGrant, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;

final class SaveStaffIdentity
{
    public function execute(User $actor, array $input, string $key): array
    {
        $actor = StaffGuard::owner($actor);
        $data = RecordInput::validate($input, ['resource_id'=>'required|integer|min:1','expected_resource_version'=>'required|integer|min:1',
            'expected_version'=>'sometimes|integer|min:1','locale'=>'required|in:es,en','phone'=>'nullable|string|max:40','active'=>'required|boolean']);
        return DB::transaction(function () use ($actor,$data,$key) {
            StaffGuard::owner($actor);
            $resource = Resource::whereKey($data['resource_id'])->lockForUpdate()->firstOrFail();
            $scope = 'owner:'.$actor->id.':staff:'.$resource->id.':configure'; $hash = MutationKey::hash($data);
            $receipt = MutationKey::find($scope,$key,$hash);
            $identity = StaffIdentity::where('resource_id',$resource->id)->lockForUpdate()->first();
            if ($receipt) return ['staff_id'=>$identity->id,'version'=>$identity->version];
            RecordWriteRejected::unless($resource->kind === 'staff' && $resource->version === (int)$data['expected_resource_version'],409,'Actualiza la ficha del personal.');
            if ($identity) RecordWriteRejected::unless(isset($data['expected_version']) && $identity->version === (int)$data['expected_version'],409,'La identidad cambió; recarga.');
            else $identity = new StaffIdentity(['resource_id'=>$resource->id]);
            $identity->fill(['locale'=>$data['locale'],'active'=>(bool)$data['active'],'phone'=>array_key_exists('phone',$data)?$data['phone']:$identity->phone]);
            if ($identity->exists) $identity->version++;
            $identity->save();
            if (!$identity->active) foreach (StaffAccessGrant::where('active_staff_id',$identity->id)->lockForUpdate()->get() as $grant) StaffGuard::revoke($grant);
            Audit::recordFor($actor,'staff.identity_saved',$identity,[],['version'=>$identity->version,'resource_id'=>$resource->id,'active'=>$identity->active]);
            MutationKey::store($scope,$key,$hash,'staff_identity',$identity->id);
            return ['staff_id'=>$identity->id,'version'=>$identity->version];
        },3);
    }
}
