<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\Pages\EventMoney;
use App\Models\{Resource, TipGrant};
use App\Services\Finance\{ConfirmPaidCapacity, CreateFinancialRevision, RecordZellePayment};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// TIP-01 owner block on Dinero over Codex's services: after a finished CRM party the owner creates the tip link
// (shown once, with the WhatsApp message), sees totals with unknown net as "Pendiente", and can revoke it.
class TipOwnerBlockTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T15:00:00Z'));
        $this->scenario();
        config(['waoo.sandbox_tips' => true, 'waoo.sandbox_finance' => true]);
        $finance = app(CreateFinancialRevision::class)->execute($this->owner, $this->event->id, ['expected_version' => 1], (string) Str::uuid());
        app(RecordZellePayment::class)->execute($this->owner, $this->event->id, ['financial_revision_id' => $finance->id, 'amount_cents' => $finance->deposit_target_cents, 'currency' => 'USD',
            'reference' => 'TIP-UI-DEPOSIT', 'received_at' => now()->subMinute()->toIso8601String(), 'verified_received' => true], (string) Str::uuid());
        $staff = Resource::create(['code' => 'TIPUI-STAFF', 'name' => 'Fixture Host', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        $speaker = Resource::create(['code' => 'TIPUI-SPK', 'name' => 'Fixture Speaker', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
        $this->event = app(ConfirmPaidCapacity::class)->execute($this->owner, $this->event->id, ['expected_version' => 1, 'financial_revision_id' => $finance->id,
            'resource_ids' => [$staff->id, $speaker->id], 'review' => ['staff_confirmed' => true, 'cargo_checked' => true, 'origin_return_note' => 'Fixture route.', 'transfers' => []]]);
        $this->travelTo($this->event->ends_at->addHour());
    }

    public function test_owner_creates_the_link_once_sees_totals_and_revokes(): void
    {
        $page = Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertSee('Propinas para el staff')->assertSee('Crear enlace de propina')
            ->callAction('tipIssue')->assertSee('Enlace de propina')->assertSee('Copiar enlace otra vez')->assertSee('/portal/tip#', false)
            ->assertSee('Enlace activo');
        $grant = TipGrant::sole();
        $page->callAction('tipRevoke', arguments: ['grant' => $grant->id, 'version' => $grant->version])->assertNotified('Enlace de propina anulado')
            ->assertSet('tipLink', null)->assertSee('Crear enlace de propina');
        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_the_same_link_can_be_copied_again_and_a_stale_screen_is_refused(): void
    {
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->callAction('tipIssue');
        $grant = TipGrant::sole();
        $page = Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertSee('Copiar enlace otra vez')
            ->callAction('tipCopy', arguments: ['grant' => $grant->id, 'version' => $grant->version, 'snapshot' => $grant->snapshot_sha256])->assertSee('/portal/tip#', false);
        $link = $page->get('tipLink')['url'];
        $this->assertStringContainsString('/portal/tip#', $link);
        $this->assertSame(1, TipGrant::count()); // same grant, no new token or renewal
        $this->assertSame($grant->expires_at->toIso8601String(), $grant->fresh()->expires_at->toIso8601String());
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->callAction('tipCopy', arguments: ['grant' => $grant->id, 'version' => $grant->version + 1, 'snapshot' => $grant->snapshot_sha256])
            ->assertNotified('No se pudo copiar')->assertSet('tipLink', null);
    }

    public function test_hidden_before_the_party_ends_and_when_flag_is_off(): void
    {
        $this->travelBack(); $this->travelTo(CarbonImmutable::parse('2026-10-19T15:00:00Z'));
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertSee('cuando la fiesta haya terminado')->assertDontSee('Crear enlace de propina');
        config(['waoo.sandbox_tips' => false]);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertDontSee('Propinas para el staff');
    }
}
