<?php
namespace App\Services\Availability;

use Carbon\CarbonImmutable;

/**
 * Day signal for the website. Demand (the next 8 weekend dates) plus one capacity fact: a day that already has the
 * owner's 10-party maximum is full (owner 2026-10-29). Free times come only from CheckWebsiteCandidates. No writes/cache.
 */
final class ReadWebsiteDay
{
    public function execute(array $input): array
    {
        $now=CarbonImmutable::instance(now())->setTimezone('America/Chicago');
        $date=CarbonImmutable::createFromFormat('!Y-m-d',$input['date'],'America/Chicago');
        $demand=$this->demand($input['date'],$now);
        $full=false;
        try{ $full=(new WebsiteCapacity($date,[]))->partiesOn($input['date'])>=WebsiteCapacity::MAX_PER_DAY; }catch(\Throwable){ $full=false; }
        return ['schema_version'=>1,'scope'=>'day_signal','date'=>$input['date'],'timezone'=>'America/Chicago',
            'level'=>$full?'full':($demand==='weekend'?'filling':'unknown'),'capacity_state'=>$full?'unavailable':'unknown','demand'=>$demand,
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
