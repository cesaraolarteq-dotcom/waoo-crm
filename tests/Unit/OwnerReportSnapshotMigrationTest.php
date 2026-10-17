<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class OwnerReportSnapshotMigrationTest extends TestCase
{
    public function test_sqlite_preserves_exact_payload_bytes_and_migration_round_trip(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('users', fn (Blueprint $t) => $t->id());
        DB::table('users')->insert(['id' => 1]);
        $migration = require database_path('migrations/2026_10_16_230000_create_owner_report_snapshots.php');
        $migration->up();
        $payload = '{"fixture":"Niña · César / prueba","amount":null}';
        DB::table('owner_report_snapshots')->insert(['environment' => 'test', 'kind' => 'weekly', 'period_starts_on' => '2026-11-02',
            'period_ends_before' => '2026-11-09', 'recipient' => 'fixture@example.invalid', 'payload_json' => $payload,
            'payload_sha256' => hash('sha256', $payload), 'prepared_by' => 1]);
        $saved = DB::table('owner_report_snapshots')->first();
        $this->assertSame($payload, $saved->payload_json);
        $this->assertSame(hash('sha256', $payload), $saved->payload_sha256);
        $this->assertSame(1, (int) $saved->schema_version);
        $migration->down();
        $this->assertFalse(Schema::hasTable('owner_report_snapshots'));
        $this->assertSame(1, DB::table('users')->count());
        $migration->up();
        $this->assertTrue(Schema::hasTable('owner_report_snapshots'));
    }
}
