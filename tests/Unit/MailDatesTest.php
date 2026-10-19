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
    public function test_repeated_wall_hour_remains_distinguishable():void
    {
        $this->assertStringEndsWith('CDT',MailDates::full('2026-11-01T06:30:00Z','es'));
        $this->assertStringEndsWith('CST',MailDates::full('2026-11-01T07:30:00Z','es'));
        $this->assertSame(MailDates::subject('2026-11-01T06:30:00Z','es'),MailDates::subject('2026-11-01T07:30:00Z','es'));
    }
}
