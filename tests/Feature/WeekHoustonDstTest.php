<?php
namespace Tests\Feature;

use App\Filament\Pages\WeekendPlan;
use App\Models\{Customer,Event};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeekHoustonDstTest extends TestCase
{
    use DatabaseMigrations;

    public static function weeks(): array
    {
        return [
            'fall: last Sunday hour is included'=>['2026-10-30','2026-11-01'],
            'spring: next Monday is excluded'=>['2026-03-06','2026-03-08'],
        ];
    }

    #[DataProvider('weeks')]
    public function test_the_week_is_seven_houston_days_even_when_the_utc_offset_changes(string $friday,string $sunday): void
    {
        $start=CarbonImmutable::parse($friday,'America/Chicago')->subDays(4)->startOfDay();$this->travelTo($start);
        $c=Customer::create(['name'=>'María González','locale'=>'es']);
        $party=function(CarbonImmutable $at)use($c): Event{
            $e=Event::create(['customer_id'=>$c->id,'title'=>'Cumpleaños de Sofía','starts_at'=>$at->utc(),'ends_at'=>$at->addMinutes(30)->utc(),'timezone'=>'America/Chicago']);
            $e->forceFill(['status'=>'capacity_reserved'])->save();return $e;
        };
        $first=$party($start);$last=$party(CarbonImmutable::parse($sunday.' 23:30','America/Chicago'));
        $party($start->subMinute()); // previous Sunday, excluded even if its end is still in the future
        $party($start->addWeek()); // following Monday 00:00, excluded
        $page=new WeekendPlan();$page->friday=$friday;
        $this->assertSame([$first->id,$last->id],$page->events()->pluck('id')->all());
    }
}
