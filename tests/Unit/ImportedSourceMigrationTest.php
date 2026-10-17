<?php
namespace Tests\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class ImportedSourceMigrationTest extends TestCase
{
    public function test_sqlite_populated_roundtrip_and_refusal_to_fabricate_missing_source_timestamp():void
    {
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
        foreach(['users','customers','events','import_batches','import_rows'] as $table){Schema::create($table,fn(Blueprint $t)=>$t->id());DB::table($table)->insert(['id'=>1]);}
        Schema::create('booking_evidence',function(Blueprint $t){$t->id();$t->dateTime('received_at');});DB::table('booking_evidence')->insert(['id'=>1,'received_at'=>'2026-10-01 12:01:02']);
        $m=require database_path('migrations/2026_10_17_340000_create_imported_source_agreements.php');$m->up();
        $this->assertNull(DB::table('booking_evidence')->value('imported_revision_id'));$this->assertSame('2026-10-01 12:01:02',DB::table('booking_evidence')->value('received_at'));
        DB::table('booking_evidence')->insert(['id'=>2,'received_at'=>null,'received_date'=>'2026-10-02']);
        try{$m->down();$this->fail('Expected day-only rollback refusal');}catch(\RuntimeException $e){$this->assertStringContainsString('no timestamp',$e->getMessage());}
        $this->assertTrue(Schema::hasColumn('booking_evidence','imported_revision_id'));$this->assertTrue(Schema::hasTable('imported_project_revisions'));$this->assertNull(DB::table('booking_evidence')->where('id',2)->value('received_at'));
        DB::table('booking_evidence')->where('id',2)->delete();$m->down();$this->assertFalse(Schema::hasColumn('booking_evidence','received_date'));$this->assertSame('2026-10-01 12:01:02',DB::table('booking_evidence')->value('received_at'));
        $m->up();$this->assertSame(0,DB::table('imported_project_revisions')->count());$this->assertNull(DB::table('booking_evidence')->value('received_date'));
    }
}
