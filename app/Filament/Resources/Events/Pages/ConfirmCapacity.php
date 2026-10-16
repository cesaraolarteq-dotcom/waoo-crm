<?php

namespace App\Filament\Resources\Events\Pages;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Variants\Pages\EditServiceVariant;
use App\Filament\Resources\Variants\ServiceVariantResource;
use App\Filament\Support\{ChicagoTime, Labels, RecordServicePageGate};
use App\Models\{Event, Resource as Unit, ResourceReservation};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{Checkbox, DatePicker, Hidden, Radio, Repeater, Select, Textarea, TextInput, TimePicker};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\{Actions, Callout, EmbeddedSchema, Form, Grid, Group, Section, Text};
use Filament\Schemas\Components\Utilities\{Get, Set};
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Step 8 (RESV-A-01): pick the crew/costumes/equipment and record the deposit already received.
// The backend revalidates everything under locks (ConfirmBooking). This page never writes models;
// it calls App\Services\Records\ConfirmCapacity (proposed in HANDOFF) and is off until that service exists.
class ConfirmCapacity extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EventResource::class;

    protected static ?string $title = 'Apartar recursos y confirmar';

    public ?array $data = [];

    #[Locked]
    public ?int $displayedVersion = null;

    public const SERVICE = 'App\\Services\\Records\\ConfirmCapacity';

    public static function canAccessFor(Event $event): bool
    {
        return $event->status === 'draft' && RecordServicePageGate::ready(self::SERVICE);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(self::canAccessFor($this->record), 403);
        $this->displayedVersion = $this->record->version;
        $this->form->fill(['payment' => ['method' => 'external_zelle'], 'transfers' => []]);
    }

    public function getSubheading(): ?string
    {
        $e = $this->record;

        return $e->title.' · '.Labels::when($e->starts_at).' · '.($e->customer?->name ?? '');
    }

    public function form(Schema $schema): Schema
    {
        $kinds = ['staff' => 'Personas', 'costume' => 'Trajes', 'equipment' => 'Equipo', 'vehicle' => 'Vehículos'];
        $pickers = [];
        foreach ($kinds as $kind => $label) {
            $pickers[] = Select::make("units.{$kind}")->label($label)->multiple()->native(false)->searchable()
                ->options(fn () => self::unitOptions($kind))->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => $set('transfers', $this->neighborTransfers(self::selectedIds($get))));
        }

        return $schema->statePath('data')->components([
            Callout::make('Qué necesita esta fiesta')->info()->columnSpanFull()
                ->description(fn () => $this->needsSummary()),
            Section::make('1. Staff, trajes y equipo')->columns(2)->schema($pickers)
                ->description('Elige las unidades exactas. Una persona no puede hacer dos roles a la vez; un traje necesita a alguien que pueda usarlo.'),
            Section::make('2. Depósito recibido')->columns(2)->schema([
                Group::make()->statePath('payment')->columns(2)->columnSpanFull()->schema([
                    Radio::make('method')->label('Cómo pagó')->inline()->required()
                        ->options(['external_zelle' => 'Zelle', 'external_card' => 'Tarjeta (Stripe)', 'external_cash' => 'Efectivo'])->columnSpanFull(),
                    TextInput::make('reference')->label('Referencia')->required()->minLength(3)->maxLength(120)
                        ->helperText('Número de confirmación de Zelle o del pago. No se puede repetir en otro evento.'),
                    TextInput::make('amount')->label('Monto recibido (USD)')->prefix('$')->required()->live(onBlur: true)
                        ->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 250 o 250.50.'])
                        ->helperText(fn () => $this->depositHint()),
                    DatePicker::make('date')->label('Fecha en que llegó')->native()->required()->live()->maxDate(now(ChicagoTime::TZ)),
                    TimePicker::make('time')->label('Hora (Houston)')->native()->seconds(false)->required()->live(),
                    Radio::make('choice')->label('Esa hora se repite ese día: ¿cuál?')->options(ChicagoTime::CHOICES)->required()->columnSpanFull()
                        ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('date'), $get('time'))),
                ]),
                Textarea::make('deposit_exception_reason')->label('Por qué se acepta menos del 50 %')->minLength(10)->maxLength(2000)->required()->columnSpanFull()
                    ->visible(fn (Get $get) => $this->isBelowTarget($get('payment.amount'))),
            ]),
            Section::make('3. Revisión')->columns(2)->schema([
                Checkbox::make('staff_confirmed')->label('Confirmé con cada persona que puede ir')->accepted()->columnSpanFull(),
                Checkbox::make('cargo_checked')->label('Revisé que el equipo y la gente caben en el vehículo')->accepted()->columnSpanFull(),
                Textarea::make('origin_return_note')->label('De dónde salen y cómo regresan')->required()->minLength(10)->maxLength(2000)->columnSpanFull()
                    ->placeholder('Ej.: salen de la bodega en Katy a las 2:00 pm en la van y regresan ahí.'),
                Textarea::make('short_notice_reason')->label('Faltan menos de 7 días: ¿por qué se aprueba?')->minLength(10)->maxLength(2000)->required()->columnSpanFull()
                    ->visible(fn () => $this->record->starts_at->lessThan(now()->addDays(7))),
            ]),
            Section::make('4. Traslados con eventos vecinos')->schema([
                Text::make('Si alguna unidad tiene otra fiesta antes o después, revisa el tráfico de ese día y esa hora. El margen mínimo es manejo + 45 min (o desmontaje + montaje + imprevistos, lo que sea mayor).'),
                Repeater::make('transfers')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->defaultItems(0)->columns(3)
                    ->itemLabel(fn (array $state) => $state['label'] ?? null)
                    ->schema([
                        Hidden::make('from_event_id'), Hidden::make('to_event_id'), Hidden::make('from_version'), Hidden::make('to_version'),
                        Hidden::make('departure_at'), Hidden::make('label'),
                        TextInput::make('travel_minutes')->label('Manejo (min)')->integer()->minValue(0)->maxValue(1440)->required(),
                        TextInput::make('contingency_minutes')->label('Imprevistos (min)')->integer()->minValue(1)->maxValue(720)->required(),
                        Textarea::make('note')->label('Fuente del tiempo de manejo')->required()->minLength(10)->maxLength(2000)->rows(2)
                            ->placeholder('Ej.: Google Maps para ese sábado a las 4:00 pm, 35 min.'),
                    ]),
            ])->visible(fn (Get $get) => count($get('transfers') ?? []) > 0),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('confirm')->footer([
                Actions::make([
                    Action::make('confirm')->label('Apartar y confirmar')->submit('confirm'),
                    Action::make('cancel')->label('Cancelar')->color('gray')->url(EventResource::getUrl('index')),
                ]),
            ]),
        ]);
    }

    public function confirm(): void
    {
        $state = $this->form->getState();
        $input = $this->toServiceInput($state);
        try {
            app(self::SERVICE)->execute(Filament::auth()->user(), $this->record->getKey(), $input);
        } catch (ValidationException $e) {
            $this->showFieldErrors($e);

            return;
        } catch (RecordWriteRejected|AuthorizationException $e) {
            $this->fail($e->getMessage());

            return;
        } catch (HttpExceptionInterface $e) {
            $this->fail($e->getMessage() ?: 'No se pudo confirmar. Recarga la página.');

            return;
        }
        Notification::make()->success()->title('Recursos apartados')
            ->body('El staff, los trajes y el equipo quedaron asignados con el depósito verificado. Esto no es el contrato firmado.')->send();
        $this->redirect(EventResource::getUrl('index'));
    }

    /** Form state -> ConfirmCapacity input (same shape as POST /admin/api/events/{id}/confirm-capacity). */
    public function toServiceInput(array $s): array
    {
        $p = $s['payment'] ?? [];
        $review = ['staff_confirmed' => (bool) ($s['staff_confirmed'] ?? false), 'cargo_checked' => (bool) ($s['cargo_checked'] ?? false),
            'origin_return_note' => trim((string) ($s['origin_return_note'] ?? '')), 'transfers' => []];
        foreach (['short_notice_reason', 'deposit_exception_reason'] as $optional) {
            if (filled($s[$optional] ?? null)) {
                $review[$optional] = trim($s[$optional]);
            }
        }
        foreach (array_values($s['transfers'] ?? []) as $t) {
            $review['transfers'][] = ['from_event_id' => (int) $t['from_event_id'], 'to_event_id' => (int) $t['to_event_id'],
                'from_version' => (int) $t['from_version'], 'to_version' => (int) $t['to_version'],
                'travel_minutes' => (int) $t['travel_minutes'], 'contingency_minutes' => (int) $t['contingency_minutes'],
                'departure_at' => $t['departure_at'], 'note' => trim((string) $t['note'])];
        }

        return [
            'expected_version' => $this->displayedVersion,
            'resource_ids' => self::selectedIds(fn ($path) => data_get($s, $path)),
            'payment' => ['method' => $p['method'] ?? null, 'reference' => trim((string) ($p['reference'] ?? '')),
                'amount_cents' => EditServiceVariant::toCents((string) ($p['amount'] ?? '0')),
                'received_at' => ChicagoTime::toIso($p['date'], $p['time'], $p['choice'] ?? null, 'data.payment.date')],
            'review' => $review,
        ];
    }

    /** Selected unit ids from the four pickers, sorted and unique. $get reads form state by path. */
    public static function selectedIds(callable $get): array
    {
        $ids = [];
        foreach (['staff', 'costume', 'equipment', 'vehicle'] as $kind) {
            $ids = [...$ids, ...array_map('intval', (array) ($get("units.{$kind}") ?? []))];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public static function unitOptions(string $kind): array
    {
        return Unit::query()->where('kind', $kind)->where('status', 'active')->orderBy('name')->get()
            ->mapWithKeys(fn ($u) => [$u->id => $u->name.match ($kind) {
                'staff' => ' · '.collect($u->skills ?? [])->map(fn ($s) => Labels::label(Labels::SKILL, $s))->implode(', '),
                'equipment' => ' · '.Labels::label(Labels::EQUIPMENT, $u->equipment_kind),
                'vehicle' => " · {$u->seats} asientos",
                default => '',
            }])->all();
    }

    /** Nearest earlier and later events sharing a selected unit -> transfer rows the owner must review. */
    public function neighborTransfers(array $ids): array
    {
        $event = $this->record;
        $pairs = [];
        foreach ($ids as $id) {
            $others = ResourceReservation::where('resource_id', $id)->where('event_id', '!=', $event->id)->with('event')->get()->pluck('event');
            $before = $others->filter(fn ($o) => $o->ends_at->lessThanOrEqualTo($event->starts_at))->sortByDesc('ends_at')->first();
            $after = $others->filter(fn ($o) => $o->starts_at->greaterThanOrEqualTo($event->ends_at))->sortBy('starts_at')->first();
            foreach ([[$before, $event], [$event, $after]] as [$from, $to]) {
                if ($from && $to) {
                    $pairs["{$from->id}-{$to->id}"] = [
                        'from_event_id' => $from->id, 'to_event_id' => $to->id, 'from_version' => $from->version, 'to_version' => $to->version,
                        'departure_at' => $from->ends_at->addMinutes((int) $from->breakdown_minutes)->utc()->format('Y-m-d\TH:i:sP'),
                        'label' => ($from->is($event) ? 'De esta fiesta' : '«'.$from->title.'» (termina '.Labels::when($from->ends_at).')')
                            .' → '.($to->is($event) ? 'esta fiesta' : '«'.$to->title.'» ('.Labels::when($to->starts_at).')'),
                        'travel_minutes' => null, 'contingency_minutes' => null, 'note' => null,
                    ];
                }
            }
        }

        return array_values($pairs);
    }

    private function totalCents(): ?int
    {
        $services = $this->record->services()->join('price_versions', 'price_versions.id', '=', 'event_services.price_version_id')->sum('price_versions.amount_cents');

        return $this->record->travel_fee_cents === null ? null : (int) $services + $this->record->travel_fee_cents;
    }

    private function isBelowTarget(?string $amount): bool
    {
        $total = $this->totalCents();
        if ($total === null || ! $amount || ! preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount)) {
            return false;
        }

        return EditServiceVariant::toCents($amount) * 2 < $total;
    }

    private function depositHint(): string
    {
        $total = $this->totalCents();

        return $total === null ? 'Falta el costo de transporte: no se puede confirmar sin él.' : 'Total '.Labels::money($total).' · 50 % = '.Labels::money(intdiv($total + 1, 2)).'.';
    }

    private function needsSummary(): string
    {
        $lines = $this->record->services()->with([])->get()->map(function ($service) {
            $name = \App\Models\ServiceVariant::whereKey($service->service_variant_id)->value('name');

            return $name.': '.(ServiceVariantResource::needs($service->requirements_snapshot ?? []) ?? 'requisitos sin definir');
        });

        return $lines->isEmpty() ? 'Este borrador no tiene servicios. Agrégalos antes de confirmar.' : $lines->implode(' · ');
    }

    private function showFieldErrors(ValidationException $e): void
    {
        $fieldErrors = [];
        foreach ($e->errors() as $key => $messages) {
            if (str_starts_with($key, 'payment.') && ! str_starts_with($key, 'payment.amount_cents') && ! str_starts_with($key, 'payment.received_at')) {
                $fieldErrors["data.{$key}"] = $messages;
            } elseif (str_starts_with($key, 'review.transfers.')) {
                $fieldErrors['data.transfers.'.substr($key, strlen('review.transfers.'))] = $messages;
            } elseif (str_starts_with($key, 'review.')) {
                $fieldErrors['data.'.substr($key, strlen('review.'))] = $messages;
            } elseif (str_starts_with($key, 'data.')) {
                $fieldErrors[$key] = $messages;
            } else {
                $general[] = collect($messages)->first(); // booking-level reasons (conflicts, crew pairing…)
            }
        }
        if ($general ?? []) {
            $this->fail(implode(' ', $general));
        }
        if ($fieldErrors) {
            throw ValidationException::withMessages($fieldErrors);
        }
    }

    private function fail(string $message): void
    {
        Notification::make()->danger()->title('No se apartó nada')->body($message)->persistent()->send();
    }
}
