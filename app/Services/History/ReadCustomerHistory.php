<?php
namespace App\Services\History;

use App\Models\{Customer, HistoricalEvent, HistoricalEventRevision, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class ReadCustomerHistory
{
    public function execute(User $actor, int $customerId, array $filters = []): array
    {
        HistoryAccess::actor($actor);
        $d = ImportInput::input($filters, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return ImportPersistence::transaction(function () use ($actor, $customerId, $d) {
            ImportInput::lock(); HistoryAccess::actor($actor); Customer::whereKey($customerId)->sharedLock()->firstOrFail();
            $query = HistoricalEvent::where('customer_id', $customerId)->orderByDesc('id');
            $total = (clone $query)->count(); $size = (int)($d['per_page'] ?? 25);
            $last = max(1, (int)ceil($total / $size)); $page = min((int)($d['page'] ?? 1), $last);
            $events = $query->offset(($page - 1) * $size)->limit($size)->lockForUpdate()->get();
            $items = [];
            foreach ($events as $event) {
                $revision = HistoricalEventRevision::whereKey($event->current_revision_id)->sharedLock()->firstOrFail();
                $p = HistoricalIntegrity::revision($event, $revision); $f = $p['facts'];
                $items[] = ['history_id' => $event->id, 'version' => $event->version, 'revision_id' => $revision->id,
                    'source' => 'honeybook', 'historical' => true, 'source_date' => $f['source_date'], 'time_precision' => $f['time_precision'],
                    'status' => $f['status'], 'event_type' => $f['event_type'], 'project_name' => $p['source']['project_name'],
                    'booked_total_cents' => $f['booked_total_cents'], 'currency' => $f['currency'], 'funds_verified' => false];
            }
            return ['customer_id' => $customerId, 'page' => ['number' => $page, 'last' => $last, 'total' => $total], 'items' => $items];
        });
    }
}
