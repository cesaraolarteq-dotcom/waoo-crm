<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class RecordVersionMigrationTest extends TestCase
{
    public function test_existing_rows_backfill_and_rollback_preserves_records_on_sqlite(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['customers', 'characters'] as $table) {
            Schema::create($table, function (Blueprint $schema) { $schema->id(); $schema->string('name'); });
            DB::table($table)->insert(['name' => 'Existing fictional record']);
        }
        $migration = require database_path('migrations/2026_10_16_220000_add_customer_and_character_versions.php');
        $migration->up();
        foreach (['customers', 'characters'] as $table) {
            $this->assertSame(1, (int) DB::table($table)->value('version'));
            DB::table($table)->insert(['name' => 'New fictional record']);
            $this->assertSame([1, 1], DB::table($table)->orderBy('id')->pluck('version')->map(fn ($v) => (int) $v)->all());
        }
        $migration->down();
        foreach (['customers', 'characters'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'version'));
            $this->assertSame(2, DB::table($table)->count());
        }
        $migration->up();
        foreach (['customers', 'characters'] as $table) $this->assertSame([1, 1], DB::table($table)->orderBy('id')->pluck('version')->map(fn ($v) => (int) $v)->all());
    }
}
