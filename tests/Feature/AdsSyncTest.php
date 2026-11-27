<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** Owner 2026-11-26 (point 5): Google Ads script → spend in, booked prospects out. */
class AdsSyncTest extends TestCase
{
    use DatabaseMigrations;

    private string $secret = 'synthetic-ads-secret-0123456789abcdef';

    public function test_spend_is_stored_once_per_day_and_campaign_and_the_door_needs_the_secret(): void
    {
        config(['waoo.ads_sync_token_sha256' => hash('sha256', $this->secret)]);
        $rows = ['rows' => [['date' => '2026-11-25', 'campaign_id' => '123', 'campaign' => 'Busqueda Katy', 'cost_micros' => 10500000, 'clicks' => 7, 'impressions' => 90]]];
        $this->postJson('/sync/ads/google', $rows)->assertUnauthorized();
        $this->postJson('/sync/ads/google', $rows, ['Authorization' => 'Bearer '.$this->secret])->assertOk()->assertJsonPath('stored', 1)->assertJsonPath('conversions', []);
        $rows['rows'][0]['cost_micros'] = 12000000; // Google corrects yesterday's numbers: the row is updated, not duplicated
        $this->postJson('/sync/ads/google', $rows, ['Authorization' => 'Bearer '.$this->secret])->assertOk();
        $this->assertDatabaseCount('ad_spend_daily', 1);
        $this->assertDatabaseHas('ad_spend_daily', ['campaign_id' => '123', 'cost_cents' => 1200]);
        $this->postJson('/sync/ads/google', ['rows' => [
            [...$rows['rows'][0], 'cost_micros' => -10000],
            [...$rows['rows'][0], 'date' => '2026-02-30'],
            [...$rows['rows'][0], 'campaign_id' => ''],
        ]], ['Authorization' => 'Bearer '.$this->secret])->assertOk()->assertJsonPath('stored', 0);
        $this->assertDatabaseCount('ad_spend_daily', 1);
        $this->assertDatabaseHas('ad_spend_daily', ['campaign_id' => '123', 'cost_cents' => 1200]);
    }
}
