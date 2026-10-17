<?php
namespace Tests\Unit;
use App\Services\Imports\HoneyBookFutureSource;
use Carbon\CarbonImmutable;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;
class HoneyBookFutureSourceTest extends TestCase
{
    use HoneyBookFutureScenario;
    private function preview(array $change=[]):array
    {
        $s=$this->futureRow($change);return app(HoneyBookFutureSource::class)->build(['projects'=>['rows'=>[['line'=>2,'cells'=>array_values($s)]]]],CarbonImmutable::parse('2026-10-17T12:00:00Z'))['rows'][0];
    }
    public function test_strict_money_blank_is_unknown_and_math_never_silently_reconciles():void
    {
        $this->assertNull(HoneyBookFutureSource::cents(''));$this->assertSame(10001,HoneyBookFutureSource::cents('100.01'));$this->assertSame(-101,HoneyBookFutureSource::cents('-1.01',true));
        foreach(['1','1.0','01.00','$1.00','1,000.00',' 1.00','-1.00','1e2'] as $bad){try{HoneyBookFutureSource::cents($bad);$this->fail('Expected strict money rejection');}catch(\InvalidArgumentException){$this->addToAssertionCount(1);}}
        $r=$this->preview(['total'=>'']);$this->assertNull($r['normalized']['total_cents']);$this->assertContains('source_required',array_column($r['errors'],'code'));
        foreach([['total'=>'121.00'],['balance'=>'61.00'],['payments'=>'2026-10-01|59.00|Zelle']] as $bad)$this->assertSame('conflict',$this->preview($bad)['status']);
    }
    public function test_houston_dst_unknown_hours_dates_and_signed_source_payment_ambiguities_require_review():void
    {
        foreach([['event_date'=>'2026-11-01','start_time'=>'01:15','end_time'=>'02:15'],['event_date'=>'2027-03-14','start_time'=>'02:15','end_time'=>'03:15'],['event_date'=>'2026-02-30'],['start_time'=>'3:00'],['start_time'=>''],['end_time'=>'15:00']] as $bad)$this->assertContains('invalid_or_ambiguous_houston_window',array_column($this->preview($bad)['errors'],'code'));
        foreach(['2026-10-18|60.00|Zelle','2026-02-30|60.00|Zelle','2026-10-01|60|Zelle','2026-10-01|60.00|','2026-10-01|60.00|Zelle|extra'] as $bad)$this->assertContains('invalid_source_payment',array_column($this->preview(['payments'=>$bad])['errors'],'code'));
        $this->assertSame('cancelled',$this->preview(['status'=>'cancelled'])['skip_reason']);$this->assertSame('past',$this->preview(['event_date'=>'2026-09-20'])['skip_reason']);
        $this->assertContains('owner_status_review_required',array_column($this->preview(['status'=>'custom-stage'])['errors'],'code'));
        $this->assertContains('yes_no_required',array_column($this->preview(['contract_signed'=>'true'])['errors'],'code'));
        $this->assertContains('repeated_source_tuple_review',$this->preview(['payments'=>'2026-10-01|30.00|Zelle;2026-10-01|30.00|Zelle'])['normalized']['payment_warnings']);
    }
}
