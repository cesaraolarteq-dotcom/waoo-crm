<?php

namespace App\Filament\Resources\Resources\Pages;

use App\Filament\Resources\Resources\ResourceUnitResource;
use App\Filament\Support\RecordServicePage;
use App\Filament\Support\RecordServicePageGate;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditResource extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = ResourceUnitResource::class;

    protected static ?string $title = 'Editar unidad';

    // The version the editor was shown; sent as expected_version so a concurrent change returns 409 instead of being overwritten.
    #[Locked]
    public ?int $displayedVersion = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->displayedVersion = $this->getRecord()->version;
    }

    // No delete (the API has none). Costumes: link who can wear it (LinkCostumeCompatibility; unlinking is not in the contract yet).
    protected function getHeaderActions(): array
    {
        return [
            Action::make('compatibility')->label('Quién puede usarlo')->icon('heroicon-o-user-plus')
                ->visible(fn () => $this->getRecord()->kind === 'costume' && RecordServicePageGate::ready(\App\Services\Records\LinkCostumeCompatibility::class))
                ->modalHeading('Personas que pueden usar este traje')
                ->modalDescription(fn () => 'Ya pueden usarlo: '.($this->compatibleNames() ?: 'nadie todavía').'. Solo aparecen personas con el rol "Personaje".')
                ->schema([
                    Select::make('staff_id')->label('Agregar persona')->native(false)->searchable()->required()
                        ->options(fn () => \App\Models\Resource::query()->where('kind', 'staff')->where('status', 'active')
                            ->whereJsonContains('skills', 'performer')->orderBy('name')->pluck('name', 'id')),
                ])
                ->modalSubmitActionLabel('Agregar')
                ->action(function (array $data, Action $action) {
                    try {
                        app(\App\Services\Records\LinkCostumeCompatibility::class)->execute(Filament::auth()->user(), (int) $data['staff_id'], $this->getRecord()->getKey());
                    } catch (ValidationException|\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException $e) {
                        $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                        Notification::make()->danger()->title('No se guardó')->body($message)->persistent()->send();
                        $action->halt();
                    }
                    Notification::make()->success()->title('Persona agregada')->send();
                }),
            Action::make('unlink')->label('Quitar persona')->icon('heroicon-o-user-minus')->color('gray')
                ->visible(fn () => $this->getRecord()->kind === 'costume' && $this->compatibleNames() !== '' && RecordServicePageGate::ready(\App\Services\Records\UnlinkCostumeCompatibility::class))
                ->modalHeading('Quitar a una persona de este traje')
                ->modalDescription('Deja de poder usarlo en eventos nuevos. Si tiene un evento comprometido con este traje, primero hay que reasignarlo. Queda en el historial.')
                ->schema([
                    Select::make('staff_id')->label('Persona')->native(false)->required()
                        ->options(fn () => \Illuminate\Support\Facades\DB::table('costume_compatibilities')->join('resources', 'resources.id', '=', 'costume_compatibilities.staff_id')
                            ->where('costume_id', $this->getRecord()->getKey())->orderBy('resources.name')->pluck('resources.name', 'resources.id')),
                    \Filament\Forms\Components\Textarea::make('reason')->label('¿Por qué?')->required()->minLength(10)->maxLength(1000)
                        ->placeholder('Ej.: ya no trabaja con nosotros desde octubre.'),
                ])
                ->modalSubmitActionLabel('Quitar')
                ->action(function (array $data, Action $action) {
                    try {
                        $r = app(\App\Services\Records\UnlinkCostumeCompatibility::class)->execute(Filament::auth()->user(), (int) $data['staff_id'], $this->getRecord()->getKey(), $data['reason']);
                    } catch (ValidationException|\App\Exceptions\RecordWriteRejected|\Illuminate\Auth\Access\AuthorizationException $e) {
                        $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                        Notification::make()->danger()->title('No se quitó')->body($message)->persistent()->send();
                        $action->halt();
                    }
                    Notification::make()->success()->title($r['removed'] ? 'Persona quitada del traje' : 'Esa persona ya no estaba asignada')->send();
                }),
        ];
    }

    private function compatibleNames(): string
    {
        return \Illuminate\Support\Facades\DB::table('costume_compatibilities')->join('resources', 'resources.id', '=', 'costume_compatibilities.staff_id')
            ->where('costume_id', $this->getRecord()->getKey())->orderBy('resources.name')->pluck('resources.name')->implode(', ');
    }

    protected function getFooterWidgets(): array
    {
        return [\App\Filament\Resources\Resources\Widgets\ResourceBlocks::class];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveResource::class, [[...ResourceUnitResource::toServiceInput([...$data, 'kind' => $record->kind]), 'expected_version' => $this->displayedVersion], $record->getKey()]);
        $this->displayedVersion = $saved->version;

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
