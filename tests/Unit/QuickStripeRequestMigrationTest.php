<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickStripeRequestMigrationTest extends TestCase
{
    public function test_sqlite_roundtrip_preserves_parents_and_refuses_to_drop_durable_request_evidence(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['events', 'customers', 'financial_revisions', 'quick_stripe_leases'] as $parent) {
            Schema::create($parent, fn (Blueprint $table) => $table->id());
            DB::table($parent)->insert(['id' => 1]);
        }
        $migration = require database_path('migrations/2026_10_20_580000_create_quick_stripe_checkout_requests.php');
        $migration->up(); $migration->down();
        $this->assertFalse(Schema::hasTable('quick_stripe_checkout_requests'));
        $this->assertSame(1, DB::table('quick_stripe_leases')->count());
        $migration->up();
        $row = ['id' => (string) Str::uuid(), 'event_id' => 1, 'customer_id' => 1, 'financial_revision_id' => 1,
            'quick_stripe_lease_id' => 1, 'envelope' => 'synthetic-private-envelope', 'envelope_sha256' => str_repeat('a', 64)];
        DB::table('quick_stripe_checkout_requests')->insert($row);
        try { $migration->down(); $this->fail('Dropped durable request evidence.'); }
        catch (\LogicException) { $this->assertSame($row['id'], DB::table('quick_stripe_checkout_requests')->value('id')); }
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(1, DB::table('customers')->count());
    }
}
