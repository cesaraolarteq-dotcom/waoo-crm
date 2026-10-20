<?php
namespace Tests\Feature;

use App\Filament\Pages\QuickBooking as QuickBookingPage;
use App\Models\{Customer, PriceVersion, ServiceVariant, User};
use App\Services\Booking\{QuickBook, SaveQuickBookingCustomer};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QuickBookingProvisionalCustomerTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private PriceVersion $price;
    protected function setUp(): void
    {
        parent::setUp();config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true]);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant=ServiceVariant::create(['name'=>'Celebración musical','status'=>'active','duration_minutes'=>60,
            'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host']]]);
        $this->price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30000,'currency'=>'USD','created_by'=>$this->owner->id]);
    }
    protected function tearDown(): void
    {
        // Synthetic fixture cleanup only: production down() must refuse to invent a missing real name.
        if(Schema::hasTable('customers')) DB::table('customers')->whereNull('name')->update(['name'=>'María González']);
        parent::tearDown();
    }
    private function input(array $customer): array
    {
        return ['new_customer'=>['locale'=>'es',...$customer],'title'=>'Celebración musical','event_type'=>'birthday',
            'starts_at'=>'2026-12-19T15:00:00-06:00','ends_at'=>'2026-12-19T16:00:00-06:00','timezone'=>'America/Chicago',
            'postal_code'=>'77493','travel_fee_cents'=>0,'services'=>[['price_version_id'=>$this->price->id]],
            'deposit'=>['method'=>'zelle','amount_cents'=>15000,'reference'=>'SYNTHETIC-'.Str::uuid(),
                'received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true]];
    }
    public static function contacts(): array { return [[['phone'=>'346-555-0100']], [['email'=>'maria@example.test']]]; }
    #[\PHPUnit\Framework\Attributes\DataProvider('contacts')]
    public function test_real_quick_book_can_create_without_a_name_and_exact_retry_preserves_one_record(array $contact): void
    {
        $input=$this->input($contact);$key=(string)Str::uuid();
        $result=app(QuickBook::class)->execute($this->owner,$input,$key);
        $this->assertNull($result->customer->name);
        $this->assertSame(1,$result->customer->version);
        $this->assertSame($result->customer->id,$result->event->customer_id);
        $again=app(QuickBook::class)->execute($this->owner,$input,$key);
        $this->assertSame($result->id,$again->id);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('ledger_entries',1);
        $this->assertDatabaseCount('quick_bookings',1);
    }

    public function test_neither_contact_rolls_back_the_entire_booking_and_global_save_still_requires_a_name(): void
    {
        try { app(QuickBook::class)->execute($this->owner,$this->input(['name'=>null]),(string)Str::uuid());$this->fail('No contact accepted'); }
        catch(ValidationException $e) { $this->assertArrayHasKey('new_customer.phone',$e->errors()); }
        $this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('events',0);$this->assertDatabaseCount('ledger_entries',0);
        $this->expectException(ValidationException::class);
        app(\App\Services\Records\SaveCustomer::class)->execute($this->owner,['name'=>null,'email'=>'maria@example.test','locale'=>'es']);
    }

    public function test_provisional_contact_update_is_versioned_and_cannot_clear_a_known_name(): void
    {
        $service=app(SaveQuickBookingCustomer::class);
        $customer=$service->execute($this->owner,['phone'=>'346-555-0100','locale'=>'es']);
        $updated=$service->execute($this->owner,['expected_version'=>1,'name'=>null,'email'=>'maria@example.test','locale'=>'es'],$customer->id);
        $this->assertSame(2,$updated->version);$this->assertNull($updated->name);$this->assertSame('346-555-0100',$updated->phone);
        try {$service->execute($this->owner,['expected_version'=>1,'name'=>null,'locale'=>'es'],$customer->id);$this->fail('Stale version accepted');}
        catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $named=$service->execute($this->owner,['expected_version'=>2,'name'=>'María González','locale'=>'es'],$customer->id);
        $this->assertSame('María González',$named->name);
        try {$service->execute($this->owner,['expected_version'=>3,'name'=>null,'phone'=>'346-555-0100','locale'=>'es'],$customer->id);$this->fail('Known name cleared');}
        catch(ValidationException){$this->assertSame('María González',$named->fresh()->name);}
    }

    public function test_actual_one_field_search_returns_full_contact_then_selects_with_locked_versions(): void
    {
        $customer=Customer::create(['name'=>'María González','phone'=>'346-555-0100','email'=>'maria@example.test','locale'=>'es']);
        $page=Livewire::test(QuickBookingPage::class)->assertSee('Escribe nombre, teléfono o correo')->assertDontSee('Buscar clienta registrada');
        $field=$page->instance()->form->getFlatFields()['client_lookup'];
        $page->call('callSchemaComponentMethod',$field->getKey(),'getSearchResultsForJs',['search'=>'maria@example.test'])
            ->assertSet('clientSearch','maria@example.test')->fillForm(['client_lookup'=>'customer:'.$customer->id])
            ->assertSet('displayedCustomerId',$customer->id)->assertSet('displayedCustomerVersion',1)
            ->assertSchemaStateSet(['customer_id'=>$customer->id,'customer_mode'=>'existing','c_name'=>'María González']);
        $label=QuickBookingPage::clientLookupLabel('customer:'.$customer->id);
        $this->assertStringContainsString('346-555-0100 · maria@example.test · 0 eventos',$label);
        $this->assertArrayHasKey($customer->id,QuickBookingPage::possibleMatches('Maria Gonzalez',null,null));
        $this->assertArrayHasKey($customer->id,QuickBookingPage::possibleMatches(null,'3465',null));
    }

    public function test_actual_lookup_can_explicitly_create_from_phone_or_email_without_inventing_a_name(): void
    {
        foreach(['346-555-0100'=>'phone','maria@example.test'=>'email'] as $search=>$contact) {
            $page=Livewire::test(QuickBookingPage::class);
            $field=$page->instance()->form->getFlatFields()['client_lookup'];
            $page->call('callSchemaComponentMethod',$field->getKey(),'getSearchResultsForJs',['search'=>$search])
                ->fillForm(['client_lookup'=>'new'])->assertSchemaStateSet(['customer_mode'=>'new','name'=>null,$contact=>$search]);
            $this->assertNull($page->get('displayedCustomerId'));
        }
        $this->assertDatabaseCount('customers',0);
    }

    public function test_migration_rollback_refuses_to_fabricate_names_for_existing_provisional_records(): void
    {
        $customer=app(SaveQuickBookingCustomer::class)->execute($this->owner,['phone'=>'346-555-0100','locale'=>'es']);
        $migration=require database_path('migrations/2026_10_20_540000_allow_provisional_customer_names.php');
        try {$migration->down();$this->fail('Rollback fabricated name');}catch(\LogicException){$this->assertNull($customer->fresh()->name);}
    }

    public function test_phone_only_customer_can_be_saved_through_the_real_single_lookup_form(): void
    {
        $page=Livewire::test(QuickBookingPage::class);
        $field=$page->instance()->form->getFlatFields()['client_lookup'];
        $page->call('callSchemaComponentMethod',$field->getKey(),'getSearchResultsForJs',['search'=>'346-555-0100'])
            ->fillForm(['client_lookup'=>'new','package_id'=>(string)$this->price->id,'date'=>'2026-12-19',
                'start_time'=>'15:00','end_time'=>'16:00','event_type'=>'birthday','postal_code'=>'77493','travel_fee'=>'0',
                'deposit'=>['amount'=>'150','reference'=>'SYNTHETIC-PHONE-ONLY','verified'=>true]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull(Customer::sole()->name);
        $this->assertDatabaseCount('events',1);$this->assertDatabaseCount('ledger_entries',1);
        $this->assertSame(15000,\App\Models\LedgerEntry::sole()->amount_cents);
    }

    public function test_audit_failure_does_not_leave_an_untracked_provisional_customer_or_partial_booking(): void
    {
        DB::listen(function($query) {
            if(str_starts_with(strtolower($query->sql),'insert into ') && str_contains(strtolower($query->sql),'audit_entries')
                && in_array('customers.created',$query->bindings,true)) throw new \RuntimeException('Synthetic customer audit failure');
        });
        try {app(QuickBook::class)->execute($this->owner,$this->input(['phone'=>'346-555-0100']),(string)Str::uuid());$this->fail('Partial customer committed');}
        catch(\RuntimeException $e){$this->assertSame('Synthetic customer audit failure',$e->getMessage());}
        $this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('events',0);
        $this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('quick_bookings',0);
    }
}
