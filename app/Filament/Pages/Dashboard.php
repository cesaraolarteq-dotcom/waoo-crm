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
    /** REPORT-A summary for Inicio: last completed week + the weekend after it (Codex's panel), or null when unavailable. */
    public function weekSummary(): ?array
    {
        if (! \App\Filament\Support\ReportsSource::available()) {
            return null;
        }
        try {
            $r = \App\Filament\Support\ReportsSource::read(\Filament\Facades\Filament::auth()->user(), 'weekly', now('America/Chicago')->toDateString());
        } catch (\Throwable) {
            return null; // the report screen shows the reason; Inicio stays usable
        }

        // Owner 2026-10-29: money and new bookings come from the same rows as Panorama (HoneyBook parties included).
        $period = $r['operations']['period'];
        try {
            $pan = app(\App\Services\Reports\ReadPanorama::class)->execute(\Filament\Facades\Filament::auth()->user(), $period['starts_on'],
                \Carbon\CarbonImmutable::parse($period['ends_before'], 'America/Chicago')->subDay()->toDateString());
            $weekend = $r['upcoming_weekend'] ? app(\App\Services\Reports\ReadPanorama::class)->execute(\Filament\Facades\Filament::auth()->user(), $r['upcoming_weekend']['window']['starts_on'],
                \Carbon\CarbonImmutable::parse($r['upcoming_weekend']['window']['starts_on'], 'America/Chicago')->addDay()->toDateString())['kpis']['parties']['value'] : null;
        } catch (\Throwable) {
            $pan = null; $weekend = $r['upcoming_weekend']['event_count'] ?? null;
        }

        return ['sample' => $r['sample'], 'label' => \App\Filament\Support\ReportsSource::periodLabel($period),
            'cash' => Labels::money($pan ? $pan['kpis']['received']['value'] : $r['operations']['cash']['net_received_cents'], 'Pendiente'),
            'bookings' => $pan ? $pan['pulse']['count'] : $r['bookings']['booking_count'],
            'packages' => \App\Filament\Support\ReportsSource::packagesLine($r['bookings']),
            'weekend' => $weekend,
            'weekend_staff' => $r['upcoming_weekend'] ? Labels::money($r['upcoming_weekend']['estimate']['staff_cost_cents'], 'Pendiente') : null];
    }

    public function attention(): array
    {
        $now = now();
        $upcoming = Event::query()->where('ends_at', '>=', $now)->where('status', '!=', 'cancelled')->with('customer')->orderBy('starts_at');
        $cards = [];
        $link = fn (Event $e) => ['label' => $e->title.' · '.Labels::when($e->starts_at), 'url' => $this->eventUrl($e)];

        if (\App\Filament\Pages\Leads::canAccess()) {
            // LEAD-01: website requests nobody has answered yet (newest first).
            $leads = \App\Models\Lead::with('submission')->where('status', 'new')->orderByDesc('id')->limit(20)->get();
            $cards[] = ['tone' => 'bad', 'title' => 'Prospectos sin contestar', 'text' => 'Llegaron por el formulario de la web. Contesta primero las fechas muy pedidas.',
                'items' => $leads->map(function ($l) {
                    $p = $l->submission->payload ?? [];
                    $date = ! empty($p['event_date']) ? \Carbon\CarbonImmutable::parse($p['event_date'])->locale('es')->translatedFormat('D j M') : 'sin fecha';
                    $package = in_array($p['package'] ?? '', ['', 'unspecified'], true) ? null : $p['package'];

                    return ['label' => $p['name'] ?? 'Prospecto', 'meta' => implode(' · ', array_filter([$date, ! empty($p['zip']) ? 'ZIP '.$p['zip'] : null, $package])),
                        'url' => \App\Filament\Pages\Leads::getUrl()];
                })->all()];
        }

        if (\App\Services\Booking\QuickPaymentProtocol::enabled()) {
            $paid=\App\Models\QuickPaymentBooking::whereIn('state',['paid','paid_needs_review'])->orderByDesc('id')->limit(20)->get();
            $cards[]=['tone'=>'warn','title'=>'Pagos de Reserva rápida','text'=>'Dinero verificado. Revisa el personal y los recursos pendientes.', 'items'=>$paid->map(fn($b)=>$link(Event::findOrFail($b->event_id)))->all()];
            $noticeBookings=\App\Models\QuickPaymentNotification::whereIn('status',['pending','blocked','delivery_unknown'])->orderBy('id')->limit(20)->pluck('quick_payment_booking_id');
            $noticeEvents=Event::whereIn('id',\App\Models\QuickPaymentBooking::whereIn('id',$noticeBookings)->select('event_id'))->orderBy('starts_at')->get();
            $cards[]=['tone'=>'warn','title'=>'Avisos automáticos de Reserva rápida','text'=>'Pendientes, bloqueados o con entrega incierta. Abrir un enlace no significa que se envió un mensaje.', 'items'=>$noticeEvents->map($link)->all()];
            $review=\App\Models\QuickPaymentBooking::where('state','review_required')->orderBy('id')->limit(20)->get();
            $cards[]=['tone'=>'bad','title'=>'Apartados vencidos con Zelle pendiente','text'=>'La fecha y el traje siguen apartados. Revisa el aviso antes de liberar.', 'items'=>$review->map(fn($b)=>$link(Event::findOrFail($b->event_id)))->all()];
        }
        // Owner 2026-10-29: HoneyBook parties keep their staff in HoneyBook until the switch; they are listed apart, not as a red alert.
        $honeybook = \App\Models\ImportedProjectLink::query()->select('event_id');
        $soon = (clone $upcoming)->where('status', 'draft')->whereNotIn('id', $honeybook)->where('starts_at', '<', $now->copy()->addDays(7))->get();
        $cards[] = ['tone' => 'bad', 'title' => 'Fiestas en menos de 7 días sin staff apartado',
            'text' => 'Asigna staff y trajes; por la fecha necesitan tu aprobación.', 'items' => $soon->map($link)->all()];
        // Owner 2026-11-02: confirmed parties of the next 7 days whose team still has empty seats (planned Tue–Wed).
        $teamless = (clone $upcoming)->where('status', 'capacity_reserved')->where('starts_at', '<', $now->copy()->addDays(7))->get()->filter(function ($e) {
            $need = \App\Services\Team\TeamSlots::for($e); if ($need['people'] === [] && $need['costumes'] === []) return false;
            $plan = \App\Models\EventTeamPlan::where('event_id', $e->id)->first();
            if (! $plan) return ! \App\Models\ResourceReservation::where('event_id', $e->id)->whereIn('resource_id', \App\Models\Resource::where('kind', 'staff')->select('id'))->exists();
            return collect($plan->slots)->contains(fn ($s) => ! $s['resource_id']) || collect($plan->costumes)->contains(fn ($c) => ! $c['wearer_id']);
        });
        if ($teamless->isNotEmpty()) $cards[] = ['tone' => 'warn', 'title' => 'Fiestas de esta semana sin equipo completo',
            'text' => 'Ármalo en Agenda → Planear el fin de semana.', 'items' => $teamless->map($link)->values()->all()];
        // EXTRAS-01: what clients added from their link in the last 7 days (already in the balance; check the team).
        $extras = \App\Models\EventExtra::where('accepted_at', '>=', $now->copy()->subDays(7))->orderByDesc('id')->get();
        if ($extras->isNotEmpty()) $cards[] = ['tone' => 'info', 'title' => 'Extras que agregaron los clientes',
            'text' => 'Ya están en su saldo. Revisa el equipo de esa fiesta.',
            'items' => $extras->map(fn ($x) => ['label' => (Event::whereKey($x->event_id)->value('title') ?? 'Fiesta').' · '.Labels::money($x->amount_cents),
                'url' => EventResource::getUrl('view', ['record' => $x->event_id])])->all()];
        // Owner 2026-11-03: someone on a planned team answered "No puedo" from their link: replace them in Planear.
        $declined = \App\Models\StaffJobAnswer::where('answer', 'no')->whereIn('event_id', (clone $upcoming)->where('status', 'capacity_reserved')->select('id'))->get()
            ->filter(function ($a) {
                $e = Event::find($a->event_id); $plan = \App\Models\EventTeamPlan::where('event_id', $a->event_id)->first();
                return $e && $plan && $a->event_starts_at->equalTo($e->starts_at) && (collect($plan->slots)->contains(fn ($s) => (int) $s['resource_id'] === (int) $a->resource_id)
                    || collect($plan->costumes)->contains(fn ($c) => (int) $c['wearer_id'] === (int) $a->resource_id));
            });
        if ($declined->isNotEmpty()) $cards[] = ['tone' => 'bad', 'title' => 'Staff que dijo «No puedo»',
            'text' => 'Toca «Reemplazar» en Planear el fin de semana y guarda.',
            'items' => $declined->map(fn ($a) => ['label' => (\App\Models\Resource::whereKey($a->resource_id)->value('name') ?? 'Alguien').' · '.Event::whereKey($a->event_id)->value('title'),
                'url' => \App\Filament\Pages\WeekendPlan::getUrl(['fiesta' => $a->event_id])])->values()->all()];
        $hbSoon = (clone $upcoming)->whereIn('id', \App\Models\ImportedProjectLink::query()->select('event_id'))->where('starts_at', '<', $now->copy()->addDays(7))->get();
        $cards[] = ['tone' => 'info', 'title' => 'Fiestas de HoneyBook en los próximos 7 días',
            'text' => 'Su staff y sus cobros se manejan en HoneyBook hasta el cambio. Aquí solo para que las tengas presentes.', 'items' => $hbSoon->map($link)->all()];

        $noTravel = (clone $upcoming)->whereNull('travel_fee_cents')->get();
        $cards[] = ['tone' => 'warn', 'title' => 'Costo de transporte pendiente',
            'text' => 'Sin él no se puede fijar el total ni confirmar.', 'items' => $noTravel->map($link)->all()];

        $incomplete = (clone $upcoming)->where('status', 'draft')->where(function ($q) {
            $q->whereDoesntHave('services')->orWhereHas('services', fn ($s) => $s->where('operational_ready', false));
        })->get();
        $cards[] = ['tone' => 'warn', 'title' => 'Borradores incompletos',
            'text' => 'Falta el servicio, el personaje o el servicio no está activo en el catálogo.', 'items' => $incomplete->map($link)->all()];

        if (FinanceGate::enabled()) {
            $balances = (clone $upcoming)->whereIn('id', FinancialRevision::query()->select('event_id'))->get()
                ->map(fn ($e) => [$e, app(FinanceSummary::class)->forEvent($e)]);
            $dueSoon = $balances->filter(fn ($x) => $x[1]['balance_cents'] > 0 && $x[1]['financial_revision']?->balance_due_at?->lessThan($now->copy()->addDays(7)));
            $cards[] = ['tone' => 'info', 'title' => 'Saldos que vencen esta semana', 'text' => 'Recuérdale al cliente; el portal le muestra cómo pagar.',
                'items' => $dueSoon->map(fn ($x) => ['label' => $x[0]->title.' · saldo '.Labels::money($x[1]['balance_cents']).' · vence '.Labels::when($x[1]['financial_revision']->balance_due_at), 'url' => EventResource::getUrl('money', ['record' => $x[0]])])->values()->all()];
            $unknownDue = $balances->filter(fn ($x) => $x[1]['balance_cents'] > 0 && $x[1]['financial_revision']?->balance_due_at === null);
            $cards[] = ['tone' => 'warn', 'title' => 'Saldo con vencimiento por confirmar', 'text' => 'Revisa la fecha antes de programar un recordatorio.',
                'items' => $unknownDue->map(fn ($x) => ['label' => $x[0]->title.' · saldo '.Labels::money($x[1]['balance_cents']), 'url' => EventResource::getUrl('money', ['record' => $x[0]])])->values()->all()];
            $exceptions = DB::table('payment_exceptions')->where('state', 'open')->get();
            $cards[] = ['tone' => 'bad', 'title' => 'Pagos que necesitan revisión', 'text' => 'Llegó dinero pero algo no cuadró (por ejemplo, no se pudo apartar el staff).',
                'items' => $exceptions->map(fn ($x) => ['label' => (Event::whereKey($x->event_id)->value('title') ?? 'Evento').' · '.(json_decode($x->context, true)['message'] ?? $x->kind), 'url' => EventResource::getUrl('money', ['record' => $x->event_id])])->all()];
        }

        if (FinanceGate::enabled() && \App\Filament\Support\DocumentsGate::enabled()) {
            // Client Zelle notices are claims, never money: the owner checks the bank, then verifies or rejects on Dinero.
            $notices = \App\Models\ZelleNotice::query()->whereDoesntHave('review')->orderBy('id')->limit(50)->get();
            $cards[] = ['tone' => 'bad', 'title' => 'Avisos de Zelle por verificar', 'text' => 'El cliente dice que ya envió el Zelle. Revisa tu banco antes de contarlo como pago.',
                'items' => $notices->map(fn ($n) => ['label' => (Event::whereKey($n->event_id)->value('title') ?? 'Evento').' · dice '.Labels::money($n->amount_cents).' · '.Labels::when($n->submitted_at).(\App\Models\ZelleNoticeScreenshot::where('notice_id', $n->id)->exists() ? ' · con captura' : ''), 'url' => EventResource::getUrl('money', ['record' => $n->event_id])])->all()];
        }

        if (\App\Filament\Support\MailUi::enabled()) {
            // Bounces only in their own card; other warnings say honestly that the result is unknown or blocked.
            $read = app(\App\Services\Mail\ReadOutboundMail::class);
            $owner = \Filament\Facades\Filament::auth()->user();
            $item = function ($m) {
                $label = $m['event_id'] ? Event::whereKey($m['event_id'])->value('title') : \App\Models\Customer::whereKey($m['customer_id'] ?? null)->value('name');
                $url = match (true) {
                    $m['event_id'] !== null => EventResource::getUrl('view', ['record' => $m['event_id']]),
                    ($m['customer_id'] ?? null) !== null => \App\Filament\Resources\Customers\CustomerResource::getUrl('view', ['record' => $m['customer_id']]),
                    ($m['lead_id'] ?? null) !== null => Leads::getUrl(),
                    default => EmailTemplates::getUrl(),
                };
                return ['label' => ($label ?: 'Mensaje').' · '.(\App\Filament\Support\MailUi::MESSAGE_NAMES[$m['template_key']] ?? $m['template_key'])
                    .' · '.(\App\Filament\Support\MailUi::STATUS[$m['status']] ?? $m['status']), 'url' => $url];
            };
            $cards[] = ['tone' => 'bad', 'title' => 'Correos que rebotaron', 'text' => 'No llegaron. Confirma el correo del cliente por WhatsApp; no se reenvía solo.',
                'items' => collect($read->warnings($owner, ['status' => 'bounced', 'per_page' => 50])['data'])->map($item)->all()];
            $review = collect(['complained', 'delivery_unknown', 'dispatching', 'blocked'])->flatMap(fn ($st) => $read->warnings($owner, ['status' => $st, 'per_page' => 50])['data']);
            $cards[] = ['tone' => 'warn', 'title' => 'Correos por revisar', 'text' => 'Bloqueados, marcados como spam, o sin saber si salieron. Nada se reenvía automáticamente.',
                'items' => $review->map($item)->values()->all()];
        }

        if (\App\Filament\Support\DocumentsGate::enabled() && class_exists(\App\Services\Booking\ClientDetailsState::class)) {
            $quick = Event::query()->whereIn('id', \App\Models\QuickBookingRecord::query()->select('event_id'))->where('ends_at', '>=', $now)->orderBy('starts_at')->get()
                ->groupBy(fn ($e) => \App\Services\Booking\ClientDetailsState::forEvent($e));
            $contractLink = fn (Event $e) => ['label' => $e->title.' · '.Labels::when($e->starts_at), 'url' => EventResource::getUrl('contract', ['record' => $e])];
            $cards[] = ['tone' => 'bad', 'title' => 'Datos de cliente por revisar', 'text' => 'Ya llenó sus datos. Revísalos y apruébalos para preparar el contrato.',
                'items' => ($quick['review_details'] ?? collect())->map($contractLink)->values()->all()];
            $cards[] = ['tone' => 'info', 'title' => 'Contratos listos para emitir', 'text' => 'Datos aprobados: prepara y emite el contrato.',
                'items' => ($quick['ready_to_issue'] ?? collect())->map($contractLink)->values()->all()];
            $cards[] = ['tone' => 'warn', 'title' => 'Esperando datos del cliente', 'text' => 'Tiene su enlace pero todavía no llena sus datos. Recuérdale por WhatsApp.',
                'items' => ($quick['waiting_details'] ?? collect())->map($contractLink)->values()->all()];
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

        return Event::query()->with('customer')->where('status', '!=', 'cancelled')->where('starts_at', '>=', $from->utc())->where('starts_at', '<=', $to->utc())->orderBy('starts_at')->get()
            // Audit 2026-11-02: each party shows only what is still missing (same chips as its header); nothing when all is done.
            ->map(fn ($e) => (object) ['title' => $e->title, 'client' => filled($e->customer?->name) && ! str_contains(mb_strtolower($e->title), mb_strtolower($e->customer->name)) ? $e->customer->name : null,
                'when' => Labels::when($e->starts_at),
                'chips' => array_values(array_filter(\App\Filament\Support\PartyHeader::chips($e), fn ($c) => $c['tone'] !== 'ok')),
                'url' => $this->eventUrl($e)]);
    }

    public function getHeading(): string
    {
        return ''; // the greeting is the heading
    }

    private function eventUrl(Event $e): ?string
    {
        return EventResource::getUrl('view', ['record' => $e]);
    }
}
