<?php
namespace App\Services\Staff;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, StaffJobInstructions, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;
final class SaveStaffJobInstructions
{
    public function execute(User $actor, int $eventId, array $input, string $key): array
    {
        $actor = StaffGuard::owner($actor);
        $d = RecordInput::validate($input,['expected_event_version'=>'required|integer|min:1','expected_version'=>'required|integer|min:0',
            'parking'=>'nullable|string|max:1000','access'=>'nullable|string|max:1000','bring'=>'required|array|max:50','bring.*'=>'required|string|max:200',
            'instructions'=>'nullable|string|max:3000','contact_name'=>'nullable|string|max:150','contact_phone'=>'nullable|string|max:40']);
        RecordWriteRejected::unless(empty($d['contact_name'])===empty($d['contact_phone']),422,'Escribe nombre y teléfono de contacto juntos.');
        return DB::transaction(function () use ($actor,$eventId,$d,$key) {
            StaffGuard::owner($actor); $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $scope='owner:'.$actor->id.':staff-instructions:'.$eventId; $hash=MutationKey::hash($d);
            if ($receipt=MutationKey::find($scope,$key,$hash)) { $row=StaffJobInstructions::findOrFail($receipt->result_id); return ['id'=>$row->id,'version'=>$row->version]; }
            $version=(int)StaffJobInstructions::where('event_id',$eventId)->max('version');
            RecordWriteRejected::unless($event->version===(int)$d['expected_event_version'] && $version===(int)$d['expected_version'],409,'Las instrucciones o la fiesta cambiaron; recarga.');
            $payload=collect($d)->except(['expected_version','expected_event_version'])->all();
            $row=StaffJobInstructions::create(['event_id'=>$eventId,'version'=>$version+1,'payload'=>$payload,'sha256'=>MutationKey::hash($payload),'created_by'=>$actor->id]);
            Audit::recordFor($actor,'staff.instructions_saved',$row,[],['event_id'=>$eventId,'version'=>$row->version]);
            MutationKey::store($scope,$key,$hash,'staff_job_instructions',$row->id);
            return ['id'=>$row->id,'version'=>$row->version];
        },3);
    }
}
