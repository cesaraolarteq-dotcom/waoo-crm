<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class CostumeVersionMigrationTest extends TestCase
{
    public function test_sqlite_legacy_roundtrip_and_non_destructive_duplicate_rollback_guard(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        foreach (['characters','resources'] as $table) Schema::create($table, function (Blueprint $t) { $t->id(); $t->string('name'); });
        Schema::create('event_service_characters', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('event_service_id'); $t->unsignedBigInteger('character_id'); $t->unique(['event_service_id','character_id']);
        });
        DB::table('characters')->insert(['name' => 'Fixture hero']); DB::table('resources')->insert(['name' => 'Fixture costume']);
        DB::table('event_service_characters')->insert(['event_service_id' => 1, 'character_id' => 1]);
        $migration = require database_path('migrations/2026_10_16_280000_add_optional_costume_versions.php');
        $migration->up(); $this->assertNull(DB::table('characters')->value('outfits')); $this->assertNull(DB::table('event_service_characters')->value('note'));
        $migration->down(); $this->assertSame('Fixture hero', DB::table('characters')->value('name'));
        $migration->up();
        DB::table('event_service_characters')->insert(['event_service_id' => 1, 'character_id' => 1, 'outfit' => 'Racing']);
        try { $migration->down(); $this->fail('Must not collapse history'); } catch (\RuntimeException $e) { $this->assertStringContainsString('forward migration', $e->getMessage()); }
        $this->assertSame(2, DB::table('event_service_characters')->count());
        foreach (['outfit','note'] as $column) $this->assertTrue(Schema::hasColumn('event_service_characters', $column));
        $this->assertTrue(Schema::hasColumn('characters','outfits')); $this->assertTrue(Schema::hasColumn('resources','outfit'));
        DB::table('event_service_characters')->where('outfit','Racing')->delete();
        $migration->down(); $this->assertSame(1, DB::table('event_service_characters')->count());
        $migration->up(); $this->assertNull(DB::table('resources')->value('outfit'));
    }
}
