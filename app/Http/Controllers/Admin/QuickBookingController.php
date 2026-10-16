<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Event, PortalGrant, QuickBookingRecord};
use App\Services\Booking\{ClientDetailsState, QuickBook};
use App\Services\Finance\{FinanceSummary, Sandbox};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

final class QuickBookingController extends Controller
{
    public function create(Request $request, QuickBook $service)
    {
        return response()->json($service->execute($request->user(), $request->all(), (string) $request->header('Idempotency-Key')), 201)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function index(Request $request)
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        $data = $request->validate(['state' => ['sometimes', Rule::in(['waiting_details','review_details','ready_to_issue','waiting_signature','signed'])], 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $page = (int) ($data['page'] ?? 1); $perPage = (int) ($data['per_page'] ?? 25);
        $query = QuickBookingRecord::orderByDesc('id');
        if (!isset($data['state'])) {
            $result = $query->paginate($perPage)->through(fn ($row) => $this->row($row));
        } else {
            // Derived states include immutable submission/review + current agreement linkage, not a mutable status flag.
            // Keep memory bounded while retaining correct pagination totals for the owner's low-volume queue.
            $items = []; $total = 0;
            foreach ($query->cursor() as $record) {
                if (ClientDetailsState::forEvent(Event::findOrFail($record->event_id)) !== $data['state']) continue;
                if ($total >= ($page - 1) * $perPage && count($items) < $perPage) $items[] = $this->row($record);
                $total++;
            }
            $result = new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]);
        }
        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    private function row(QuickBookingRecord $record): array
    {
        $event = Event::findOrFail($record->event_id);
        $submission = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
        $grant = $record->portal_grant_id ? PortalGrant::find($record->portal_grant_id) : null;
        return ['id' => $record->id, 'event' => $event->only(['id','title','customer_id','starts_at','status','version']), 'state' => ClientDetailsState::forEvent($event),
            'receipt_id' => $record->receipt_id, 'initial_financial_revision_id' => $record->initial_financial_revision_id,
            'finance' => app(FinanceSummary::class)->forEvent($event), 'latest_submission_id' => $submission?->id,
            'latest_review' => $submission ? ClientDetailsReview::where('submission_id', $submission->id)->first() : null,
            'portal_grant_id' => $grant?->id, 'link_issue_pending' => !$grant || $grant->revoked_at !== null || !$grant->expires_at->isFuture(), 'portal_expires_at' => $grant?->expires_at];
    }
}
