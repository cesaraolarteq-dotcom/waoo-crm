<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalEvent,HistoryImportBatch,ImportRow,HoneyBookEventFacts,HoneyBookOriginal};
use App\Services\History\{ImportSafeHistoricalRows,PreserveHoneyBookOriginal,ReadHistoricalImport,ReadHoneyBookArchiveReport,ReadHoneyBookEventFacts,ReadHoneyBookOriginal,SaveHoneyBookEventFacts,StageHistoricalImport};
use App\Services\Imports\{CommitHoneyBookEvent,HoneyBookPaymentLabels};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Tests\Support\{HistoricalSourceFixture,HoneyBookFutureScenario};
use Tests\TestCase;
class HoneyBookArchiveAdaptersTest extends TestCase
{
    use DatabaseMigrations,HistoricalSourceFixture,HoneyBookFutureScenario;
    protected function setUp():void {parent::setUp();$this->futureScenario();config(['waoo.sandbox_history'=>true]);}
    protected function tearDown():void {
        if($this->app&&Schema::hasTable('historical_events')){
            DB::table('booking_evidence')->where('method','honeybook_import')->delete();
            DB::table('honeybook_originals')->delete();DB::table('honeybook_event_facts')->delete();DB::table('historical_events')->update(['current_revision_id'=>null]);
            foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links','history_import_rows','history_import_batches'] as $t)DB::table($t)->delete();
        }parent::tearDown();
    }
    private function archive(array $changes=[]):int {
        $source=$this->historyRow($changes);$headers=array_keys($source);$id=app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$source],$headers),(string)Str::uuid())['batch_id'];
        $r=app(ReadHistoricalImport::class)->execute($this->owner,$id);
        app(ImportSafeHistoricalRows::class)->execute($this->owner,$id,['expected_batch_version'=>$r['batch']['version'],'expected_coverage_sha256'=>$r['coverage_sha256'],'expected_plan_sha256'=>$r['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());return $id;
    }
    private function imported(){
        $batch=$this->stageFuture();$row=ImportRow::sole();return app(CommitHoneyBookEvent::class)->execute($this->owner,$batch->id,$row->id,$this->commitInput($batch,$row),(string)Str::uuid());
    }
    private function pdf():UploadedFile {return UploadedFile::fake()->createWithContent('synthetic-contract.pdf',"%PDF-1.4\nSynthetic original document for María González.\n%%EOF");}
    public function test_original_is_encrypted_hash_verified_and_never_native_signature():void {
        $this->archive();$input=['project_id'=>'fictional-history-1','kind'=>'contract','source_reference'=>'synthetic-document-1'];$key=(string)Str::uuid();
        $service=app(PreserveHoneyBookOriginal::class);$result=$service->execute($this->owner,$this->pdf(),$input,$key);
        $this->assertSame($result,$service->execute($this->owner,$this->pdf(),$input,$key));$this->assertSame($result,$service->execute($this->owner,$this->pdf(),$input,(string)Str::uuid()));
        $this->assertDatabaseCount('honeybook_originals',1);$this->assertFalse($result['native_signature_verified']);
        $read=app(ReadHoneyBookOriginal::class)->execute($this->owner,$result['id']);$this->assertSame($result['file_sha256'],hash('sha256',$read['bytes']));
        $this->assertStringNotContainsString('María',DB::table('honeybook_originals')->sole()->payload);
        $this->assertDatabaseCount('contracts',0);$this->assertDatabaseCount('ledger_entries',0);
        $response=$this->get('/admin/api/history/originals/'.$result['id'])->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringContainsString('attachment',$response->headers->get('Content-Disposition'));$this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
    }
    public function test_original_unknown_project_is_rejected_without_orphan():void {
        $this->expectException(RecordWriteRejected::class);app(PreserveHoneyBookOriginal::class)->execute($this->owner,$this->pdf(),['project_id'=>'unknown','kind'=>'invoice','source_reference'=>'synthetic'],(string)Str::uuid());
    }
    public function test_non_pdf_cannot_enter_original_archive():void {
        $this->expectException(\Illuminate\Validation\ValidationException::class);app(PreserveHoneyBookOriginal::class)->execute($this->owner,UploadedFile::fake()->createWithContent('fake.pdf','<script>not PDF</script>'),['project_id'=>'unknown','kind'=>'invoice','source_reference'=>'synthetic'],(string)Str::uuid());
    }
    public function test_original_ciphertext_corruption_is_private_refusal():void {
        $this->archive();$r=app(PreserveHoneyBookOriginal::class)->execute($this->owner,$this->pdf(),['project_id'=>'fictional-history-1','kind'=>'invoice','source_reference'=>'synthetic'],(string)Str::uuid());
        DB::table('honeybook_originals')->where('id',$r['id'])->update(['payload'=>'corrupted']);
        $this->getJson('/admin/api/history/originals/'.$r['id'])->assertStatus(409)->assertDontSee('Synthetic original');
    }
    public function test_future_questionnaire_is_source_evidence_not_a_portal_submission():void {
        $link=$this->imported();$event=\App\Models\Event::findOrFail($link->event_id);
        $input=['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['child_name'=>'Sofía','child_age'=>6,'children_count'=>15,'setup_area'=>'outdoor','event_language'=>'es','notes'=>'Actividades tranquilas','lead_source'=>'Instagram','sms_consent_honeybook'=>'yes']];
        $r=app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,$input,(string)Str::uuid());
        $read=app(ReadHoneyBookEventFacts::class)->execute($this->owner,$event->id);$this->assertSame('Sofía',$read['fields']['child_name']);$this->assertFalse($read['stale']);
        $staff=ReadHoneyBookEventFacts::forEvent($event->fresh());$this->assertSame('Sofía',$staff['fields']['child_name']);$this->assertArrayNotHasKey('sms_consent_honeybook',$staff['fields']);$this->assertArrayNotHasKey('lead_source',$staff['fields']);
        $this->assertDatabaseCount('client_details_submissions',0);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('outbound_messages',0);
        $this->assertStringNotContainsString('Sofía',DB::table('honeybook_event_facts')->sole()->payload);
        $this->assertSame('honeybook',$event->fresh()->responsible_system);
    }
    public function test_stale_event_questionnaire_version_rejects_without_overwrite():void {
        $link=$this->imported();$event=\App\Models\Event::find($link->event_id);
        $input=['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['child_name'=>'Sofía']];
        app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,$input,(string)Str::uuid());
        try{app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,[...$input,'fields'=>['child_name'=>'Elena']],(string)Str::uuid());$this->fail('Expected stale');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('honeybook_event_facts',1);
    }
    public function test_resync_invalidates_staff_source_fallback_instead_of_showing_old_data():void {
        $link=$this->imported();$event=\App\Models\Event::find($link->event_id);
        app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['child_name'=>'Sofía']],(string)Str::uuid());
        $link->current_revision_id=null;$link->save();$staff=ReadHoneyBookEventFacts::forEvent($event->fresh());$this->assertTrue($staff['stale']);$this->assertSame([],$staff['fields']);
    }
    public function test_report_keeps_balance_source_and_does_not_assume_real_six_rows():void {
        $this->archive(['paid_total'=>'60.00','balance'=>'60.00','lead_source'=>'Instagram']);
        $r=app(ReadHoneyBookArchiveReport::class)->execute($this->owner);$this->assertCount(1,$r['balances_to_review']);$this->assertSame(6000,$r['balance_total_cents']);$this->assertFalse($r['bank_verified']);
        $this->assertSame([['label'=>'Instagram','projects'=>1,'customers'=>1]],$r['lead_sources']);
        $this->get('/admin/honeybook-revision')->assertOk()->assertSee('Saldos pendientes de HoneyBook')->assertSee('Instagram');
    }
    public function test_operating_project_cannot_be_duplicated_as_history():void {
        $this->imported();$id=app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$this->historyRow(['project_id'=>'HB-FAKE-1'])]),(string)Str::uuid())['batch_id'];
        $r=app(ReadHistoricalImport::class)->execute($this->owner,$id);$this->assertSame(1,$r['bulk']['review_required']);$this->assertContains('operating_project_exists',$r['review_rows'][0]['reason_codes']);
    }
    public function test_cash_display_is_neutral_and_frozen_legacy_remains_available():void {
        $r=HoneyBookPaymentLabels::display('Cash');$this->assertSame('Zelle/efectivo (HoneyBook)',$r['display_method']);$this->assertNull($r['canonical_method']);$this->assertFalse($r['funds_verified']);
        $this->assertSame('Zelle (importado de HoneyBook, no verificado en banco)',HoneyBookPaymentLabels::display('Cash','owner-honeybook-method-labels-v1')['display_method']);
    }
    public function test_future_lead_source_and_consent_are_visible_on_customer_and_report_without_overwriting():void {
        $link=$this->imported();$event=\App\Models\Event::find($link->event_id);$before=\App\Models\Customer::find($event->customer_id)->getAttributes();
        app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['lead_source'=>'WhatsApp','sms_consent_honeybook'=>'yes']],(string)Str::uuid());
        $sources=app(\App\Services\History\ReadHoneyBookCustomerSources::class)->execute($this->owner,$event->customer_id);
        $this->assertSame(['WhatsApp'],$sources['lead_sources']);$this->assertSame('yes',$sources['sms_consent_evidence'][0]['raw_value']);
        $this->assertSame($before,\App\Models\Customer::find($event->customer_id)->getAttributes());
        $this->assertSame([['label'=>'WhatsApp','projects'=>1,'customers'=>1]],app(ReadHoneyBookArchiveReport::class)->execute($this->owner)['lead_sources']);
        $this->get('/admin/clientas/'.$event->customer_id)->assertOk()->assertSee('Cómo nos conoció')->assertSee('WhatsApp');
    }
    public function test_native_portal_fields_take_priority_over_staff_source_fallback():void {
        $link=$this->imported();$event=\App\Models\Event::find($link->event_id);
        app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['child_name'=>'Sofía','child_age'=>0]],(string)Str::uuid());
        $grant=\App\Models\PortalGrant::create(['event_id'=>$event->id,'customer_id'=>$event->customer_id,'token_hash'=>hash('sha256','synthetic-portal-token'),'scopes'=>['details.submit'],'expires_at'=>now()->addDay(),'issued_by'=>$this->owner->id]);
        \App\Models\ClientDetailsSubmission::create(['event_id'=>$event->id,'customer_id'=>$event->customer_id,'portal_grant_id'=>$grant->id,'revision'=>1,'event_version'=>$event->version,'data'=>['child_name'=>'Elena','child_age'=>0],'base_customer_snapshot'=>[],'submitted_at'=>now()]);
        $projection=ReadHoneyBookEventFacts::forEvent($event->fresh());$this->assertArrayNotHasKey('child_name',$projection['fields']);$this->assertArrayNotHasKey('child_age',$projection['fields']);
    }
    public function test_original_and_facts_reads_deny_access_when_history_disabled():void {
        $this->archive();$r=app(PreserveHoneyBookOriginal::class)->execute($this->owner,$this->pdf(),['project_id'=>'fictional-history-1','kind'=>'contract','source_reference'=>'synthetic'],(string)Str::uuid());
        config(['waoo.sandbox_history'=>false]);$this->get('/admin/api/history/originals/'.$r['id'])->assertForbidden();$this->get('/admin/honeybook-revision')->assertForbidden();
    }

    public function test_questionnaire_preserves_existing_capacity_proof_and_uses_own_version():void {
        $link=$this->imported();$event=\App\Models\Event::find($link->event_id);
        $staff=\App\Models\Resource::create(['code'=>'HB-HOST','name'=>'Carlos Hernández','kind'=>'staff','status'=>'active','skills'=>['host','driver']]);
        $speaker=\App\Models\Resource::create(['code'=>'HB-SPEAKER','name'=>'Speaker principal','kind'=>'equipment','status'=>'active','equipment_kind'=>'speaker','skills'=>[]]);
        app(\App\Services\Imports\ConfirmImportedCapacity::class)->execute($this->owner,$event->id,['expected_version'=>$event->version,'imported_revision_id'=>$link->current_revision_id,'resource_ids'=>[$staff->id,$speaker->id],
            'review'=>['staff_confirmed'=>true,'cargo_checked'=>true,'origin_return_note'=>'Salen y regresan a la bodega.','transfers'=>[]]],(string)Str::uuid());
        $event->refresh();$nativeVersion=$event->version;$revision=\App\Models\ImportedProjectRevision::find($link->current_revision_id);
        $this->assertTrue(\App\Services\Imports\ImportedAgreement::capacityMatches($event,$revision));
        app(SaveHoneyBookEventFacts::class)->execute($this->owner,$event->id,['expected_event_version'=>$event->version,'expected_facts_version'=>0,'imported_revision_id'=>$link->current_revision_id,'fields'=>['child_name'=>'Sofía']],(string)Str::uuid());
        $this->assertSame($nativeVersion,$event->fresh()->version);$this->assertTrue(\App\Services\Imports\ImportedAgreement::capacityMatches($event->fresh(),$revision));
        $this->assertSame(1,app(ReadHoneyBookEventFacts::class)->execute($this->owner,$event->id)['version']);
        DB::table('booking_evidence')->where('event_id',$event->id)->delete();
    }
    public function test_history_identity_prevents_later_operating_import():void {
        $this->archive(['project_id'=>'HB-FAKE-1']);$batch=$this->stageFuture();$row=ImportRow::sole();
        try{app(CommitHoneyBookEvent::class)->execute($this->owner,$batch->id,$row->id,$this->commitInput($batch,$row),(string)Str::uuid());$this->fail('Expected shared identity guard');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $this->assertDatabaseCount('events',0);$this->assertDatabaseCount('historical_events',1);
    }

}
