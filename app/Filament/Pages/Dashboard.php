<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{ChicagoTime, FinanceGate, Labels};
use App\Models\{Event, FinancialRevision, LedgerEntry, Resource, ResourceBlock};
use App\Services\Finance\FinanceSummary;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\DB;

// Inicio: "Necesita tu atención" (only real, computed signals; a card disappears when there is nothing to do)
// + this week's parties. Read-only; every card links to where it is solved.
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Inicio';

    protected static ?string $navigationLabel = 'Inicio';

    protected string $view = 'filament.pages.dashboard';

    public function getWidgets(): array
    {
        return [];
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now(ChicagoTime::TZ);
    }

    /** @return array<int, array{tone: string, count: int, title: string, text: string, items: array}> */
    public function attention(): array
    {
        $now = now();
        $upcoming = Event::query()->where('ends_at', '>=', $now)->with('customer')->orderBy('starts_at');
        $cards = [];
        $link = fn (Event $e) => ['label' => $e->title.' · '.Labels::when($e->starts_at), 'url' => $this->eventUrl($e)];

        $soon = (clone $upcoming)->where('status', 'draft')->where('starts_at', '<', $now->copy()->addDays(7))->get();
        $cards[] = ['tone' => 'bad', 'title' => 'Fiestas en menos de 7 días sin staff apartado',
            'text' => 'Asigna staff y trajes; por la fecha necesitan tu aprobación.', 'items' => $soon->map($link)->all()];

        $noTravel = (clone $upcoming)->whereNull('travel_fee_cents')->get();
        $cards[] = ['tone' => 'warn', 'title' => 'Costo de transporte pendiente',
            'text' => 'Sin él no se puede fijar el total ni confirmar.', 'items' => $noTravel->map($link)->all()];

        $incomplete = (clone $upcoming)->where('status', 'draft')->where(function ($q) {
            $q->whereDoesntHave('services')->orWhereHas('services', fn ($s) => $s->where('operational_ready', false));
        })->get();
        $cards[] = ['tone' => 'warn', 'title' => 'Borradores incompletos',
            'text' => 'Falta el servicio, el personaje o el servicio no está activo en el catálogo.', 'items' => $incomplete->map($link)->all()];

        if (FinanceGate::enabled()) {
            $dueSoon = (clone $upcoming)->whereIn('id', FinancialRevision::query()->select('event_id'))->get()
                ->map(fn ($e) => [$e, app(FinanceSummary::class)->forEvent($e)])
                ->filter(fn ($x) => $x[1]['balance_cents'] > 0 && $x[1]['financial_revision']->balance_due_at->lessThan($now->copy()->addDays(7)));
            $cards[] = ['tone' => 'info', 'title' => 'Saldos que vencen esta semana', 'text' => 'Recuérdale a la clienta; el portal le muestra cómo pagar.',
                'items' => $dueSoon->map(fn ($x) => ['label' => $x[0]->title.' · saldo '.Labels::money($x[1]['balance_cents']).' · vence '.Labels::when($x[1]['financial_revision']->balance_due_at), 'url' => EventResource::getUrl('money', ['record' => $x[0]])])->values()->all()];
            $exceptions = DB::table('payment_exceptions')->where('state', 'open')->get();
            $cards[] = ['tone' => 'bad', 'title' => 'Pagos que necesitan revisión', 'text' => 'Llegó dinero pero algo no cuadró (por ejemplo, no se pudo apartar el staff).',
                'items' => $exceptions->map(fn ($x) => ['label' => (Event::whereKey($x->event_id)->value('title') ?? 'Evento').' · '.(json_decode($x->context, true)['message'] ?? $x->kind), 'url' => EventResource::getUrl('money', ['record' => $x->event_id])])->all()];
        }

        $maintenance = Resource::query()->where('status', 'maintenance')->orderBy('name')->get();
        $cards[] = ['tone' => 'info', 'title' => 'Unidades en mantenimiento', 'text' => 'No se pueden asignar hasta que las pongas como disponibles.',
            'items' => $maintenance->map(fn ($r) => ['label' => $r->name, 'url' => \App\Filament\Resources\Resources\ResourceUnitResource::getUrl('edit', ['record' => $r])])->all()];

        return array_values(array_filter(array_map(fn ($c) => [...$c, 'count' => count($c['items'])], $cards), fn ($c) => $c['count'] > 0));
    }

    /** Parties from today to Sunday (Houston). */
    public function thisWeek()
    {
        $from = $this->now()->startOfDay();
        $to = $this->now()->endOfWeek(CarbonImmutable::SUNDAY);

        return Event::query()->with('customer')->where('starts_at', '>=', $from->utc())->where('starts_at', '<=', $to->utc())->orderBy('starts_at')->get()
            ->map(fn ($e) => (object) ['title' => $e->title, 'client' => $e->customer?->name, 'when' => Labels::when($e->starts_at),
                'status' => Labels::label(Labels::EVENT_STATUS, $e->status), 'color' => Labels::EVENT_STATUS_COLOR[$e->status] ?? 'gray',
                'travel' => Labels::money($e->travel_fee_cents), 'url' => $this->eventUrl($e)]);
    }

    private function eventUrl(Event $e): ?string
    {
        return EventResource::getUrl('view', ['record' => $e]);
    }
}
