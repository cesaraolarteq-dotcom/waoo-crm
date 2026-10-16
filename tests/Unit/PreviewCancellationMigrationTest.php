<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class PreviewCancellationMigrationTest extends TestCase
{
    public function test_sqlite_cancellation_columns_rollback_preserves_existing_tasks(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        Schema::create('users', fn (Blueprint $t) => $t->id());
        Schema::create('report_preview_deliveries', function (Blueprint $t) { $t->id(); $t->string('status'); });
        DB::table('report_preview_deliveries')->insert(['status' => 'pending']);
        $migration = require database_path('migrations/2026_10_16_250000_add_preview_cancellation.php');
        $migration->up();
        $this->assertNull(DB::table('report_preview_deliveries')->value('cancelled_at'));
        DB::table('users')->insert(['id' => 1]);
        DB::table('report_preview_deliveries')->update(['status' => 'cancelled', 'cancelled_by' => 1, 'cancellation_note' => 'Fixture note']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('report_preview_deliveries', 'cancelled_by'));
        $this->assertSame('cancelled', DB::table('report_preview_deliveries')->value('status'));
        $migration->up();
        $this->assertSame(1, DB::table('report_preview_deliveries')->count());
    }
}
