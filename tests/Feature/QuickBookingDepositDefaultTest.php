<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Models\{PriceVersion, ServiceVariant, User};
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class QuickBookingDepositDefaultTest extends TestCase
{
    use DatabaseMigrations;
    private PriceVersion $price;
    protected function setUp(): void
    {
        parent::setUp();config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true]);
        $owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($owner);Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant=ServiceVariant::create(['name'=>'Celebración musical','status'=>'active','duration_minutes'=>60,
            'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host']]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30001,'currency'=>'USD','created_by'=>$owner->id]);
    }
    public function test_default_uses_integer_ceil_and_never_guesses_a_missing_price_or_transport(): void
    {
        $id=$this->price->id;
        $this->assertSame('150.01',QuickBooking::depositDefault([$id],'0'));
        $this->assertSame('150.02',QuickBooking::depositDefault([$id],'0.02'));
        foreach([null,'',true,'not a fee'] as $fee) $this->assertNull(QuickBooking::depositDefault([$id],$fee));
        $this->assertNull(QuickBooking::depositDefault([],0));
        $this->assertNull(QuickBooking::depositDefault([$id,999999],0));
    }
    public function test_actual_form_recalculates_until_owner_types_the_received_amount(): void
    {
        $page=Livewire::test(QuickBooking::class)->fillForm(['package_id'=>(string)$this->price->id])
            ->assertSchemaStateSet(['deposit.amount'=>null])->fillForm(['travel_fee'=>'0.02'])
            ->assertSchemaStateSet(['deposit.amount'=>'150.02','deposit.amount_custom'=>false])
            ->fillForm(['travel_fee'=>'0.06'])->assertSchemaStateSet(['deposit.amount'=>'150.04'])
            ->fillForm(['deposit.amount'=>'200.00'])->fillForm(['travel_fee'=>'50'])
            ->assertSchemaStateSet(['deposit.amount'=>'200.00','deposit.amount_custom'=>true]);
        $this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_successful_travel_quote_triggers_the_default_without_any_external_google_call(): void
    {
        Http::preventStrayRequests();
        config(['waoo.sandbox_routes'=>true,'waoo.google_routes_key'=>null]);
        Livewire::test(QuickBooking::class)->fillForm(['package_id'=>(string)$this->price->id,
            'address'=>'123 Oak Street, Hockley, TX 77433','postal_code'=>'77433'])
            ->callAction(TestAction::make('quoteTravel')->schemaComponent('travel_fee'))
            ->assertSchemaStateSet(['travel_fee'=>'40','travel_quote_status'=>'ok','deposit.amount'=>'170.01']);
        $this->assertDatabaseCount('ledger_entries',0);
    }
}
