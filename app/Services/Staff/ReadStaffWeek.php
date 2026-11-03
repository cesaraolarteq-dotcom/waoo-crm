<?php
namespace App\Services\Staff;

use App\Models\{BookingEvidence, Character, Event, Resource, StaffJobInstructions};
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReadStaffWeek
{
    public function execute(StaffContext $staff): array
    {
        return DB::transaction(function () use ($staff) {
            [, $person]=$staff->authorize();
            \App\Models\StaffAccessGrant::whereKey($staff->grantId)->update(['last_opened_at'=>now()]);
            $now=CarbonImmutable::now('America/Chicago');
            $start=$now->startOfWeek(CarbonImmutable::MONDAY); $end=$start->addWeek();
            $events=Event::where('status','capacity_reserved')->where('starts_at','>=',$start->utc())->where('starts_at','<',$end->utc())
                ->where('ends_at','>',$now->utc())->whereHas('reservations',fn($q)=>$q->where('resource_id',$person->id)
                    ->whereColumn('resource_reservations.starts_at','events.starts_at')->whereColumn('resource_reservations.ends_at','events.ends_at'))
                ->orderBy('starts_at')->orderBy('id')->get();
            $jobs=$events->map(fn($e)=>$this->job($e,$person,$staff->locale,$now))->filter()->values()->all();
            return ['lang'=>$staff->locale,'preview'=>false,'staff'=>['display_name'=>$this->firstName($person->name)],
                'week'=>['starts_at'=>$start->utc()->toIso8601String(),'ends_at'=>$end->utc()->toIso8601String(),
                    'from'=>$this->label($start,$staff->locale,'ddd D MMM'),'to'=>$this->label($end->subDay(),$staff->locale,'ddd D MMM')],
                'updated_at'=>$this->label($now,$staff->locale,'ddd D MMM, h:mm a'),'generated_at'=>$now->utc()->toIso8601String(),
                'lang_url'=>['es'=>url('/staff?lang=es'),'en'=>url('/staff?lang=en')],'jobs'=>$jobs,'ask'=>$this->ask($person,$staff->locale)];
        },3);
    }
    private function firstName(string $name): string { return preg_split('/[\s\p{Z}]+/u',$name,-1,PREG_SPLIT_NO_EMPTY)[0]??''; }
    private function label(CarbonImmutable $at,string $lang,string $format): string { return $at->locale($lang)->isoFormat($format); }
    private function job(Event $event,Resource $person,string $lang,CarbonImmutable $now): ?array
    {
        $reservations=$event->reservations()->get();
        $resources=Resource::whereIn('id',$reservations->pluck('resource_id'))->where('status','active')->get()->keyBy('id');
        $evidence=BookingEvidence::where('event_id',$event->id)->latest('id')->first();
        $snapshot=$evidence?->staff_assignment_snapshot??[];
        // Owner 2026-11-02: a team chosen in "Planear el fin de semana" is the source of who works and in which seat.
        if ($plan=\App\Models\EventTeamPlan::where('event_id',$event->id)->first()) $snapshot=self::fromPlan($plan,$snapshot);
        $assigned=collect($snapshot['assignments']??[])->first(fn($r)=>(int)($r['resource_id']??0)===$person->id && ($r['kind']??null)==='staff');
        if ($snapshot !== [] && $assigned === null) return null;
        $actualStaffIds=collect($snapshot['assignments']??[])->where('kind','staff')->pluck('resource_id')->map(fn($id)=>(int)$id)->all();
        $slot=$assigned['slot']??[];
        $drivers=array_map('intval',$snapshot['assigned_driver_ids']??[]);
        $costume=isset($slot['costume_unit_id'])?$resources->get((int)$slot['costume_unit_id']):null;
        if ($costume?->kind!=='costume') $costume=null;
        $character=$costume?->character_id?Character::find($costume->character_id):null;
        $instruction=StaffJobInstructions::where('event_id',$event->id)->latest('version')->first();
        $payload=$instruction?->payload??[];
        if ($instruction && !hash_equals($instruction->sha256,MutationKey::hash($payload))) abort(409,'Las instrucciones requieren revisión.');
        $starts=$event->starts_at->setTimezone('America/Chicago'); $ends=$event->ends_at->setTimezone('America/Chicago');
        $unknown=$lang==='es'?'Pendiente':'Not provided';
        $skill=$slot['skill']??null;
        $roles=['performer'=>['Intérprete','Performer'],'entertainer'=>['Animador/a','Entertainer'],'host'=>['Recreador/a','Host'],'face_painter'=>['Pintacaritas','Face painter'],'driver'=>['Conductor','Driver']];
        $role=$roles[$skill][$lang==='es'?0:1]??($lang==='es'?'Personal':'Staff');
        $answer=\App\Models\StaffJobAnswer::current($event,$person->id);
        return ['event_id'=>$event->id,'answer'=>$answer?->answer,'answer_note'=>$answer?->note,'date'=>$this->label($starts,$lang,$lang==='es'?'dddd D [de] MMMM':'dddd, MMMM D'),
            'hours'=>$this->label($starts,$lang,'h:mm a').' '.$starts->format('T').' – '.$this->label($ends,$lang,'h:mm a').' '.$ends->format('T'),
            'arrival'=>$event->setup_minutes!==null?$this->label($starts->subMinutes($event->setup_minutes),$lang,'h:mm a').' '.$starts->subMinutes($event->setup_minutes)->format('T'):$unknown,
            'setup_minutes'=>$event->setup_minutes,'address'=>($address=\App\Support\ClientAddress::forEvent($event)[0])??$unknown,
            'maps'=>$address?'https://maps.google.com/?q='.rawurlencode($address):null,
            'parking'=>$payload['parking']??null,'access'=>$payload['access']??null,
            'role'=>$role,'character'=>($snapshot['costume_policy_version']??null)==='costume-outfit-v2'?($slot['character_name']??null):$character?->name,
            'outfit_version'=>($snapshot['costume_policy_version']??null)==='costume-outfit-v2'?($slot['outfit']??null):$costume?->outfit,
            'is_driver'=>$assigned!==null && in_array($person->id,$drivers,true),
            'team'=>$resources->filter(fn($r)=>$r->kind==='staff' && $r->id!==$person->id && ($snapshot===[] || in_array($r->id,$actualStaffIds,true)))->map(fn($r)=>[
                'first_name'=>$this->firstName($r->name),'drives'=>in_array($r->id,$drivers,true)])->values()->all(),
            'honeybook'=>\App\Services\History\ReadHoneyBookEventFacts::forEvent($event),
            'bring'=>$payload['bring']??[],'instructions'=>$payload['instructions']??null,
            'day_contact'=>$starts->isSameDay($now) && !empty($payload['contact_name']) && !empty($payload['contact_phone'])
                ?['name'=>$payload['contact_name'],'phone'=>$payload['contact_phone']]:null];
    }
    /**
     * Owner 2026-11-03: the weekend asked about, how many parties each day has (Saturday first: it is the busiest day) and
     * this person's answer so far. Counts only; never clients, prices or other people.
     */
    private function ask(Resource $person,string $lang): array
    {
        $monday=SaveStaffAnswers::askedWeek();
        $row=\App\Models\StaffAvailability::where('resource_id',$person->id)->whereDate('week_start',$monday->toDateString())->first();
        $count=fn(CarbonImmutable $from,CarbonImmutable $to)=>Event::where('status','!=','cancelled')->where('starts_at','>=',$from->utc())->where('starts_at','<',$to->utc())->count();
        $fri=$monday->addDays(4);
        $days=['sat'=>[$fri->addDay(),$fri->addDays(2)],'fri'=>[$fri,$fri->addDay()],'sun'=>[$fri->addDays(2),$fri->addDays(3)],'weekday'=>[$monday,$fri]];
        $out=[];
        foreach ($days as $k=>[$from,$to]) $out[$k]=['label'=>$k==='weekday'?($lang==='es'?'Entre semana (lun–jue)':'Weekdays (Mon–Thu)'):$this->label($from,$lang,$lang==='es'?'dddd D [de] MMMM':'dddd, MMMM D'),
            'parties'=>$count($from,$to),'answer'=>$row?->days[$k]??null];
        return ['week'=>$monday->toDateString(),'days'=>$out,'answered'=>$row!==null,'note'=>$row?->note,
            'answered_at'=>$row?$this->label($row->answered_at->setTimezone('America/Chicago'),$lang,'ddd D MMM, h:mm a'):null];
    }

    /** Same shape as the booking snapshot, built from the weekend plan (seats, costume wearers, the driver). */
    private static function fromPlan(\App\Models\EventTeamPlan $plan,array $snapshot): array
    {
        $rows=[];
        foreach ($plan->slots as $seat) if ($seat['resource_id']) $rows[]=['resource_id'=>(int)$seat['resource_id'],'kind'=>'staff','slot'=>['skill'=>$seat['skill']]];
        foreach ($plan->costumes as $c) if ($c['wearer_id']) $rows[]=['resource_id'=>(int)$c['wearer_id'],'kind'=>'staff',
            'slot'=>['skill'=>'performer','costume_unit_id'=>$c['unit_id'],'character_name'=>Character::whereKey($c['character_id'])->value('name')]];
        return [...$snapshot,'costume_policy_version'=>'costume-outfit-v2','assignments'=>$rows,'assigned_driver_ids'=>$plan->driver_resource_id?[(int)$plan->driver_resource_id]:[]];
    }
}
