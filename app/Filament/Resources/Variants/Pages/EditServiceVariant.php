<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Resources\Variants\ServiceVariantResource;
use App\Filament\Support\RecordServicePage;
use App\Filament\Support\RecordServicePageGate;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

class EditServiceVariant extends EditRecord
{
    use RecordServicePage;

    protected static string $resource = ServiceVariantResource::class;

    protected static ?string $title = 'Editar servicio';

    // The version the editor was shown; sent as expected_version so a concurrent change returns 409 instead of being overwritten.
    #[Locked]
    public ?int $displayedVersion = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->displayedVersion = $this->getRecord()->version;
    }

    // No delete (the API has none). Prices are append-only: "Nuevo precio" adds a version, never edits one.
    protected function getHeaderActions(): array
    {
        return [
            Action::make('newPrice')->label('Nuevo precio')->icon(Heroicon::OutlinedTag)
                ->visible(fn () => RecordServicePageGate::ready(\App\Services\Records\AddPriceVersion::class))
                ->modalHeading('Nuevo precio')
                ->modalDescription('El precio anterior queda en el historial. Las reservas ya hechas conservan su precio.')
                ->schema([
                    TextInput::make('amount')->label('Precio regular (USD, sin costo de transporte)')->prefix('$')
                        ->required()->rule('regex:/^\d{1,7}(\.\d{1,2})?$/')->placeholder('Ej.: 100')
                        ->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 100 o 100.50.']),
                ])
                ->modalSubmitActionLabel('Guardar precio')
                ->action(function (array $data, Action $action) {
                    try {
                        app(\App\Services\Records\AddPriceVersion::class)->execute(
                            Filament::auth()->user(), $this->getRecord()->getKey(),
                            ['amount_cents' => self::toCents($data['amount']), 'currency' => 'USD'],
                        );
                    } catch (ValidationException|\App\Exceptions\RecordWriteRejected $e) {
                        $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                        Notification::make()->danger()->title('No se guardó el precio')->body($message)->persistent()->send();
                        $action->halt();
                    }
                    Notification::make()->success()->title('Precio guardado')->send();
                }),
        ];
    }

    /** "375.5" -> 37550, without floating-point rounding. */
    public static function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $saved = $this->runRecordService(\App\Services\Records\SaveVariant::class, [[...ServiceVariantResource::toServiceInput($data), 'expected_version' => $this->displayedVersion], $record->getKey()]);
        $this->displayedVersion = $saved->version;

        return $saved;
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
