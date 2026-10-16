<?php

namespace App\Filament\Resources\Resources\Widgets;

use App\Exceptions\RecordWriteRejected;
use App\Filament\Support\{ChicagoTime, Labels, RecordServicePageGate};
use App\Models\ResourceBlock;
use App\Services\Records\{BlockResource, ReleaseBlock};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\{DatePicker, Radio, Textarea, TextInput, TimePicker};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

// Calendar blocks of one unit (vacation, costume in repair…). Writes only through BlockResource / ReleaseBlock.
// A lifted block stays in the history; lifting it does not mark the unit as ready (that is its status).
class ResourceBlocks extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Bloqueos de calendario')
            ->description('Fechas en que esta unidad no se puede asignar. Levantar un bloqueo no cambia su estado (Disponible / En mantenimiento).')
            ->query(fn () => ResourceBlock::query()->where('resource_id', $this->record?->getKey())
                ->select('resource_blocks.*')
                ->selectSub(fn ($s) => $s->from('users')->select('name')->whereColumn('users.id', 'resource_blocks.released_by')->limit(1), 'released_by_name'))
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('Desde')->formatStateUsing(fn ($state) => Labels::when($state)),
                TextColumn::make('ends_at')->label('Hasta')->formatStateUsing(fn ($state) => Labels::when($state)),
                TextColumn::make('reason')->label('Motivo')->wrap(),
                TextColumn::make('state')->label('Estado')->badge()
                    ->state(fn ($record) => $record->released_at ? 'Levantado' : 'Activo')
                    ->color(fn ($state) => $state === 'Activo' ? 'warning' : 'gray')
                    ->description(fn ($record) => $record->released_at ? Labels::when($record->released_at).' · '.($record->released_by_name ?? '').' · '.$record->release_reason : null),
            ])
            ->headerActions([
                Action::make('block')->label('Bloquear fechas')->icon('heroicon-o-no-symbol')
                    ->visible(fn () => RecordServicePageGate::ready(BlockResource::class))
                    ->modalHeading('Bloquear esta unidad')
                    ->modalDescription('No se podrá asignar en ese horario. Si choca con un evento o un traslado ya revisado, el sistema no lo permite.')
                    ->schema([
                        Grid::make(2)->schema([
                            DatePicker::make('start_date')->label('Desde (fecha)')->native()->required()->live(),
                            TimePicker::make('start_time')->label('Hora')->native()->seconds(false)->required()->live(),
                            Radio::make('start_choice')->label('Esa hora se repite ese día: ¿cuál?')->options(ChicagoTime::CHOICES)->columnSpanFull()
                                ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('start_date'), $get('start_time'))),
                            DatePicker::make('end_date')->label('Hasta (fecha)')->native()->required()->live(),
                            TimePicker::make('end_time')->label('Hora')->native()->seconds(false)->required()->live(),
                            Radio::make('end_choice')->label('Esa hora se repite ese día: ¿cuál?')->options(ChicagoTime::CHOICES)->columnSpanFull()
                                ->visible(fn (Get $get) => ChicagoTime::isAmbiguous($get('end_date'), $get('end_time'))),
                        ]),
                        TextInput::make('reason')->label('Motivo')->required()->maxLength(255)->placeholder('Ej.: vacaciones, traje en reparación'),
                    ])
                    ->modalSubmitActionLabel('Bloquear')
                    ->action(function (array $data, Action $action) {
                        $this->runService($action, fn () => app(BlockResource::class)->execute(Filament::auth()->user(), $this->record->getKey(), [
                            'starts_at' => ChicagoTime::toIso($data['start_date'], $data['start_time'], $data['start_choice'] ?? null, 'start_date'),
                            'ends_at' => ChicagoTime::toIso($data['end_date'], $data['end_time'], $data['end_choice'] ?? null, 'end_date'),
                            'reason' => $data['reason'],
                        ]), 'Fechas bloqueadas');
                    }),
            ])
            ->recordActions([
                Action::make('release')->label('Levantar bloqueo')->icon('heroicon-o-lock-open')->color('gray')
                    ->visible(fn ($record) => $record->released_at === null && RecordServicePageGate::ready(ReleaseBlock::class))
                    ->modalHeading('Levantar bloqueo')
                    ->modalDescription('El bloqueo queda en el historial con tu nombre y el motivo. No cambia el estado de la unidad.')
                    ->schema([
                        Textarea::make('reason')->label('¿Por qué se levanta?')->required()->minLength(10)->maxLength(1000)
                            ->placeholder('Ej.: el traje ya regresó de la tintorería y se revisó.'),
                    ])
                    ->modalSubmitActionLabel('Levantar')
                    ->action(function (array $data, Action $action, $record) {
                        $this->runService($action, fn () => app(ReleaseBlock::class)->execute(Filament::auth()->user(), $this->record->getKey(), $record->getKey(), $data['reason']), 'Bloqueo levantado');
                    }),
            ])
            ->emptyStateHeading('Sin bloqueos')
            ->emptyStateDescription('Esta unidad está libre en el calendario, salvo sus eventos.')
            ->paginated([10]);
    }

    private function runService(Action $action, \Closure $call, string $success): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('No se guardó')->body(collect($e->errors())->flatten()->first())->persistent()->send();
            $action->halt();
        } catch (RecordWriteRejected|AuthorizationException $e) {
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
            $action->halt();
        }
        Notification::make()->success()->title($success)->send();
    }
}
