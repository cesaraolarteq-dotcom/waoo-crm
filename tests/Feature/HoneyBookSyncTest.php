<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/** Owner 2026-11-26: the automatic HoneyBook → CRM sync door. */
class HoneyBookSyncTest extends TestCase
{
    use DatabaseMigrations;

    private string $secret = 'synthetic-sync-secret-0123456789abcdef';

    private function send(?string $token, string $body)
    {
        return $this->call('POST', '/sync/honeybook', [], [], [], array_filter(['CONTENT_TYPE' => 'text/csv', 'HTTP_AUTHORIZATION' => $token ? 'Bearer '.$token : null]), $body);
    }

    public function test_the_door_is_closed_when_off_and_refuses_a_wrong_secret(): void
    {
        $csv = implode(',', \App\Services\Imports\HoneyBookFutureSource::HEADERS)."\n";
        $this->send($this->secret, $csv)->assertNotFound();
        config(['waoo.honeybook_sync_enabled' => true, 'waoo.honeybook_sync_token_sha256' => hash('sha256', $this->secret)]);
        $this->send(null, $csv)->assertUnauthorized();
        $this->send('wrong-secret-wrong-secret-wrong-secret', $csv)->assertUnauthorized();
        \App\Models\User::factory()->create(['id' => 1, 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $this->send($this->secret, $csv)->assertOk()->assertJsonPath('status', 'ok');
        $this->send($this->secret, $csv."incomplete,row\n")->assertUnprocessable();
        $this->send($this->secret, str_repeat('x', 1_048_577))->assertStatus(413);
        $this->assertDatabaseCount('honeybook_sync_runs', 1);
    }

    public function test_a_new_party_without_address_waits_for_review(): void
    {
        config(['waoo.honeybook_sync_enabled' => true, 'waoo.honeybook_sync_token_sha256' => hash('sha256', $this->secret)]);
        \App\Models\User::factory()->create(['id' => 1, 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
        $h = \App\Services\Imports\HoneyBookFutureSource::HEADERS;
        $row = array_fill_keys($h, '');
        $row = [...$row, 'honeybook_project_id' => 'hb-fixture-1', 'project_name' => 'Waoo Package 12/13 Lucía Hernández', 'status' => 'confirmed', 'client_first_name' => 'Lucía', 'client_last_name' => 'Hernández',
            'client_email' => 'lucia@example.test', 'event_date' => now('America/Chicago')->addDays(20)->toDateString(), 'start_time' => '13:00', 'end_time' => '15:00',
            'services' => 'Waoo Package', 'package_total' => '950.00', 'travel_fee' => '0.00', 'discount' => '0.00', 'total' => '950.00', 'amount_paid' => '0.00', 'balance' => '950.00', 'contract_signed' => 'no'];
        $csv = implode(',', $h)."\n".implode(',', array_map(fn ($k) => '"'.str_replace('"', '""', $row[$k]).'"', $h))."\n";
        $this->send($this->secret, $csv)->assertOk()->assertJsonPath('status', 'review')->assertJsonPath('counts.review', 1);
        $this->assertDatabaseCount('events', 0);
        $paid = [...$row, 'amount_paid' => '475.00', 'balance' => '475.00', 'contract_signed' => 'yes'];
        $csv = implode(',', $h)."\n".implode(',', array_map(fn ($k) => '"'.str_replace('"', '""', $paid[$k]).'"', $h))."\n";
        $this->send($this->secret, $csv)->assertOk()->assertJsonPath('review.0', $paid['project_name'].' — Vincula el proyecto antes de aplicar sus pagos o firma.');
        $this->assertDatabaseCount('events', 0);
    }
}
