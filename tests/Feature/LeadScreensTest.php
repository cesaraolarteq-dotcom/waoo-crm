<?php
namespace Tests\Feature;

use App\Filament\Pages\{Leads, QuickBooking};
use App\Models\{Customer, Event, Lead, PriceVersion, ServiceVariant, User};
use App\Services\Leads\{ReceiveWebsiteLead, UpdateLead};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's LEAD-01 screens over Codex's services: list (escaped website text, duplicate hints), update with the
// version shown in the modal, conversion through Reserva rápida (lead booked only when the booking saves).
class LeadScreensTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'fixture-shared-secret-fixture-shared-secret-123';

    private PriceVersion $price;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_documents' => true, 'waoo.sandbox_lead_intake' => true, 'waoo.lead_shared_secret' => self::SECRET, 'app.url' => 'http://localhost']);
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $owner->id]);
    }

    private function lead(array $over = []): Lead
    {
        $body = json_encode([...['name' => 'Ana Ficticia', 'phone' => '(346) 555-0100', 'email' => 'ana@example.test', 'event_date' => '2026-12-19', 'start_time' => '15:00',
            'zip' => '77494', 'event_type' => 'Birthday', 'package' => 'Magic Package', 'message' => '<script>alert(1)</script> ¡Hola!', 'travel_estimate_text' => '$40',
            'locale' => 'es', 'demand_flag' => 'filling', 'submitted_at' => now()->toIso8601String(), 'page' => '/contact', 'consent' => null], ...$over]);
        $ts = (string) time(); $key = (string) Str::uuid();

        return app(ReceiveWebsiteLead::class)->execute($body, $ts, $key, 'sha256='.hash_hmac('sha256', $ts."\n".$key."\n".$body, self::SECRET));
    }

    public function test_list_escapes_website_text_and_shows_hints(): void
    {
        $this->lead();
        Customer::create(['name' => 'Ana F.', 'phone' => '346-555-0100', 'locale' => 'es']);
        $this->get('/admin/prospectos')->assertOk()->assertSee('Ana Ficticia')->assertSee('Fecha muy pedida')->assertSee('77494')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)
            ->assertSee('Cumpleaños')->assertSee('Magic Package')->assertSeeText('Puede ser un cliente registrado')->assertSeeText('Ana F. (mismo teléfono)')->assertSee('Convertir en reserva rápida');
        $this->get('/admin')->assertSee('Prospectos sin contestar');
    }

    public function test_update_uses_the_version_shown_in_the_modal(): void
    {
        $lead = $this->lead();
        Livewire::test(Leads::class)->callAction('update', ['status' => 'contacted', 'owner_note' => 'Le escribí por WhatsApp.'], ['id' => $lead->id])->assertNotified('Prospecto actualizado');
        $this->assertSame(['contacted', 2], [$lead->fresh()->status, $lead->fresh()->version]);

        $page = Livewire::test(Leads::class)->mountAction('update', ['id' => $lead->id]);
        app(UpdateLead::class)->execute(User::sole(), $lead->id, ['expected_version' => 2, 'owner_note' => 'Maybeli la llamó.']);
        $page->setActionData(['status' => 'lost', 'owner_note' => 'No contestó.'])->callMountedAction()->assertNotified('No se guardó');
        $this->assertSame(['contacted', 'Maybeli la llamó.'], [$lead->fresh()->status, $lead->fresh()->owner_note]);
    }

    public function test_convert_prefills_quick_booking_and_marks_booked_only_when_saved(): void
    {
        $lead = $this->lead();
        $page = Livewire::test(QuickBooking::class, [])->call('fillFromLead', $lead->id)
            ->assertSet('leadId', $lead->id)->assertSet('leadVersion', 1)
            ->assertSchemaStateSet(['customer_mode' => 'new', 'event_type' => 'birthday', 'name' => 'Ana Ficticia', 'email' => 'ana@example.test', 'postal_code' => '77494', 'date' => '2026-12-19', 'start_time' => '15:00'])
            ->assertSee('Convirtiendo el prospecto de');
        $this->assertSame('new', $lead->fresh()->status, 'Opening the conversion changes nothing.');
        $page->fillForm(['price_version_ids' => [$this->price->id], 'event_type' => 'birthday', 'title' => 'Fiesta Ana', 'end_time' => '16:00', 'travel_fee' => '40',
            'deposit' => ['amount' => '70', 'reference' => 'ZELLE-LEAD-1', 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05', 'verified' => true], 'expires_days' => 14])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('booked', $lead->fresh()->status);
        $this->assertSame(Event::sole()->id, $lead->fresh()->booked_event_id);
        $this->assertSame('website', \App\Models\EventChannel::where('event_id', Event::sole()->id)->value('channel'), 'A website lead came through the website (owner 2026-10-28).');
        $this->get('/admin/prospectos?status=booked')->assertSee('Ver la reserva');
    }

    public function test_a_lost_lead_keeps_why_and_forgets_it_when_reopened(): void
    {
        // Owner 2026-10-28: lost leads say why, for Reportes.
        $lead = $this->lead();
        Livewire::test(Leads::class)->callAction('update', ['status' => 'lost', 'owner_note' => null], ['id' => $lead->id])->assertHasActionErrors(['lost_reason' => 'required']);
        Livewire::test(Leads::class)->callAction('update', ['status' => 'lost', 'lost_reason' => 'date_full', 'owner_note' => null], ['id' => $lead->id])->assertNotified('Prospecto actualizado');
        $this->assertSame('date_full', \App\Models\LeadLossReason::where('lead_id', $lead->id)->value('reason'));
        $this->get('/admin/prospectos?status=lost')->assertSee('Perdido · Fecha llena');
        Livewire::test(Leads::class)->callAction('update', ['status' => 'contacted', 'owner_note' => null], ['id' => $lead->id])->assertNotified('Prospecto actualizado');
        $this->assertSame(0, \App\Models\LeadLossReason::count());
    }

    public function test_hidden_without_the_lead_sandbox(): void
    {
        config(['waoo.sandbox_lead_intake' => false]);
        $this->get('/admin/prospectos')->assertForbidden();
    }

    // Codex review #59: lead 51 is reachable through the page controls; changing the filter goes back to page 1.
    public function test_pagination_reaches_every_lead_and_filter_resets_the_page(): void
    {
        $first = $this->lead(['name' => 'Primera Ficticia', 'email' => 'primera@example.test']);
        foreach (range(2, 51) as $n) $this->lead(['name' => "Prospecto $n", 'email' => "p$n@example.test"]);
        $page = Livewire::test(Leads::class)->assertSee('Prospecto 51')->assertDontSee('Primera Ficticia')
            ->assertSee('1–50 de 51 · página 1 de 2')->assertSee('Siguientes →');
        $page->call('goToPage', 2)->assertSet('leadPage', 2)->assertSee('Primera Ficticia')->assertDontSee('Prospecto 51')->assertSee('51–51 de 51 · página 2 de 2');
        $page->call('goToPage', 9)->assertSet('leadPage', 2);
        $page->set('status', 'all')->assertSet('leadPage', 1)->assertSee('Prospecto 51');
        $this->get('/admin/prospectos?pagina=2')->assertOk()->assertSee('Primera Ficticia');
        $this->assertSame(51, Lead::count());
    }
}
