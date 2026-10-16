<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class TravelFeeMigrationTest extends TestCase
{
    public function test_sqlite_quote_schema_rollback_preserves_existing_events(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('events', function (Blueprint $t) { $t->id(); $t->string('title'); });
        DB::table('events')->insert(['title' => 'Existing fixture event']);
        $migration = require database_path('migrations/2026_10_16_270000_create_travel_fee_quotes.php'); $migration->up();
        $this->assertNull(DB::table('events')->value('travel_fee_evidence')); $this->assertTrue(Schema::hasTable('travel_route_daily_usage'));
        $migration->down(); $this->assertSame('Existing fixture event', DB::table('events')->value('title'));
        $migration->up(); $this->assertTrue(Schema::hasTable('travel_fee_quotes'));
    }
}
