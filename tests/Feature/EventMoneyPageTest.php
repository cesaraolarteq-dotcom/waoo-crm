<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\{ConfirmCapacity, EventMoney};
use App\Models\{Customer, Event, EventService, FinancialRevision, LedgerEntry, PriceVersion, Resource, ServiceVariant, User};
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// "Dinero" page and paid-mode confirmation against Codex's real sandbox Finance services. Fictitious amounts.
class EventMoneyPageTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::create(['name' => 'Fictitious Client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 10000, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $start = CarbonImmutable::parse('2026-12-19 15:00', 'America/Chicago');
        $this->event = Event::create(['customer_id' => $customer->id, 'title' => 'Fiesta', 'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'timezone' => 'America/Chicago',
            'address' => 'Fictitious address', 'postal_code' => '00001', 'setup_minutes' => 30, 'breakdown_minutes' => 20, 'travel_fee_cents' => 2500]);
        EventService::create(['event_id' => $this->event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 60, 'operational_ready' => true]);
    }

    private function zelle(string $amount, string $ref = 'ZELLE-FIXTURE-1'): array
    {
        return ['amount' => $amount, 'reference' => $ref, 'date' => now('America/Chicago')->subDay()->format('Y-m-d'), 'time' => '00:05', 'verified' => true];
    }

    public function test_money_page_is_off_outside_the_sandbox(): void
    {
        config(['waoo.sandbox_finance' => false]);
        $this->get("/admin/eventos/{$this->event->id}/dinero")->assertForbidden();
    }

    public function test_fix_amounts_then_record_verified_zelle_with_shortfall_and_balance(): void
    {
        $page = Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertSee('Primero fija los montos acordados')
            ->callAction('fixAmounts')->assertNotified('Montos fijados');
        $revision = FinancialRevision::sole();
        $this->assertSame(12500, $revision->total_cents);
        $this->assertSame(6250, $revision->deposit_target_cents);

        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('62'))->assertNotified('Pago registrado')
            ->assertSee('Faltan $0.50 para el depósito')->assertSee('$63')->assertSee('Remaining balance due')->assertSee('ZELLE-FIXTURE-1');
        $this->assertSame(6200, LedgerEntry::sole()->amount_cents);
        $this->assertSame($this->owner->id, (int) LedgerEntry::sole()->verified_by);
    }

    public function test_unverified_or_duplicate_receipts_are_refused(): void
    {
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('fixAmounts');
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', [...$this->zelle('62.50'), 'verified' => false])->assertHasActionErrors(['verified']);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('62.50'))->assertNotified('Pago registrado');
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('10'))->assertNotified('No se guardó');
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_overpayment_is_shown_not_hidden(): void
    {
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('fixAmounts');
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('130'))
            ->assertSee('Pagó $5 de más')->assertSee('Depósito cubierto');
    }

    public function test_travel_fee_pending_blocks_fixing_amounts(): void
    {
        $this->event->forceFill(['travel_fee_cents' => null])->save();
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('fixAmounts')->assertNotified('No se guardó');
        $this->assertSame(0, FinancialRevision::count());
    }

    public function test_paid_mode_reserves_with_the_recorded_money(): void
    {
        $performer = Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer']]);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('fixAmounts');
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('62.50'))->assertSee('Siguiente paso');

        Livewire::test(ConfirmCapacity::class, ['record' => $this->event->id])->assertSee('Depósito ya registrado')->assertDontSee('Cómo pagó')
            ->fillForm(['units' => ['staff' => [$performer->id]], 'staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Salen y regresan a la bodega ficticia.'])
            ->call('confirm')->assertHasNoFormErrors()->assertNotified('Recursos apartados');
        $this->assertSame('capacity_reserved', $this->event->fresh()->status);
        $this->assertSame(1, DB::table('capacity_payment_links')->where('event_id', $this->event->id)->count());
        $this->assertSame(1, LedgerEntry::count(), 'Booking evidence is not a second payment.');
    }
    public function test_stale_money_page_does_not_fix_unseen_amounts(): void
    {
        $page = Livewire::test(EventMoney::class, ['record' => $this->event->id]);
        // Another editor commits a valid newer draft while this owner is reading the old amounts.
        $this->event->forceFill(['travel_fee_cents' => 7500, 'version' => $this->event->fresh()->version + 1])->save();
        $page->callAction('fixAmounts')->assertNotified('No se guardó');
        $this->assertSame(0, FinancialRevision::count(), 'Never agree to the unseen newer event using fresh()->version.');
    }

    public function test_changed_draft_can_create_a_new_revision_without_rewriting_previous_money(): void
    {
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('fixAmounts');
        $first = FinancialRevision::sole();
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('zelle', $this->zelle('62.50'));
        $this->event->forceFill(['travel_fee_cents' => 7500, 'version' => $this->event->fresh()->version + 1])->save();
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->assertActionVisible('fixAmounts')->callAction('fixAmounts')->assertNotified('Montos fijados');
        $this->assertSame(2, FinancialRevision::count());
        $this->assertSame(12500, $first->fresh()->total_cents);
        $this->assertSame(17500, FinancialRevision::latest('revision')->first()->total_cents);
        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(6250, LedgerEntry::sole()->amount_cents);
    }

}
