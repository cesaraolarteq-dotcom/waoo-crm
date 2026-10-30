<?php
namespace App\Services\Availability;

use Carbon\CarbonImmutable;

/**
 * Day signal for the website: demand only (next 8 weekend dates), no database or provider reads (contract S1).
 * Free times come only from CheckWebsiteCandidates. No writes/cache.
 */
final class ReadWebsiteDay
{
    public function execute(array $input): array
    {
        $now=CarbonImmutable::instance(now())->setTimezone('America/Chicago');
        $date=CarbonImmutable::createFromFormat('!Y-m-d',$input['date'],'America/Chicago');
        $demand=$this->demand($input['date'],$now);
        return ['schema_version'=>1,'scope'=>'day_signal','date'=>$input['date'],'timezone'=>'America/Chicago',
            'level'=>$demand==='weekend'?'filling':'unknown','capacity_state'=>'unknown','demand'=>$demand,
            'requires_owner_approval'=>\App\Services\Scheduling\BookingNoticePolicy::requiresWebsiteApproval($date->setTime(8,0), $now),
            'slots'=>[],'alts'=>[],'checked_at'=>$now->toIso8601String(),'expires_at'=>$now->toIso8601String(),'reservation_created'=>false];
    }

    /** The next 8 upcoming Saturday/Sunday dates keep the website's existing weekend demand signal. */
    public function demand(string $date, CarbonImmutable $now): string
    {
        $weekends=[];$day=$now->startOfDay();
        while(count($weekends)<8){if($day->isWeekend())$weekends[]=$day->toDateString();$day=$day->addDay();}
        return in_array($date,$weekends,true)?'weekend':'normal';
    }
}
