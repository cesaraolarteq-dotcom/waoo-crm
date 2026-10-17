<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{BookingEvidence,Customer,Event,ImportBatch,ImportFile,ImportedLedgerLine,ImportedProjectLink,ImportedProjectRevision,ImportRow,ImportServiceMapping,Resource,User};
use App\Services\Imports\{CommitHoneyBookEvent,ConfirmImportedCapacity,FinalizeHoneyBookImport,ImportedAgreement,ProfileHoneyBookImport,ReadImportCoverage,ReadImportedEvent};
use App\Services\Records\ConfirmCapacity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\{ConcurrentWorkers,HoneyBookFutureScenario};
use Tests\TestCase;
class HoneyBookFutureImportTest extends TestCase
{
    use DatabaseMigrations,HoneyBookFutureScenario;
    protected function setUp():void{parent::setUp();$this->futureScenario();}
    protected function tearDown():void
    {
        // Synthetic day-only evidence deliberately refuses production down() timestamp fabrication.
        // Remove only this test's evidence before DatabaseMigrations drops the entire disposable schema.
        if($this->app&&\Illuminate\Support\Facades\Schema::hasTable('booking_evidence'))DB::table('booking_evidence')->where('method','honeybook_import')->delete();
        if($this->app)$this->app->detectEnvironment(fn()=>'testing');parent::tearDown();
    }
    private function commit(array $change=[],?string $key=null)
    {
        $b=$this->stageFuture($change);$r=ImportRow::where('import_batch_id',$b->id)->sole();return app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r),$key??(string)Str::uuid());
    }
    private function resources():array
    {
        return [Resource::create(['code'=>'IMP-HOST','name'=>'Fictional Host','kind'=>'staff','status'=>'active','skills'=>['host','driver']])->id,
            Resource::create(['code'=>'IMP-SPEAKER','name'=>'Fictional Speaker','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]])->id];
    }
    private function capacityInput($link,array $ids):array
    {
        return ['expected_version'=>Event::findOrFail($link->event_id)->version,'imported_revision_id'=>$link->current_revision_id,'resource_ids'=>$ids,
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Owner reviewed fictional source travel and return plan.','transfers'=>[]]];
    }
    public function test_exact24_source_arithmetic_and_signed_payment_lines_are_private_and_do_not_create_domain_until_commit():void
    {
        $b=$this->stageFuture();$row=ImportRow::sole();$this->assertSame('ready',$row->status);$n=$row->normalized;
        $this->assertSame([10001,2500,501,12000,6000,6000],array_map(fn($k)=>$n[$k],['package_cents','travel_cents','discount_cents','total_cents','paid_cents','balance_cents']));
        $this->assertSame([7000,-1000],array_column($n['payments'],'amount_cents'));$this->assertSame('2026-12-20T21:00:00+00:00',$n['starts_at']);
        $this->assertTrue($n['contract_signed']);foreach(['customers','events','imported_ledger_lines','ledger_entries','contracts','resource_reservations'] as $t)$this->assertDatabaseCount($t,0);
        $this->getJson('/admin/api/imports/honeybook/'.$b->id)->assertOk()->assertDontSee('PRIVATE_SOURCE_NOTE_FIXTURE')->assertDontSee('private_path');
    }
    public function test_commit_freezes_source_money_not_current_prices_and_does_not_log_client_cells():void
    {
        $link=$this->commit();$r=ImportedProjectRevision::sole();$event=Event::sole();
        $coverage=app(ReadImportCoverage::class)->execute($this->owner,$r->import_batch_id);$this->assertSame(12000,$coverage['reconciliation']['total_cents']['source_sum_cents']);$this->assertSame(0,$coverage['reconciliation']['paid_cents']['difference_cents']);
        $this->assertSame(12000,$r->normalized['total_cents']);$this->assertSame(6000,(int)ImportedLedgerLine::sum('amount_cents'));$this->assertSame('honeybook',$event->travel_fee_evidence['source']);
        $this->assertSame('2026-10-01',ImportedLedgerLine::orderBy('id')->first()->received_date->format('Y-m-d'));$this->assertFalse(ImportedAgreement::summary($event->id)['funds_verified']);
        foreach(['ledger_entries','financial_revisions','contracts','portal_grants','resource_reservations'] as $t)$this->assertDatabaseCount($t,0);
        $audit=json_encode(DB::table('audit_entries')->get());foreach(['Fictional Source','fixture-source@example.test','PRIVATE_SOURCE_NOTE_FIXTURE','123 Fictional Venue'] as $secret)$this->assertStringNotContainsString($secret,$audit);Queue::assertNothingPushed();
        $this->getJson('/admin/api/imported-events/'.$event->id)->assertOk()->assertJsonPath('summary.environment','honeybook_import');
    }
    public function test_real_money_bridge_uses_historical_total_and_date_only_evidence_then_capacity_replay():void
    {
        $link=$this->commit();$ids=$this->resources();$key=(string)Str::uuid();$d=$this->capacityInput($link,$ids);$event=app(ConfirmImportedCapacity::class)->execute($this->owner,$link->event_id,$d,$key);
        $this->assertSame('capacity_reserved',$event->status);$this->assertDatabaseCount('resource_reservations',2);$e=BookingEvidence::sole();$this->assertSame('honeybook_import',$e->method);$this->assertNull($e->received_at);$this->assertSame('2026-10-02',$e->received_date->format('Y-m-d'));$this->assertSame(6000,$e->amount_cents);
        $this->assertSame($event->id,app(ConfirmImportedCapacity::class)->execute($this->owner,$link->event_id,$d,$key)->id);$this->assertDatabaseCount('booking_evidence',1);
        $c=app(ReadImportCoverage::class)->execute($this->owner,ImportBatch::sole()->id);$this->assertTrue($c['domain_complete']);$this->assertTrue($c['capacity_complete']);$this->assertFalse($c['pilot_approved']);
    }
    public function test_underpaid_import_keeps_source_money_and_never_invents_deposit_exception():void
    {
        $link=$this->commit(['amount_paid'=>'50.00','balance'=>'70.00','payments'=>'2026-10-01|50.00|Zelle']);$ids=$this->resources();
        try{app(ConfirmImportedCapacity::class)->execute($this->owner,$link->event_id,$this->capacityInput($link,$ids),(string)Str::uuid());$this->fail('Expected historical deposit shortfall');}catch(ValidationException $e){$this->assertArrayHasKey('booking',$e->errors());}
        $this->assertDatabaseCount('resource_reservations',0);$this->assertSame(5000,(int)ImportedLedgerLine::sum('amount_cents'));$this->assertSame('draft',Event::sole()->status);
    }
    public function test_same_file_and_same_project_retries_do_not_duplicate_clients_events_or_source_money():void
    {
        $b=$this->stageFuture();$row=ImportRow::sole();$input=$this->commitInput($b,$row);$key=(string)Str::uuid();$service=app(CommitHoneyBookEvent::class);$link=$service->execute($this->owner,$b->id,$row->id,$input,$key);
        $this->assertSame($link->id,$service->execute($this->owner,$b->id,$row->id,$input,$key)->id);$input['expected_batch_version']=$b->fresh()->version;
        $this->assertSame($link->id,$service->execute($this->owner,$b->id,$row->id,$input,(string)Str::uuid())->id);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('imported_project_revisions',1);$this->assertDatabaseCount('imported_ledger_lines',2);
        $this->assertSame($b->id,$this->stageFuture()->id);
    }
    public function test_existing_customer_is_only_linked_never_overwritten_and_displayed_version_is_required():void
    {
        $customer=Customer::create(['name'=>'Existing Fictional Person','email'=>'existing@example.test','phone'=>'+13465550100','locale'=>'en']);$b=$this->stageFuture();$row=ImportRow::sole();$d=$this->commitInput($b,$row,['customer_id'=>$customer->id,'expected_customer_version'=>1]);unset($d['create_separate_customer'],$d['new_customer_locale']);
        $link=app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$d,(string)Str::uuid());$this->assertSame($customer->id,$link->customer_id);$this->assertSame('existing@example.test',$customer->fresh()->email);$this->assertSame('en',$customer->fresh()->locale);$this->assertSame(1,$customer->fresh()->version);$this->assertDatabaseCount('customers',1);
    }
    public function test_explicit_draft_source_revision_switches_current_money_without_adding_history_twice():void
    {
        $link=$this->commit();$old=ImportedProjectRevision::sole();$b=$this->stageFuture(['total'=>'130.00','package_total'=>'110.01','balance'=>'70.00']);$row=ImportRow::where('import_batch_id',$b->id)->sole();$d=$this->commitInput($b,$row,['expected_import_version'=>1,'expected_event_version'=>1]);
        $updated=app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$d,(string)Str::uuid());$this->assertSame($link->id,$updated->id);$this->assertSame(2,$updated->version);$this->assertSame(12000,$old->fresh()->normalized['total_cents']);$this->assertSame(7000,ImportedAgreement::summary($link->event_id)['balance_cents']);$this->assertSame(6000,ImportedAgreement::summary($link->event_id)['net_received_cents']);$this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('imported_project_revisions',2);
    }
    public function test_capacity_reserved_source_cannot_be_repriced_by_reimport():void
    {
        $link=$this->commit();app(ConfirmImportedCapacity::class)->execute($this->owner,$link->event_id,$this->capacityInput($link,$this->resources()),(string)Str::uuid());$b=$this->stageFuture(['project_name'=>'Changed source title']);$row=ImportRow::where('import_batch_id',$b->id)->sole();
        $this->expectException(RecordWriteRejected::class);app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$this->commitInput($b,$row,['expected_import_version'=>1,'expected_event_version'=>2]),(string)Str::uuid());
    }
    public function test_audit_failure_rolls_back_customer_event_revision_mapping_receipt_and_restores_normal_audit_scope():void
    {
        $b=$this->stageFuture();$row=ImportRow::sole();$before=DB::table('mutation_receipts')->count();$fail=true;
        DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('import.project_committed',$bindings,true))throw new \RuntimeException('Fixture imported audit failure');});
        try{app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$this->commitInput($b,$row,['remember_mapping'=>true]),(string)Str::uuid());$this->fail('Expected audit rollback');}catch(\RuntimeException $e){$this->assertSame('Fixture imported audit failure',$e->getMessage());}finally{$fail=false;}
        foreach(['customers','events','imported_project_links','imported_project_revisions','imported_ledger_lines','import_service_mappings'] as $t)$this->assertDatabaseCount($t,0);$this->assertSame($before,DB::table('mutation_receipts')->count());
        $c=app(\App\Services\Records\SaveCustomer::class)->execute($this->owner,['name'=>'Normal audit fixture','locale'=>'es']);$this->assertStringContainsString('Normal audit fixture',DB::table('audit_entries')->where('subject_type','customers')->value('after'));
    }
    public function test_finalize_refuses_missing_rows_and_deletes_raw_csv_only_after_complete_domain_import():void
    {
        $b=$this->stageFuture();$this->assertFalse(app(ReadImportCoverage::class)->execute($this->owner,$b->id)['domain_complete']);
        try{app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1,'source_coverage_reviewed'=>true],(string)Str::uuid());$this->fail('Expected unimported source denial');}catch(RecordWriteRejected){}
        $row=ImportRow::sole();app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$this->commitInput($b,$row),(string)Str::uuid());$path=ImportFile::sole()->private_path;$key=(string)Str::uuid();$input=['expected_version'=>2,'source_coverage_reviewed'=>true];
        $done=app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key);$this->assertSame('imported',$done->status);Storage::disk('local')->assertMissing($path);$this->assertDatabaseCount('imported_ledger_lines',2);$this->assertFalse(app(ReadImportCoverage::class)->execute($this->owner,$b->id)['capacity_complete']);$this->assertSame('imported',app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key)->status);
    }
    public function test_known_signed_source_is_not_a_new_signature_and_legacy_finance_or_claimed_money_is_blocked():void
    {
        $link=$this->commit();$ids=$this->resources();$d=$this->capacityInput($link,$ids);unset($d['imported_revision_id']);$d['payment']=['method'=>'external_zelle','reference'=>'FAKE-NOT-SOURCE','amount_cents'=>100000,'received_at'=>now()->subMinute()->toIso8601String()];
        try{app(ConfirmCapacity::class)->execute($this->owner,$link->event_id,$d);$this->fail('Expected imported source guard');}catch(RecordWriteRejected){}
        try{app(\App\Services\Finance\CreateFinancialRevision::class)->execute($this->owner,$link->event_id,['expected_version'=>1],(string)Str::uuid());$this->fail('Expected no current catalog repricing');}catch(RecordWriteRejected){}
        config(['waoo.sandbox_quotes'=>true]);try{app(\App\Services\Quotes\CreateQuote::class)->execute($this->owner,['event_id'=>$link->event_id,'expected_event_version'=>1],(string)Str::uuid());$this->fail('Expected no replacement quote');}catch(RecordWriteRejected){}
        $this->assertDatabaseCount('quotes',0);$this->assertDatabaseCount('contracts',0);$this->assertDatabaseCount('signature_evidence',0);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('resource_reservations',0);
    }
    public function test_two_actual_owners_commit_one_source_project_without_duplicate_domain_writes():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB pcntl required');$b=$this->stageFuture();$row=ImportRow::sole();$d=$this->commitInput($b,$row);$other=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $worker=fn($actor)=>function()use($actor,$b,$row,$d){try{return app(CommitHoneyBookEvent::class)->execute($actor,$b->id,$row->id,$d,(string)Str::uuid())->id;}catch(RecordWriteRejected $e){return $e->status;}};
        $results=ConcurrentWorkers::run([$worker($this->owner),$worker($other)]);$this->assertContains(409,$results);$this->assertContains(ImportedProjectLink::sole()->id,$results);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('imported_ledger_lines',2);
    }
    public function test_header_order_and_unknown_or_missing_money_are_refused_without_domain_writes():void
    {
        $headers=\App\Services\Imports\HoneyBookFutureSource::HEADERS;[$headers[0],$headers[1]]=[$headers[1],$headers[0]];
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->post('/admin/api/imports/honeybook/future',['file'=>$this->futureFile([$this->futureRow()],$headers)],['Accept'=>'application/json'])->assertUnprocessable();
        $b=$this->stageFuture(['amount_paid'=>'']);$row=ImportRow::sole();try{app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$this->commitInput($b,$row),(string)Str::uuid());$this->fail('Expected missing money rejection');}catch(RecordWriteRejected){}
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('customers',0);
    }
    public function test_tampered_private_artifact_and_stale_displayed_catalog_are_rejected_before_domain_commit():void
    {
        $b=$this->stageFuture();$row=ImportRow::sole();$input=$this->commitInput($b,$row);$file=ImportFile::sole();$original=Storage::disk('local')->get($file->private_path);Storage::disk('local')->put($file->private_path,'Tampered synthetic bytes');
        try{app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$input,(string)Str::uuid());$this->fail('Expected integrity denial');}catch(\RuntimeException $e){$this->assertSame('Private import artifact unavailable.',$e->getMessage());}
        Storage::disk('local')->put($file->private_path,$original);\App\Models\ServiceVariant::whereKey($this->price->service_variant_id)->increment('version');
        try{app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$row->id,$input,(string)Str::uuid());$this->fail('Expected stale variant');}catch(RecordWriteRejected){}
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('imported_project_links',0);
    }
    public function test_cleanup_failure_is_retryable_without_losing_durable_source_money():void
    {
        $link=$this->commit();$b=ImportBatch::sole();$key=(string)Str::uuid();$input=['expected_version'=>$b->version,'source_coverage_reviewed'=>true];$manager=Storage::getFacadeRoot();Storage::shouldReceive('disk->exists')->andReturn(true);Storage::shouldReceive('disk->delete')->once()->andReturn(false);
        $pending=app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key);$this->assertSame('imported_cleanup_pending',$pending->status);$this->assertSame(6000,ImportedAgreement::summary($link->event_id)['net_received_cents']);
        Storage::swap($manager);$done=app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key);$this->assertSame('imported',$done->status);$this->assertDatabaseCount('events',1);$this->assertDatabaseCount('imported_ledger_lines',2);
    }
    public function test_real_competing_imported_confirmations_reserve_shared_resources_once_and_keep_both_source_ledgers():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB pcntl required');
        $first=$this->commit();$second=$this->commit(['honeybook_project_id'=>'HB-FAKE-2']);$ids=$this->resources();
        $worker=function($link)use($ids){return function()use($link,$ids){try{app(ConfirmImportedCapacity::class)->execute($this->owner,$link->event_id,$this->capacityInput($link,$ids),(string)Str::uuid());return 'reserved';}catch(RecordWriteRejected $e){return $e->status;}catch(ValidationException){return 422;}};};
        $results=ConcurrentWorkers::run([$worker($first),$worker($second)]);$this->assertContains('reserved',$results);$this->assertContains(422,$results,json_encode($results));$this->assertDatabaseCount('resource_reservations',2);$this->assertDatabaseCount('booking_evidence',1);$this->assertDatabaseCount('imported_ledger_lines',4);$this->assertDatabaseCount('events',2);
        $this->assertSame(12000,(int)ImportedLedgerLine::sum('amount_cents'));$this->assertDatabaseCount('ledger_entries',0);
    }

    public function test_source_start_is_rechecked_after_preview_and_never_silently_imported_as_past():void
    {
        $batch=$this->stageFuture(['event_date'=>'2026-10-17','start_time'=>'07:30','end_time'=>'08:30']);$row=ImportRow::sole();
        $this->assertSame('ready',$row->status);$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-17T12:31:00Z'));
        try{app(CommitHoneyBookEvent::class)->execute($this->owner,$batch->id,$row->id,$this->commitInput($batch,$row),(string)Str::uuid());$this->fail('Elapsed source accepted');}
        catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);$this->assertSame('El evento de origen ya no es futuro.',$e->getMessage());}
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('imported_project_links',0);$this->assertSame('ready',$row->fresh()->status);
        $coverage=app(ReadImportCoverage::class)->execute($this->owner,$batch->id);$this->assertFalse($coverage['domain_complete']);$this->assertSame(1,$coverage['counts']['unmapped']);
        try{app(FinalizeHoneyBookImport::class)->execute($this->owner,$batch->id,['expected_version'=>$batch->version,'source_coverage_reviewed'=>true],(string)Str::uuid());$this->fail('Elapsed unresolved source hidden at finalization');}
        catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
    }

}
