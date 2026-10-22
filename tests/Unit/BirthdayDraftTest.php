<?php
namespace Tests\Unit;

use App\Services\Campaigns\BirthdayDraft;
use PHPUnit\Framework\TestCase;

class BirthdayDraftTest extends TestCase
{
    private function anchor(array $changes=[]): array
    {
        return [...['key'=>'history:1','customer_id'=>1,'status'=>'completed','event_type'=>'birthday','date'=>'2025-12-10'], ...$changes];
    }
    public function test_only_completed_birthdays_with_dates_become_blocked_drafts_never_messages_or_codes(): void
    {
        $r=(new BirthdayDraft)->prepare([$this->anchor(),$this->anchor(['customer_id'=>2,'status'=>'archived_unknown']),
            $this->anchor(['customer_id'=>3,'event_type'=>'corporate']), $this->anchor(['customer_id'=>4,'date'=>'2025-02-30'])], [], '2026-10-21');
        $this->assertSame(1,$r['candidate_count']); $this->assertSame(0,$r['sendable_count']);
        $this->assertSame('2026-11-10',$r['items'][0]['proposed_review_on']);
        $this->assertContains('marketing_permission_unverified',$r['items'][0]['reasons']);
        $this->assertFalse($r['copy']['approved']); $this->assertFalse($r['delivery_enabled']); $this->assertSame(0,$r['codes_issued']);
    }
    public function test_latest_anchor_and_new_booking_suppression_are_customer_scoped(): void
    {
        $r=(new BirthdayDraft)->prepare([$this->anchor(['date'=>'2024-12-10']),$this->anchor()],
            [['customer_id'=>1,'status'=>'draft','date'=>'2026-12-10']], '2026-10-21');
        $this->assertSame(1,$r['candidate_count']); $this->assertSame('2025-12-10',$r['items'][0]['source_date']);
        $this->assertContains('already_rebooked',$r['items'][0]['reasons']);
        $r=(new BirthdayDraft)->prepare([$this->anchor()], [['customer_id'=>1,'status'=>'cancelled','date'=>'2026-12-10']], '2026-10-21');
        $this->assertNotContains('already_rebooked',$r['items'][0]['reasons']);
    }
    public function test_leap_day_is_not_silently_changed_and_invalid_or_future_anchor_is_excluded(): void
    {
        $r=(new BirthdayDraft)->prepare([$this->anchor(['date'=>'2024-02-29']), $this->anchor(['customer_id'=>2,'date'=>'2027-12-10'])], [], '2026-10-21');
        $this->assertSame(1,$r['candidate_count']); $this->assertNull($r['items'][0]['anniversary']);
        $this->assertContains('leap_day_requires_review',$r['items'][0]['reasons']);
    }
}
