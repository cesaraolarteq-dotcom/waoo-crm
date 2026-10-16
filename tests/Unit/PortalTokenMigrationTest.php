<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class PortalTokenMigrationTest extends TestCase
{
    public function test_sqlite_additive_roundtrip_keeps_legacy_hashes_and_grants(): void
    {
        config(['database.default' => 'sqlite','database.connections.sqlite.database' => ':memory:']); DB::purge('sqlite');
        Schema::create('portal_grants',function (Blueprint $t) { $t->id(); $t->string('token_hash',64)->unique(); });
        $hash = hash('sha256','fictional-old-token'); DB::table('portal_grants')->insert(['token_hash' => $hash]);
        $migration = require database_path('migrations/2026_10_16_290000_add_encrypted_portal_token.php'); $migration->up();
        $this->assertNull(DB::table('portal_grants')->value('encrypted_token')); $this->assertSame($hash,DB::table('portal_grants')->value('token_hash'));
        $migration->down(); $this->assertSame($hash,DB::table('portal_grants')->value('token_hash')); $this->assertSame(1,DB::table('portal_grants')->count());
        $migration->up(); $this->assertNull(DB::table('portal_grants')->value('encrypted_token'));
    }
}
