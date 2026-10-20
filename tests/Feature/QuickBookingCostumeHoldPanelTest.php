<?php
namespace Tests\Feature;

use App\Livewire\QuickCostumeHoldPanel;
use App\Models\{Character, LedgerEntry, PriceVersion, QuickCostumeHold, Resource, ServiceVariant, User};
use App\Services\Booking\{QuickBook, ReadQuickBookingCostumeHold, ReleaseQuickBookingCostumes};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class QuickBookingCostumeHoldPanelTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private object $booking;
    protected function setUp():void
    {
        parent::setUp();config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true]);
        $this->owner=User::factory()->create(['name'=>'María González','role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $character=Character::create(['name'=>'Mickey','active'=>true,'outfits'=>['0','Clásico']]);
        Resource::create(['code'=>'MICKEY-1','name'=>'Cabeza de Mickey','kind'=>'costume','status'=>'active','character_id'=>$character->id,'skills'=>[]]);
        $variant=ServiceVariant::create(['name'=>'Música y juegos','status'=>'active','duration_minutes'=>120,'character_quantity'=>1,'requirements'=>[],
            'character_options'=>[['character_id'=>$character->id,'requirements'=>[['kind'=>'costume','character_id'=>$character->id,'quantity'=>1],['kind'=>'staff','quantity'=>1,'skill'=>'performer']]]]]);
        $price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30000,'currency'=>'USD','created_by'=>$this->owner->id]);
        $start=now('America/Chicago')->addDays(20)->setTime(12,0)->utc();
        $this->booking=app(QuickBook::class)->execute($this->owner,[
            'new_customer'=>['name'=>'Elena Martínez','phone'=>'346-555-0101','locale'=>'es'],'title'=>'Cumpleaños de Lucía','event_type'=>'birthday',
            'starts_at'=>$start->toIso8601String(),'ends_at'=>$start->copy()->addHours(2)->toIso8601String(),'timezone'=>'America/Chicago',
            'postal_code'=>'77493','travel_fee_cents'=>0,'setup_minutes'=>null,'breakdown_minutes'=>null,
            'services'=>[['price_version_id'=>$price->id,'characters'=>[['character_id'=>$character->id,'quantity'=>1,'outfit'=>'0']]]],
            'deposit'=>['method'=>'zelle','amount_cents'=>15000,'reference'=>'ELENA-BANK-RECEIPT','received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],
        ],(string)Str::uuid());
    }
    private function panel(){return Livewire::test(QuickCostumeHoldPanel::class,['eventId'=>$this->booking->event->id]);}
    public function test_real_read_shows_wardrobe_zero_and_timing_warning_without_reholding_on_refresh():void
    {
        $this->panel()->assertSee('Apartado por revisar')->assertSee('Vestuario: 0')->assertSee('Faltan minutos de montaje o desmontaje')
            ->assertSee('Personal, equipo y traslados se confirman por separado')->call('reviewLatest')->assertSet('hold.version',1);
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);$this->assertDatabaseCount('booking_evidence',0);
    }
    public function test_release_requires_owner_reason_preserves_money_and_can_be_explicitly_attempted_again():void
    {
        $panel=$this->panel()->call('releaseHold')->assertHasErrors(['reason'=>'required']);
        $this->assertDatabaseCount('resource_reservations',1);
        $panel->set('reason','La dueña revisó otra opción para el personaje.')->call('releaseHold')->assertHasNoErrors()->assertSet('hold.state','released');
        $this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('ledger_entries',1);$this->assertSame(15000,LedgerEntry::sole()->amount_cents);
        $panel->call('reviewLatest')->assertSet('hold.state','released');$this->assertDatabaseCount('resource_reservations',0);
        $panel->call('attempt')->assertSet('hold.state','review_required')->assertSee('Vestuario: 0');
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_stale_release_does_not_silently_pick_up_new_versions():void
    {
        $panel=$this->panel();$id=$this->booking->event->id;$read=app(ReadQuickBookingCostumeHold::class)->execute($this->owner,$id);
        app(ReleaseQuickBookingCostumes::class)->execute($this->owner,$id,['expected_event_version'=>$read['event_version'],'expected_hold_version'=>$read['version'],
            'reason'=>'Maybeli revisó y liberó los trajes desde otra pantalla.'],(string)Str::uuid());
        $panel->set('reason','César revisó el apartado mostrado antes de liberar.')->call('releaseHold')
            ->assertSet('hold.version',1)->assertSee('Actualiza y revisa antes de intentarlo de nuevo.');
        $this->assertSame(2,QuickCostumeHold::sole()->version);$this->assertDatabaseCount('ledger_entries',1);
        $panel->call('reviewLatest')->assertSet('hold.version',2)->assertSet('hold.state','released')->assertSet('releaseInput',null);
    }
    public function test_displayed_versions_cannot_be_changed_by_the_browser():void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $this->panel()->set('hold.version',0);
    }
    public static function denied():array{return [['staff'],['unenrolled'],['anonymous'],['off']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('denied')]
    public function test_owner_authentication_and_flags_are_required_for_component_reads(string $kind):void
    {
        if($kind==='anonymous')auth()->logout();
        elseif($kind==='off')config(['waoo.sandbox_finance'=>false]);
        else $this->actingAs(User::factory()->create(['role'=>$kind==='staff'?'staff':'owner_admin','active'=>true]));
        $this->panel()->assertForbidden();$this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_release_storage_failure_does_not_remove_inventory_or_expose_internal_errors():void
    {
        $panel=$this->panel();
        DB::listen(function($query){if(str_starts_with(strtolower($query->sql),'insert into ')&&str_contains($query->sql,'quick_costume_hold_events'))throw new \RuntimeException('SYNTHETIC_PRIVATE_STORAGE_DETAIL');});
        $panel->set('reason','La dueña revisó el cambio antes de liberar los trajes.')->call('releaseHold')
            ->assertSee('No se pudo comprobar la liberación')->assertDontSee('SYNTHETIC_PRIVATE_STORAGE_DETAIL');
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);$this->assertSame(1,QuickCostumeHold::sole()->version);
    }
    public function test_event_file_and_saved_quick_booking_embed_the_same_read_only_hold_panel():void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->get('/admin/eventos/'.$this->booking->event->id)->assertOk()->assertSee('Apartado por revisar')->assertSee('Vestuario: 0')
            ->assertHeader('Cache-Control','no-store, private');
        $result=\App\Filament\Pages\QuickBooking::presentResult($this->booking,[]);
        Livewire::test(\App\Filament\Pages\QuickBooking::class)->set('result',$result)->assertSee('Apartado por revisar')->assertSee('Vestuario: 0');
        $this->assertDatabaseCount('resource_reservations',1);$this->assertDatabaseCount('ledger_entries',1);$this->assertSame(1,QuickCostumeHold::sole()->version);
    }

}
