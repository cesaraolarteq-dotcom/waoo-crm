<?php

namespace App\Filament\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{FinanceGate, Labels};
use App\Models\Lead;
use App\Services\Leads\{ReadLeads, UpdateLead};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Select, Textarea};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\{Locked, Url};

// LEAD-01 "Prospectos": requests from the website form, read through Codex's ReadLeads (owner only). Website text is
// untrusted and always escaped. Duplicate hints are hints, never merges. Converting opens Reserva rápida prefilled;
// the lead becomes "booked" only when that booking really saves.
class Leads extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;
    protected static string|\UnitEnum|null $navigationGroup = 'Vender';
    protected static ?string $navigationLabel = 'Prospectos';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'prospectos';
    protected static ?string $title = 'Prospectos de la web';
    protected string $view = 'filament.pages.leads';

    /** Website form event types (as sent) → [CRM event_type, Spanish label]. */
    public const EVENT_TYPES = ['Birthday' => ['birthday', 'Cumpleaños'], 'Baby Shower' => ['baby_shower', 'Baby shower'], 'Gender Reveal' => ['gender_reveal', 'Gender reveal'],
        'Corporate Event' => ['corporate', 'Corporativo'], 'Family Celebration' => ['other', 'Celebración familiar'], 'School Event' => ['other', 'Evento escolar'], 'Other' => ['other', 'Otro']];

    public const STATUS = ['new' => 'Nuevo', 'contacted' => 'Contactado', 'quoted' => 'Cotizado', 'booked' => 'Reservado', 'lost' => 'Perdido'];

    #[Url]
    public string $status = 'new';

    /** Page of the current filter (50 per page, the service's bound). Changing the filter goes back to page 1. */
    #[Url(as: 'pagina')]
    public int $leadPage = 1;

    public ?int $lastPage = null;

    public array $pageInfo = [];

    public function updatedStatus(): void
    {
        $this->leadPage = 1;
    }

    public function goToPage(int $page): void
    {
        $this->leadPage = max(1, min($page, $this->lastPage ?? $page));
    }

    /** The lead open in the "Actualizar" modal, as shown when it opened; a save sends this version only. */
    #[Locked]
    public ?int $editingLeadId = null;

    #[Locked]
    public ?int $editingLeadVersion = null;

    public static function canAccess(): bool
    {
        return parent::canAccess() && FinanceGate::enabled() && (bool) config('waoo.sandbox_lead_intake', false) && class_exists(ReadLeads::class);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) return null;
        $n = Lead::where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public function rows(): array
    {
        $page = app(ReadLeads::class)->index(Filament::auth()->user(), [...(array_key_exists($this->status, self::STATUS) ? ['status' => $this->status] : []),
            'per_page' => 50, 'page' => max(1, $this->leadPage)]);
        $this->lastPage = $page->lastPage();
        $this->pageInfo = ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];

        return collect($page->items())->map(function ($l) {
            $p = $l['submission']['payload'] ?? [];

            return (object) ['id' => $l['id'], 'status' => $l['status'], 'version' => $l['version'], 'note' => $l['owner_note'] ?? null, 'event_id' => $l['booked_event_id'] ?? null,
                'name' => $p['name'] ?? '', 'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '', 'date' => $p['event_date'] ?? null, 'time' => $p['start_time'] ?? null,
                'zip' => $p['zip'] ?? '', 'package' => in_array($p['package'] ?? '', ['', 'unspecified'], true) ? 'Sin elegir' : $p['package'],
                'type' => self::EVENT_TYPES[$p['event_type'] ?? ''][1] ?? (in_array($p['event_type'] ?? '', ['', 'unspecified'], true) ? 'Sin elegir' : $p['event_type']), 'message' => $p['message'] ?? null,
                'travel' => $p['travel_estimate_text'] ?? null, 'demand' => $p['demand_flag'] ?? 'unknown', 'locale' => $p['locale'] ?? 'es',
                'received' => isset($l['submission']['received_at']) ? Labels::when(\Carbon\CarbonImmutable::parse($l['submission']['received_at'])) : '',
                'hints' => $l['duplicate_hints'] ?? []];
        })->all();
    }

    public function counts(): array
    {
        return Lead::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
    }

    public function updateAction(): Action
    {
        return Action::make('update')->label('Actualizar')->icon('heroicon-o-pencil-square')->color('gray')->size('sm')
            ->mountUsing(function (array $arguments, $schema) {
                $lead = Lead::findOrFail((int) ($arguments['id'] ?? 0));
                $this->editingLeadId = $lead->id;
                $this->editingLeadVersion = $lead->version;
                $schema->fill(['status' => $lead->status === 'booked' ? null : $lead->status, 'owner_note' => $lead->owner_note]);
            })
            ->modalHeading('Actualizar prospecto')
            ->schema([
                Select::make('status')->label('Estado')->native(false)->options(array_diff_key(self::STATUS, ['booked' => 1]))
                    ->disabled(fn () => Lead::find($this->editingLeadId)?->status === 'booked')
                    ->helperText('“Reservado” se pone solo cuando la reserva rápida se guarda.'),
                Textarea::make('owner_note')->label('Nota (solo la ves tú)')->maxLength(2000)->rows(3),
            ])
            ->modalSubmitActionLabel('Guardar')
            ->action(function (array $data, Action $action) {
                $lead = Lead::find($this->editingLeadId);
                $input = ['expected_version' => (int) $this->editingLeadVersion, 'owner_note' => filled($data['owner_note'] ?? null) ? trim($data['owner_note']) : null];
                if ($lead && $lead->status !== 'booked' && filled($data['status'] ?? null)) $input['status'] = $data['status'];
                try {
                    app(UpdateLead::class)->execute(Filament::auth()->user(), (int) $this->editingLeadId, $input);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('No se guardó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
                    $action->halt();
                } catch (RecordWriteRejected $e) {
                    Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Prospecto actualizado')->send();
            });
    }
}
