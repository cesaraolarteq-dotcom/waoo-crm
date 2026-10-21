<?php
namespace App\Services\Imports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{HistoricalEvent, ImportedProjectLink};

/** Both callers must already hold ImportInput::lock(). Staging never claims an identity. */
final class HoneyBookProjectIdentity
{
    public static function operating(string $key): void
    {
        RecordWriteRejected::unless(!HistoricalEvent::where('project_key', $key)->lockForUpdate()->first(),
            409, 'Este proyecto ya existe en el historial; no se creó una fiesta operativa.');
    }
    public static function history(string $key): void
    {
        RecordWriteRejected::unless(!ImportedProjectLink::where('project_key', $key)->lockForUpdate()->first(),
            409, 'Este proyecto ya existe como fiesta operativa; no se duplicó en el historial.');
    }
}
