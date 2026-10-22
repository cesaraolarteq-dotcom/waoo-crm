<?php
namespace Tests\Unit;
use App\Services\Mail\MailDates;
use PHPUnit\Framework\TestCase;
class MailDatesTest extends TestCase
{
    public function test_spanish_order_and_date_only_subject_use_houston_calendar():void
    {
        $date='2026-10-01T01:00:00Z';
        $this->assertSame('miércoles 30 de septiembre',MailDates::subject($date,'es'));
        $this->assertSame('30 de septiembre de 2026',MailDates::due($date,'es'));
        $this->assertStringStartsWith('miércoles 30 de septiembre de 2026, 8:00',MailDates::full($date,'es'));
        $this->assertSame(59,mb_strlen('Tu cotización Waoo Party para el '.MailDates::subject($date,'es')));
        $this->assertSame('Wed, Sep 30',MailDates::subject($date,'en'));
        $this->assertStringNotContainsString(':',MailDates::subject($date,'es'));
    }
    public function test_owner_approved_mail_dates_omit_zone_abbreviations_but_keep_houston_time():void
    {
        foreach (['es','en'] as $locale) {
            foreach (['2026-11-01T06:30:00Z','2026-11-01T07:30:00Z'] as $at) {
                $this->assertDoesNotMatchRegularExpression('/\\b(?:CST|CDT)\\b/', MailDates::full($at,$locale));
            }
            $this->assertSame(MailDates::full('2026-11-01T06:30:00Z',$locale),MailDates::full('2026-11-01T07:30:00Z',$locale));
        }
    }
}
