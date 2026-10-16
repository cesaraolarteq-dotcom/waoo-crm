<?php
namespace Tests\Review;

use App\Filament\Pages\Leads;
use App\Filament\Resources\Characters\CharacterResource;
use App\Filament\Support\ContactLinks;
use App\Models\{Lead, LeadSubmission, User};
use App\Services\Leads\ReadLeads;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Opt-in diagnostics of the reviewed heads. Passing reproduces defects; it is NOT acceptance.
class Phase2ScreensProbe extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_lead_intake' => true]);
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_zero_named_outfit_is_silently_removed_before_the_service_receives_it(): void
    {
        $this->assertNull(CharacterResource::toServiceInput(['name' => 'Fixture Character', 'active' => true, 'outfits' => ['0']])['outfits']);
    }

    public function test_lead_page_two_still_hides_the_51st_record_even_though_the_service_exposes_it(): void
    {
        for ($n = 1; $n <= 51; $n++) {
            $s = LeadSubmission::create(['request_key' => (string) Str::uuid(), 'payload_sha256' => hash('sha256', 'fixture-'.$n),
                'payload' => ['name' => 'Fixture '.$n, 'email' => 'fixture'.$n.'@example.test', 'phone' => '', 'zip' => '77493'],
                'submitted_at' => now(), 'received_at' => now()]);
            Lead::create(['lead_submission_id' => $s->id, 'status' => 'new']);
        }
        $oldest = Lead::orderBy('id')->first()->id;
        $backend = app(ReadLeads::class)->index(User::sole(), ['status' => 'new', 'per_page' => 50, 'page' => 2]);
        $this->assertSame([$oldest], collect($backend->items())->pluck('id')->all());
        $page = Livewire::withQueryParams(['page' => 2])->test(Leads::class);
        $rows = $page->instance()->rows();
        $this->assertCount(50, $rows);
        $this->assertNotContains($oldest, collect($rows)->pluck('id')->all());
        $page->assertDontSee('Siguiente')->assertDontSee('Anterior');
    }

    public function test_phone_extension_becomes_a_different_international_recipient(): void
    {
        $this->assertSame('3465550100123', ContactLinks::digits('(346) 555-0100 ext 123'));
        $this->assertSame('https://wa.me/3465550100123?text=fixture', ContactLinks::whatsapp('(346) 555-0100 ext 123', 'fixture'));
        $this->assertNotNull(ContactLinks::sms('DO NOT TEXT 346-555-0100', 'fixture'));
        $this->assertSame('00000000000', ContactLinks::digits('00000000000'));
    }
}
