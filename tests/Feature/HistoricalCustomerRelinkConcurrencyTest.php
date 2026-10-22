<?php
namespace Tests\Feature;
use App\Models\{Customer,HistoricalEvent,HistoricalEventRevision,User};
use App\Services\History\{StageHistoricalImport,ReadHistoricalImport,ImportSafeHistoricalRows,ReassignHistoricalCustomer};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\Support\{HistoricalSourceFixture,ConcurrentWorkers};
use Tests\TestCase;
class HistoricalCustomerRelinkConcurrencyTest extends TestCase
{
    use DatabaseMigrations,HistoricalSourceFixture;
    protected function tearDown():void {
        DB::table('historical_customer_relinks')->delete();DB::table('historical_events')->update(['current_revision_id'=>null]);
        foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links','history_import_rows','history_import_batches'] as $t)DB::table($t)->delete();
        parent::tearDown();
    }
    public function test_two_real_owners_cannot_move_the_same_revision_twice():void {
        if(DB::connection()->getDriverName()!=='mysql'){$this->markTestSkipped('Real row-lock concurrency requires MariaDB.');}
        config(['waoo.sandbox_history'=>true,'waoo.sandbox_history_verification'=>true]);Storage::fake('local');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        $owners=[];foreach(['César Olarte','Maybeli González'] as $name)$owners[]=User::factory()->create(['name'=>$name,'role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
        $this->actingAs($owners[0]);$row=$this->historyRow(['project_status'=>'completed','event_type'=>'birthday']);
        $batch=app(StageHistoricalImport::class)->execute($owners[0],$this->historyFile([$row]),(string)Str::uuid())['batch_id'];
        $read=app(ReadHistoricalImport::class)->execute($owners[0],$batch);
        app(ImportSafeHistoricalRows::class)->execute($owners[0],$batch,['expected_batch_version'=>$read['batch']['version'],'expected_coverage_sha256'=>$read['coverage_sha256'],'expected_plan_sha256'=>$read['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());
        $h=HistoricalEvent::sole();$revision=HistoricalEventRevision::sole();$workers=[];
        foreach(['Ana Martínez','Elena Pérez'] as $i=>$name){
            $target=Customer::create(['name'=>$name,'locale'=>'es'])->fresh();$actorId=$owners[$i]->id;$historyId=$h->id;
            $input=['expected_history_version'=>1,'expected_revision_id'=>$revision->id,'expected_source_sha256'=>$revision->source_sha256,
                'owner_reason'=>'La dueña cotejó el contacto original antes de corregir el vínculo.', 'target'=>['mode'=>'existing','id'=>$target->id,'expected_version'=>$target->version]];
            $key=(string)Str::uuid();$workers[]=static function()use($actorId,$historyId,$input,$key){
                try{app(ReassignHistoricalCustomer::class)->execute(User::findOrFail($actorId),$historyId,$input,$key);return 'saved';}
                catch(\App\Exceptions\RecordWriteRejected $e){return $e->status===409?'conflict':'unexpected';}
            };
        }
        $result=ConcurrentWorkers::run($workers);sort($result);$this->assertSame(['conflict','saved'],$result);
        $this->assertSame(2,HistoricalEvent::sole()->version);$this->assertDatabaseCount('historical_customer_relinks',1);
        $this->assertDatabaseCount('historical_event_revisions',2);$this->assertDatabaseCount('ledger_entries',0);
    }
}
