<?php

namespace App\Services\Campaigns;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, HistoricalEvent, HistoricalEventRevision, User};
use App\Services\Finance\Sandbox;
use App\Services\History\{HistoricalIntegrity, HistoryAccess};
use App\Services\Records\RecordInput;
use Illuminate\Support\Facades\DB;

final class ReadBirthdayCampaignDraft
{
    /** Owner 2026-10-29: Star, Magic, Music and Games (2.0), Waoo, pool party, foam cannon and glam station parties were birthday parties. */
    public const BIRTHDAY_PACKAGES = '/\\b(star|magic)\\b|music\\s*(and|&|y)\\s*games|m[uú]sica\\s*y\\s*juegos|waoo|pool|piscina|foam|espuma|glam/iu';

    /** HoneyBook typed most projects "other"; their packages say which ones were birthdays. */
    public static function eventType(?string $type, mixed $lineItems): ?string
    {
        if ($type !== 'other') return $type;
        $text = implode(' | ', array_map(fn ($x) => is_scalar($x) ? (string) $x : (string) json_encode($x), (array) $lineItems));

        return preg_match(self::BIRTHDAY_PACKAGES, $text) ? 'birthday' : 'other';
    }

    public function execute(User $actor, array $input): array
    {
        HistoryAccess::actor($actor);
        Sandbox::require('sandbox_anniversary');
        $data = RecordInput::validate($input, ['as_of' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2101-01-01']);
        return DB::transaction(function () use ($data) {
            $anchors = $later = [];
            $history = HistoricalEvent::orderBy('id')->sharedLock()->limit(10001)->get();
            $events = Event::orderBy('id')->sharedLock()->limit(10001)->get();
            RecordWriteRejected::unless($history->count() + $events->count() <= 10000, 409, 'La campaña requiere preparación por lotes.');
            foreach ($history as $event) {
                $p = HistoricalIntegrity::revision($event, HistoricalEventRevision::findOrFail($event->current_revision_id));
                $f = $p['facts'];
                if (($f['record_kind'] ?? '') === 'prospect') continue;
                $row = ['key' => 'history:'.$event->id, 'customer_id' => $event->customer_id,
                    'status' => $f['status'], 'event_type' => self::eventType($f['event_type'], $p['source']['line_items'] ?? []), 'date' => $f['source_date']];
                $anchors[] = $row; $later[] = $row;
            }
            foreach ($events as $event) {
                $row = ['key' => 'crm:'.$event->id, 'customer_id' => $event->customer_id, 'status' => $event->status,
                    'event_type' => $event->event_type, 'date' => $event->starts_at?->setTimezone('America/Chicago')->toDateString()];
                $anchors[] = $row; $later[] = $row;
            }
            return app(BirthdayDraft::class)->prepare($anchors, $later, $data['as_of']);
        });
    }
}
