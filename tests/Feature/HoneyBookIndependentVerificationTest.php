<?php
namespace Tests\Feature;

use App\Models\{Customer,HistoricalCustomerRelink,HistoricalEvent,HistoricalEventRevision,HoneyBookOriginal,User};
use App\Services\History\{HistoricalCustomerMatch,HistoricalIntegrity,ImportSafeHistoricalRows,PreserveHoneyBookOriginal,ReadHistoricalImport,ReassignHistoricalCustomer,StageHistoricalImport};
use App\Services\MigrationVerification\{PrivateMigrationAct,RawHoneyBookSnapshot,VerifyHoneyBookMigration};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan,DB,Storage};
use Illuminate\Support\Str;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

class HoneyBookIndependentVerificationTest extends TestCase
{
    use RefreshDatabase,HistoricalSourceFixture;
    private User $owner;
    private array $directories=[];
    protected function setUp(): void {
        parent::setUp();config(['waoo.sandbox_history'=>true,'waoo.sandbox_history_verification'=>true]);Storage::fake('local');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
    }
    protected function tearDown(): void {
        foreach($this->directories as $dir){foreach(glob($dir.'/*') as $file)if(is_file($file)||is_link($file))unlink($file);rmdir($dir);}parent::tearDown();
    }
    private function archive(array $changes=[]): HistoricalEvent {
        $row=$this->historyRow(['project_status'=>'completed','event_type'=>'birthday',...$changes]);
        $id=app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$row],array_keys($row)),(string)Str::uuid())['batch_id'];
        $r=app(ReadHistoricalImport::class)->execute($this->owner,$id);
        app(ImportSafeHistoricalRows::class)->execute($this->owner,$id,['expected_batch_version'=>$r['batch']['version'],'expected_coverage_sha256'=>$r['coverage_sha256'],'expected_plan_sha256'=>$r['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());
        return HistoricalEvent::sole();
    }
    private function directory(): string {$dir=sys_get_temp_dir().'/hb-independent-'.Str::uuid();mkdir($dir,0700);$this->directories[]=$dir;return $dir;}
    private function snapshot(array $changes=[]): string {
        $dir=$this->directory();$s=$this->historyRow();
        $project=['id'=>$s['project_id'],'title'=>$s['project_name'],'event_date'=>$s['event_date'],'postal_code'=>$s['postal_code'],'event_type'=>'birthday','project_status'=>'completed','city'=>$s['city'],'currency'=>'USD','contact_id'=>$s['contact_id'],'total'=>'120.00',...$changes];
        $files=['projects.json'=>['projects'=>[$project],'has_more'=>false],
            'contacts-pipeline-files.json'=>['contacts'=>[['id'=>$s['contact_id'],'name'=>$s['client_name'],'email'=>$s['client_email'],'phone'=>$s['client_phone']]],'has_more'=>false],
            'workspaces.json'=>['workspaces'=>[],'files'=>[],'has_more'=>false],
            'file-responses.json'=>['responses'=>[],'has_more'=>false],'originals.json'=>[]];
        foreach($files as $file=>$value)file_put_contents($dir.'/'.$file,json_encode($value,JSON_THROW_ON_ERROR));return $dir;
    }
    private function verify(string $dir): array {return app(VerifyHoneyBookMigration::class)->execute(app(RawHoneyBookSnapshot::class)->read($dir),$dir);}
    private function input(HistoricalEvent $h,Customer $target): array {
        $r=HistoricalEventRevision::findOrFail($h->current_revision_id);
        return ['expected_history_version'=>$h->version,'expected_revision_id'=>$r->id,'expected_source_sha256'=>$r->source_sha256,
            'owner_reason'=>'La dueña revisó el contacto original y confirmó la identidad correcta.',
            'target'=>['mode'=>'existing','id'=>$target->id,'expected_version'=>$target->fresh()->version]];
    }
    private function flatSnapshot(): string {
        $d=\Tests\Unit\FlatHoneyBookCaptureTest::capture();$source=$this->historyRow();
        $d=json_decode(str_replace(['project-a','contact-a','maria@example.test'],[$source['project_id'],$source['contact_id'],$source['client_email']],json_encode($d,JSON_UNESCAPED_UNICODE)),true);
        $d['projects.json'][0]['name']=$source['project_name'];$d['projects.json'][0]['project_date']=$source['event_date'];
        $dir=$this->directory();foreach($d as $file=>$value)file_put_contents($dir.'/'.$file,json_encode($value,JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/originals.json','[]');return $dir;
    }
    public function test_real_capture_references_are_not_duplicate_projects_and_booked_status_is_semantic(): void {
        $this->archive(['start_time'=>'15:00','end_time'=>'17:00','paid_total'=>'50.00','balance'=>'70.00','contract_signed'=>'true','line_items'=>'Celebración|120.00','payments'=>'2025-07-01|50.00|Cash']);
        $dir=$this->flatSnapshot();$a=$this->verify($dir);$fields=$a['projects'][0]['fields'];
        $this->assertSame(1,$a['summary']['source_projects']);$this->assertSame(0,$a['summary']['duplicate_source_projects']);
        $this->assertNotContains('ambiguous_source_field',array_column($a['issues'],'code'));
        foreach(['project_status','source_snapshot.project_status','start_time','end_time','total_cents','paid_cents','balance_cents','customer_name','customer_email','source_contact_id','contract_signed','payments','line_items'] as $key)$this->assertSame('matched',$fields[$key]['status'],$key.': '.json_encode($fields[$key]));
        $this->assertSame('client',$fields['project_status']['source']);$this->assertSame('completed',$fields['project_status']['crm']);
        $this->assertSame('review_required',$a['verdict']); // Unmapped raw metadata never disappears.
        $this->assertFalse($a['human_double_review_complete']);
        $p=json_decode(file_get_contents($dir.'/file-responses.json'),true);$p['file-a']['payments']['amount_paid']['amount']=5100;file_put_contents($dir.'/file-responses.json',json_encode($p));
        $b=$this->verify($dir);$this->assertSame('different',$b['projects'][0]['fields']['paid_cents']['status']);
    }
    public function test_missing_date_cannot_verify_temporal_status_and_duplicate_authority_still_fails(): void {
        $this->archive();$dir=$this->flatSnapshot();$p=json_decode(file_get_contents($dir.'/projects.json'),true);$p[0]['project_date']=null;file_put_contents($dir.'/projects.json',json_encode($p));
        $a=$this->verify($dir);$field=$a['projects'][0]['fields']['project_status'];
        $this->assertSame('matched',$field['status']);$this->assertSame('unverified',$field['temporal_classification']);
        $this->assertSame('source_date_absent_for_status_classification',$field['reason']);
        $p[]=$p[0];file_put_contents($dir.'/projects.json',json_encode($p));
        $this->assertSame(1,$this->verify($dir)['summary']['duplicate_source_projects']);
    }
    public function test_workspace_reference_metadata_cannot_override_project_creation_or_name(): void {
        $this->archive();$dir=$this->flatSnapshot();$a=$this->verify($dir);$fields=$a['projects'][0]['fields'];
        $this->assertSame('matched',$fields['project_name']['status']);$this->assertSame('2025-06-01',$fields['created_at']['source']);
        $w=json_decode(file_get_contents($dir.'/workspaces.json'),true);$w['workspaces'][0]['status']='unknown_status';file_put_contents($dir.'/workspaces.json',json_encode($w));
        $a=$this->verify($dir);$this->assertSame('unverified',$a['projects'][0]['fields']['project_status']['status']);
        $this->assertSame('unsupported_source_status',$a['projects'][0]['fields']['project_status']['reason']);
    }
    public function test_extra_fields_in_recognized_cent_objects_and_questions_are_still_private_unverified(): void {
        $this->archive();$dir=$this->flatSnapshot();$f=json_decode(file_get_contents($dir.'/file-responses.json'),true);
        $f['file-a']['payments']['total']['new_source_metadata']='Información adicional';
        $f['file-a']['questionnaire']=['questions'=>[['question'=>'<p>Una pregunta desconocida</p>','answer'=>'Dato privado']]];
        file_put_contents($dir.'/file-responses.json',json_encode($f));$a=$this->verify($dir);
        $this->assertSame('matched',$a['projects'][0]['fields']['total_cents']['status']);
        $raw=array_values(array_filter($a['unverified_source'],fn($r)=>$r['kind']==='responses'))[0]['fields'];
        $paths=array_column($raw,'path');$this->assertContains('payments/total/new_source_metadata',$paths);
        $this->assertContains('questionnaire/questions/0/answer',$paths);
        $this->assertNotContains('payments/total/amount',$paths);$this->assertSame('review_required',$a['verdict']);
        $this->assertSame($a['summary']['unverified_fields'],$a['summary']['unverified_comparisons']+$a['summary']['unverified_raw_fields']);
    }
    public function test_date_classification_uses_revision_date_not_the_day_the_verifier_is_rerun(): void {
        $this->archive();$dir=$this->flatSnapshot();$w=json_decode(file_get_contents($dir.'/workspaces.json'),true);$w['workspaces'][0]['status']='lead_sent';file_put_contents($dir.'/workspaces.json',json_encode($w));
        $this->assertSame('different',$this->verify($dir)['projects'][0]['fields']['project_status']['status']);
        $w['workspaces'][0]['status']='client';file_put_contents($dir.'/workspaces.json',json_encode($w));
        $p=json_decode(file_get_contents($dir.'/projects.json'),true);$p[0]['project_date']='2026-10-22';file_put_contents($dir.'/projects.json',json_encode($p));
        $this->travelTo(\Carbon\CarbonImmutable::parse('2027-10-22T16:00:00Z'));$a=$this->verify($dir);
        $this->assertSame('different',$a['projects'][0]['fields']['project_status']['status']);
        $this->assertSame('2026-10-21',$a['projects'][0]['status_classification_date']);
    }
    public function test_honeybook_sms_boolean_is_only_origin_evidence_and_unknown_text_is_not_guessed(): void {
        $this->archive(['sms_consent_honeybook'=>'false']);$dir=$this->flatSnapshot();$c=json_decode(file_get_contents($dir.'/contacts-pipeline-files.json'),true);
        $c['contacts'][0]['sms_consent_confirmed']=false;file_put_contents($dir.'/contacts-pipeline-files.json',json_encode($c));$a=$this->verify($dir);
        $this->assertSame('matched',$a['projects'][0]['fields']['sms_consent_honeybook']['status']);
        $this->assertSame('matched',$a['projects'][0]['fields']['source_snapshot.sms_consent_honeybook']['status']);
        $c['contacts'][0]['sms_consent_confirmed']='Unknown new policy';file_put_contents($dir.'/contacts-pipeline-files.json',json_encode($c));$a=$this->verify($dir);
        $this->assertSame('unverified',$a['projects'][0]['fields']['sms_consent_honeybook']['status']);
        $this->assertSame('unsupported_origin_consent_vocabulary',$a['projects'][0]['fields']['sms_consent_honeybook']['reason']);
    }
    public function test_independent_source_fields_and_reverse_coverage_detect_actual_errors(): void {
        $h=$this->archive();$dir=$this->snapshot();$a=$this->verify($dir);
        $this->assertSame('verified',$a['verdict'],json_encode($a['issues']));$this->assertSame(1,$a['summary']['source_projects']);
        $this->assertSame(0,$a['summary']['different_fields']);
        Customer::findOrFail($h->customer_id)->update(['name'=>'Ana Martínez']);
        $a=$this->verify($dir);$this->assertSame('different',$a['projects'][0]['fields']['customer_name']['status']);
        $dir2=$this->snapshot(['id'=>'another-source-project']);$a=$this->verify($dir2);
        $this->assertSame(1,$a['summary']['missing_projects']);$this->assertSame(1,$a['summary']['extra_projects']);$this->assertSame('review_required',$a['verdict']);
    }
    public function test_raw_payment_and_line_item_values_are_independently_compared(): void {
        $this->archive(['paid_total'=>'50.00','balance'=>'70.00','contract_signed'=>'true','line_items'=>'Celebración|120.00','payments'=>'2025-07-10|50.00|Cash']);
        $dir=$this->snapshot(['paid_total'=>'50.00','balance'=>'70.00','contract_signed'=>true,'line_items'=>[['title'=>'Celebración','amount'=>'120.00']],
            'payments'=>[['date'=>'2025-07-10','amount'=>'50.00','method'=>'Cash']]]);
        $a=$this->verify($dir);$this->assertSame('verified',$a['verdict'],json_encode($a['issues']));
        $this->assertSame('matched',$a['projects'][0]['fields']['payments']['status']);
        $p=json_decode(file_get_contents($dir.'/projects.json'),true);$p['projects'][0]['payments'][0]['amount']='51.00';file_put_contents($dir.'/projects.json',json_encode($p));
        $a=$this->verify($dir);$this->assertSame('different',$a['projects'][0]['fields']['payments']['status']);
    }
    public function test_explicit_cent_units_are_verified_without_hiding_extra_money_metadata(): void {
        $this->archive();$dir=$this->snapshot(['total'=>['amount_cents'=>12000,'currency'=>'USD']]);
        $this->assertSame('verified',$this->verify($dir)['verdict']);
        $p=json_decode(file_get_contents($dir.'/projects.json'),true);$p['projects'][0]['total']['extra_source_field']='Información adicional';file_put_contents($dir.'/projects.json',json_encode($p));
        $a=$this->verify($dir);$this->assertSame('review_required',$a['verdict']);
        $this->assertSame('total/extra_source_field',$a['projects'][0]['unverified_source_fields'][0]['path']);
    }
    public function test_wrong_global_binding_is_detected_even_if_customer_text_is_identical(): void {
        $this->archive();$duplicate=Customer::create(['name'=>'María González','email'=>'history@example.test','phone'=>'+12025550123','locale'=>'es']);
        DB::table('external_customer_links')->update(['customer_id'=>$duplicate->id]);
        $a=$this->verify($this->snapshot());$this->assertSame('review_required',$a['verdict']);
        $this->assertSame('contact_binding_integrity',$a['projects'][0]['fields']['customer_binding']['reason']);
    }
    public function test_owner_can_create_a_new_target_and_old_import_receipt_remains_valid(): void {
        $h=$this->archive();$oldCustomer=$h->customer_id;$decision=\App\Models\HistoryImportDecision::sole();$payload=$decision->payload;
        $receipt=DB::table('mutation_receipts')->where('result_type','history_import_decision')->where('result_id',(string)$decision->id)->first();
        $input=$this->input($h,Customer::findOrFail($oldCustomer));$input['target']=['mode'=>'new','name'=>'María González','email'=>'maria.correcta@example.test','locale'=>'es'];
        $result=app(ReassignHistoricalCustomer::class)->execute($this->owner,$h->id,$input,(string)Str::uuid());
        $this->assertNotSame($oldCustomer,$result['customer_id']);$this->assertDatabaseCount('customers',2);
        $replay=app(\App\Services\History\CommitHistoricalRow::class)->execute($this->owner,$decision->history_import_batch_id,$decision->staged_row_id,$payload['input'],$receipt->request_key);
        $this->assertSame($payload['result'],$replay);$this->assertSame($result['customer_id'],$h->fresh()->customer_id);
        try{(require database_path('migrations/2026_10_21_340000_create_historical_customer_relinks.php'))->down();$this->fail('Guard must preserve relink evidence');}
        catch(\RuntimeException $e){$this->assertStringContainsString('forward migration',$e->getMessage());}
    }
    public function test_non_owner_or_unconfirmed_actor_cannot_reassign(): void {
        $h=$this->archive();$target=Customer::create(['name'=>'Ana Martínez','locale'=>'es']);
        foreach([['role'=>'staff'],['active'=>false],['two_factor_confirmed_at'=>null]] as $change){
            $actor=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now(),...$change]);
            try{app(ReassignHistoricalCustomer::class)->execute($actor,$h->id,$this->input($h,$target),(string)Str::uuid());$this->fail('Expected denied actor');}
            catch(\Illuminate\Auth\Access\AuthorizationException){$this->assertDatabaseCount('historical_customer_relinks',0);}
        }
    }
    public function test_unknown_source_fields_incomplete_pages_and_no_source_bytes_never_get_a_green_act(): void {
        $this->archive();$dir=$this->snapshot(['new_useful_field'=>'Información privada adicional']);
        unlink($dir.'/originals.json');$p=json_decode(file_get_contents($dir.'/projects.json'),true);$p['has_more']=true;file_put_contents($dir.'/projects.json',json_encode($p));
        $a=$this->verify($dir);$this->assertSame('review_required',$a['verdict']);$this->assertGreaterThan(0,$a['summary']['unverified_fields']);
        $this->assertContains('independent_original_bytes_not_supplied',array_column($a['issues'],'code'));
        $this->assertContains('pagination_completeness_unverified',array_column($a['issues'],'code'));
        $this->assertSame('Información privada adicional',$a['projects'][0]['unverified_source_fields'][0]['value']);
    }
    public function test_real_source_pdf_bytes_are_compared_and_tampering_is_detected_independently(): void {
        $this->archive();$dir=$this->snapshot();$pdf="%PDF-1.4\nSynthetic María González contract\n%%EOF";
        app(PreserveHoneyBookOriginal::class)->execute($this->owner,\Illuminate\Http\UploadedFile::fake()->createWithContent('contract.pdf',$pdf),
            ['project_id'=>'fictional-history-1','kind'=>'contract','source_reference'=>'file-1'],(string)Str::uuid());
        file_put_contents($dir.'/source.pdf',$pdf);
        file_put_contents($dir.'/originals.json',json_encode([['project_id'=>'fictional-history-1','kind'=>'contract','source_reference'=>'file-1','path'=>'source.pdf','sha256'=>hash('sha256',$pdf)]]));
        file_put_contents($dir.'/workspaces.json',json_encode(['workspaces'=>[],'files'=>[['id'=>'file-1','project_id'=>'fictional-history-1','kind'=>'contract']],'has_more'=>false]));
        $a=$this->verify($dir);$this->assertSame(1,$a['summary']['originals']['source_pdf_matched']);$this->assertSame('verified',$a['verdict'],json_encode($a['issues']));
        $record=HoneyBookOriginal::sole();$p=$record->payload;$p['bytes_base64']=base64_encode($pdf.'changed');
        DB::table('honeybook_originals')->where('id',$record->id)->update(['payload'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($p))]);
        $a=$this->verify($dir);$this->assertSame(0,$a['summary']['originals']['database_hash_valid']);$this->assertSame('review_required',$a['verdict']);
    }
    public function test_command_prints_aggregates_only_creates_private_act_and_never_overwrites_it(): void {
        $this->archive();$dir=$this->snapshot();$output=$this->directory().'/act.json';
        $before=DB::table('audit_entries')->count();
        $code=Artisan::call('waoo:honeybook:verify',['--snapshot'=>$dir,'--report'=>$output,'--owner'=>$this->owner->id]);
        $console=Artisan::output();$this->assertSame(0,$code,$console);
        foreach(['María','history@example.test','fictional-history-1','=UNTRUSTED'] as $private)$this->assertStringNotContainsString($private,$console);
        $this->assertSame(0600,fileperms($output)&0777);$bytes=file_get_contents($output);
        $this->assertSame('verified',json_decode($bytes,true)['verdict']);$this->assertSame($before,DB::table('audit_entries')->count());
        $this->assertSame(1,Artisan::call('waoo:honeybook:verify',['--snapshot'=>$dir,'--report'=>$output,'--owner'=>$this->owner->id]));
        $this->assertSame($bytes,file_get_contents($output));
        $this->expectException(\RuntimeException::class);app(PrivateMigrationAct::class)->write(base_path('unsafe-act.json'),[]);
    }
    public function test_phone_only_with_different_or_missing_name_requires_owner_review(): void {
        Customer::create(['name'=>'Ana Martínez','phone'=>'+12025550123','locale'=>'es']);$source=$this->historyRow(['client_email'=>'']);
        $match=HistoricalCustomerMatch::read($source);$this->assertSame('phone',$match['tier']);$this->assertSame('conflicting',$match['state']);
        $source['client_name']='  ANA   MARTÍNEZ ';$this->assertSame('suggested',HistoricalCustomerMatch::read($source)['state']);
        $source['client_name']='';$this->assertSame('conflicting',HistoricalCustomerMatch::read($source)['state']);
    }
    public function test_owner_relink_keeps_old_evidence_and_retries_exactly_without_overwriting_customers(): void {
        $h=$this->archive();$old=HistoricalEventRevision::sole();$payload=$old->payload;
        $target=Customer::create(['name'=>'María González','email'=>'maria@example.test','locale'=>'es']);$input=$this->input($h,$target);$key=(string)Str::uuid();
        $service=app(ReassignHistoricalCustomer::class);$result=$service->execute($this->owner,$h->id,$input,$key);
        $this->assertSame($result,$service->execute($this->owner,$h->id,$input,$key));
        $this->assertSame($payload,$old->fresh()->payload);$this->assertSame($target->id,$h->fresh()->customer_id);$this->assertDatabaseCount('historical_event_revisions',2);
        $this->assertSame($payload,HistoricalIntegrity::revision($h->fresh(),$old,false));
        $this->assertSame('maria@example.test',$target->fresh()->email);$this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('events',0);
        $this->assertStringNotContainsString('dueña revisó',DB::table('audit_entries')->get()->toJson());
        $match=HistoricalCustomerMatch::read($this->historyRow());$this->assertSame('project_correction',$match['tier']);$this->assertSame($target->id,$match['linked_customer_id']);
        $this->assertSame('conflicting',HistoricalCustomerMatch::read($this->historyRow(['project_id'=>'new-project']))['state']);
        $this->expectException(\LogicException::class);HistoricalCustomerRelink::sole()->delete();
    }
    public function test_new_customer_and_relink_roll_back_together_on_audit_failure_and_stale_version_is_denied(): void {
        $h=$this->archive();$target=Customer::findOrFail($h->customer_id);$input=$this->input($h,$target);
        $input['expected_history_version']=99;
        try{app(ReassignHistoricalCustomer::class)->execute($this->owner,$h->id,$input,(string)Str::uuid());$this->fail('Expected stale conflict');}catch(\App\Exceptions\RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $input=$this->input($h,$target);$input['target']=['mode'=>'new','name'=>'María González','email'=>'correcta@example.test','locale'=>'es'];
        DB::listen(function($query){if(str_contains($query->sql,'insert into `audit_entries`')&&in_array('history.customer_reassigned',$query->bindings,true))throw new \RuntimeException('Synthetic audit failure');});
        try{app(ReassignHistoricalCustomer::class)->execute($this->owner,$h->id,$input,(string)Str::uuid());$this->fail('Expected audit failure');}catch(\RuntimeException $e){$this->assertSame('Synthetic audit failure',$e->getMessage());}
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('historical_event_revisions',1);$this->assertDatabaseCount('historical_customer_relinks',0);
    }
    public function test_relink_ui_pins_source_and_owner_permissions_are_rechecked(): void {
        $h=$this->archive();$target=Customer::create(['name'=>'María González','locale'=>'es']);
        \Livewire\Livewire::test(\App\Filament\Resources\Customers\Pages\ViewCustomer::class,['record'=>$h->customer_id])
            ->call('startHistoryRelink',$h->id)->set('relinkCustomerId',(string)$target->id)->set('relinkReason','La dueña confirmó la identidad con el contacto original.')
            ->call('saveHistoryRelink')->assertRedirect(\App\Filament\Resources\Customers\CustomerResource::getUrl('view',['record'=>$target->id]));
        config(['waoo.sandbox_history_verification'=>false]);
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ReassignHistoricalCustomer::class)->execute($this->owner,$h->id,$this->input($h->fresh(),$target),(string)Str::uuid());
    }
}
