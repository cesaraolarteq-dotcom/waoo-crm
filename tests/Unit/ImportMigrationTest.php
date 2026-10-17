<?php
namespace Tests\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class ImportMigrationTest extends TestCase
{
    public function test_populated_sqlite_roundtrip_preserves_domain_rows_and_recreates_staging_mutex():void
    {
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');Schema::create('users',fn(Blueprint $t)=>$t->id());DB::table('users')->insert(['id'=>1]);
        $m=require database_path('migrations/2026_10_17_330000_create_private_import_staging.php');$m->up();$this->assertSame(1,DB::table('import_staging_locks')->count());
        DB::table('import_batches')->insert(['id'=>1,'created_by'=>1,'version'=>1,'status'=>'prepared','profile'=>'{}','profile_sha256'=>str_repeat('a',64),'preview_sha256'=>str_repeat('b',64),'reconciliation'=>'{}','received_at'=>'2026-10-17 12:00:00']);
        DB::table('import_files')->insert(['import_batch_id'=>1,'role'=>'projects','private_path'=>'honeybook/fixture/projects.csv','sha256'=>str_repeat('c',64),'byte_count'=>1]);
        DB::table('import_rows')->insert(['import_batch_id'=>1,'version'=>1,'role'=>'projects','source_line'=>2,'status'=>'ready','source'=>'{}','normalized'=>'{}','errors'=>'[]','duplicate_hints'=>'[]','preview_sha256'=>str_repeat('b',64)]);
        DB::table('import_preview_versions')->insert(['import_batch_id'=>1,'version'=>1,'profile_sha256'=>str_repeat('a',64),'preview_sha256'=>str_repeat('b',64),'counts'=>'{}','created_by'=>1]);
        $m->down();$this->assertFalse(Schema::hasTable('import_rows'));$this->assertSame(1,DB::table('users')->count());$m->up();$this->assertSame(1,DB::table('import_staging_locks')->count());$this->assertSame(0,DB::table('import_batches')->count());
    }
}
