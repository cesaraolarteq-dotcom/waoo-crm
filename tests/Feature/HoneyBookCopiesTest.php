<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Customer, Event, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Owner 2026-10-28: record copies of HoneyBook files are private, owner-only and shown only on the matching event.
class HoneyBookCopiesTest extends TestCase
{
    use DatabaseMigrations;

    public function test_copy_is_shown_only_for_its_event_and_served_privately(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $client = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $make = fn (string $t) => Event::create(['customer_id' => $client->id, 'title' => $t, 'starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHour(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => 0]);
        $with = $make('Magic Package 11/28 María González');
        $without = $make('Otra fiesta');
        Storage::disk('local')->put('honeybook-copies/0123456789abcdef01234567.pdf', '%PDF-1.4 fixture');
        Storage::disk('local')->put('honeybook-copies/manifest.json', json_encode(['extracted_at' => '2026-10-28', 'copies' => [['project_id' => '0123456789abcdef01234567', 'event_id' => $with->id]]]));

        $this->get('/admin/api/events/'.$with->id.'/honeybook-copy')->assertRedirect(); // not logged in
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/api/events/'.$with->id.'/honeybook-copy')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/admin/api/events/'.$without->id.'/honeybook-copy')->assertNotFound();
        Livewire::test(ViewEvent::class, ['record' => $with->id])->assertActionVisible('honeybookCopy');
        Livewire::test(ViewEvent::class, ['record' => $without->id])->assertActionHidden('honeybookCopy');
    }
}
