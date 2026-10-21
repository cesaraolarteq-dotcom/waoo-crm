<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer,ExternalCustomerLink,HistoricalEvent,HistoricalEventRevision,HistoryImportBatch,HistoryImportRow,User};
use App\Services\History\{CloseHistoricalImport,CommitHistoricalRow,ExcludeHistoricalRow,ImportSafeHistoricalRows,ReadCustomerHistory,ReadHistoricalImport,ReadHistoricalRow,StageHistoricalImport};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Http,Mail,Queue,Schema};
use Illuminate\Support\Str;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

class HistoricalDomainTest extends TestCase
{
    use DatabaseMigrations,HistoricalSourceFixture;
    private User $owner;
    protected function setUp():void {
        parent::setUp();$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        config(['waoo.sandbox_history'=>true]); Http::preventStrayRequests();Mail::fake();Queue::fake();
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
    }
    protected function tearDown():void {
        if($this->app&&Schema::hasTable('historical_events')) {
            DB::table('historical_events')->update(['current_revision_id'=>null]);
            foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links','history_import_rows','history_import_batches'] as $table) DB::table($table)->delete();
        }
        if($this->app)$this->app->detectEnvironment(fn()=>'testing');parent::tearDown();
    }
    private function stage(array $rows):int {
        $headers=\App\Services\History\HistoricalSource::HEADERS;
        foreach($rows as $row)foreach(array_keys($row) as $key)if(!in_array($key,$headers,true))$headers[]=$key;
        $stream=fopen('php://temp','w+');fputcsv($stream,$headers,',','"','');
        foreach($rows as $row)fputcsv($stream,array_map(fn($h)=>$row[$h]??'',$headers),',','"','');rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);
        return app(StageHistoricalImport::class)->execute($this->owner,UploadedFile::fake()->createWithContent('synthetic-history.csv',$bytes),(string)Str::uuid())['batch_id'];
    }
    private function bulkInput(int $id):array {
        $r=app(ReadHistoricalImport::class)->execute($this->owner,$id);
        return ['expected_batch_version'=>$r['batch']['version'],'expected_coverage_sha256'=>$r['coverage_sha256'],'expected_plan_sha256'=>$r['bulk']['plan_sha256'],'new_customer_locale'=>'es'];
    }
    private function bulk(int $id,?array $input=null,?string $key=null):array {
        return app(ImportSafeHistoricalRows::class)->execute($this->owner,$id,$input??$this->bulkInput($id),$key??(string)Str::uuid());
    }
    public function test_bulk_preserves_all_extended_source_evidence_without_native_side_effects():void {
        $source=$this->historyRow(['child_name'=>'Sofía','celebrant_age'=>'6','paid_total'=>'60.00','balance'=>'60.00',
            'payments'=>'2025-07-01|60.00|cash','line_items'=>'Animación|120.00','contract_signed'=>'yes','children_count'=>'20',
            'setup_area'=>'outdoor','event_language'=>'es','special_requests'=>'Actividades tranquilas.','sms_consent_honeybook'=>'yes',
            'lead_source_text'=>'Instagram','created_at'=>'2025-06-01T12:00:00Z']);
        $id=$this->stage([$source]);$result=$this->bulk($id);$this->assertSame(1,$result['imported']);
        $history=HistoricalEvent::sole();$revision=HistoricalEventRevision::sole();$this->assertSame($source,$revision->payload['source']);
        $view=app(ReadCustomerHistory::class)->execute($this->owner,$history->customer_id);
        $this->assertSame(6000,$view['items'][0]['paid_total_cents']);$this->assertFalse($view['items'][0]['funds_verified']);
        foreach(['historical_event_revisions','external_customer_links','history_import_decisions','history_import_rows','history_import_batches','audit_entries','mutation_receipts'] as $table) {
            $raw=DB::table($table)->get()->toJson();foreach(['María González','Actividades tranquilas.','history@example.test'] as $secret)$this->assertStringNotContainsString($secret,$raw,$table);
        }
        foreach(['events','financial_revisions','ledger_entries','resource_reservations','contracts','portal_grants','outbound_messages','tip_grants','leads'] as $table)if(Schema::hasTable($table))$this->assertDatabaseCount($table,0);
        Mail::assertNothingSent();Http::assertNothingSent();Queue::assertNothingPushed();
    }
    public function test_repeated_source_contact_creates_one_customer_and_two_archive_entries():void {
        $id=$this->stage([$this->historyRow(),$this->historyRow(['project_id'=>'synthetic-2'])]);
        $this->assertSame(2,$this->bulk($id)['imported']);$this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('external_customer_links',1);
    }
    public function test_exact_email_match_links_without_overwriting_customer():void {
        $customer=Customer::create(['name'=>'Laura Martínez','email'=>'HISTORY@example.test','phone'=>'+13465550190','locale'=>'en']);$before=$customer->fresh()->getAttributes();
        $id=$this->stage([$this->historyRow(['client_phone'=>''])]);$this->bulk($id);
        $this->assertSame($customer->id,HistoricalEvent::sole()->customer_id);$this->assertEquals($before,$customer->fresh()->getAttributes());
    }
    public function test_phone_match_is_conservative():void {
        $customer=Customer::create(['name'=>'Laura Martínez','email'=>null,'phone'=>'+1 (202) 555-0123','locale'=>'es']);
        $id=$this->stage([$this->historyRow(['client_email'=>''])]);$this->bulk($id);$this->assertSame($customer->id,HistoricalEvent::sole()->customer_id);
    }
    public function test_conflicting_email_and_phone_are_left_for_review_while_safe_row_imports():void {
        Customer::create(['name'=>'Laura Martínez','email'=>'history@example.test','phone'=>null,'locale'=>'es']);
        Customer::create(['name'=>'Elena Pérez','email'=>null,'phone'=>'+12025550123','locale'=>'es']);
        $id=$this->stage([$this->historyRow(),$this->historyRow(['project_id'=>'safe-2','contact_id'=>'safe-contact','client_email'=>'maria.safe@example.test','client_phone'=>''])]);
        $result=$this->bulk($id);$this->assertSame(1,$result['imported']);$this->assertSame(1,$result['review_required']);$this->assertDatabaseCount('historical_events',1);
    }
    public function test_duplicate_email_candidates_are_not_automatically_merged():void {
        foreach(['Laura Martínez','Elena Pérez'] as $name)Customer::create(['name'=>$name,'email'=>'history@example.test','locale'=>'es']);
        $id=$this->stage([$this->historyRow(['client_phone'=>''])]);$this->assertSame(1,$this->bulk($id)['review_required']);$this->assertDatabaseCount('historical_events',0);
    }
    public function test_same_contact_with_conflicting_source_addresses_stays_for_review():void {
        $id=$this->stage([$this->historyRow(),$this->historyRow(['project_id'=>'synthetic-2','client_email'=>'other@example.test'])]);
        $r=$this->bulk($id);$this->assertSame(2,$r['review_required']);$this->assertDatabaseCount('customers',0);
    }
    public function test_prospect_has_no_fabricated_date_or_operating_lead():void {
        $id=$this->stage([$this->historyRow(['event_date'=>'','project_status'=>'lead'])]);$this->assertSame(1,$this->bulk($id)['imported']);
        $facts=HistoricalEventRevision::sole()->payload['facts'];$this->assertSame('prospect',$facts['record_kind']);$this->assertNull($facts['source_date']);$this->assertSame('archived_unknown',$facts['status']);
    }
    public function test_future_and_ambiguous_dst_rows_are_not_bulk_imported():void {
        $id=$this->stage([$this->historyRow(['event_date'=>'2027-01-01']),$this->historyRow(['project_id'=>'fold','event_date'=>'2025-11-02','start_time'=>'01:30','end_time'=>'03:00'])]);
        $this->assertSame(2,$this->bulk($id)['review_required']);$this->assertDatabaseCount('customers',0);
    }
    public function test_bulk_replay_returns_original_result_and_changed_body_conflicts():void {
        $id=$this->stage([$this->historyRow()]);$input=$this->bulkInput($id);$key=(string)Str::uuid();$r=$this->bulk($id,$input,$key);
        $this->assertSame($r,$this->bulk($id,$input,$key));$this->assertDatabaseCount('historical_event_revisions',1);
        $this->expectException(RecordWriteRejected::class);$this->bulk($id,[...$input,'new_customer_locale'=>'en'],$key);
    }
    public function test_stale_customer_plan_is_rejected_before_any_import():void {
        $id=$this->stage([$this->historyRow()]);$input=$this->bulkInput($id);Customer::create(['name'=>'Laura Martínez','email'=>'history@example.test','locale'=>'es']);
        try{$this->bulk($id,$input);$this->fail('Expected stale plan');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('historical_events',0);
    }
    public function test_same_source_in_another_batch_is_unchanged():void {
        $id=$this->stage([$this->historyRow()]);$this->bulk($id);
        $id2=$this->stage([$this->historyRow(),$this->historyRow(['project_id'=>'second','contact_id'=>'second','client_email'=>'maria.other@example.test','client_phone'=>''])]);
        $r=$this->bulk($id2);$this->assertSame(1,$r['unchanged']);$this->assertSame(1,$r['imported']);$this->assertDatabaseCount('historical_events',2);
    }
    public function test_changed_source_does_not_overwrite_original():void {
        $id=$this->stage([$this->historyRow()]);$this->bulk($id);$old=HistoricalEventRevision::sole()->payload;
        $id2=$this->stage([$this->historyRow(['booked_total'=>'121.00'])]);$this->assertSame(1,$this->bulk($id2)['review_required']);$this->assertSame($old,HistoricalEventRevision::sole()->payload);
    }
    public function test_close_requires_all_rows_and_preserves_encrypted_source_after_close():void {
        $id=$this->stage([$this->historyRow(),$this->historyRow(['project_id'=>'future','event_date'=>'2027-01-01'])]);$this->bulk($id);
        $view=app(ReadHistoricalImport::class)->execute($this->owner,$id);
        try{app(CloseHistoricalImport::class)->execute($this->owner,$id,['expected_batch_version'=>$view['batch']['version'],'expected_coverage_sha256'=>$view['coverage_sha256']],(string)Str::uuid());$this->fail('Expected pending');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $row=HistoryImportRow::where('history_import_batch_id',$id)->orderByDesc('id')->first();$detail=app(ReadHistoricalRow::class)->execute($this->owner,$id,$row->id);
        app(ExcludeHistoricalRow::class)->execute($this->owner,$id,$row->id,['expected_batch_version'=>$detail['batch_version'],'expected_row_version'=>$detail['version'],'expected_preview_sha256'=>$detail['preview_sha256'],'owner_reason'=>'Es una fiesta futura, no pertenece al archivo.'],(string)Str::uuid());
        $view=app(ReadHistoricalImport::class)->execute($this->owner,$id);$input=['expected_batch_version'=>$view['batch']['version'],'expected_coverage_sha256'=>$view['coverage_sha256']];$key=(string)Str::uuid();
        $closed=app(CloseHistoricalImport::class)->execute($this->owner,$id,$input,$key);$this->assertSame($closed,app(CloseHistoricalImport::class)->execute($this->owner,$id,$input,$key));
        $this->assertTrue($closed['source_retained']);$this->assertNotNull(HistoryImportBatch::find($id)->artifact);$this->assertSame('closed',app(ReadHistoricalImport::class)->execute($this->owner,$id)['batch']['state']);
    }
    public function test_audit_failure_rolls_back_whole_bulk_including_customer_and_contact():void {
        $id=$this->stage([$this->historyRow()]);$input=$this->bulkInput($id);
        DB::listen(function($q){if(str_contains($q->sql,'insert into `audit_entries`')&&in_array('history.row_committed',$q->bindings,true))throw new \RuntimeException('Synthetic audit failure');});
        try{$this->bulk($id,$input);$this->fail('Expected rollback');}catch(\RuntimeException $e){$this->assertSame('Synthetic audit failure',$e->getMessage());}
        foreach(['historical_events','customers','external_customer_links','history_import_decisions'] as $table)$this->assertDatabaseCount($table,0);
    }
    public function test_owner_excluded_fields_reject_before_storage():void {
        $this->expectException(\Illuminate\Validation\ValidationException::class);$this->stage([$this->historyRow(['private_notes'=>'No debe copiarse'])]);
    }
    public function test_unknown_optional_money_stays_original_not_zero():void {
        $id=$this->stage([$this->historyRow(['paid_total'=>'unknown','balance'=>''])]);$this->bulk($id);$p=HistoricalEventRevision::sole()->payload;
        $this->assertNull($p['facts']['paid_total_cents']);$this->assertSame('unknown',$p['source']['paid_total']);
    }
    public function test_http_bulk_and_customer_history_are_private_and_owner_only():void {
        $id=$this->stage([$this->historyRow()]);$this->withHeader('Idempotency-Key',(string)Str::uuid())->postJson('/admin/api/history/imports/'.$id.'/commit-safe',$this->bulkInput($id))->assertOk()->assertJsonPath('imported',1);
        $customer=HistoricalEvent::sole()->customer_id;$response=$this->getJson('/admin/api/customers/'.$customer.'/history')->assertOk();$this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        config(['waoo.sandbox_history'=>false]);$this->getJson('/admin/api/customers/'.$customer.'/history')->assertForbidden();
    }
    public function test_reviewed_changed_source_appends_revision_and_preserves_old_decision_replay():void {
        $first=$this->stage([$this->historyRow()]);$this->bulk($first);$old=HistoricalEventRevision::sole();
        $second=$this->stage([$this->historyRow(['booked_total'=>'125.00'])]);$row=HistoryImportRow::where('history_import_batch_id',$second)->first();
        $r=app(ReadHistoricalRow::class)->execute($this->owner,$second,$row->id);$candidate=$r['customer_match']['candidates'][0];
        $input=['expected_batch_version'=>$r['batch_version'],'expected_row_version'=>$r['version'],'expected_preview_sha256'=>$r['preview_sha256'],
            'expected_match_sha256'=>$r['customer_match']['expected_match_sha256'],'customer'=>['mode'=>'link','id'=>$candidate['id'],'expected_version'=>$candidate['version']],
            'source_status_confirmation'=>$r['normalized']['status'],'event_type_confirmation'=>$r['normalized']['event_type'],
            'expected_history_version'=>$r['previous']['version'],'expected_revision_id'=>$r['previous']['revision_id'],'expected_diff_sha256'=>$r['previous']['diff_sha256'],'owner_note'=>'La nueva extracción corrige el total de origen.'];
        $key=(string)Str::uuid();$saved=app(CommitHistoricalRow::class)->execute($this->owner,$second,$row->id,$input,$key);
        $this->assertSame(2,$saved['history_version']);$this->assertDatabaseCount('historical_event_revisions',2);
        $this->assertSame($old->payload,$old->fresh()->payload);
        $this->assertSame($saved,app(CommitHistoricalRow::class)->execute($this->owner,$second,$row->id,$input,$key));
        $verify=app(\App\Services\History\VerifyHistoricalImport::class)->execute($this->owner,$first);$this->assertSame('copied_fields_match',$verify['verdict']);
    }
    public function test_independent_verification_reports_pending_then_exact_copy_without_human_acceptance():void {
        $id=$this->stage([$this->historyRow(['paid_total'=>'60.01','balance'=>'59.99'])]);
        $v=app(\App\Services\History\VerifyHistoricalImport::class)->execute($this->owner,$id);$this->assertSame('pending_decisions',$v['verdict']);
        $this->bulk($id);$v=app(\App\Services\History\VerifyHistoricalImport::class)->execute($this->owner,$id);
        $this->assertSame('copied_fields_match',$v['verdict']);$this->assertFalse($v['human_independent_reviews_complete']);$this->assertSame([],$v['differences']);
        $this->assertStringNotContainsString('María',json_encode($v));
    }
    public function test_two_owners_cannot_bulk_import_the_same_rows_twice():void {
        if(DB::getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB/pcntl required');
        $id=$this->stage([$this->historyRow()]);$input=$this->bulkInput($id);$first=$this->owner;
        $other=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $worker=function(User $actor)use($id,$input){try{app(ImportSafeHistoricalRows::class)->execute($actor,$id,$input,(string)Str::uuid());return 'imported';}catch(RecordWriteRejected $e){return 'conflict:'.$e->status;}};
        $results=\Tests\Support\ConcurrentWorkers::run([fn()=>$worker($first),fn()=>$worker($other)]);sort($results);
        $this->assertSame(['conflict:409','imported'],$results);$this->assertDatabaseCount('historical_events',1);$this->assertDatabaseCount('customers',1);
    }
    public function test_bulk_volume_is_one_atomic_operation_without_per_row_owner_confirmation():void {
        $rows=[];for($i=0;$i<130;$i++)$rows[]=$this->historyRow(['project_id'=>'synthetic-volume-'.$i,'contact_id'=>'synthetic-contact-'.$i,'client_name'=>'María González','client_email'=>'maria.'.$i.'@example.test','client_phone'=>'']);
        $id=$this->stage($rows);$result=$this->bulk($id);$this->assertSame(130,$result['imported']);$this->assertDatabaseCount('historical_events',130);
        $this->assertSame('copied_fields_match',app(\App\Services\History\VerifyHistoricalImport::class)->execute($this->owner,$id)['verdict']);
    }
    public function test_customer_file_displays_archive_separately_and_escapes_notes():void {
        $id=$this->stage([$this->historyRow(['special_requests'=>'<script>alert(1)</script>','lead_source'=>'Instagram'])]);$this->bulk($id);$customer=HistoricalEvent::sole()->customer_id;
        $this->get('/admin/clientas/'.$customer)->assertOk()->assertSee('Historial HoneyBook')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertDontSee('<script>alert(1)</script>',false)->assertSee('Instagram');
    }

}
