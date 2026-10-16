<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\{FinanceGate, Labels};
use App\Models\{PriceVersion, Resource as Unit, ServiceVariant, User};
use App\Services\Finance\FinanceSummary;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

// Event file (read-only), for drafts and booked events alike: details, services + characters, assigned units,
// money and the audit history. Changes happen in their own screens (edit draft, Apartar recursos, Dinero).
class ViewEvent extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament.pages.event-file';

    public const ACTIONS = [
        'event.created' => 'Creó el borrador', 'event.draft_updated' => 'Editó el borrador', 'booking.capacity_reserved' => 'Apartó staff y trajes',
        'finance.revision_created' => 'Fijó los montos', 'payment.zelle_received' => 'Registró un Zelle', 'payment.capacity_linked' => 'Vinculó el pago con los recursos',
        'portal.zelle_notice_submitted' => 'La clienta avisó que envió un Zelle', 'payment.zelle_notice_reviewed' => 'Revisó un aviso de Zelle de la clienta',
    ];

    /** Zelle notice reviews say which way they went; amounts stay on the Dinero screen. */
    public static function historyLabel(object $a): string
    {
        $label = self::ACTIONS[$a->action] ?? $a->action;
        if ($a->action === 'payment.zelle_notice_reviewed') {
            $decision = json_decode((string) $a->after, true)['decision'] ?? null;
            $label .= match ($decision) { 'verified' => ': verificado', 'rejected' => ': rechazado', default => '' };
        }

        return $label;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        return ($this->record->customer?->name ?? '').' · '.Labels::when($this->record->starts_at).' – '.$this->record->ends_at->setTimezone(Labels::TZ)->locale('es')->translatedFormat('g:i a');
    }

    protected function getHeaderActions(): array
    {
        $e = $this->record;

        return [
            Action::make('edit')->label('Editar borrador')->color('gray')->visible(fn () => EventResource::canEdit($e))->url(fn () => EventResource::getUrl('edit', ['record' => $e])),
            Action::make('money')->label('Dinero')->icon('heroicon-o-banknotes')->color('gray')->visible(fn () => FinanceGate::enabled())->url(fn () => EventResource::getUrl('money', ['record' => $e])),
            Action::make('contract')->label('Contrato y enlace')->icon('heroicon-o-document-text')->color('gray')->visible(fn () => \App\Filament\Support\DocumentsGate::enabled())->url(fn () => EventResource::getUrl('contract', ['record' => $e])),
            Action::make('confirm')->label('Apartar recursos y confirmar')->visible(fn () => ConfirmCapacity::canAccessFor($e))->url(fn () => EventResource::getUrl('confirm', ['record' => $e])),
        ];
    }

    public function file(): array
    {
        $e = $this->record->fresh(['customer', 'services.characters', 'reservations']);
        $services = $e->services->map(fn ($s) => (object) [
            'name' => ServiceVariant::whereKey($s->service_variant_id)->value('name'),
            'price' => Labels::money(PriceVersion::whereKey($s->price_version_id)->value('amount_cents')),
            'duration' => Labels::duration($s->duration_minutes),
            'characters' => $s->characters->map(fn ($c) => $c->quantity.' × '.$c->character_name_snapshot)->implode(', '),
            'ready' => $s->operational_ready,
        ]);
        $units = Unit::query()->whereIn('id', $e->reservations->pluck('resource_id'))->orderBy('kind')->orderBy('name')->get()
            ->map(fn ($u) => (object) ['name' => $u->name, 'kind' => Labels::label(Labels::KIND, $u->kind)]);
        $history = DB::table('audit_entries')->where(function ($q) use ($e) {
            $q->where(fn ($w) => $w->where('subject_type', 'events')->where('subject_id', $e->id));
            foreach (['financial_revisions', 'ledger_entries', 'zelle_notices', 'zelle_notice_reviews'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', $table)->whereIn('subject_id', DB::table($table)->where('event_id', $e->id)->pluck('id')));
                }
            }
        })->orderByDesc('id')->limit(50)->get()->map(fn ($a) => (object) [
            'what' => self::historyLabel($a), 'who' => User::whereKey($a->actor_id)->value('name') ?? (str_starts_with($a->action, 'portal.') ? 'La clienta' : 'Sistema'),
            'when' => Labels::when(\Carbon\CarbonImmutable::parse($a->created_at)),
        ]);

        return [
            'event' => $e, 'services' => $services, 'units' => $units, 'history' => $history,
            'type' => $e->event_type ? (EventResource::EVENT_TYPES[$e->event_type] ?? $e->event_type) : 'Sin clasificar',
            'status' => Labels::label(Labels::EVENT_STATUS, $e->status), 'status_color' => Labels::EVENT_STATUS_COLOR[$e->status] ?? 'gray',
            'finance' => FinanceGate::enabled() ? app(FinanceSummary::class)->forEvent($e) : null,
        ];
    }
}
