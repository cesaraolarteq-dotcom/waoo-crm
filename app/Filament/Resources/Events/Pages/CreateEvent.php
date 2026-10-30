<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Filament\Support\RecordServicePage;
use App\Models\{Customer, PriceVersion, ServiceVariant};
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateEvent extends CreateRecord
{
    use RecordServicePage;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Nuevo evento (borrador)';

    protected ?string $subheading = 'Un borrador no aparta staff ni trajes. Se apartan al confirmar con el depósito recibido.';

    protected static bool $canCreateAnother = false;

    /** Website package label (EN and ES form options) → catalog variant name. Anything else is left for the owner. */
    public const LEAD_PACKAGES = [
        'Star Package' => 'Estrella (Star)', 'Paquete Estrella' => 'Estrella (Star)',
        'Magic Package' => 'Mágico (Magic)', 'Paquete Mágico' => 'Mágico (Magic)',
        'Music & Games' => 'Música y Juegos', 'Música y Juegos' => 'Música y Juegos',
        'Music & Games 2.0' => 'Música y Juegos 2.0', 'Música y Juegos 2.0' => 'Música y Juegos 2.0',
        'Waoo Package' => 'Waoo', 'Paquete Waoo' => 'Waoo',
    ];

    /** "Crear cotización" from a website lead: the lead (id + version as opened) and, when she is not a client yet, her contact. */
    #[\Livewire\Attributes\Locked]
    public ?int $leadId = null;

    #[\Livewire\Attributes\Locked]
    public ?int $leadVersion = null;

    #[\Livewire\Attributes\Locked]
    public ?array $leadClient = null;

    #[\Livewire\Attributes\Locked]
    public ?array $leadSummary = null;

    /** "Nueva cotización" (Cotizaciones page): after saving, open the quote right away. */
    #[\Livewire\Attributes\Locked]
    public bool $quoteAfterSave = false;

    public function mount(): void
    {
        parent::mount();
        $this->quoteAfterSave = request()->boolean('cotizar');
        $this->fillFromLead(request()->integer('lead') ?: null);
    }

    /** Prefill from the lead; the owner still reviews everything before saving. Never writes on page load. */
    public function fillFromLead(?int $id): void
    {
        if (! $id || ! (bool) config('waoo.sandbox_lead_intake', false) || ! class_exists(\App\Services\Leads\ReadLeads::class)) return;
        $lead = app(\App\Services\Leads\ReadLeads::class)->show(Filament::auth()->user(), $id);
        if (! in_array($lead['status'], ['new', 'contacted', 'quoted'], true)) {
            \Filament\Notifications\Notification::make()->warning()->title('Este prospecto ya no se puede cotizar')->body('Está '.(\App\Filament\Pages\Leads::STATUS[$lead['status']] ?? $lead['status']).'.')->send();

            return;
        }
        $p = $lead['submission']['payload'];
        $this->leadId = $lead['id'];
        $this->leadVersion = $lead['version'];
        $this->leadSummary = ['package' => $p['package'] ?? '', 'travel' => $p['travel_estimate_text'] ?? null, 'message' => $p['message'] ?? null];

        $hints = $lead['duplicate_hints'] ?? [];
        $customerId = count($hints) === 1 ? (int) $hints[0]['customer_id'] : null;
        if ($customerId === null) {
            $this->leadClient = ['name' => $p['name'], 'phone' => $p['phone'] ?? null, 'email' => $p['email'] ?? null, 'locale' => $p['locale'] ?? 'es'];
        }

        $variant = isset(self::LEAD_PACKAGES[$p['package'] ?? ''])
            ? ServiceVariant::where('name', self::LEAD_PACKAGES[$p['package']])->where('status', '!=', 'retired')->first() : null;
        $price = $variant ? PriceVersion::where('service_variant_id', $variant->id)->orderByDesc('id')->first() : null;
        $priceIds = $price ? [(string) $price->id] : [];
        $start = $p['start_time'] ?? null;
        $end = ($start && $variant && $variant->duration_minutes) ? date('H:i', strtotime("2000-01-01 $start") + 60 * (int) $variant->duration_minutes) : null;
        $timing = $priceIds ? \App\Services\Records\PackageTimingDefaults::forPriceIds([(int) $price->id]) : ['setup_minutes' => null, 'breakdown_minutes' => null];
        $characters = (int) ($variant->character_quantity ?? 0);
        $slots = [];
        for ($i = 0; $i < $characters; $i++) {
            $slots[(string) \Illuminate\Support\Str::uuid()] = ['price_version_id' => $priceIds[0], 'character_id' => null, 'quantity' => 1, 'outfit' => null, 'note' => null];
        }
        $clientName = $customerId ? Customer::whereKey($customerId)->value('name') : $p['name'];
        $title = EventResource::suggestTitle($priceIds, [], $p['event_date'] ?? null, $clientName);

        $this->form->fill([...$this->form->getRawState(),
            'customer_id' => $customerId, 'date' => $p['event_date'] ?? null, 'start_time' => $start, 'end_time' => $end,
            'event_type' => \App\Filament\Pages\Leads::EVENT_TYPES[$p['event_type'] ?? ''][0] ?? null,
            'postal_code' => $p['zip'] ?? null, 'price_version_ids' => $priceIds,
            'characters' => $slots, 'character_count' => $characters, 'character_count_auto' => $characters,
            'setup_minutes' => $timing['setup_minutes'] === null ? null : (string) $timing['setup_minutes'], 'setup_minutes_auto' => $timing['setup_minutes'] === null ? null : (string) $timing['setup_minutes'],
            'breakdown_minutes' => $timing['breakdown_minutes'] === null ? null : (string) $timing['breakdown_minutes'], 'breakdown_minutes_auto' => $timing['breakdown_minutes'] === null ? null : (string) $timing['breakdown_minutes'],
            'title' => $title, 'title_suggested' => $title,
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        // A lead who is not a client yet becomes one in the same transaction as her draft, so a failed save leaves neither.
        return DB::transaction(function () use ($data) {
            if (blank($data['customer_id'] ?? null) && $this->leadClient !== null) {
                $data['customer_id'] = $this->runRecordService(\App\Services\Records\SaveCustomer::class, [array_filter($this->leadClient, fn ($v) => $v !== null)])->id;
            }

            $event = $this->runRecordService(\App\Services\Records\SaveEventDraft::class, [EventResource::toServiceInput($data)]);
            // A party created from a website lead came through the website (Reportes, "De dónde vienen").
            if ($this->leadId !== null) \App\Models\EventChannel::record($event->id, 'website', \Filament\Facades\Filament::auth()->id());

            return $event;
        });
    }

    protected function getRedirectUrl(): string
    {
        // From a lead, go straight to the quote: the event page opens "Crear cotización" and marks the lead quoted when it is created.
        if ($this->leadId !== null) {
            return static::getResource()::getUrl('view', ['record' => $this->record, 'action' => 'quoteCreate', 'lead' => $this->leadId, 'lead_version' => $this->leadVersion]);
        }

        return $this->quoteAfterSave
            ? static::getResource()::getUrl('view', ['record' => $this->record, 'action' => 'quoteCreate'])
            : static::getResource()::getUrl('index');
    }
}
