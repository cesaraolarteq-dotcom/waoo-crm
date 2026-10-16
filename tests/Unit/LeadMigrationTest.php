<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class LeadMigrationTest extends TestCase
{
    public function test_sqlite_lead_migration_round_trip_preserves_parent_records(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        foreach (['events','quick_bookings'] as $table) Schema::create($table, fn (Blueprint $t) => $t->id());
        DB::table('events')->insert(['id' => 1]); DB::table('quick_bookings')->insert(['id' => 1]);
        $migration = require database_path('migrations/2026_10_16_260000_create_website_leads.php'); $migration->up();
        $id = DB::table('lead_submissions')->insertGetId(['request_key' => '00000000-0000-4000-8000-000000000001', 'payload_sha256' => str_repeat('a', 64),
            'payload' => '{}', 'submitted_at' => '2026-10-16 12:00:00', 'received_at' => '2026-10-16 12:00:00']);
        DB::table('leads')->insert(['lead_submission_id' => $id, 'booked_event_id' => 1, 'quick_booking_id' => 1]);
        $this->assertSame(1, (int) DB::table('leads')->value('version')); $migration->down();
        $this->assertFalse(Schema::hasTable('lead_submissions')); $this->assertSame(1, DB::table('events')->count());
        $migration->up(); $this->assertTrue(Schema::hasTable('leads'));
    }
}
