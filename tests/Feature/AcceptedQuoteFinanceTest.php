<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Contract, Event, FinancialRevision, LedgerEntry, PriceVersion, Quote, QuoteConversion, QuoteDecision, Resource, ServiceVariant};
use App\Services\Documents\{ActivateTemplateVersion, ArtifactStore, CreateContract, CreateTemplateVersion, IssueContract};
use App\Services\Finance\{ConfirmPaidCapacity, CreateCheckoutIntent, CreateFinancialRevision, FinanceSummary, RecordZellePayment, StripeGateway};
use App\Services\Quotes\{ConvertAcceptedQuote, ReviseQuote};
use App\Services\Records\{ConfirmCapacity, SaveEventDraft};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers, FakeStripeGateway, QuoteScenario};
use Tests\TestCase;

class AcceptedQuoteFinanceTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;
    protected function setUp():void {parent::setUp();$this->scenario();}
    private function conversionInput(QuoteDecision $d):array
    {
        $q=Quote::findOrFail($d->quote_id);
        return ['expected_version'=>$q->version,'expected_revision'=>$q->current_revision,'expected_event_version'=>$this->event->fresh()->version,'decision_id'=>$d->id];
    }
    private function bind(?QuoteDecision $decision=null,?string $key=null):FinancialRevision
    {
        $decision??=$this->acceptOffer();return app(ConvertAcceptedQuote::class)->execute($this->owner,$decision->quote_id,$this->conversionInput($decision),$key??(string)Str::uuid());
    }
    private function resources():array
    {
        return [Resource::create(['code'=>'QF-STAFF','name'=>'Fixture Performer','kind'=>'staff','status'=>'active','skills'=>['performer','driver']])->id,
            Resource::create(['code'=>'QF-SPEAKER','name'=>'Fixture Speaker','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]])->id];
    }
    private function receipt(FinancialRevision $f,int $amount=5000):LedgerEntry
    {
        return app(RecordZellePayment::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>$f->id,'amount_cents'=>$amount,'currency'=>'USD',
            'reference'=>'QF-RECEIPT','received_at'=>now()->subMinute()->toIso8601String(),'verified_received'=>true],(string)Str::uuid());
    }
    private function capacityInput(FinancialRevision $f,array $ids):array
    {
        return ['expected_version'=>$this->event->fresh()->version,'financial_revision_id'=>$f->id,'resource_ids'=>$ids,
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Fixture origin and return transport plan.','transfers'=>[]]];
    }
    public function test_accepted_discounted_snapshot_survives_catalog_changes_then_real_receipt_confirms_capacity():void
    {
        $decision=$this->acceptOffer();
        ServiceVariant::whereKey($this->price->service_variant_id)->update(['name'=>'New catalog label','long_description'=>'New catalog description']);
        PriceVersion::create(['service_variant_id'=>$this->price->service_variant_id,'amount_cents'=>90000,'currency'=>'USD','created_by'=>$this->owner->id]);
        $key=(string)Str::uuid();$input=$this->conversionInput($decision);
        $f=app(ConvertAcceptedQuote::class)->execute($this->owner,$decision->quote_id,$input,$key);
        $this->assertSame([10001,2500,2501,10000,5000],[$f->subtotal_cents,$f->travel_fee_cents,$f->discount_cents,$f->total_cents,$f->deposit_target_cents]);
        $this->assertSame('Original Fictional Package',$f->lines_snapshot[0]['name']);
        $this->assertSame($this->offer->id,$f->quote_revision_id);
        $this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('resource_reservations',0);
        $this->receipt($f);$ids=$this->resources();
        $saved=app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,$this->capacityInput($f,$ids));
        $this->assertSame('capacity_reserved',$saved->status);$this->assertDatabaseCount('resource_reservations',2);
        $this->assertSame($f->id,app(ConvertAcceptedQuote::class)->execute($this->owner,$decision->quote_id,$input,$key)->id);
        $this->assertDatabaseCount('financial_revisions',1);$this->assertDatabaseCount('quote_conversions',1);$this->assertDatabaseCount('ledger_entries',1);
        $this->assertSame(5000,app(FinanceSummary::class)->forEvent($saved)['balance_cents']);
    }
    public function test_quote_conversion_requires_current_accepted_decision_and_never_reconciles_existing_money_silently():void
    {
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/quotes/'.$this->offer->quote_id.'/convert',[
            'expected_version'=>1,'expected_revision'=>1,'expected_event_version'=>1,'decision_id'=>1])->assertConflict();
        $decision=$this->acceptOffer();
        app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>1],(string)Str::uuid());
        $this->expectException(RecordWriteRejected::class);$this->bind($decision);
    }
    public function test_insufficient_actual_payment_fails_without_erasing_money_or_reserving_any_resource():void
    {
        $f=$this->bind();$this->receipt($f,4999);$ids=$this->resources();
        try{app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,$this->capacityInput($f,$ids));$this->fail('Expected shortfall');}
        catch(ValidationException $e){$this->assertArrayHasKey('booking',$e->errors());}
        $this->assertDatabaseCount('ledger_entries',1);$this->assertDatabaseCount('resource_reservations',0);$this->assertSame('draft',$this->event->fresh()->status);
    }
    public function test_legacy_manual_capacity_cannot_substitute_claimed_money_for_a_converted_quote():void
    {
        $f=$this->bind();$ids=$this->resources();$input=$this->capacityInput($f,$ids);unset($input['financial_revision_id']);
        $input['payment']=['method'=>'external_zelle','reference'=>'CLAIMED-NOT-LEDGER','amount_cents'=>90000,'received_at'=>now()->subMinute()->toIso8601String()];
        try{app(ConfirmCapacity::class)->execute($this->owner,$this->event->id,$input);$this->fail('Expected ledger gate');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_busy_equipment_still_blocks_capacity_and_keeps_the_verified_quote_deposit():void
    {
        $f=$this->bind();$ids=$this->resources();
        $other=app(SaveEventDraft::class)->execute($this->owner,['customer_id'=>$this->event->customer_id,'title'=>'Other Fixture Party',
            'starts_at'=>$this->event->starts_at->toIso8601String(),'ends_at'=>$this->event->ends_at->toIso8601String(),'timezone'=>'America/Chicago',
            'address'=>'456 Other Fictional Venue, TX 77433','postal_code'=>'77433','travel_fee_cents'=>2500,'setup_minutes'=>15,'breakdown_minutes'=>15,'services'=>[['price_version_id'=>$this->price->id]]]);
        app(ConfirmCapacity::class)->execute($this->owner,$other->id,['expected_version'=>1,'resource_ids'=>$ids,
            'payment'=>['method'=>'external_zelle','reference'=>'OTHER-FIXTURE','amount_cents'=>6251,'received_at'=>now()->subMinute()->toIso8601String()],
            'review'=>$this->capacityInput($f,$ids)['review']]);
        $this->receipt($f);
        try{app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,$this->capacityInput($f,$ids));$this->fail('Expected occupied resource');}
        catch(ValidationException|RecordWriteRejected){}
        $this->assertSame('draft',$this->event->fresh()->status);$this->assertDatabaseCount('ledger_entries',1);$this->assertSame(0,$this->event->reservations()->count());
    }
    public function test_address_only_reconfirmation_preserves_accepted_money_and_old_labels_but_updates_service_links():void
    {
        $f=$this->bind();
        ServiceVariant::whereKey($this->price->service_variant_id)->update(['name'=>'Newer label']);
        $updated=app(SaveEventDraft::class)->execute($this->owner,['expected_version'=>1,'customer_id'=>$this->event->customer_id,'title'=>$this->event->title,
            'starts_at'=>$this->event->starts_at->toIso8601String(),'ends_at'=>$this->event->ends_at->toIso8601String(),'timezone'=>'America/Chicago',
            'address'=>'789 Updated Fictional Venue, TX 77433','postal_code'=>'77433','travel_fee_cents'=>2500,'travel_fee_override_note'=>'Owner reviewed changed location; agreed travel amount unchanged.',
            'setup_minutes'=>15,'breakdown_minutes'=>15,'services'=>[['price_version_id'=>$this->price->id]]],$this->event->id);
        $new=app(CreateFinancialRevision::class)->execute($this->owner,$updated->id,['expected_version'=>2],(string)Str::uuid());
        $this->assertSame($f->total_cents,$new->total_cents);$this->assertSame(5000,$new->deposit_target_cents);$this->assertSame(2501,$new->discount_cents);
        $this->assertSame('Original Fictional Package',$new->lines_snapshot[0]['name']);
        $this->assertSame($updated->services()->first()->id,$new->lines_snapshot[0]['event_service_id']);$this->assertSame(1,$f->fresh()->event_version);
    }
    public function test_commercial_change_after_conversion_cannot_silently_reprice_the_accepted_agreement():void
    {
        $f=$this->bind();$this->event->update(['travel_fee_cents'=>2501]);$this->event->forceFill(['version'=>2])->save();
        try{app(CreateFinancialRevision::class)->execute($this->owner,$this->event->id,['expected_version'=>2],(string)Str::uuid());$this->fail('Expected commercial drift denial');}
        catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('financial_revisions',1);$this->assertSame(10000,$f->fresh()->total_cents);
    }
    public function test_late_conversion_audit_failure_rolls_back_finance_binding_and_receipt_but_keeps_acceptance():void
    {
        $d=$this->acceptOffer();$count=DB::table('mutation_receipts')->count();$fail=true;
        DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('quote.converted',$bindings,true))throw new \RuntimeException('Fixture convert audit failure');});
        try{$this->bind($d);$this->fail('Expected audit failure');}catch(\RuntimeException $e){$this->assertSame('Fixture convert audit failure',$e->getMessage());}finally{$fail=false;}
        $this->assertDatabaseCount('quote_conversions',0);$this->assertDatabaseCount('financial_revisions',0);$this->assertSame($count,DB::table('mutation_receipts')->count());$this->assertSame('accepted',Quote::sole()->status);
    }
    public function test_printed_contract_shows_discount_and_preserves_terms_without_private_discount_reason():void
    {
        $f=$this->bind();
        $template=app(CreateTemplateVersion::class)->execute($this->owner,['terms_html'=>'<p>Fixture original legal terms.</p>','change_note'=>'Fictional test template, no real terms changed.'],(string)Str::uuid());
        $template=app(ActivateTemplateVersion::class)->execute($this->owner,$template->id,['expected_source_sha256'=>$template->source_sha256,'approval_note'=>'Approve fixture for isolated automated test only.']);
        $contract=app(CreateContract::class)->execute($this->owner,$this->event->id,['expected_version'=>1,'financial_revision_id'=>$f->id,'template_version_id'=>$template->id,
            'client'=>['first_name'=>'Fictional','last_name'=>'Client','email'=>'client@example.test','phone'=>'346-555-0100','address'=>'123 Fictional Client Address']],(string)Str::uuid());
        $issued=app(IssueContract::class)->execute($this->owner,$contract->id,['expected_version'=>$contract->version],(string)Str::uuid());
        $html=app(ArtifactStore::class)->read($issued->presented_html_path,$issued->presented_html_sha256);
        $this->assertStringContainsString('Discount: Visible adjustment',$html);$this->assertStringContainsString('25.01',$html);$this->assertStringContainsString('$100.00',$html);
        $this->assertStringContainsString('Fixture original legal terms.',$html);$this->assertStringNotContainsString('PRIVATE_DISCOUNT_REASON',$html);
        $this->assertSame($template->terms_sha256,hash('sha256',$template->terms_html));
    }
    public function test_test_checkout_uses_the_accepted_discounted_deposit_without_contacting_stripe():void
    {
        $f=$this->bind();$this->app->instance(StripeGateway::class,new FakeStripeGateway());config(['waoo.stripe_test_account'=>'acct_fixture']);
        $intent=app(CreateCheckoutIntent::class)->execute($this->owner,$this->event->id,['financial_revision_id'=>$f->id,'purpose'=>'deposit'],(string)Str::uuid());
        $this->assertSame(5000,$intent->principal_cents);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_two_actual_owners_converting_one_acceptance_create_only_one_financial_agreement():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $d=$this->acceptOffer();$other=\App\Models\User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $data=$this->conversionInput($d);
        $worker=fn($actor)=>function()use($actor,$data,$d){try{return app(ConvertAcceptedQuote::class)->execute($actor,$d->quote_id,$data,(string)Str::uuid())->id;}catch(RecordWriteRejected $e){return $e->status;}};
        $results=ConcurrentWorkers::run([$worker($this->owner),$worker($other)]);
        $this->assertContains(409,$results);$this->assertContains(FinancialRevision::sole()->id,$results);$this->assertDatabaseCount('quote_conversions',1);
    }
    public function test_discount_and_verified_deposit_do_not_bypass_short_notice_owner_approval():void
    {
        $start=now()->addDays(3)->startOfHour();
        $this->event->forceFill(['starts_at'=>$start,'ends_at'=>$start->copy()->addHour(),'version'=>2])->save();
        $this->offer=app(ReviseQuote::class)->execute($this->owner,$this->offer->quote_id,['expected_version'=>1,'expected_event_version'=>2,
            'valid_until'=>now()->addDay()->toIso8601String(),'discounts'=>[['label'=>'Visible adjustment','amount_cents'=>2501,'reason'=>'Fictional approved adjustment.']]],(string)Str::uuid());
        $f=$this->bind();$this->receipt($f);$ids=$this->resources();$input=$this->capacityInput($f,$ids);
        try{app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,$input);$this->fail('Expected explicit short-notice approval');}
        catch(ValidationException $e){$this->assertArrayHasKey('booking',$e->errors());}
        $this->assertDatabaseCount('resource_reservations',0);$this->assertDatabaseCount('ledger_entries',1);
        $input['review']['short_notice_reason']='Owner explicitly verified preparation and staff for this fictional short-notice party.';
        $saved=app(ConfirmPaidCapacity::class)->execute($this->owner,$this->event->id,$input);
        $this->assertSame('capacity_reserved',$saved->status);$this->assertDatabaseCount('resource_reservations',2);
    }

}
