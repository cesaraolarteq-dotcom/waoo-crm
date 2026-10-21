<?php
namespace Tests\Feature;
use App\Exceptions\RecordWriteRejected;
use App\Models\{ImportBatch,ImportRow,ImportedProjectRevision,ImportedLedgerLine,ImportedProjectLink,ImportServiceMapping};
use App\Services\Imports\{CommitHoneyBookEvent,FinalizeHoneyBookImport,HoneyBookMappingKey,HoneyBookPaymentLabels,ReadImportCoverage,ReadImportedEvent};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\HoneyBookFutureScenario;
use Tests\TestCase;
class HoneyBookOwnerResolutionTest extends TestCase
{
    use RefreshDatabase,HoneyBookFutureScenario;
    protected function setUp():void{parent::setUp();$this->futureScenario();}
    public function test_difference_requires_explicit_note_and_can_close_without_recalculating_source_money():void
    {
        $b=$this->stageFuture(['total'=>'123.60','balance'=>'63.60']);$r=ImportRow::sole();$service=app(CommitHoneyBookEvent::class);
        foreach([[],['source_difference_accepted'=>true],['source_difference_note'=>'PRIVATE owner fee explanation']] as $change){
            try{$service->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r,$change),(string)Str::uuid());$this->fail('Missing explicit acceptance');}catch(ValidationException $e){$this->assertArrayHasKey('source_difference_note',$e->errors());}
        }
        $this->assertDatabaseCount('events',0);$d=$this->commitInput($b,$r,['source_difference_accepted'=>true,'source_difference_note'=>'  PRIVATE owner fee explanation  ']);$key=(string)Str::uuid();
        $link=$service->execute($this->owner,$b->id,$r->id,$d,$key);$this->assertSame($link->id,$service->execute($this->owner,$b->id,$r->id,$d,$key)->id);
        $revision=ImportedProjectRevision::sole();$e=$revision->mapping['source_difference'];
        $this->assertSame(360,$e['difference_cents']);$this->assertSame($this->owner->id,$e['accepted_by']);$this->assertSame('PRIVATE owner fee explanation',$e['note']);
        $this->assertSame(12360,$revision->normalized['total_cents']);$this->assertSame(6360,$revision->normalized['balance_cents']);$this->assertSame('123.60',$revision->source_snapshot['total']);$this->assertSame(6000,(int)ImportedLedgerLine::sum('amount_cents'));
        $this->assertContains('package_equation_mismatch',array_column($r->fresh()->errors,'code'));
        $coverage=app(ReadImportCoverage::class)->execute($this->owner,$b->id);$this->assertTrue($coverage['domain_complete']);$this->assertFalse($coverage['capacity_complete']);$this->assertCount(1,$coverage['accepted_source_differences']);$this->assertSame(0,$coverage['reconciliation']['total_cents']['difference_cents']);
        $this->assertStringNotContainsString('PRIVATE owner fee explanation',json_encode(DB::table('audit_entries')->get()));
        $this->assertSame('imported',app(FinalizeHoneyBookImport::class)->execute($this->owner,$b->id,['expected_version'=>$b->fresh()->version,'source_coverage_reviewed'=>true],(string)Str::uuid())->status);
        $this->assertDatabaseCount('ledger_entries',0);$this->assertDatabaseCount('resource_reservations',0);
    }
    public function test_an_accepted_difference_cannot_cover_a_later_source_hash_or_silently_change_owner_note():void
    {
        $b=$this->stageFuture(['total'=>'123.60','balance'=>'63.60']);$r=ImportRow::sole();$service=app(CommitHoneyBookEvent::class);
        $change=['source_difference_accepted'=>true,'source_difference_note'=>'PRIVATE original explanation','remember_mapping'=>true];
        $link=$service->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r,$change),(string)Str::uuid());
        $this->assertNotNull(HoneyBookMappingKey::suggestion(['services'=>' historical package + EXTRA ','characters'=>'']));
        $this->assertSame($link->id,$service->execute($this->owner,$b->id,$r->id,$this->commitInput($b->fresh(),$r,$change),(string)Str::uuid())->id);
        try{$service->execute($this->owner,$b->id,$r->id,$this->commitInput($b->fresh(),$r,[...$change,'source_difference_note'=>'PRIVATE different explanation']),(string)Str::uuid());$this->fail('Implicit note change');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        $b2=$this->stageFuture(['total'=>'124.60','balance'=>'64.60']);$coverage=app(ReadImportCoverage::class)->execute($this->owner,$b2->id);
        $this->assertFalse($coverage['domain_complete']);$this->assertSame(1,$coverage['counts']['unresolved']);$this->assertSame([],$coverage['accepted_source_differences']);$this->assertDatabaseCount('imported_project_revisions',1);
    }
    public function test_acceptance_never_bypasses_other_equations_or_missing_money():void
    {
        foreach([['balance'=>'99.00'],['travel_fee'=>''],['payments'=>'2026-10-01|1.00|Cash']] as $extra){
            $b=$this->stageFuture(['honeybook_project_id'=>'FAKE-'.Str::uuid(),'total'=>'123.60','balance'=>'63.60',...$extra]);$r=ImportRow::where('import_batch_id',$b->id)->sole();
            try{app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r,['source_difference_accepted'=>true,'source_difference_note'=>'PRIVATE source explanation']),(string)Str::uuid());$this->fail('Other source error bypassed');}catch(RecordWriteRejected $e){$this->assertSame(409,$e->status);}
        }$this->assertDatabaseCount('events',0);
    }
    public function test_normal_row_cannot_receive_invented_source_difference_evidence():void
    {
        $b=$this->stageFuture();$r=ImportRow::sole();$this->expectException(ValidationException::class);
        app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r,['source_difference_accepted'=>true,'source_difference_note'=>'Invented difference']),(string)Str::uuid());
    }
    public function test_empty_due_date_only_for_valid_nonpositive_balance_and_never_accepts_malformed_dates():void
    {
        foreach([['balance'=>'0.00','amount_paid'=>'120.00','payments'=>'2026-10-01|120.00|Cash'],['balance'=>'-1.00','amount_paid'=>'121.00','payments'=>'2026-10-01|121.00|Cash'],['balance'=>'60.00'],['balance'=>'']] as $i=>$change){
            $b=$this->stageFuture(['honeybook_project_id'=>'FAKE-DUE-'.$i,'balance_due_date'=>'',...$change]);$r=ImportRow::where('import_batch_id',$b->id)->sole();
            $this->assertNull($r->normalized['balance_due_date']);$this->assertSame($i>=2,in_array('invalid_source_date',array_column($r->errors,'code'),true));
        }
        $b=$this->stageFuture(['honeybook_project_id'=>'FAKE-BAD-DATE','balance'=>'0.00','balance_due_date'=>'2026-02-30']);$this->assertContains('invalid_source_date',array_column(ImportRow::where('import_batch_id',$b->id)->sole()->errors,'code'));
    }
    public function test_normalized_suggestion_endpoint_preserves_source_identity_and_supports_legacy_exact_keys():void
    {
        $b=$this->stageFuture(['services'=>'  Historical  Package + Extra ','characters'=>' Rumi ']);$r=ImportRow::sole();
        $selection=[['price_version_id'=>$this->price->id,'expected_variant_version'=>1]];
        $source=['services'=>'historical package + extra','characters'=>'rumi'];
        ImportServiceMapping::create(['source_key'=>HoneyBookMappingKey::forSource($source),'version'=>1,'selection'=>$selection,'updated_by'=>$this->owner->id]);
        $this->getJson("/admin/api/imports/honeybook/{$b->id}/rows/{$r->id}/mapping")->assertOk()->assertJsonPath('suggestion.selection.0.price_version_id',$this->price->id);
        $this->assertSame(' Rumi ',$r->fresh()->source['characters']);$this->assertSame(HoneyBookMappingKey::forSource($source),HoneyBookMappingKey::forSource(['services'=>"historical\u{a0}package + extra",'characters'=>'RUMI']));
        $this->assertNotSame(HoneyBookMappingKey::forSource(['services'=>'ab c']),HoneyBookMappingKey::forSource(['services'=>'a bc']));
        ImportServiceMapping::query()->delete();ImportServiceMapping::create(['source_key'=>\App\Services\Finance\MutationKey::hash([$r->source['services'],$r->source['characters']]),'version'=>1,'selection'=>$selection,'updated_by'=>$this->owner->id]);$this->assertNotNull(HoneyBookMappingKey::suggestion($r->source));
    }
    public function test_cash_is_owner_display_only_with_raw_ledger_and_frozen_map_version():void
    {
        $b=$this->stageFuture(['payments'=>'2026-10-01|60.00|Cash']);$r=ImportRow::sole();$link=app(CommitHoneyBookEvent::class)->execute($this->owner,$b->id,$r->id,$this->commitInput($b,$r),(string)Str::uuid());
        $read=app(ReadImportedEvent::class)->execute($this->owner,$link->event_id);$label=$read['payment_display'][0];
        $this->assertSame('Cash',ImportedLedgerLine::sole()->method);$this->assertSame('Cash',$label['raw_method']);$this->assertNull($label['canonical_method']);$this->assertSame('Zelle/efectivo (HoneyBook)',$label['display_method']);$this->assertTrue($label['historical_version_frozen']);$this->assertFalse($label['funds_verified']);$this->assertFalse($read['summary']['funds_verified']);
        $this->assertSame('visa',HoneyBookPaymentLabels::display('visa')['display_method']);$this->assertNull(HoneyBookPaymentLabels::display('visa')['canonical_method']);$this->assertFalse(HoneyBookPaymentLabels::display('cash')['historical_version_frozen']);$this->assertDatabaseCount('ledger_entries',0);
    }
}
