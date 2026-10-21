<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, LedgerEntry, PriceVersion, ServiceVariant, User};
use App\Services\Booking\QuickBook;
use App\Services\Finance\{RecordZellePayment, ZelleReceivedDate};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;

class ZelleOptionalReferenceTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private array $input;
    protected function setUp(): void
    {
        parent::setUp(); config(['waoo.sandbox_finance'=>true,'waoo.sandbox_documents'=>true]);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($this->owner);
        $variant=ServiceVariant::create(['name'=>'Celebración musical','status'=>'active','duration_minutes'=>60,'requirements'=>[['kind'=>'staff','quantity'=>1,'skill'=>'host']]]);
        $price=PriceVersion::create(['service_variant_id'=>$variant->id,'amount_cents'=>30000,'currency'=>'USD','created_by'=>$this->owner->id]);
        $this->input=['new_customer'=>['name'=>'María González','phone'=>'346-555-0100','email'=>'maria@example.test','locale'=>'es'],
            'title'=>'Celebración de María','event_type'=>'birthday','starts_at'=>'2026-12-19T15:00:00-06:00','ends_at'=>'2026-12-19T16:00:00-06:00',
            'timezone'=>'America/Chicago','postal_code'=>'77493','travel_fee_cents'=>0,'services'=>[['price_version_id'=>$price->id]],
            'deposit'=>['method'=>'zelle','amount_cents'=>15000,'received_on'=>now('America/Chicago')->format('Y-m-d'),'verified_received'=>true]];
    }
    protected function tearDown(): void
    {
        // Only synthetic fixture cleanup for the intentionally guarded schema rollback.
        DB::table('ledger_entries')->whereNull('external_reference')->update(['external_reference'=>'SYNTHETIC-ROLLBACK-ONLY']);
        parent::tearDown();
    }
    private function book(?array $input=null, ?string $key=null) {return app(QuickBook::class)->execute($this->owner,$input??$this->input,$key??(string)Str::uuid());}


    public function test_day_based_retry_survives_moving_clock_without_a_second_receipt(): void
    {
        $this->travelTo(now()->startOfSecond()); $key=(string)Str::uuid();$result=$this->book(key:$key);
        $timestamp=LedgerEntry::sole()->received_at->toIso8601String();
        $this->travel(10)->minutes();$retry=$this->book(key:$key);
        $this->assertSame($result->id,$retry->id);$this->assertSame($timestamp,LedgerEntry::sole()->received_at->toIso8601String());
        $this->assertNull(LedgerEntry::sole()->external_reference);$this->assertDatabaseCount('quick_bookings',1);
    }
    public function test_new_uuid_similar_booking_is_rejected_without_leaving_another_customer_event_or_payment(): void
    {
        $this->book();$input=$this->input;$input['new_customer']['phone']='+1 (346) 555-0100';$input['new_customer']['email']=null;
        try{$this->book($input);$this->fail('Duplicate accepted');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_distinct_real_reference_can_distinguish_a_genuine_additional_payment(): void
    {
        $result=$this->book();$service=app(RecordZellePayment::class);
        $payment=['financial_revision_id'=>$result->financial_revision->id,'amount_cents'=>15000,'currency'=>'USD','received_on'=>$this->input['deposit']['received_on'],'verified_received'=>true];
        try{$service->execute($this->owner,$result->event->id,$payment,(string)Str::uuid());$this->fail('Duplicate accepted');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $entry=$service->execute($this->owner,$result->event->id,[...$payment,'reference'=>'MARIA-BANK-SECOND'],(string)Str::uuid());
        $this->assertSame('MARIA-BANK-SECOND',$entry->external_reference);$this->assertDatabaseCount('ledger_entries',2);
    }
    public function test_another_day_or_event_is_not_a_duplicate(): void
    {
        $this->book();$input=$this->input;$input['deposit']['received_on']=now('America/Chicago')->subDay()->format('Y-m-d');$this->book($input);
        $input=$this->input;$input['starts_at']='2026-12-20T15:00:00-06:00';$input['ends_at']='2026-12-20T16:00:00-06:00';$this->book($input);
        $this->assertDatabaseCount('ledger_entries',3);
    }
    public function test_future_day_or_both_timestamp_and_day_are_rejected(): void
    {
        foreach([['received_on'=>now('America/Chicago')->addDay()->format('Y-m-d')],['received_at'=>now()->subHour()->toIso8601String()]] as $extra){
            $input=$this->input;$input['deposit']=[...$input['deposit'],...$extra];
            try{$this->book($input);$this->fail('Invalid date accepted');}catch(ValidationException){$this->assertDatabaseCount('ledger_entries',0);}
        }
    }
    public function test_houston_day_bounds_follow_dst_and_past_day_uses_noon(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-01T18:00:00Z'));
        [$start,$end]=ZelleReceivedDate::bounds(['received_on'=>'2026-11-01']);
        $this->assertSame(25,(int)$start->diffInHours($end));
        $this->assertSame('2026-11-01T18:00:00+00:00',ZelleReceivedDate::timestamp(['received_on'=>'2026-11-01'])->toIso8601String());
        [$start,$end]=ZelleReceivedDate::bounds(['received_on'=>'2026-03-08']);$this->assertSame(23,(int)$start->diffInHours($end));
    }
    public function test_two_owners_cannot_create_the_same_unreferenced_booking_concurrently(): void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required.');
        $other=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $input=$this->input;$workers=[];
        foreach([$this->owner->id,$other->id] as $id)$workers[]=function()use($id,$input){
            try {app(QuickBook::class)->execute(User::findOrFail($id),$input,(string)Str::uuid());return 'created';}
            catch(RecordWriteRejected $e){return $e->status;}
        };
        $results=ConcurrentWorkers::run($workers);sort($results);$this->assertEquals([409,'created'],$results);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('ledger_entries',1);
    }
    public function test_real_owner_form_has_optional_reference_and_no_deposit_clock_and_records_one_payment(): void
    {
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $page=\Livewire\Livewire::test(\App\Filament\Pages\QuickBooking::class)
            ->assertSee('Referencia de Zelle (opcional)')->assertDontSee('Hora (opcional)');
        $fields=$page->instance()->form->getFlatFields();
        $this->assertArrayNotHasKey('deposit.time',$fields);$this->assertArrayNotHasKey('deposit.choice',$fields);
        $page->fillForm(['customer_mode'=>'new','name'=>'María González','phone'=>'346-555-0100','locale'=>'es',
            'package_id'=>(string)$this->input['services'][0]['price_version_id'],'date'=>'2026-12-19','start_time'=>'15:00','end_time'=>'16:00',
            'event_type'=>'birthday','postal_code'=>'77493','travel_fee'=>'0','deposit'=>['amount'=>'150','reference'=>'','verified'=>true]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseCount('ledger_entries',1);$this->assertNull(LedgerEntry::sole()->external_reference);
    }
    public function test_blank_and_missing_reference_are_the_same_retry_intent_and_audit_failure_rolls_back(): void
    {
        $key=(string)Str::uuid();$first=$this->book(key:$key);$input=$this->input;$input['deposit']['reference']='  ';
        $this->assertSame($first->id,$this->book($input,$key)->id);
        DB::listen(function($query){
            if(str_starts_with(strtolower($query->sql),'insert into ') && str_contains(strtolower($query->sql),'audit_entries')
                && in_array('payment.zelle_received',$query->bindings,true)) throw new \RuntimeException('Synthetic receipt audit failure');
        });
        $input=$this->input;$input['starts_at']='2026-12-20T15:00:00-06:00';$input['ends_at']='2026-12-20T16:00:00-06:00';
        try{$this->book($input);$this->fail('Partial receipt committed');}catch(\RuntimeException $e){$this->assertSame('Synthetic receipt audit failure',$e->getMessage());}
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('ledger_entries',1);
    }

}
