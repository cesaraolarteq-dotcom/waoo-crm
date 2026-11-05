<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\{Customer, Event, Quote};
use App\Services\ConfirmBooking;
use App\Services\Quotes\{DecideQuote, ReadQuoteViewModel};
use App\Services\Records\SaveEventDraft;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Owner 2026-11-04: «Nueva cotización» creates a new client on the spot and can go out before the start time is known.
class QuoteWithoutTimeTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_new_quote_without_time_uses_a_noon_placeholder_for_the_package_length(): void
    {
        $client = Customer::create(['name' => 'Gabriela Torres', 'locale' => 'es']);
        Livewire::test(CreateEvent::class, ['cotizar' => 1])
            ->fillForm(['customer_id' => $client->id, 'price_version_ids' => [$this->price->id], 'date' => now()->addDays(30)->format('Y-m-d'), 'time_tbd' => true])
            ->assertFormFieldHidden('start_time')
            ->call('create')->assertHasNoFormErrors();
        $event = Event::where('customer_id', $client->id)->sole();
        $this->assertTrue($event->time_tbd);
        $this->assertSame('12:00', $event->starts_at->setTimezone('America/Chicago')->format('H:i'));
        $this->assertSame('13:00', $event->ends_at->setTimezone('America/Chicago')->format('H:i'));
    }

    public function test_a_new_client_is_created_from_the_client_field(): void
    {
        Livewire::test(CreateEvent::class, ['cotizar' => 1])
            ->callAction(TestAction::make('createOption')->schemaComponent('customer_id'), ['name' => 'Valeria Ramírez', 'phone' => '346-555-0142', 'email' => '', 'locale' => 'es'])
            ->assertHasNoFormErrors();
        $client = Customer::where('name', 'Valeria Ramírez')->sole();
        $this->assertSame('346-555-0142', $client->phone);
        $this->assertNull($client->email);
    }

    public function test_client_search_ignores_accents(): void
    {
        Customer::create(['name' => 'César Olarte', 'locale' => 'es']);
        $this->assertContains('César Olarte', array_map(fn ($l) => explode(' · ', $l)[0], \App\Filament\Pages\QuickBooking::possibleMatches('cesar olarte', 'cesar olarte', 'cesar olarte')));
    }

    public function test_quote_without_time_says_so_and_cannot_be_accepted_or_confirmed(): void
    {
        $event = app(SaveEventDraft::class)->execute($this->owner, [...\App\Filament\Resources\Events\EventResource::toServiceInput([
            ...\App\Filament\Resources\Events\EventResource::toFormState($this->event), 'time_tbd' => true]), 'expected_version' => $this->event->version], $this->event->id);
        $this->assertTrue($event->time_tbd);
        $q = Quote::findOrFail($this->offer->quote_id);
        $this->offer = app(\App\Services\Quotes\ReviseQuote::class)->execute($this->owner, $q->id, ['expected_version' => $q->version, 'expected_event_version' => $event->version, 'discounts' => []], (string) Str::uuid());

        $prepared = $this->prepare();
        $vm = app(ReadQuoteViewModel::class)->execute($this->client($prepared['portal_grant_id']), 'es');
        $this->assertStringContainsString('Hora por definir', $vm['quote']['when']);
        $this->assertNull($vm['actions']['accept']);

        $this->viewPdf($prepared);
        try {
            app(DecideQuote::class)->execute($this->client($prepared['portal_grant_id']), $this->decisionInput($prepared), (string) Str::uuid());
            $this->fail('A quote without time was accepted.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Falta confirmar la hora', $e->getMessage());
        }

        try {
            app(ConfirmBooking::class)->execute($event->id, ['resource_ids' => [], 'staff_later' => true], $this->owner);
            $this->fail('A party without time was confirmed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Falta la hora', $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage());
        }
    }
}
