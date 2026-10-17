<?php
namespace Tests\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class OutboundMailMigrationTest extends TestCase
{
    public function test_sqlite_outbox_roundtrip_preserves_populated_legacy_finance_and_grants():void
    {
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
        foreach(['users','customers','events','financial_revisions','portal_grants'] as $table){Schema::create($table,fn(Blueprint $t)=>$t->id());DB::table($table)->insert(['id'=>1]);}
        $migration=require database_path('migrations/2026_10_17_350000_create_outbound_mail.php');$migration->up();
        DB::table('outbound_messages')->insert(['id'=>1,'event_id'=>1,'customer_id'=>1,'financial_revision_id'=>1,'portal_grant_id'=>1,'created_by'=>1,'event_version'=>2,'customer_version'=>1,
            'template_key'=>'week_before','template_version'=>'fixture','locale'=>'es','template_sha256'=>str_repeat('a',64),'recipient_sha256'=>str_repeat('b',64),'intent_sha256'=>str_repeat('c',64),'source_sha256'=>str_repeat('d',64),'due_at'=>'2026-11-01 12:00:00','payload'=>'synthetic encrypted placeholder']);
        DB::table('outbound_message_states')->insert(['outbound_message_id'=>1]);DB::table('outbound_message_events')->insert(['outbound_message_id'=>1,'event_key'=>str_repeat('e',64),'kind'=>'scheduled','metadata'=>'{}','occurred_at'=>'2026-10-17 12:00:00']);
        $migration->down();$this->assertFalse(Schema::hasTable('outbound_messages'));foreach(['users','customers','events','financial_revisions','portal_grants'] as $table)$this->assertSame(1,DB::table($table)->count());
        $migration->up();$this->assertSame(0,DB::table('outbound_messages')->count());$this->assertSame(1,DB::table('financial_revisions')->count());
    }
}
