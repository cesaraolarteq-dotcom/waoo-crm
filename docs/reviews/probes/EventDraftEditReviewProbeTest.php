<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\{Customer, PriceVersion, ServiceVariant, User};
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class EventDraftEditReviewProbeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_title_edit_can_keep_the_original_price_after_a_new_catalog_price(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $customer = Customer::create(['name' => 'Fixture client', 'locale' => 'es']);
        $variant = ServiceVariant::create(['name' => 'Fixture service', 'status' => 'active', 'duration_minutes' => 60,
            'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer']]]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 12345,
            'currency' => 'USD', 'created_by' => $owner->id]);
        $event = app(SaveEventDraft::class)->execute($owner, ['customer_id' => $customer->id,
            'title' => 'Fixture draft', 'starts_at' => '2027-06-12T16:00:00-05:00', 'ends_at' => '2027-06-12T17:00:00-05:00',
            'timezone' => 'America/Chicago', 'services' => [['price_version_id' => $price->id]], 'travel_fee_cents' => null]);
        PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 15000,
            'currency' => 'USD', 'created_by' => $owner->id]);
        Livewire::test(EditEvent::class, ['record' => $event->id])->fillForm(['title' => 'Edited fixture title'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Edited fixture title', $event->fresh()->title);
        $this->assertSame([$price->id], $event->services()->pluck('price_version_id')->all());
    }
}
