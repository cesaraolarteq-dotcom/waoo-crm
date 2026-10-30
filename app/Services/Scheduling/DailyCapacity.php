<?php

namespace App\Services\Scheduling;

use App\Models\Event;
use Carbon\{CarbonImmutable, CarbonInterface};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Admission only: a quota below10 never proves resource/route feasibility. No public locking reads. */
final class DailyCapacity
{
    public static function lock(): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Capacity admission requires a transaction.');
        DB::table('capacity_admission_locks')->where('id',1)->lockForUpdate()->firstOrFail();
    }

    public static function bounds(CarbonInterface $start): array
    {
        $local=CarbonImmutable::instance($start)->setTimezone('America/Chicago');
        // César 2026-10-29: ten parties per Houston calendar day, at any hour.
        // Keep website previews, day-lock admission and every money path on the same boundary.
        $from=$local->startOfDay();
        return [$from->utc(),$from->addDay()->utc()];
    }

    private static function commitments(CarbonInterface $start): Builder
    {
        [$from,$until]=self::bounds($start);
        return DB::table('events as e')->where('e.starts_at','>=',$from)->where('e.starts_at','<',$until)
            ->whereNotIn('e.status',['cancelled','canceled'])->where(function(Builder $q){
                $q->where('e.status','capacity_reserved')
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('booking_evidence as b')->whereColumn('b.event_id','e.id'))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('resource_reservations as r')->whereColumn('r.event_id','e.id'))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('quick_stripe_leases as l')->whereColumn('l.event_id','e.id')->whereNotIn('l.state',['cancelled','expired']))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('quick_bookings as b')->whereColumn('b.event_id','e.id'))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('quick_payment_bookings as b')->whereColumn('b.event_id','e.id')->whereNotNull('b.receipt_id'))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('ledger_entries as l')->whereColumn('l.event_id','e.id')->where('l.kind','receipt')->where('l.amount_cents','>',0))
                    ->orWhereExists(fn($s)=>$s->selectRaw('1')->from('imported_project_links as l')->whereColumn('l.event_id','e.id'));
            });
    }

    public static function isCommitted(Event $event): bool
    {
        self::lock();
        return self::commitments($event->starts_at)->where('e.id',$event->id)->lockForUpdate()->first(['e.id'])!==null;
    }

    public static function room(Event $event): bool
    {
        self::lock();
        // Current read after the singleton lock: never use a pre-existing repeatable-read snapshot.
        return self::commitments($event->starts_at)->where('e.id','!=',$event->id)->orderBy('e.id')->limit(10)->lockForUpdate()->get(['e.id'])->count()<10;
    }

    public static function assertRoom(Event $event): void
    {
        if(!self::room($event))throw ValidationException::withMessages(['booking'=>'Ese día ya tiene el máximo de diez fiestas. Revisa otra fecha antes de apartar.']);
    }

    public static function recordPaidOverflow(Event $event, int $revisionId, int $receiptId): void
    {
        if(!$event->starts_at->isFuture() || self::room($event))return;
        [$day]=self::bounds($event->starts_at);
        DB::table('payment_exceptions')->insertOrIgnore(['event_id'=>$event->id,'financial_revision_id'=>$revisionId,'kind'=>'daily_capacity_exceeded',
            'exception_key'=>hash('sha256','daily-capacity:'.$event->id.':'.$day->toDateString()),'state'=>'open',
            'context'=>json_encode(['ledger_entry_id'=>$receiptId,'operating_day'=>$day->setTimezone('America/Chicago')->toDateString()],JSON_THROW_ON_ERROR),
            'created_at'=>now(),'updated_at'=>now()]);
    }
}
