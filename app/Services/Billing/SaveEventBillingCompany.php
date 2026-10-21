<?php
namespace App\Services\Billing;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Event,User};
use App\Services\Finance\{MutationKey,Sandbox};
use App\Services\Records\{RecordActor,RecordInput};
use Illuminate\Support\Facades\DB;
final class SaveEventBillingCompany
{
    public function execute(User $actor,int $eventId,array $input,string $key): array {
        RecordActor::authorize($actor);Sandbox::require('sandbox_company');\App\Services\Portal\PortalContext::requireTransport(request());
        $d=RecordInput::validate(BillingCompany::input($input),['expected_event_version'=>'required|integer|min:1','expected_billing_version'=>'required|integer|min:0',...BillingCompany::rules()]);
        RecordWriteRejected::unless(array_key_exists('billing_company',$d),422,'Indica la empresa o elimina el bloque.');
        $d['expected_event_version']=(int)$d['expected_event_version'];$d['expected_billing_version']=(int)$d['expected_billing_version'];
        $hash=MutationKey::hash($d);$scope='owner:'.$actor->id.':event:'.$eventId.':billing';
        return DB::transaction(function()use($actor,$eventId,$d,$key,$hash,$scope){
            $event=Event::whereKey($eventId)->lockForUpdate()->firstOrFail();RecordActor::authorize($actor);Sandbox::require('sandbox_company');
            if($r=MutationKey::find($scope,$key,$hash)){ [$id,$ev,$bv]=array_map('intval',explode(':',$r->result_id));return ['event_id'=>$id,'event_version'=>$ev,'billing_company_version'=>$bv]; }
            RecordWriteRejected::unless($event->version===$d['expected_event_version']&&(int)$event->billing_company_version===$d['expected_billing_version'],409,'La fiesta o los datos de empresa cambiaron.');
            BillingCompany::apply($event,$d['billing_company'],$actor);
            $result=['event_id'=>$event->id,'event_version'=>$event->version,'billing_company_version'=>$event->billing_company_version];
            MutationKey::store($scope,$key,$hash,'event_billing',implode(':',array_values($result)));return $result;
        });
    }
}
