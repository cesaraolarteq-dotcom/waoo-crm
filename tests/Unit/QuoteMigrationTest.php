<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class QuoteMigrationTest extends TestCase
{
    public function test_populated_sqlite_round_trip_preserves_existing_event_and_customer(): void
    {
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        foreach (['users','customers','leads','events'] as $table) {
            Schema::create($table, fn (Blueprint $t) => $t->id()); DB::table($table)->insert(['id' => 1]);
        }
        $migration = require database_path('migrations/2026_10_17_300000_create_versioned_quotes.php'); $migration->up();
        $id = DB::table('quotes')->insertGetId(['event_id'=>1,'customer_id'=>1,'lead_id'=>1,'status'=>'draft','version'=>1,'current_revision'=>1,'valid_until'=>'2026-12-01 12:00:00','locale'=>'es','created_by'=>1]);
        DB::table('quote_revisions')->insert(['quote_id'=>$id,'revision'=>1,'event_version'=>1,'currency'=>'USD','customer_snapshot'=>'{}','event_snapshot'=>'{}','lines_snapshot'=>'[]','discounts_snapshot'=>'[]',
            'subtotal_cents'=>101,'travel_fee_cents'=>0,'discount_cents'=>0,'total_cents'=>101,'deposit_target_cents'=>51,'valid_until'=>'2026-12-01 12:00:00','created_by'=>1]);
        $this->assertSame(51, DB::table('quote_revisions')->value('deposit_target_cents'));
        $migration->down();
        foreach (['users','customers','leads','events'] as $table) $this->assertSame(1,DB::table($table)->count());
        $migration->up(); $this->assertSame(0,DB::table('quotes')->count()); $this->assertSame(0,DB::table('quote_revisions')->count());
    }
}
