<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class MailOccurrenceMigrationTest extends TestCase
{
    public function test_sqlite_round_trip_preserves_legacy_links_and_blocks_unrepresentable_rollback(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        foreach (['events', 'customers', 'financial_revisions', 'users', 'leads'] as $name) {
            Schema::create($name, fn (Blueprint $t) => $t->id()); DB::table($name)->insert(['id' => 1]);
        }
        Schema::create('outbound_messages', function (Blueprint $t) {
            $t->id(); foreach (['event_id', 'customer_id', 'financial_revision_id', 'created_by'] as $c) $t->unsignedBigInteger($c);
            foreach (['event_version', 'customer_version'] as $c) $t->unsignedInteger($c);
        });
        $legacy = ['event_id' => 1, 'customer_id' => 1, 'financial_revision_id' => 1, 'created_by' => 1, 'event_version' => 3, 'customer_version' => 2];
        DB::table('outbound_messages')->insert($legacy);
        $migration = require database_path('migrations/2026_10_17_370000_create_mail_occurrences.php'); $migration->up();
        $row = DB::table('outbound_messages')->first();
        foreach ($legacy as $key => $value) $this->assertSame($value, $row->$key);
        $leadOnly = DB::table('outbound_messages')->insertGetId(['lead_id' => 1]);
        try { $migration->down(); $this->fail('Nullable source links were silently fabricated.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertTrue(Schema::hasTable('mail_occurrences')); $this->assertSame(2, DB::table('outbound_messages')->count());
        DB::table('outbound_messages')->where('id', $leadOnly)->delete();
        $migration->down(); $this->assertFalse(Schema::hasTable('mail_occurrences'));
        $this->assertFalse(Schema::hasColumn('outbound_messages', 'source_occurrence_id'));
        $this->assertSame(1, DB::table('outbound_messages')->count());
        $migration->up(); $this->assertTrue(Schema::hasColumn('outbound_messages', 'source_occurrence_id'));
        $this->assertSame(3, DB::table('outbound_messages')->value('event_version'));
    }
}
