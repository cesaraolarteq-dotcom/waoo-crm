<?php
namespace Tests\Unit;

use App\Services\Reports\BookingAnalytics;
use PHPUnit\Framework\TestCase;

class BookingAnalyticsTest extends TestCase
{
    private function row(array $changes = []): array
    {
        return [...['source'=>'honeybook','status'=>'completed','date'=>'2025-08-10','postal_code'=>'77001',
            'lead_source'=>'Instagram','created_at'=>'2025-07-10','total_cents'=>12000,'currency'=>'USD','package_ids'=>[]], ...$changes];
    }
    public function test_counts_unknowns_and_exact_ticket_denominator_without_treating_drafts_as_sales(): void
    {
        $r = (new BookingAnalytics)->summarize([
            $this->row(), $this->row(['source'=>'crm','status'=>'capacity_reserved','total_cents'=>null,'created_at'=>null,'package_ids'=>[4,4]]),
            $this->row(['status'=>'cancelled']), $this->row(['status'=>'archived_unknown']), $this->row(['date'=>'2024-08-10']),
        ],2025);
        $this->assertSame(2,$r['booking_count']); $this->assertSame(['crm'=>1,'honeybook'=>1],$r['source_counts']);
        $this->assertSame(12000,$r['ticket']['average_cents']); $this->assertSame(1,$r['ticket']['unknown_count']);
        $this->assertSame(31.0,$r['lead_time']['average_days']); $this->assertSame(1,$r['lead_time']['unknown_count']);
        $this->assertSame(1,$r['packages'][4]); $this->assertSame(1,$r['packages']['unknown']);
        $this->assertSame(2,$r['weekdays'][7]); $this->assertSame(2,$r['exclusions']['not_confirmed_or_completed']);
    }
    public function test_filters_exclusions_to_the_year_and_ranks_categories_by_count(): void
    {
        $r=(new BookingAnalytics)->summarize([
            $this->row(['lead_source'=>'Facebook','package_ids'=>[2]]),
            $this->row(['lead_source'=>'Instagram','package_ids'=>[4]]),
            $this->row(['lead_source'=>'Instagram','package_ids'=>[4]]),
            $this->row(['date'=>'2024-08-10','status'=>'cancelled']),
            $this->row(['created_at'=>'2025-02-30','total_cents'=>0]),
        ],2025);
        $this->assertSame(4,$r['booking_count']);
        $this->assertSame([], $r['exclusions']);
        $this->assertSame(4,array_key_first($r['packages']));
        $this->assertSame('instagram',array_key_first($r['lead_sources']));
        $this->assertSame(1,$r['lead_time']['unknown_count']);
        $this->assertSame(4,$r['ticket']['known_count'], 'A known zero is distinct from missing money.');
    }

    public function test_export_is_closed_vocabulary_and_cannot_leak_source_text_or_formulas(): void
    {
        $private='=HYPERLINK("María González history@example.test")';
        $service=new BookingAnalytics;
        $r=$service->summarize([$this->row(['lead_source'=>$private,'postal_code'=>$private,'package_ids'=>[$private], 'project_name'=>$private])],2025);
        $csv=$service->csv($r);
        $this->assertSame(['other'=>1],$r['lead_sources']); $this->assertSame(['unknown'=>1],$r['postal_codes']);
        $this->assertStringNotContainsString('María',$csv); $this->assertStringNotContainsString('HYPERLINK',$csv);
        $this->assertStringNotContainsString('example.test',json_encode($r));
    }
    public function test_missing_invalid_and_negative_dates_or_amounts_do_not_become_zero(): void
    {
        $r=(new BookingAnalytics)->summarize([
            $this->row(['date'=>'2025-02-30']), $this->row(['created_at'=>'yesterday','total_cents'=>-10]),
            $this->row(['created_at'=>'2025-08-11','currency'=>'EUR']),
        ],2025);
        $this->assertSame(2,$r['booking_count']); $this->assertSame(1,$r['exclusions']['date_unknown_all_years']);
        $this->assertNull($r['ticket']['average_cents']); $this->assertNull($r['lead_time']['average_days']);
        $this->assertSame(2,$r['ticket']['unknown_count']);
    }
}
