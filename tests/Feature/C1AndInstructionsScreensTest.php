<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\StaffJobInstructions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Owner screens over Codex's C1 responsibility guard and versioned staff instructions (fictitious data).
class C1AndInstructionsScreensTest extends TestCase
{
    use RefreshDatabase, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        config(['waoo.sandbox_staff' => true]);
    }

    public function test_instructions_form_reads_pinned_versions_saves_and_edits(): void
    {
        $page = Livewire::test(ViewEvent::class, ['record' => $this->event->id])
            ->mountAction('staffInstructions')
            ->setActionData(['parking' => 'Street parking', 'access' => 'Left gate', 'bring' => "Speaker\n\n Bubbles ", 'instructions' => 'Piñata 4:15',
                'contact_name' => 'Fixture Mom', 'contact_phone' => '555-0100'])
            ->callMountedAction()->assertNotified('Indicaciones guardadas');
        $row = StaffJobInstructions::where('event_id', $this->event->id)->sole();
        $this->assertSame(1, $row->version);
        $this->assertSame(['Speaker', 'Bubbles'], $row->payload['bring']);
        $page->mountAction('staffInstructions')->assertActionDataSet(['parking' => 'Street parking', 'bring' => "Speaker\nBubbles"])
            ->setActionData(['parking' => 'Driveway', 'access' => null, 'bring' => '', 'instructions' => null, 'contact_name' => null, 'contact_phone' => null])
            ->callMountedAction()->assertNotified('Indicaciones guardadas');
        $this->assertSame(2, StaffJobInstructions::where('event_id', $this->event->id)->max('version'));
    }

    public function test_stale_instructions_are_refused_without_overwriting(): void
    {
        $page = Livewire::test(ViewEvent::class, ['record' => $this->event->id])->mountAction('staffInstructions');
        app(\App\Services\Staff\SaveStaffJobInstructions::class)->execute($this->owner, $this->event->id,
            ['expected_event_version' => $this->event->fresh()->version, 'expected_version' => 0, 'bring' => [], 'parking' => 'Other admin'], (string) \Illuminate\Support\Str::uuid());
        $page->setActionData(['parking' => 'Mine', 'access' => null, 'bring' => '', 'instructions' => null, 'contact_name' => null, 'contact_phone' => null])
            ->callMountedAction()->assertNotified('No se guardó');
        $this->assertSame('Other admin', StaffJobInstructions::where('event_id', $this->event->id)->sole()->payload['parking']);
    }

    public function test_honeybook_events_show_who_charges_and_a_clear_notice(): void
    {
        config(['waoo.sandbox_finance' => true]);
        DB::table('events')->where('id', $this->event->id)->update(['responsible_system' => 'honeybook']);
        $this->get(\App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $this->event->id]))->assertOk()->assertSee('Cobro y avisos:')->assertSee('HoneyBook cobra y avisa');
        $this->get(\App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $this->event->id]))->assertOk()->assertSee('HoneyBook cobra y avisa esta fiesta');
        DB::table('events')->where('id', $this->event->id)->update(['responsible_system' => 'crm']);
        $this->get(\App\Filament\Resources\Events\EventResource::getUrl('view', ['record' => $this->event->id]))->assertSee('CRM cobra y avisa');
        $this->get(\App\Filament\Resources\Events\EventResource::getUrl('money', ['record' => $this->event->id]))->assertDontSee('HoneyBook cobra y avisa esta fiesta');
    }
}
