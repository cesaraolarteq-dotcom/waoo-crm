<?php

namespace App\Filament\Support;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

// Filament adapter for the audited record services (docs/RECORD-SERVICES.md).
// Writes only through App\Services\Records\*; never Eloquent create/update/save here.
trait RecordServicePage
{
    protected function runRecordService(string $service, array $arguments): mixed
    {
        try {
            return app($service)->execute(Filament::auth()->user(), ...$arguments);
        } catch (ValidationException $e) {
            // Keep the service's Spanish messages and field paths, mapped onto the form state.
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["data.{$field}" => $messages])->all()
            );
        } catch (\App\Exceptions\RecordWriteRejected $e) {
            Notification::make()->danger()->title('No se guardó')->body($e->getMessage())->persistent()->send();
            $this->halt(true);
        }
    }

}
