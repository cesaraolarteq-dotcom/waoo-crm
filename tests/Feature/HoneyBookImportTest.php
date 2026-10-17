<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer,ImportBatch,ImportFile,ImportPreviewVersion,ImportRow,User};
use App\Services\Imports\{DiscardHoneyBookImport,ProfileHoneyBookImport,ReadHoneyBookImport,StageHoneyBookImport};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Queue,Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ConcurrentWorkers;
use Tests\TestCase;
class HoneyBookImportTest extends TestCase
{
    use DatabaseMigrations;
    private User $owner;
    private const HEADERS=['project_id','project_status','customer_name','customer_email','event_date','start_time','duration_minutes','address','package_text','total_amount','paid_amount','balance_amount','travel_amount'];
    protected function setUp():void
    {
        parent::setUp();$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-17T12:00:00Z'));Storage::fake('local');Queue::fake();
        config(['waoo.sandbox_imports'=>true,'waoo.sandbox_finance'=>false,'waoo.sandbox_documents'=>false,'app.url'=>'https://localhost']);
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
    }
    protected function tearDown():void { if($this->app)$this->app->detectEnvironment(fn()=>'testing');parent::tearDown(); }
    private function profile(array $columns=self::HEADERS):array
    {
        return ['schema_version'=>1,'timezone'=>'America/Chicago','encoding'=>'utf-8','delimiter'=>',','date_format'=>'Y-m-d','money_format'=>'usd_dot',
            'columns'=>['projects'=>array_flip($columns)],'status_map'=>['confirmed'=>['Booked'],'cancelled'=>['Cancelled']],'payment_state_map'=>['received'=>['Paid'],'refunded'=>['Refund'],'pending'=>['Pending'],'void'=>['Void']]];
    }
    private function row(string $id='P-1'):array{return [$id,'Booked','Fictional Import Client','fixture@example.test','2026-12-20','15:00','60','123 Fictional Venue, TX 77433','Original Source Package','120.01','60.01','60.00','20.00'];}
    private function file(array $rows,?array $headers=null):UploadedFile
    {
        $stream=fopen('php://temp','w+');foreach([$headers??self::HEADERS,...$rows] as $row)fputcsv($stream,$row,',','"','');rewind($stream);$bytes=stream_get_contents($stream);fclose($stream);return UploadedFile::fake()->createWithContent('fictional-import.csv',$bytes);
    }
    private function stage(?array $rows=null,?string $key=null,?array $profile=null):ImportBatch
    {
        return app(StageHoneyBookImport::class)->execute($this->owner,['files'=>[['role'=>'projects','file'=>$this->file($rows??[$this->row()])]],'profile'=>$profile??$this->profile()],$key??(string)Str::uuid());
    }
    public function test_private_stage_exact_money_owner_metadata_and_zero_domain_side_effects():void
    {
        $b=$this->stage();$this->assertSame('prepared',$b->status);$r=ImportRow::sole();$this->assertSame('ready',$r->status);
        $this->assertSame([12001,6001,6000,2000],array_map(fn($k)=>$r->normalized[$k],['total_cents','paid_cents','balance_cents','travel_cents']));
        $this->assertSame('2026-12-20T21:00:00+00:00',$r->normalized['starts_at']);
        $f=ImportFile::sole();Storage::disk('local')->assertExists($f->private_path);
        $this->getJson('/admin/api/imports/honeybook/'.$b->id)->assertOk()->assertHeader('Cache-Control','no-store, private')->assertDontSee('private_path')->assertDontSee($f->private_path)->assertDontSee('fixture@example.test');
        $this->getJson('/admin/api/imports/honeybook/'.$b->id.'/rows')->assertOk()->assertJsonPath('data.0.normalized.total_cents',12001);
        foreach(['customers','events','financial_revisions','ledger_entries','contracts','portal_grants','resource_reservations'] as $table)$this->assertDatabaseCount($table,0);Queue::assertNothingPushed();
    }
    public function test_http_multipart_accepts_json_profile_and_never_exposes_raw_path():void
    {
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->post('/admin/api/imports/honeybook',['files'=>[['role'=>'projects','file'=>$this->file([$this->row()])]],'profile'=>json_encode($this->profile())],['Accept'=>'application/json'])
            ->assertCreated()->assertJsonPath('batch.status','prepared')->assertDontSee('private_path');
    }
    public function test_missing_unknown_and_mismatched_money_never_become_zero_or_complete():void
    {
        $a=$this->row('P-1');$a[10]='';$b=$this->row('P-2');$b[11]='1.00';$c=$this->row('P-3');$c[1]='Unknown';$d=$this->row('P-4');$d[1]='Cancelled';$this->stage([$a,$b,$c,$d]);
        $rows=ImportRow::orderBy('id')->get();$this->assertNull($rows[0]->normalized['paid_cents']);$this->assertSame('needs_mapping',$rows[0]->status);$this->assertSame('conflict',$rows[1]->status);$this->assertSame('needs_mapping',$rows[2]->status);$this->assertSame('skipped',$rows[3]->status);
        $s=ImportBatch::sole()->reconciliation['totals']['paid_cents'];$this->assertSame(1,$s['unknown_count']);$this->assertSame(3,$s['known_count']);$this->assertSame(1,$s['conflict_count']);
    }
    public function test_duplicate_source_keys_conflict_instead_of_last_row_wins():void
    {
        $this->stage([$this->row(),$this->row()]);$this->assertSame(['conflict','conflict'],ImportRow::pluck('status')->all());$this->assertDatabaseCount('import_rows',2);
        $b=$this->stage([$this->row('P-1'),$this->row('p-1')]);$this->assertSame(['ready','ready'],ImportRow::where('import_batch_id',$b->id)->pluck('status')->all());
    }
    public function test_exact_contact_hints_never_merge_even_a_same_name():void
    {
        $same=Customer::create(['name'=>'Different Name','email'=>'fixture@example.test','locale'=>'en']);Customer::create(['name'=>'Fictional Import Client','phone'=>'346-555-0199','locale'=>'es']);
        $this->stage();$h=ImportRow::sole()->duplicate_hints;$this->assertCount(1,$h);$this->assertSame($same->id,$h[0]['customer_id']);$this->assertTrue($h[0]['requires_explicit_choice']);$this->assertDatabaseCount('customers',2);$this->assertSame('Different Name',$same->fresh()->name);
    }
    public function test_same_key_and_same_content_new_key_reuse_one_active_batch_and_conflicting_intent_is_refused():void
    {
        $key=(string)Str::uuid();$a=$this->stage(key:$key);$this->assertSame($a->id,$this->stage(key:$key)->id);$this->assertSame($a->id,$this->stage()->id);$this->assertDatabaseCount('import_batches',1);$this->assertDatabaseCount('import_preview_versions',1);
        $this->expectException(RecordWriteRejected::class);$this->stage([$this->row('DIFFERENT')],$key);
    }
    public function test_profile_without_reupload_tracks_version_hash_history_and_refuses_stale_preview():void
    {
        $p=$this->profile();$p['columns']=[];$b=$this->stage(profile:$p);$hash=$b->preview_sha256;$this->assertSame('needs_review',$b->status);$this->assertDatabaseCount('import_files',1);
        $input=['expected_version'=>1,'expected_preview_sha256'=>$hash,'profile'=>$this->profile()];$key=(string)Str::uuid();
        $new=app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key);$this->assertSame(2,$new->version);$this->assertSame('prepared',$new->status);$this->assertNotSame($hash,$new->preview_sha256);$this->assertDatabaseCount('import_preview_versions',2);
        $this->assertSame(2,app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,$input,$key)->version);$this->assertDatabaseCount('import_files',1);
        $this->expectException(RecordWriteRejected::class);app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,$input,(string)Str::uuid());
    }
    public function test_pagination_reaches_all_rows_and_normalizes_large_initial_pages():void
    {
        $b=$this->stage(array_map(fn($n)=>$this->row('P-'.$n),range(1,51)));
        $this->getJson('/admin/api/imports/honeybook/'.$b->id.'/rows?page=999&per_page=25')->assertOk()->assertJsonPath('current_page',3)->assertJsonCount(1,'data')->assertJsonPath('data.0.source.project_id','P-51');
        $this->getJson('/admin/api/imports/honeybook/'.$b->id.'/rows?status=conflict&page=999')->assertOk()->assertJsonPath('current_page',1)->assertJsonCount(0,'data');
    }
    public function test_discard_purges_payload_and_source_and_retry_cannot_restore_it():void
    {
        $stageKey=(string)Str::uuid();$b=$this->stage(key:$stageKey);$path=ImportFile::sole()->private_path;$key=(string)Str::uuid();
        $discard=app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],$key);$this->assertSame('discarded',$discard->status);Storage::disk('local')->assertMissing($path);$this->assertDatabaseCount('import_rows',0);$this->assertNull(ImportFile::sole()->original_name);$this->assertSame([],$discard->profile);
        $this->assertSame('discarded',$this->stage(key:$stageKey)->status);$this->assertDatabaseCount('import_batches',1);
        $this->assertSame('discarded',app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],$key)->status);
        $this->getJson('/admin/api/imports/honeybook/'.$b->id.'/rows')->assertStatus(410);
    }
    public function test_discard_cleanup_failure_remains_explicit_and_retries_same_key():void
    {
        $b=$this->stage();$key=(string)Str::uuid();$path=ImportFile::sole()->private_path;$store=Storage::disk('local');
        $manager=Storage::getFacadeRoot();Storage::shouldReceive('disk->exists')->andReturn(true);Storage::shouldReceive('disk->delete')->once()->andReturn(false);
        $pending=app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],$key);$this->assertSame('cleanup_pending',$pending->status);$this->assertDatabaseCount('import_rows',0);$this->assertTrue($store->exists($path));
        Storage::swap($manager);
        $this->assertSame('discarded',app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],$key)->status);$this->assertFalse($store->exists($path));
    }
    public function test_late_audit_and_private_write_failures_publish_no_partial_batch():void
    {
        $fail=true;DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_contains($sql,'audit_entries')&&in_array('import.staged',$bindings,true))throw new \RuntimeException('Fixture audit failure');});
        try{$this->stage();$this->fail('Expected audit failure');}catch(\RuntimeException $e){$this->assertSame('Fixture audit failure',$e->getMessage());}finally{$fail=false;}
        foreach(['import_batches','import_rows','import_files','import_preview_versions','mutation_receipts'] as $t)$this->assertDatabaseCount($t,0);
        Storage::shouldReceive('disk->put')->once()->andReturn(false);
        try{$this->stage();$this->fail('Expected private write failure');}catch(\RuntimeException $e){$this->assertSame('Private import artifact unavailable.',$e->getMessage());}
        $this->assertDatabaseCount('import_batches',0);
    }
    public function test_corrupted_private_bytes_cannot_be_reprofiled():void
    {
        $b=$this->stage();Storage::disk('local')->put(ImportFile::sole()->private_path,'corrupted');
        $this->expectException(\RuntimeException::class);app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1,'expected_preview_sha256'=>$b->preview_sha256,'profile'=>$this->profile()],(string)Str::uuid());
    }
    public function test_gate_fresh_actor_production_and_fresh_two_factor_are_required():void
    {
        DB::table('users')->where('id',$this->owner->id)->update(['role'=>'staff']);$this->expectException(AuthorizationException::class);$this->stage();
    }
    public function test_import_feature_off_blocks_even_authenticated_http_read():void
    {
        config(['waoo.sandbox_imports'=>false]);$this->getJson('/admin/api/imports/honeybook')->assertForbidden()->assertHeader('Cache-Control','no-store, private');
    }
    public function test_real_two_owner_staging_race_returns_one_batch_without_duplicate_evidence():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $other=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $worker=fn($owner)=>function()use($owner){return app(StageHoneyBookImport::class)->execute($owner,['files'=>[['role'=>'projects','file'=>$this->file([$this->row()])]],'profile'=>$this->profile()],(string)Str::uuid())->id;};
        $results=ConcurrentWorkers::run([$worker($this->owner),$worker($other)]);$this->assertSame($results[0],$results[1]);$this->assertDatabaseCount('import_batches',1);$this->assertDatabaseCount('import_rows',1);$this->assertDatabaseCount('import_preview_versions',1);
    }
    public function test_production_and_unconfirmed_two_factor_refuse_staging():void
    {
        DB::table('users')->where('id',$this->owner->id)->update(['two_factor_confirmed_at'=>null]);
        try{$this->stage();$this->fail('Expected fresh 2FA denial');}catch(AuthorizationException){}
        DB::table('users')->where('id',$this->owner->id)->update(['two_factor_confirmed_at'=>now()]);$this->app['env']='production';
        try{$this->stage();$this->fail('Expected production denial');}catch(AuthorizationException){}
        $this->assertDatabaseCount('import_batches',0);
    }
    public function test_staging_requires_actual_csrf_https_and_secure_cookie():void
    {
        $this->app['env']='staging';config(['session.secure'=>true]);
        $this->getJson('/admin/api/imports/honeybook')->assertForbidden();
        $this->getJson('https://localhost/admin/api/imports/honeybook')->assertOk();
        $this->postJson('https://localhost/admin/api/imports/honeybook',[])->assertStatus(419)->assertHeader('Cache-Control','no-store, private');
        $token=Str::random(40);$this->withSession(['_token'=>$token])->withHeader('X-CSRF-TOKEN',$token)->withHeader('Idempotency-Key',(string)Str::uuid())
            ->post('https://localhost/admin/api/imports/honeybook',['files'=>[['role'=>'projects','file'=>$this->file([$this->row()])]],'profile'=>json_encode($this->profile())],['Accept'=>'application/json'])->assertCreated();
        config(['session.secure'=>false]);$this->getJson('https://localhost/admin/api/imports/honeybook')->assertForbidden();
    }

    public function test_late_profile_and_discard_audit_failures_keep_original_preview_and_private_source():void
    {
        $b=$this->stage();$before=ImportRow::sole()->getAttributes();$path=ImportFile::sole()->private_path;$action='import.profiled';
        DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$action){if($action&&str_contains($sql,'audit_entries')&&in_array($action,$bindings,true))throw new \RuntimeException('Fixture mutation audit failure');});
        foreach(['import.profiled','import.discard_requested'] as $next){$action=$next;
            try{if($next==='import.profiled')app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1,'expected_preview_sha256'=>$b->preview_sha256,'profile'=>$this->profile()],(string)Str::uuid());
                else app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],(string)Str::uuid());$this->fail('Expected audit rollback');}
            catch(\RuntimeException $e){$this->assertSame('Fixture mutation audit failure',$e->getMessage());}finally{$action='';}
            $this->assertSame(1,$b->fresh()->version);$this->assertSame($before,ImportRow::sole()->getAttributes());Storage::disk('local')->assertExists($path);$this->assertDatabaseCount('import_preview_versions',1);
        }
    }
    public function test_actual_profile_vs_discard_race_cannot_restore_purged_source():void
    {
        if(DB::connection()->getDriverName()!=='mysql'||!function_exists('pcntl_fork'))$this->markTestSkipped('MariaDB and pcntl required');
        $b=$this->stage();$input=['expected_version'=>1,'expected_preview_sha256'=>$b->preview_sha256,'profile'=>$this->profile()];
        $profile=function()use($b,$input){try{app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,$input,(string)Str::uuid());return 'profiled';}catch(RecordWriteRejected $e){return $e->status;}};
        $discard=function()use($b){try{return app(DiscardHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1],(string)Str::uuid())->status;}catch(RecordWriteRejected $e){return $e->status;}};
        $results=ConcurrentWorkers::run([$profile,$discard]);$this->assertContains(409,$results);$this->assertTrue(in_array('profiled',$results,true)||in_array('discarded',$results,true));
        $final=$b->fresh();$this->assertSame($final->status==='discarded'?0:1,ImportRow::count());
        $this->assertSame($final->status!=='discarded',Storage::disk('local')->exists(ImportFile::sole()->private_path));
    }

    public function test_historical_upload_retry_retains_original_preview_reference_separate_from_current_metadata():void
    {
        $key=(string)Str::uuid();$b=$this->stage(key:$key);$original=$b->mutation_result;
        $p=$this->profile();$p['columns']=[];
        app(ProfileHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>1,'expected_preview_sha256'=>$b->preview_sha256,'profile'=>$p],(string)Str::uuid());
        $retry=$this->stage(key:$key);$this->assertSame(2,$retry->version);$this->assertSame(1,$retry->mutation_result['preview_version']);
        $this->assertSame($original['preview_sha256'],$retry->mutation_result['preview_sha256']);$this->assertNotSame($retry->preview_sha256,$retry->mutation_result['preview_sha256']);$this->assertTrue($retry->mutation_result['historical']);
        $this->assertDatabaseCount('import_batches',1);$this->assertDatabaseCount('import_preview_versions',2);
    }

    public function test_unused_csv_columns_and_safe_parse_errors_do_not_leak_private_cells():void
    {
        $headers=[...self::HEADERS,'unused_sensitive'];$row=[...$this->row(),'UNUSED_PRIVATE_CELL_999'];
        $b=app(StageHoneyBookImport::class)->execute($this->owner,['files'=>[['role'=>'projects','file'=>$this->file([$row],$headers)]],'profile'=>$this->profile()],(string)Str::uuid());
        $this->getJson('/admin/api/imports/honeybook/'.$b->id.'/rows')->assertOk()->assertDontSee('UNUSED_PRIVATE_CELL_999');
        $this->assertStringNotContainsString('UNUSED_PRIVATE_CELL_999',json_encode(DB::table('audit_entries')->get()));
        $broken=UploadedFile::fake()->createWithContent('fictional.csv',"header\n\"PRIVATE_BAD_CELL_555");
        $this->withHeader('Idempotency-Key',(string)Str::uuid())->post('/admin/api/imports/honeybook',['files'=>[['role'=>'projects','file'=>$broken]],'profile'=>json_encode($this->profile())],['Accept'=>'application/json'])->assertUnprocessable()->assertDontSee('PRIVATE_BAD_CELL_555');
    }
    public function test_paths_urls_and_duplicate_upload_roles_are_not_accepted():void
    {
        foreach(['https://example.test/private.csv','/tmp/private.csv'] as $file){try{app(StageHoneyBookImport::class)->execute($this->owner,['files'=>[['role'=>'projects','file'=>$file]],'profile'=>$this->profile()],(string)Str::uuid());$this->fail('Expected non-upload rejection');}catch(ValidationException){}}
        $this->assertDatabaseCount('import_batches',0);
    }

    public function test_database_failure_discards_private_sql_binding_and_exception_chain():void
    {
        $fail=true;DB::connection()->beforeExecuting(function(string $sql,array $bindings)use(&$fail){if($fail&&str_starts_with($sql,'insert into `import_rows`'))throw new \Illuminate\Database\QueryException('mysql',$sql,['PRIVATE_SQL_CELL_123'],new \PDOException('Fixture DB error PRIVATE_SQL_CELL_123'));});
        try{$this->stage();$this->fail('Expected safe persistence failure');}catch(\RuntimeException $e){$this->assertSame('Private import persistence unavailable.',$e->getMessage());$this->assertNull($e->getPrevious());$this->assertStringNotContainsString('PRIVATE_SQL_CELL_123',(string)$e);}finally{$fail=false;}
        $this->assertDatabaseCount('import_batches',0);$this->assertDatabaseCount('import_rows',0);$this->assertDatabaseCount('mutation_receipts',0);
    }

}
