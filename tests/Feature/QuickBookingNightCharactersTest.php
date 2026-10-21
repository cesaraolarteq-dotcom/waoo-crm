<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Models\{Character, Customer, PriceVersion, ServiceVariant, User};
use App\Services\Records\SaveEventDraft;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class QuickBookingNightCharactersTest extends TestCase
{
    use DatabaseMigrations;
    private PriceVersion $price;
    private Character $mickey;
    private Character $minnie;
    protected function setUp(): void
    {
        parent::setUp();
        config(['waoo.sandbox_finance'=>true, 'waoo.sandbox_documents'=>true]);
        $this->actingAs(User::factory()->create(['role'=>'owner_admin', 'active'=>true,
            'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at'=>now()]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->mickey=Character::create(['name'=>'Mickey', 'active'=>true, 'outfits'=>['0','Rojo']]);
        $this->minnie=Character::create(['name'=>'Minnie', 'active'=>true, 'outfits'=>['Rosa']]);
        $this->price=$this->package('Celebración mágica',2);
    }
    private function package(string $name,int $count): PriceVersion
    {
        $variant=ServiceVariant::create(['name'=>$name, 'status'=>'active', 'duration_minutes'=>60, 'requirements'=>[],
            'character_quantity'=>$count, 'character_options'=>collect([$this->mickey,$this->minnie])->map(fn($c)=>[
                'character_id'=>$c->id, 'requirements'=>[['kind'=>'staff','skill'=>'performer','quantity'=>1],
                    ['kind'=>'costume','character_id'=>$c->id,'quantity'=>1]]])->all()]);
        return PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>35000,'currency'=>'USD','created_by'=>auth()->id()]);
    }
    public static function ownerForms(): array { return [[QuickBooking::class,'package_id'], [CreateEvent::class,'price_version_ids']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownerForms')]
    public function test_single_character_service_has_count_first_and_one_picker_per_slot_without_para_or_audience(string $class,string $field): void
    {
        $page=Livewire::test($class)->fillForm([$field=>$field==='package_id'?(string)$this->price->id:[$this->price->id]]);
        $rows=$page->get('data.characters');
        $this->assertCount(2,$rows);
        $this->assertSame(2,$page->get('data.character_count'));
        foreach($rows as $id=>$row) {
            $this->assertSame((string)$this->price->id,$row['price_version_id']);
            $this->assertSame(1,$row['quantity']);
            $page->assertFormFieldIsHidden('characters.'.$id.'.price_version_id');
        }
        $page->assertDontSee('Mostrar');
        $html=$page->html();
        $this->assertLessThan(strpos($html,'Personajes'),strpos($html,'Cuántos'));
        foreach(['start_time','end_time'] as $name) {
            $control=$page->instance()->form->getFlatFields()[$name];
            $this->assertFalse($control->isNative());
            $this->assertSame(15,$control->getMinutesStep());
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownerForms')]
    public function test_para_is_only_requested_when_more_than_one_selected_service_takes_characters(string $class,string $field): void
    {
        $second=$this->package('Personaje adicional',1);
        $page=Livewire::test($class)->fillForm([$field=>$field==='package_id'?(string)$this->price->id:[$this->price->id]]);
        $page->fillForm($field==='package_id'?['extra_ids'=>[(string)$second->id]]:['price_version_ids'=>[$this->price->id,$second->id]]);
        $rows=$page->get('data.characters');
        $this->assertCount(3,$rows);
        foreach(array_keys($rows) as $id) $page->assertFormFieldIsVisible('characters.'.$id.'.price_version_id');
    }

    public function test_count_resize_keeps_chosen_rows_and_wardrobe_zero_without_leaking_it_into_title(): void
    {
        $page=Livewire::test(QuickBooking::class)->fillForm(['package_id'=>(string)$this->price->id]);
        $rows=$page->get('data.characters');$id=array_key_first($rows);
        $rows[$id]=[...$rows[$id], 'character_id'=>(string)$this->mickey->id];
        $page->fillForm(['characters'=>$rows]);
        $rows=$page->get('data.characters');$rows[$id]['outfit']='0';$rows[$id]['note']='Llevar el vestuario acordado';
        $page->fillForm(['characters'=>$rows])->fillForm(['character_count'=>1]);
        $remaining=$page->get('data.characters');
        $this->assertCount(1,$remaining);
        $this->assertSame('0',$remaining[$id]['outfit']);
        $this->assertSame('Llevar el vestuario acordado',$remaining[$id]['note']);
        $title=EventResource::suggestTitle([$this->price->id],array_values($remaining),null,'María González');
        $this->assertSame('Celebración mágica Mickey María González',$title);
        $input=EventResource::servicesInput(['price_version_ids'=>[$this->price->id],'characters'=>$remaining]);
        $this->assertSame('0',$input[0]['characters'][0]['outfit']);
    }

    public function test_stored_repeated_character_expands_and_round_trips_without_recipe_duplicate_or_note_loss(): void
    {
        $customer=Customer::create(['name'=>'María González','locale'=>'es']);
        $event=app(SaveEventDraft::class)->execute(auth()->user(),['customer_id'=>$customer->id,'title'=>'Nombre acordado',
            'starts_at'=>'2026-12-19T15:07:00-06:00','ends_at'=>'2026-12-19T16:07:00-06:00','timezone'=>'America/Chicago',
            'travel_fee_cents'=>0,'services'=>[['price_version_id'=>$this->price->id,'characters'=>[
                ['character_id'=>$this->mickey->id,'quantity'=>2,'outfit'=>'0','note'=>'Vestuario original']]]]]);
        $form=EventResource::toFormState($event);
        $this->assertSame(2,$form['character_count']);$this->assertCount(2,$form['characters']);
        $this->assertSame([1,1],array_column($form['characters'],'quantity'));
        $input=EventResource::servicesInput($form);
        $this->assertCount(1,$input[0]['characters']);
        $this->assertSame(['character_id'=>$this->mickey->id,'quantity'=>2,'outfit'=>'0','note'=>'Vestuario original'],$input[0]['characters'][0]);
        $saved=app(SaveEventDraft::class)->execute(auth()->user(),[...EventResource::toServiceInput($form),'expected_version'=>$event->version],$event->id);
        $this->assertSame('Nombre acordado',$saved->title);
        $this->assertSame('15:07',$saved->starts_at->setTimezone('America/Chicago')->format('H:i'));
        $this->assertSame(2,$saved->services->first()->characters->sole()->quantity);
        Livewire::test(\App\Filament\Resources\Events\Pages\EditEvent::class,['record'=>$saved->id])
            ->assertSchemaStateSet(['start_time'=>'15:07','end_time'=>'16:07'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('15:07',$saved->fresh()->starts_at->setTimezone('America/Chicago')->format('H:i'));
    }

    public function test_blank_pick_slots_do_not_invent_a_character_or_inventory_reservation(): void
    {
        $input=EventResource::servicesInput(['price_version_ids'=>[$this->price->id], 'characters'=>[
            ['price_version_id'=>$this->price->id,'character_id'=>null,'quantity'=>1,'outfit'=>null]]]);
        $this->assertSame([],$input[0]['characters']);
        $this->assertDatabaseCount('resource_reservations',0);
    }

    public function test_equipment_only_extra_does_not_add_a_character_or_force_para(): void
    {
        $variant=ServiceVariant::create(['name'=>'Máquina de burbujas','status'=>'active','duration_minutes'=>60,
            'requirements'=>[['kind'=>'equipment','equipment_kind'=>'bubbles','quantity'=>1]],'character_quantity'=>0]);
        $extra=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>5000,'currency'=>'USD','created_by'=>auth()->id()]);
        $page=Livewire::test(QuickBooking::class)->fillForm(['package_id'=>(string)$this->price->id,'extra_ids'=>[(string)$extra->id]]);
        $rows=$page->get('data.characters');$this->assertCount(2,$rows);
        foreach(array_keys($rows) as $id) $page->assertFormFieldIsHidden('characters.'.$id.'.price_version_id');
    }
}
