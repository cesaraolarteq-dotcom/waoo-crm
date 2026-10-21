<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalBalanceResolution,HistoricalEvent,HistoricalEventRevision,HistoryImportRow,User};
use App\Services\History\{ImportSafeHistoricalRows,ReadCustomerHistory,ReadHistoricalImport,ReadHistoricalRow,ResolveHistoricalBalance,StageHistoricalImport};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Str;
use Tests\Support\HistoricalSourceFixture;
use Tests\TestCase;
class HistoricalBalanceResolutionTest extends TestCase
{
    use DatabaseMigrations,HistoricalSourceFixture;
    private User $owner;
    protected function setUp():void {
        parent::setUp();config(['waoo.sandbox_history'=>true]);$this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-21T16:00:00Z'));
        $this->owner=User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]);$this->actingAs($this->owner);
    }
    protected function tearDown():void {
        if($this->app&&Schema::hasTable('historical_balance_resolutions')){
            DB::table('historical_balance_resolutions')->delete();DB::table('historical_events')->update(['current_revision_id'=>null]);
            foreach(['history_import_decisions','historical_event_revisions','historical_events','external_customer_links','history_import_rows','history_import_batches'] as $table)DB::table($table)->delete();
        }parent::tearDown();
    }
    private function archive(array $changes=[]):HistoricalEvent {
        $row=$this->historyRow(['paid_total'=>'50.00','balance'=>'70.00',...$changes]);
        $batch=app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$row],array_keys($row)),(string)Str::uuid())['batch_id'];
        $read=app(ReadHistoricalImport::class)->execute($this->owner,$batch);
        app(ImportSafeHistoricalRows::class)->execute($this->owner,$batch,['expected_batch_version'=>$read['batch']['version'],'expected_coverage_sha256'=>$read['coverage_sha256'],'expected_plan_sha256'=>$read['bulk']['plan_sha256'],'new_customer_locale'=>'es'],(string)Str::uuid());
        return HistoricalEvent::orderByDesc('id')->firstOrFail();
    }
    private function input(HistoricalEvent $event,array $changes=[]):array {
        $r=HistoricalEventRevision::findOrFail($event->current_revision_id);
        return ['expected_history_version'=>$event->version,'expected_revision_id'=>$r->id,'expected_source_sha256'=>$r->source_sha256,
            'expected_resolution_version'=>0,'kind'=>'paid_outside_honeybook','owner_note'=>'La dueña conserva la evidencia de un pago recibido fuera de HoneyBook.',...$changes];
    }
    public function test_resolution_is_encrypted_immutable_and_does_not_change_original_money_or_create_receipt():void {
        $event=$this->archive();$revision=HistoricalEventRevision::sole();$source=$revision->payload;$key=(string)Str::uuid();$input=$this->input($event);
        $service=app(ResolveHistoricalBalance::class);$saved=$service->execute($this->owner,$event->id,$input,$key);
        $this->assertSame($saved,$service->execute($this->owner,$event->id,$input,$key));$this->assertFalse($saved['bank_verified']);
        $this->assertSame($source,$revision->fresh()->payload);$this->assertSame(1,$event->fresh()->version);
        $raw=DB::table('historical_balance_resolutions')->sole()->payload;
        $this->assertSame($input['owner_note'],json_decode(\Illuminate\Support\Facades\Crypt::decryptString($raw),true)['input']['owner_note']);
        $this->assertStringNotContainsString('fuera de HoneyBook',DB::table('audit_entries')->get()->toJson());
        $this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('booking_evidence',0);$this->assertDatabaseCount('events',0);
        $read=app(ReadCustomerHistory::class)->execute($this->owner,$event->customer_id)['items'][0];$this->assertSame(7000,$read['balance_cents']);$this->assertSame('paid_outside_honeybook',$read['balance_resolution']['kind']);
        $this->expectException(\LogicException::class);HistoricalBalanceResolution::sole()->delete();
    }
    public function test_same_key_changed_resolution_and_stale_version_are_rejected():void {
        $event=$this->archive();$key=(string)Str::uuid();$input=$this->input($event);$service=app(ResolveHistoricalBalance::class);$service->execute($this->owner,$event->id,$input,$key);
        foreach([$key,(string)Str::uuid()] as $attempt){try{$service->execute($this->owner,$event->id,[...$input,'kind'=>'duplicate_source_file'],$attempt);$this->fail('Expected conflict');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}}
        $this->assertDatabaseCount('historical_balance_resolutions',1);
    }
    public function test_pending_reschedule_remains_an_action_without_date_credit_or_native_event():void {
        $event=$this->archive();app(ResolveHistoricalBalance::class)->execute($this->owner,$event->id,$this->input($event,['kind'=>'pending_reschedule']),(string)Str::uuid());
        $r=app(\App\Services\History\ReadHoneyBookArchiveReport::class)->execute($this->owner);
        $this->assertSame(1,$r['balance_counts']['pending_reschedule']);$this->assertTrue($r['balances_to_review'][0]['review_required']);$this->assertDatabaseCount('events',0);$this->assertDatabaseCount('ledger_entries',0);
    }
    public function test_audit_failure_rolls_back_only_new_resolution():void {
        $event=$this->archive();DB::listen(function($q){if(str_contains($q->sql,'insert into `audit_entries`')&&in_array('history.balance_resolved',$q->bindings,true))throw new \RuntimeException('Synthetic audit failure');});
        try{app(ResolveHistoricalBalance::class)->execute($this->owner,$event->id,$this->input($event),(string)Str::uuid());$this->fail('Expected rollback');}catch(\RuntimeException $e){$this->assertSame('Synthetic audit failure',$e->getMessage());}
        $this->assertDatabaseCount('historical_balance_resolutions',0);$this->assertDatabaseCount('historical_event_revisions',1);
    }
    public function test_later_source_revision_makes_old_resolution_stale():void {
        $event=$this->archive();app(ResolveHistoricalBalance::class)->execute($this->owner,$event->id,$this->input($event),(string)Str::uuid());
        $row=$this->historyRow(['paid_total'=>'55.00','balance'=>'65.00']);$batch=app(StageHistoricalImport::class)->execute($this->owner,$this->historyFile([$row],array_keys($row)),(string)Str::uuid())['batch_id'];
        $staged=HistoryImportRow::where('history_import_batch_id',$batch)->sole();$r=app(ReadHistoricalRow::class)->execute($this->owner,$batch,$staged->id);$c=$r['customer_match']['candidates'][0];
        app(\App\Services\History\CommitHistoricalRow::class)->execute($this->owner,$batch,$staged->id,['expected_batch_version'=>$r['batch_version'],'expected_row_version'=>$r['version'],'expected_preview_sha256'=>$r['preview_sha256'],'expected_match_sha256'=>$r['customer_match']['expected_match_sha256'],
            'customer'=>['mode'=>'link','id'=>$c['id'],'expected_version'=>$c['version']],'source_status_confirmation'=>$r['normalized']['status'],'event_type_confirmation'=>$r['normalized']['event_type'],
            'expected_history_version'=>$r['previous']['version'],'expected_revision_id'=>$r['previous']['revision_id'],'expected_diff_sha256'=>$r['previous']['diff_sha256'],'owner_note'=>'Una extracción posterior cambia el saldo de origen.'],(string)Str::uuid());
        $r=app(\App\Services\History\ReadHoneyBookArchiveReport::class)->execute($this->owner);
        $this->assertTrue($r['balances_to_review'][0]['balance_resolution']['stale']);$this->assertTrue($r['balances_to_review'][0]['review_required']);$this->assertSame(6500,$r['balance_total_cents']);
    }
    public function test_owner_ui_pins_versions_and_records_resolution_without_contacting_client():void {
        $event=$this->archive();$input=$this->input($event);
        \Livewire\Livewire::test(\App\Filament\Pages\HoneyBookArchiveReview::class)
            ->call('startResolution',$event->id,$event->version,$event->current_revision_id,$input['expected_source_sha256'],0)
            ->set('resolutionNote',$input['owner_note'])->call('saveResolution')->assertNotified('Decisión guardada');
        $this->assertDatabaseCount('historical_balance_resolutions',1);$this->assertDatabaseCount('outbound_messages',0);
        config(['waoo.sandbox_history'=>false]);$this->get('/admin/honeybook-revision')->assertForbidden();
    }
}
