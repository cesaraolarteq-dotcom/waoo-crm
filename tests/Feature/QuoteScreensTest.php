<?php
namespace Tests\Feature;

use App\Filament\Pages\{QuoteDetail, Quotes};
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\{Customer, Event, PriceVersion, Quote, ServiceVariant, User};
use App\Services\Quotes\CreateQuote;
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

// Claude's QUOTE-01 A screens over Codex's services: create from the event draft with the version the page showed,
// server totals and visible discounts, revisions with Locked quote+event versions, paginated list; B/C stay disabled.
class QuoteScreensTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private PriceVersion $price;
    private Customer $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_quotes' => true]);
        $this->owner = User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ServiceVariant::create(['name' => 'Mágico (Magic)', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $this->price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 37500, 'currency' => 'USD', 'created_by' => $this->owner->id]);
        $this->client = Customer::create(['name' => 'Claudia Ficticia', 'locale' => 'es']);
    }

    private function event(string $start = '2026-12-19T15:00:00-06:00', int $fee = 4000): Event
    {
        return app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $this->client->id, 'title' => 'Fiesta '.Str::random(4), 'starts_at' => $start,
            'ends_at' => \Carbon\CarbonImmutable::parse($start)->addHour()->toIso8601String(), 'timezone' => 'America/Chicago', 'travel_fee_cents' => $fee,
            'services' => [['price_version_id' => $this->price->id]]]);
    }

    public function test_create_from_event_with_visible_discount_and_server_totals(): void
    {
        $e = $this->event();
        Livewire::test(ViewEvent::class, ['record' => $e->id])->assertActionVisible('quoteCreate')
            ->callAction('quoteCreate', ['locale' => 'es', 'discounts' => [['label' => 'Descuento por reservar hoy', 'amount' => '25', 'reason' => 'Promoción aprobada por César hoy.']]])
            ->assertHasNoActionErrors();
        $q = Quote::sole();
        $this->get(QuoteDetail::getUrl(['quote' => $q->id]))->assertOk()
            ->assertSee('Mágico (Magic)')->assertSee('$375')->assertSee('Costo de transporte')->assertSee('$40')
            ->assertSee('− $25')->assertSee('Descuento por reservar hoy')->assertSee('$390')->assertSee('$195')
            ->assertSee('Enviar · PDF · Convertir (próximamente)');
        Livewire::test(QuoteDetail::class, ['quote' => $q->id])->assertActionDisabled('soon');
        Livewire::test(ViewEvent::class, ['record' => $e->id])->assertActionHidden('quoteCreate')->assertActionVisible('quoteView');
    }

    public function test_revision_uses_displayed_versions_and_explicit_discount_list(): void
    {
        $e = $this->event();
        $rev = app(CreateQuote::class)->execute($this->owner, ['event_id' => $e->id, 'expected_event_version' => $e->version,
            'discounts' => [['label' => 'Promo', 'amount_cents' => 2500, 'reason' => 'Promoción aprobada por César.']]], (string) Str::uuid());
        $page = Livewire::test(QuoteDetail::class, ['quote' => $rev->quote_id]);
        // The event draft changes after the page loaded: the old page is refused, nothing new is written.
        app(SaveEventDraft::class)->execute($this->owner, ['customer_id' => $this->client->id, 'title' => $e->title, 'starts_at' => '2026-12-19T15:00:00-06:00',
            'ends_at' => '2026-12-19T16:00:00-06:00', 'timezone' => 'America/Chicago', 'travel_fee_cents' => 5500, 'services' => [['price_version_id' => $this->price->id]], 'expected_version' => $e->version], $e->id);
        $page->mountAction('revise');
        $this->assertSame(['Promo'], array_column(array_values($page->instance()->mountedActions[0]['data']['discounts']), 'label'), 'The revision starts from the visible discounts.');
        $page->callMountedAction()->assertNotified('No se guardó');
        $this->assertSame(1, Quote::sole()->current_revision);
        // After reloading, the owner removes the discount explicitly; revision 2 takes the new travel fee and no discount.
        Livewire::test(QuoteDetail::class, ['quote' => $rev->quote_id])->callAction('revise', ['discounts' => []])->assertNotified('Revisión 2 guardada');
        $q = Quote::sole(); $cur = $q->revisions()->where('revision', 2)->sole();
        $this->assertSame([5500, 0, 43000, 21500], [$cur->travel_fee_cents, $cur->discount_cents, $cur->total_cents, $cur->deposit_target_cents]);
        $this->get(QuoteDetail::getUrl(['quote' => $q->id]))->assertSee('Historial de versiones')->assertSee('Versión 1');
    }

    public function test_list_paginates_and_filter_resets_the_page(): void
    {
        foreach (range(1, 26) as $n) {
            $e = $this->event('2026-12-'.str_pad((string) (1 + $n % 27), 2, '0', STR_PAD_LEFT).'T1'.($n % 9).':00:00-06:00');
            app(CreateQuote::class)->execute($this->owner, ['event_id' => $e->id, 'expected_event_version' => $e->version], (string) Str::uuid());
        }
        $first = Quote::orderBy('id')->first();
        $page = Livewire::test(Quotes::class)->assertDontSee($first->number)->assertSee('1–25 de 26');
        $page->call('goToPage', 2)->assertSee($first->number)->set('status', 'draft')->assertSet('quotePage', 1);
    }

    public function test_hidden_when_quote_sandbox_is_off(): void
    {
        config(['waoo.sandbox_quotes' => false]);
        $this->get('/admin/cotizaciones')->assertForbidden();
    }
}
