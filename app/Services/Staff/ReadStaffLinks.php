<?php
namespace App\Services\Staff;
use App\Models\{StaffIdentity, StaffAccessGrant, Resource, User};
use App\Services\Records\RecordInput;
final class ReadStaffLinks
{
    public function execute(User $actor, array $filters = []): array
    {
        StaffGuard::owner($actor);
        $data = RecordInput::validate($filters,['page'=>'sometimes|integer|min:1']);
        $query = StaffIdentity::query()->orderBy('id');
        $last = max(1,(int)ceil($query->count()/25)); $page = min((int)($data['page']??1),$last);
        $rows = $query->forPage($page,25)->get();
        $resources = Resource::whereIn('id',$rows->pluck('resource_id'))->get()->keyBy('id');
        $grants = StaffAccessGrant::whereIn('active_staff_id',$rows->pluck('id'))->get()->keyBy('staff_identity_id');
        return ['data'=>$rows->map(function ($row) use ($resources,$grants) {
            $r = $resources[$row->resource_id]; $g = $grants->get($row->id);
            return ['staff_id'=>$row->id,'resource_id'=>$r->id,'display_name'=>$r->name,'locale'=>$row->locale,
                'active'=>$row->active && $r->status==='active','version'=>$row->version,
                'grant'=>$g ? ['id'=>$g->id,'version'=>$g->version,'last_opened_at'=>$g->last_opened_at?->toIso8601String()] : null];
        })->all(),'current_page'=>$page,'last_page'=>$last];
    }
}
