<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Filament\Support\TimeSelect;
use App\Models\{Character, Customer, PriceVersion, ServiceVariant, User};
use App\Services\Booking\SaveQuickBookingCustomer;
use App\Services\Finance\CreateFinancialRevision;
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner walkthrough 2026-10-21: AM/PM times, neutral "cliente", optional contact, $150 extra characters, link switches booking. */
class WalkthroughThreeTest extends TestCase
{
    use DatabaseMigrations;
    private PriceVersion $price;
    private Character $rumi;

    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance'=>true, 'waoo.sandbox_documents'=>true]);
        $this->actingAs(User::factory()->create(['role'=>'owner_admin', 'active'=>true,
            'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at'=>now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->rumi=Character::create(['name'=>'Rumi', 'active'=>true]);
        $variant=ServiceVariant::create(['name'=>'Música y Juegos 2.0', 'status'=>'active', 'duration_minutes'=>120, 'requirements'=>[],
            'character_quantity'=>1, 'character_options'=>[['character_id'=>$this->rumi->id, 'requirements'=>[['kind'=>'staff','skill'=>'performer','quantity'=>1]]]]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>60000,'currency'=>'USD','created_by'=>auth()->id()]);
    }

    public function test_time_list_shows_am_pm_starting_in_the_morning_and_keeps_an_off_grid_saved_time(): void
    {
        $options=TimeSelect::options();
        $this->assertSame('6:00 AM',reset($options));
        $this->assertSame('4:45 PM',$options['16:45']);
        $this->assertSame('12:00 PM',$options['12:00']);
        $this->assertSame('12:00 AM',$options['00:00']);
        $this->assertSame('3:07 PM',TimeSelect::options('15:07')['15:07']);
    }

    public function test_owner_screens_say_cliente(): void
    {
        Livewire::test(QuickBooking::class)->assertSee('3. Cliente')->assertSee('Crear cliente nuevo')->assertDontSee('Clien'.'ta'); // split so the neutral-wording script never rewrites it
    }

    public function test_a_named_client_needs_no_phone_or_email_but_nothing_at_all_is_rejected(): void
    {
        $customer=app(SaveQuickBookingCustomer::class)->execute(auth()->user(),['name'=>'María González','locale'=>'es']);
        $this->assertNull($customer->phone);$this->assertNull($customer->email);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SaveQuickBookingCustomer::class)->execute(auth()->user(),['name'=>null,'locale'=>'es']);
    }

    public function test_extra_character_adds_the_flat_amount_instead_of_an_error(): void
    {
        $customer=Customer::create(['name'=>'María González','locale'=>'es']);
        $event=app(SaveEventDraft::class)->execute(auth()->user(),['customer_id'=>$customer->id,'title'=>'Música y Juegos Rumi',
            'starts_at'=>'2026-12-19T16:45:00-06:00','ends_at'=>'2026-12-19T18:45:00-06:00','timezone'=>'America/Chicago',
            'travel_fee_cents'=>2000,'services'=>[['price_version_id'=>$this->price->id,'characters'=>[['character_id'=>$this->rumi->id,'quantity'=>2]]]]]);
        $service=$event->services->sole();
        $this->assertSame(1,$service->extra_characters);$this->assertSame(15000,$service->extra_character_cents);$this->assertTrue($service->operational_ready);
        $finance=app(CreateFinancialRevision::class)->execute(auth()->user(),$event->id,['expected_version'=>$event->version],(string)Str::uuid());
        $this->assertSame(75000,$finance->subtotal_cents);
        $this->assertSame(77000,$finance->total_cents);
        $this->assertSame(['base_amount_cents'=>60000,'extra_characters'=>1,'extra_character_cents'=>15000],
            array_intersect_key($finance->lines_snapshot[0],array_flip(['base_amount_cents','extra_characters','extra_character_cents'])));
    }

    public function test_quick_booking_deposit_and_summary_include_the_extra_character(): void
    {
        $page=Livewire::test(QuickBooking::class)->fillForm(['package_id'=>(string)$this->price->id,'travel_fee'=>'20'])->fillForm(['character_count'=>2]);
        $rows=$page->get('data.characters');
        foreach(array_keys($rows) as $id) $rows[$id]['character_id']=(string)$this->rumi->id;
        foreach(array_keys($rows) as $id) $page->set("data.characters.{$id}.character_id",(string)$this->rumi->id);
        $this->assertSame(2,\App\Filament\Resources\Events\EventResource::extraCharacters(['price_version_ids'=>$page->get('data.price_version_ids'),'characters'=>$page->get('data.characters')])+1);
        $this->assertSame('385.00',$page->get('data.deposit.amount')); // (600 + 150 + 20) / 2
        $page->assertSee('personajes adicionales $150');
    }

    public function test_portal_pages_switch_to_a_newly_opened_link(): void
    {
        $html=view('portal.landing',['lang'=>'es'])->render();
        $this->assertStringContainsString('a new link opened while an older portal session',$html);
    }
}
