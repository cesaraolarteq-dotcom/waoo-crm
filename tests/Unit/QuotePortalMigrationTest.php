<?php
namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class QuotePortalMigrationTest extends TestCase
{
    public function test_sqlite_additive_evidence_finance_roundtrip_keeps_legacy_grants_and_money():void
    {
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);DB::purge('sqlite');
        foreach(['users','customers','leads','events','financial_revisions'] as $table){Schema::create($table,fn(Blueprint $t)=>$t->id());DB::table($table)->insert(['id'=>1]);}
        Schema::create('portal_grants',function(Blueprint $t){$t->id();$t->string('token_hash',64);});
        $hash=hash('sha256','legacy-fixture-token');DB::table('portal_grants')->insert(['id'=>1,'token_hash'=>$hash]);
        $a=require database_path('migrations/2026_10_17_300000_create_versioned_quotes.php');$a->up();
        DB::table('quotes')->insert(['id'=>1,'event_id'=>1,'customer_id'=>1,'status'=>'accepted','valid_until'=>'2026-12-01 12:00:00','locale'=>'es','created_by'=>1]);
        DB::table('quote_revisions')->insert(['id'=>1,'quote_id'=>1,'revision'=>1,'event_version'=>1,'currency'=>'USD','customer_snapshot'=>'{}','event_snapshot'=>'{}','lines_snapshot'=>'[]',
            'discounts_snapshot'=>'[]','subtotal_cents'=>101,'travel_fee_cents'=>0,'discount_cents'=>1,'total_cents'=>100,'deposit_target_cents'=>50,'valid_until'=>'2026-12-01 12:00:00','created_by'=>1]);
        $b=require database_path('migrations/2026_10_17_310000_add_quote_portal_evidence.php');$b->up();
        $this->assertNull(DB::table('portal_grants')->value('quote_revision_id'));$this->assertSame($hash,DB::table('portal_grants')->value('token_hash'));
        DB::table('portal_grants')->insert(['id'=>2,'token_hash'=>hash('sha256','quote-fixture'),'quote_revision_id'=>1]);
        DB::table('quote_presentations')->insert(['id'=>1,'quote_revision_id'=>1,'payload'=>'{}','snapshot_sha256'=>str_repeat('a',64),'revision_sha256'=>str_repeat('b',64),
            'pdf_path'=>'quotes/1/fixture.pdf','pdf_sha256'=>str_repeat('c',64),'consents'=>'{}','issued_by'=>1]);
        DB::table('quote_decisions')->insert(['id'=>1,'quote_id'=>1,'quote_revision_id'=>1,'quote_presentation_id'=>1,'portal_grant_id'=>2,'customer_id'=>1,'outcome'=>'accepted',
            'name'=>'Fixture Client','email'=>'fixture@example.test','decision_sha256'=>str_repeat('a',64),'snapshot_sha256'=>str_repeat('a',64),'pdf_sha256'=>str_repeat('c',64),
            'consent_version'=>'fixture','consent_text'=>'Fixture text','consent_sha256'=>hash('sha256','Fixture text'),'user_agent'=>'Fixture','decided_at'=>'2026-10-17 12:00:00']);
        $c=require database_path('migrations/2026_10_17_320000_bind_accepted_quote_finance.php');$c->up();
        $this->assertSame(0,DB::table('financial_revisions')->where('id',1)->value('discount_cents'));$this->assertNull(DB::table('financial_revisions')->where('id',1)->value('quote_revision_id'));
        DB::table('financial_revisions')->insert(['id'=>2,'quote_revision_id'=>1,'discount_cents'=>1,'discounts_snapshot'=>'[]']);
        DB::table('quote_conversions')->insert(['quote_id'=>1,'event_id'=>1,'quote_revision_id'=>1,'quote_decision_id'=>1,'financial_revision_id'=>2,'created_by'=>1]);
        $c->down();$this->assertSame(2,DB::table('financial_revisions')->count());
        $b->down();$this->assertSame($hash,DB::table('portal_grants')->where('id',1)->value('token_hash'));$this->assertSame(2,DB::table('portal_grants')->count());
        $b->up();$c->up();$this->assertSame(0,DB::table('quote_decisions')->count());$this->assertSame(0,DB::table('quote_conversions')->count());
        $this->assertNull(DB::table('portal_grants')->where('id',1)->value('quote_revision_id'));$this->assertSame(0,DB::table('financial_revisions')->where('id',1)->value('discount_cents'));
    }
}
