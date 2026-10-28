<?php
namespace App\Services\Availability;

use Carbon\CarbonImmutable;

/** Demand/unknown only until a complete combined resources-and-transfer evaluator exists. No domain writes/cache. */
final class ReadWebsiteDay
{
    public function execute(array $input): array
    {
        $now=CarbonImmutable::instance(now())->setTimezone('America/Chicago');
        $date=CarbonImmutable::createFromFormat('!Y-m-d',$input['date'],'America/Chicago');
        $weekends=[];$day=$now->startOfDay();
        while(count($weekends)<8){if($day->isWeekend())$weekends[]=$day->toDateString();$day=$day->addDay();}
        $demand=in_array($input['date'],$weekends,true)?'weekend':'normal';
        return ['schema_version'=>1,'scope'=>'day_signal','date'=>$input['date'],'timezone'=>'America/Chicago',
            'level'=>$demand==='weekend'?'filling':'unknown','capacity_state'=>'unknown','demand'=>$demand,
            'requires_owner_approval'=>\App\Services\Scheduling\BookingNoticePolicy::requiresWebsiteApproval($date->setTime(8,0), $now),
            'slots'=>[],'alts'=>[],'checked_at'=>$now->toIso8601String(),'expires_at'=>$now->toIso8601String(),'reservation_created'=>false];
    }
}
