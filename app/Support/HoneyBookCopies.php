<?php
namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Owner 2026-10-28: record copies of HoneyBook contracts/invoices for upcoming parties, made by Claude from HoneyBook's data
 * (HoneyBook offers no PDF download). Private files in honeybook-copies/ with a manifest (project_id → event_id). Never originals.
 */
final class HoneyBookCopies
{
    public const DIR = 'honeybook-copies';

    public static function forEvent(int $eventId): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::DIR.'/manifest.json')) {
            return null;
        }
        $manifest = json_decode((string) $disk->get(self::DIR.'/manifest.json'), true);
        foreach ($manifest['copies'] ?? [] as $copy) {
            $path = self::DIR.'/'.($copy['project_id'] ?? '').'.pdf';
            if ((int) ($copy['event_id'] ?? 0) === $eventId && preg_match('/^[a-f0-9]{24}$/', $copy['project_id'] ?? '') && $disk->exists($path)) {
                return [...$copy, 'path' => $path, 'extracted_at' => $manifest['extracted_at'] ?? null];
            }
        }

        return null;
    }
}
