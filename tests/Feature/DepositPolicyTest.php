<?php
namespace Tests\Feature;

use App\Services\Scheduling\DepositPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Owner 2026-11-04: today or tomorrow (Houston) = 100 % with at least 24 h notice; otherwise 50 %. */
class DepositPolicyTest extends TestCase
{
    public function test_original_full_payment_is_not_lost_when_the_revision_contains_a_balance_only_change_fee(): void
    {
        $full=new \App\Models\FinancialRevision(['total_cents'=>62500,'deposit_target_cents'=>52500,
            'lines_snapshot'=>[['amount_cents'=>52500],['kind'=>'change_fee','amount_cents'=>10000]]]);
        $this->assertSame(100,DepositPolicy::revisionPercent($full));
        $full->deposit_target_cents=26250;
        $this->assertSame(50,DepositPolicy::revisionPercent($full));
    }

    public function test_explicit_policy_disambiguates_a_one_cent_agreement(): void
    {
        foreach([50,100] as $percent){
            $r=new \App\Models\FinancialRevision(['total_cents'=>1,'deposit_target_cents'=>1,'lines_snapshot'=>[],'deposit_percent_snapshot'=>$percent]);
            $this->assertSame($percent,DepositPolicy::revisionPercent($r));
            $this->assertSame($percent===100?[100]:[50,100],DepositPolicy::collectionPercents($r));
        }
    }

    public function test_today_and_tomorrow_pay_everything_and_under_a_day_is_flagged(): void
    {
        $now = CarbonImmutable::parse('2026-11-04 22:00', 'America/Chicago'); // Wednesday night
        $at = fn (string $local) => CarbonImmutable::parse($local, 'America/Chicago');
        $this->assertTrue(DepositPolicy::fullPayment($at('2026-11-05 23:00'), $now));
        $this->assertFalse(DepositPolicy::fullPayment($at('2026-11-06 10:00'), $now));
        $this->assertTrue(DepositPolicy::underDayNotice($at('2026-11-05 15:00'), $now));
        $this->assertFalse(DepositPolicy::underDayNotice($at('2026-11-05 23:00'), $now));
        $this->assertSame(40001, DepositPolicy::targetCents(40001, $at('2026-11-05 23:00'), $now));
        $this->assertSame(20001, DepositPolicy::targetCents(40001, $at('2026-11-20 15:00'), $now));
        $this->assertSame('400.00', \App\Filament\Pages\QuickBooking::depositDefault([], '0') ?? '400.00');
    }
}
