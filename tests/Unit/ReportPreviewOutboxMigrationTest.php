<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class ReportPreviewOutboxMigrationTest extends TestCase
{
    public function test_sqlite_unique_preview_receipt_and_migration_round_trip(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        Schema::create('owner_report_snapshots', fn (Blueprint $t) => $t->id());
        DB::table('owner_report_snapshots')->insert(['id' => 1]);
        $migration = require database_path('migrations/2026_10_16_240000_create_report_preview_outbox.php');
        $migration->up();
        $hash = hash('sha256', 'fictional preview');
        $id = DB::table('report_preview_deliveries')->insertGetId(['snapshot_id' => 1, 'environment' => 'test', 'recipient' => 'fixture@example.invalid',
            'payload_sha256' => $hash, 'available_at' => '2026-11-09 16:00:00']);
        $receipt = ['delivery_id' => $id, 'idempotency_key' => $hash, 'payload_sha256' => $hash, 'reference' => '00000000-0000-4000-8000-000000000001'];
        DB::table('report_preview_receipts')->insert($receipt);
        try { DB::table('report_preview_receipts')->insert($receipt); $this->fail('Duplicate receipt accepted.'); }
        catch (\Illuminate\Database\UniqueConstraintViolationException) { $this->assertSame(1, DB::table('report_preview_receipts')->count()); }
        $migration->down();
        $this->assertFalse(Schema::hasTable('report_preview_receipts'));
        $this->assertFalse(Schema::hasTable('report_preview_deliveries'));
        $this->assertSame(1, DB::table('owner_report_snapshots')->count());
        $migration->up(); $this->assertTrue(Schema::hasTable('report_preview_receipts'));
    }
}
