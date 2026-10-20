<?php
namespace Tests\Feature;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\Pages\EventMoney;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\QuoteScenario;
use Tests\TestCase;

// Codex B1: read-only list of Stripe refunds on an imported party's opening, in Dinero. Never an allocate button or a budget.
class OpeningRefundListTest extends TestCase
{
    use DatabaseMigrations, QuoteScenario;

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario();
        config(['waoo.sandbox_finance' => true, 'waoo.sandbox_imports' => true, 'waoo.sandbox_cutover' => true, 'waoo.sandbox_cutover_bridge' => true]);
        $this->actingAs($this->owner);
    }

    private function imported(): void
    {
        DB::table('imported_project_links')->insert(['project_key' => hash('sha256', 'synthetic-refund-project'), 'event_id' => $this->event->id,
            'customer_id' => $this->event->customer_id, 'version' => 1, 'domain_sha256' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fake(array|\Throwable $result): void
    {
        $test = $this;
        $this->app->instance(\App\Services\Finance\ReadOpeningRefundCandidates::class, new class($test, $result) {
            public function __construct(private $test, private $result) {}
            public function execute($actor, int $event, array $filters = []): array
            {
                $this->test->calls[] = [$event, $filters];
                if ($this->result instanceof \Throwable) throw $this->result;

                return $this->result;
            }
        });
    }

    private function row(array $over = []): array
    {
        return [...['ledger_entry_id' => 1, 'parent_ledger_entry_id' => 9, 'gross_refund_cents' => 12550, 'received_at' => '2026-10-18T20:00:00+00:00',
            'allocated' => false, 'eligible' => false, 'issue_codes' => ['refund_parent_allocation_required', 'refund_allocation_not_available'],
            'refund_sha256' => str_repeat('a', 64), 'parent_remaining_principal_cents' => null, 'parent_remaining_customer_fee_cents' => null, 'parent_budget_sha256' => null], ...$over];
    }

    public function test_imported_party_lists_refunds_read_only_with_spanish_reasons(): void
    {
        $this->imported();
        $this->fake(['opening_id' => 1, 'expected_collection_sha256' => str_repeat('b', 64), 'collection_current' => true, 'missing' => [],
            'page' => ['number' => 1, 'last' => 2, 'total' => 26], 'rows' => [
                $this->row(),
                $this->row(['ledger_entry_id' => 2, 'gross_refund_cents' => null, 'received_at' => null, 'refund_sha256' => null,
                    'issue_codes' => ['refund_evidence_invalid', 'refund_observation_volume_exceeded', 'refund_allocation_not_available']]),
            ]]);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])
            ->assertSee('Devoluciones de Stripe (fiesta importada)')->assertSee('Devolución registrada en Stripe')->assertSee('$125.50')
            ->assertSee('Pendiente de conciliación')->assertSee('El pago original aún no está conciliado')
            ->assertSee('La evidencia necesita revisión')->assertSee('Demasiados avisos: requiere revisión')
            ->assertSee('Página 1 de 2 · 26 devoluciones')->assertDontSee('refund_')->assertDontSee('Asignar')->assertDontSee('Conciliar devolución')
            ->call('refundPageTo', 2);
        $this->assertSame(['page' => 2, 'per_page' => 25], end($this->calls)[1]);
    }

    public function test_refusal_shows_its_reason_and_crm_parties_show_nothing(): void
    {
        $this->fake(new RecordWriteRejected(409, 'No hay un cambio efectivo autorizado para esta fiesta.'));
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertDontSee('Devoluciones de Stripe');
        $this->assertSame([], $this->calls, 'A CRM party (not imported) never asks for opening refunds.');
        $this->imported();
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertSee('Devoluciones de Stripe')->assertSee('No hay un cambio efectivo autorizado para esta fiesta.');
        config(['waoo.sandbox_cutover_bridge' => false]);
        Livewire::test(EventMoney::class, ['record' => $this->event->id])->assertDontSee('Devoluciones de Stripe');
    }
}
