<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\History\{ImportSafeHistoricalRows, ReadHistoricalImport, StageHistoricalImport};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{Artisan, DB, Http, Mail, Queue, Schema};
use Illuminate\Support\Str;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;

class HistoricalImportDryRunTest extends TestCase
{
    use DatabaseMigrations, HistoricalSourceFixture;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28T12:00:00-05:00'));
        config(['waoo.sandbox_history'=>true]);
        Http::preventStrayRequests();Mail::fake();Queue::fake();
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);
    }

    protected function tearDown(): void
    {
        // Existing history migrations deliberately refuse rolling back populated evidence, including synthetic fixtures.
        if($this->app && Schema::hasTable('historical_events')){
            DB::table('historical_events')->update(['current_revision_id'=>null]);
            foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links','history_import_rows','history_import_batches'] as $table)DB::table($table)->delete();
        }
        if($this->app)$this->app->detectEnvironment(fn()=>'testing');
        parent::tearDown();
    }

    private function stage(): int
    {
        return app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$this->historyRow()]),(string)Str::uuid())['batch_id'];
    }

    private function invoke(int $batch, array $overrides=[]): array
    {
        $writes=[];
        DB::listen(function($query)use(&$writes){if(preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i',$query->sql))$writes[]=$query->sql;});
        $status=Artisan::call('waoo:history:import',['--owner'=>$this->owner->id,'--batch'=>$batch,'--dry-run'=>true,...$overrides]);
        $out=Artisan::output();
        $this->assertSame([],$writes,'Dry run issued a mutation query.');
        foreach(['María González','history@example.test','fictional-history-1','PRIVATE_FILENAME','UNTRUSTED_SOURCE_FORMULA','120.00'] as $private)$this->assertStringNotContainsString($private,$out);
        Http::assertNothingSent();Mail::assertNothingSent();Queue::assertNothingPushed();
        return [$status,$out];
    }

    public function test_existing_staging_is_reported_without_importing_a_customer_or_writing_any_table(): void
    {
        $this->assertContains('waoo:history:import',array_keys(Artisan::all()));
        $id=$this->stage();
        $before=DB::table('history_import_rows')->get()->toJson();
        [$status,$out]=$this->invoke($id);
        $this->assertSame(2,$status);$report=json_decode(trim($out),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(1,$report['counts']['source_rows']);$this->assertSame(1,$report['counts']['eligible']);
        $this->assertSame(1,$report['counts']['pending']);$this->assertFalse($report['domain_writes']);
        $this->assertSame('pending_decisions',$report['verdict']);
        $this->assertSame($before,DB::table('history_import_rows')->get()->toJson());
        foreach(['customers','historical_events','ledger_entries','events','contracts','resource_reservations'] as $table)$this->assertDatabaseCount($table,0);
    }

    public function test_already_imported_history_is_verified_without_reimporting_or_overwriting_it(): void
    {
        $id=$this->stage();$v=app(ReadHistoricalImport::class)->execute($this->owner,$id);
        app(ImportSafeHistoricalRows::class)->execute($this->owner,$id,['expected_batch_version'=>$v['batch']['version'],
            'expected_coverage_sha256'=>$v['coverage_sha256'],'expected_plan_sha256'=>$v['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());
        [$status,$out]=$this->invoke($id);$r=json_decode(trim($out),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(0,$status);$this->assertSame(1,$r['counts']['imported']);$this->assertSame(0,$r['counts']['pending']);
        $this->assertFalse($r['human_independent_reviews_complete']);
        $this->assertDatabaseCount('customers',1);$this->assertDatabaseCount('historical_events',1);
    }

    public function test_missing_dry_run_or_owner_authority_fails_without_mutating_history(): void
    {
        $id=$this->stage();
        [$status]=$this->invoke($id,['--dry-run'=>false]);$this->assertSame(1,$status);
        $this->owner->forceFill(['active'=>false])->save();
        [$status]=$this->invoke($id);$this->assertSame(1,$status);
        $this->assertDatabaseCount('customers',0);
    }

    public function test_disabled_gate_and_corrupt_evidence_do_not_print_private_exception_details(): void
    {
        $id=$this->stage();config(['waoo.sandbox_history'=>false]);
        [$status]=$this->invoke($id);$this->assertSame(1,$status);
        config(['waoo.sandbox_history'=>true]);
        DB::table('history_import_batches')->where('id',$id)->update(['coverage_sha256'=>str_repeat('f',64)]);
        [$status]=$this->invoke($id);$this->assertSame(1,$status);
        $this->assertDatabaseCount('historical_events',0);
    }

    public function test_production_never_uses_the_sandbox_dry_run_even_with_the_flag_set(): void
    {
        $id=$this->stage();$this->app['env']='production';
        [$status]=$this->invoke($id);$this->assertSame(1,$status);
    }
}
