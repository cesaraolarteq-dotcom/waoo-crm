<?php
namespace App\Services\Availability;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Character,Event,FinancialRevision,JointCapacityHold,JointCapacityHoldEvent,Resource,ResourceBlock,ResourceReservation};
use App\Services\Audit;
use App\Services\Booking\{QuickCostumeHolds,QuickStripeLeases};
use App\Services\Finance\MutationKey;
use App\Services\Routing\DriveMinutes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Private all-or-nothing physical commitment. Does not assign a roster, notify staff, charge or activate a public flow. */
final class JointCapacityHolds
{
    public static function needsInitialHold(Event $e): bool
    {
        return !\App\Models\LedgerEntry::where('event_id',$e->id)->where('kind','receipt')->exists();
    }

    /** Before any admission transaction. Routes and private calendar I/O never run while inventory is locked. */
    public function prepare(Event $e): array
    {
        RecordWriteRejected::unless(DB::transactionLevel()===0,409,'Revisa la disponibilidad antes de iniciar el cobro.');
        $fingerprint=$this->snapshot($e,false);$readAt=now()->timestamp;
        $day=CarbonImmutable::instance($e->starts_at)->setTimezone('America/Chicago')->startOfDay();
        $calendar=HoneyBookCalendarFeed::configured()?app(HoneyBookCalendarFeed::class)->between($day->subDay(),$day->addDays(2)):null;
        $routes=[];$provider=new DriveMinutes(6,2.0);
        $drive=function($a,$b,$at,$from=null,$to=null)use(&$routes,$provider){
            $key=self::routeKey($a,$b,$at,$from,$to);
            if(!array_key_exists($key,$routes)){
                $n=filled($from)&&filled($to)?(app()->bound('waoo.availability.drive')?app('waoo.availability.drive')($a,$b,$at,$from,$to):$provider->betweenAddresses($from,$to,$at)):null;
                $routes[$key]=is_int($n)&&$n>=0&&$n<=1440?$n:null;
            }
            return $routes[$key];
        };
        $allocation=$this->allocation($e,$calendar,$drive);
        return ['read_at'=>$readAt,'snapshot'=>$fingerprint,'calendar'=>$calendar,'routes'=>$routes,'state'=>$allocation['state']];
    }

    /** Caller: global DailyCapacity → actor/grant → event → lease/hold. The original deadline is never renewed. */
    public function acquire(Event $e,FinancialRevision $finance,array $proof,?int $leaseId=null,?CarbonImmutable $deadline=null): JointCapacityHold
    {
        RecordWriteRejected::unless(DB::transactionLevel()>0,409,'No se pudo apartar el equipo.');
        $hold=JointCapacityHold::where('event_id',$e->id)->lockForUpdate()->first();
        RecordWriteRejected::unless($finance->event_id===$e->id && $proof['state']==='available' && $proof['calendar']!==null
            && now()->timestamp>=$proof['read_at'] && now()->timestamp-$proof['read_at']<=120,409,'La disponibilidad necesita una revisión antes del cobro.');
        AdmitEventCapacity::lockDays(CarbonImmutable::instance($e->starts_at),CarbonImmutable::instance($e->ends_at));
        Character::orderBy('id')->limit(501)->lockForUpdate()->get();
        $units=Resource::orderBy('id')->limit(501)->lockForUpdate()->get()->keyBy('id');
        RecordWriteRejected::unless($units->count()<=500 && hash_equals($proof['snapshot'],$this->snapshot($e,true)),409,'La agenda cambió mientras se revisaba. Vuelve a intentarlo.');
        $drive=fn($a,$b,$at,$from=null,$to=null)=>$proof['routes'][self::routeKey($a,$b,$at,$from,$to)]??null;
        $allocation=$this->allocation($e,$proof['calendar'],$drive);
        RecordWriteRejected::unless($allocation['state']==='available' && $allocation['assignment']!==[],409,'No alcanza todo el equipo para reservar y cobrar.');
        if($hold){
            RecordWriteRejected::unless($hold->expires_at->isFuture() && $hold->financial_revision_id===$finance->id,409,'El apartado original venció o cambió el acuerdo.');
            $this->verify($hold,$e,true);return $hold;
        }
        $expires=$deadline??CarbonImmutable::now()->addHours(48);
        RecordWriteRejected::unless($expires->isFuture(),409,'El plazo original ya venció.');
        $rows=[];
        foreach(array_unique(array_values($allocation['assignment'])) as $id){
            $unit=$units->get($id);RecordWriteRejected::unless($unit&&$unit->status==='active',409,'Cambió una unidad del equipo.');
            $r=ResourceReservation::where('event_id',$e->id)->where('resource_id',$id)->lockForUpdate()->first();$owned=$r===null;
            $r??=ResourceReservation::create(['event_id'=>$e->id,'resource_id'=>$id,'starts_at'=>$e->starts_at,'ends_at'=>$e->ends_at]);
            $rows[]=['resource_id'=>(int)$id,'resource_version'=>$unit->version,'kind'=>$unit->kind,'reservation_id'=>$r->id,'owned'=>$owned];
        }
        $hold=JointCapacityHold::create(['event_id'=>$e->id,'financial_revision_id'=>$finance->id,'quick_stripe_lease_id'=>$leaseId,
            'state'=>'held','version'=>0,'expires_at'=>$expires,'payload'=>[],'payload_sha256'=>str_repeat('0',64)]);
        self::append($hold,['schema_version'=>1,'hold_id'=>$hold->id,'event_id'=>$e->id,'financial_revision_id'=>$finance->id,
            'financial_sha256'=>QuickStripeLeases::financialHash($finance),'recipe_sha256'=>MutationKey::hash(QuickCostumeHolds::recipe($e)),
            'window'=>QuickCostumeHolds::window($e),'expires_at'=>$expires->timestamp,'rows'=>$rows,'state'=>'held','reason'=>'joint_physical_admission']);
        return $hold;
    }

    /** No provider calls. A recorded payment can still be reconciled against immutable history after a release. */
    public function verify(JointCapacityHold $h,Event $e,bool $current=true): array
    {
        $p=$h->payload;$history=JointCapacityHoldEvent::where('joint_capacity_hold_id',$h->id)->where('version',$h->version)->sharedLock()->first();
        RecordWriteRejected::unless($history && $history->payload===$p && $history->payload_sha256===$h->payload_sha256
            && hash_equals($h->payload_sha256,MutationKey::hash($p)) && $p['hold_id']===$h->id && $p['event_id']===$e->id
            && $h->event_id===$e->id && $p['state']===$h->state && $p['version']===$h->version && $p['expires_at']===$h->expires_at->timestamp,409,'La evidencia del equipo apartado necesita revisión.');
        if(!$current)return $p;
        RecordWriteRejected::unless(in_array($h->state,['held','committed'],true) && $p['window']===QuickCostumeHolds::window($e)
            && $p['recipe_sha256']===MutationKey::hash(QuickCostumeHolds::recipe($e)),409,'Cambió el equipo o el horario apartado.');
        $seen=[];
        foreach($p['rows'] as $row){
            RecordWriteRejected::unless(!in_array($row['resource_id'],$seen,true),409,'Una unidad se repite en el apartado.');$seen[]=$row['resource_id'];
            $r=ResourceReservation::whereKey($row['reservation_id'])->sharedLock()->first();$u=Resource::whereKey($row['resource_id'])->sharedLock()->first();
            RecordWriteRejected::unless($r&&$u&&$r->event_id===$e->id&&$r->resource_id===$u->id&&$u->status==='active'&&$u->version===$row['resource_version']
                &&$r->starts_at->equalTo($e->starts_at)&&$r->ends_at->equalTo($e->ends_at),409,'Cambió una unidad del equipo apartado.');
            RecordWriteRejected::unless(!ResourceBlock::where('resource_id',$u->id)->whereNull('released_at')->where('starts_at','<',$e->ends_at->addMinutes($e->breakdown_minutes))
                ->where('ends_at','>',$e->starts_at->subMinutes($e->setup_minutes))->sharedLock()->exists(),409,'El equipo apartado tiene un bloqueo nuevo.');
        }
        return $p;
    }

    public static function append(JointCapacityHold $h,array $p): void
    {
        if(DB::transactionLevel()===0)throw new \LogicException('Hold transitions require the admission transaction.');
        $p=[...$p,'version'=>$h->version+1,'previous_sha256'=>$h->version?$h->payload_sha256:null];$hash=MutationKey::hash($p);
        $h->fill(['version'=>$p['version'],'state'=>$p['state'],'payload'=>$p,'payload_sha256'=>$hash])->save();
        JointCapacityHoldEvent::create(['joint_capacity_hold_id'=>$h->id,'version'=>$h->version,'payload'=>$p,'payload_sha256'=>$hash]);
        Audit::recordSystem('capacity.hold_'.$h->state,$h,[],['event_id'=>$h->event_id,'version'=>$h->version,'sha256'=>$hash]);
    }

    private function allocation(Event $e,?array $calendar,callable $drive): array
    {
        RecordWriteRejected::unless(filled($e->address)&&$e->setup_minutes!==null&&$e->breakdown_minutes!==null,409,'Completa dirección, montaje y desmontaje antes del cobro.');
        $services=$e->exists?$e->services()->get():$e->getRelation('services');
        $requirements=$services->flatMap(fn($s)=>$s->requirements_snapshot??[])->all();
        RecordWriteRejected::unless($requirements!==[] && !$services->contains(fn($s)=>!$s->operational_ready),422,'Completa los requisitos del paquete.');
        $day=CarbonImmutable::instance($e->starts_at)->setTimezone('America/Chicago')->startOfDay();
        return (new WebsiteCapacity($day,$calendar,$e->id))->withTravel($e->postal_code,$drive,$e->address)->allocation(
            CarbonImmutable::instance($e->starts_at)->subMinutes($e->setup_minutes),CarbonImmutable::instance($e->ends_at)->addMinutes($e->breakdown_minutes+30),
            $day->toDateString(),$requirements,ResourceReservation::where('event_id',$e->id)->orderBy('resource_id')->pluck('resource_id')->map(fn($id)=>(int)$id)->all());
    }

    private function snapshot(Event $e,bool $lock): string
    {
        $day=CarbonImmutable::instance($e->starts_at)->setTimezone('America/Chicago')->startOfDay();
        $events=Event::where('id','!=',$e->id)->where('starts_at','<',$day->addDays(2)->utc())->where('ends_at','>',$day->subDay()->utc())->orderBy('id')->limit(1001);
        $events=($lock?$events->lockForUpdate():$events)->get();RecordWriteRejected::unless($events->count()<=1000,409,'La agenda requiere revisión por volumen.');
        $data=['candidate'=>[$e->starts_at->toIso8601String(),$e->ends_at->toIso8601String(),$e->address,$e->postal_code,$e->setup_minutes,$e->breakdown_minutes,self::recipe($e)],
            'scope'=>HoneyBookCalendarFeed::scope(),'events'=>$events->map(fn($r)=>$r->getAttributes())->all()];
        foreach(['resources','characters','costume_compatibilities','calendar_identity_links','resource_blocks'] as $table){
            $q=DB::table($table)->orderBy($table==='costume_compatibilities'?'costume_id':'id')->limit(10001);
            if($table==='costume_compatibilities')$q->orderBy('staff_id');
            $rows=($lock?$q->lockForUpdate():$q)->get();RecordWriteRejected::unless($rows->count()<=10000,409,'El inventario requiere revisión por volumen.');
            $data[$table]=$rows->map(fn($r)=>(array)$r)->all();
        }
        foreach(['event_services','resource_reservations','imported_project_links','ledger_entries','quick_stripe_leases','quick_bookings','quick_costume_holds'] as $table){
            $q=DB::table($table)->whereIn('event_id',$events->pluck('id'))->orderBy('id')->limit(10001);
            $rows=($lock?$q->lockForUpdate():$q)->get();RecordWriteRejected::unless($rows->count()<=10000,409,'La agenda requiere revisión por volumen.');
            $data[$table]=$rows->map(fn($r)=>(array)$r)->all();
        }
        return MutationKey::hash($data);
    }

    private static function routeKey($a,$b,$at,$from,$to): string{return hash('sha256',json_encode([$a,$b,$at->timestamp,$from,$to]));}

    private static function recipe(Event $e): array
    {
        if($e->exists)return QuickCostumeHolds::recipe($e);
        return $e->getRelation('services')->sortBy('price_version_id')->map(fn($s)=>[
            'price_version_id'=>$s->price_version_id,'duration_minutes'=>$s->duration_minutes,'ready'=>$s->operational_ready,
            'requirements'=>$s->requirements_snapshot,'characters'=>$s->characters->map(fn($c)=>$c->only(['character_id','quantity','outfit','note']))->all(),
            ...($s->extends_event_snapshot?['extends_event_snapshot'=>true]:[]),...($s->covers_event_snapshot?['covers_event_snapshot'=>true]:[])])->values()->all();
    }
}
