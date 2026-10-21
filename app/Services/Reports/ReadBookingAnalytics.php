<?php

namespace App\Services\Reports;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, HistoricalEvent, HistoricalEventRevision, ImportedProjectLink, ImportedProjectRevision, User};
use App\Services\Finance\Sandbox;
use App\Services\History\{HistoricalIntegrity, ReadHoneyBookEventFacts};
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;

final class ReadBookingAnalytics
{
    public function execute(User $actor, array $input): array
    {
        RecordActor::authorize($actor);
        Sandbox::require('sandbox_analytics');
        Sandbox::require('sandbox_history');
        PortalContext::requireTransport(request());
        $data = RecordInput::validate($input, ['year' => 'required|integer|between:2000,2100']);
        return DB::transaction(function () use ($data) {
            $rows = [];
            // Shared head locks keep source revisions coherent during this bounded report.
            $history = HistoricalEvent::orderBy('id')->sharedLock()->limit(10001)->get();
            $events = Event::with('services')->orderBy('id')->sharedLock()->limit(10001)->get();
            RecordWriteRejected::unless($history->count() + $events->count() <= 10000, 409, 'El volumen requiere un reporte por lotes.');
            foreach ($history as $event) {
                $revision = HistoricalEventRevision::findOrFail($event->current_revision_id);
                $p = HistoricalIntegrity::revision($event, $revision); $f = $p['facts']; $s = $p['source'];
                $rows[] = ['source' => 'honeybook', 'status' => ($f['record_kind'] ?? '') === 'prospect' ? 'prospect' : $f['status'],
                    'date' => $f['source_date'], 'postal_code' => $s['postal_code'], 'package_ids' => [],
                    'lead_source' => trim($s['lead_source_text'] ?? '') ?: ($s['lead_source'] ?? ''),
                    'created_at' => $s['created_at'] ?? null, 'total_cents' => $f['booked_total_cents'], 'currency' => $f['currency']];
            }
            foreach ($events as $event) {
                $link = ImportedProjectLink::where('event_id', $event->id)->first();
                $source = $link ? ImportedProjectRevision::findOrFail($link->current_revision_id) : null;
                $financial = $link ? null : FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
                $facts = $link ? ReadHoneyBookEventFacts::forEvent($event, false) : null;
                $rows[] = ['source' => $link ? 'honeybook' : 'crm', 'status' => $event->status,
                    'date' => $event->starts_at?->setTimezone('America/Chicago')->toDateString(),
                    'postal_code' => $event->postal_code, 'package_ids' => $event->services->pluck('service_variant_id')->all(),
                    'lead_source' => $facts && !$facts['stale'] ? ($facts['fields']['lead_source'] ?? '') : '',
                    'created_at' => $link ? null : $event->created_at?->toIso8601String(),
                    'total_cents' => $source ? ($source->normalized['total_cents'] ?? null) : $financial?->total_cents,
                    'currency' => $source ? 'USD' : $financial?->currency];
            }
            $report = app(BookingAnalytics::class)->summarize($rows, (int)$data['year']);
            $ids = array_filter(array_keys($report['packages']), fn ($id) => is_int($id) && $id > 0);
            $report['package_labels'] = \App\Models\ServiceVariant::whereIn('id', $ids)->pluck('name', 'id')->all();
            return $report;
        });
    }
}
