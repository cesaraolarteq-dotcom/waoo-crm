<?php
namespace Tests\Unit;

use App\Services\Campaigns\ReadBirthdayCampaignDraft;
use PHPUnit\Framework\TestCase;

// Owner 2026-10-29: HoneyBook typed most projects "other"; Star, Magic, Music and Games, Waoo, pool and foam parties were birthdays.
class BirthdayPackagesTest extends TestCase
{
    public function test_packages_decide_which_other_parties_were_birthdays(): void
    {
        foreach (['Magic package', 'Star package', 'Music and Games Package 2.0', 'WAOO!! Package', 'Pool Party pacakge + Foam Cannon', 'Música y juegos'] as $name)
            $this->assertSame('birthday', ReadBirthdayCampaignDraft::eventType('other', [$name.'|', 'Travel fee|']), $name);
        foreach (['Photo booth station', 'Glam station', 'Costume character rental', 'Superstar character'] as $name)
            $this->assertSame('other', ReadBirthdayCampaignDraft::eventType('other', [$name]), $name);
        $this->assertSame('baby_shower', ReadBirthdayCampaignDraft::eventType('baby_shower', ['Magic package']));
    }
}
