<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Event, ZelleNotice};
use App\Services\Finance\{FinanceSummary, ReviewZelleNotice, Sandbox};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ZelleNoticeController extends Controller
{
    public function index(Request $request)
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        $data = $request->validate(['state' => ['sometimes', Rule::in(['pending','verified','rejected'])], 'event_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $query = ZelleNotice::with('review')->orderByDesc('id');
        if (isset($data['event_id'])) $query->where('event_id', $data['event_id']);
        if (($data['state'] ?? 'pending') === 'pending') $query->whereDoesntHave('review');
        else $query->whereHas('review', fn ($q) => $q->where('decision', $data['state']));
        return response()->json($query->paginate($data['per_page'] ?? 25));
    }
    public function review(Request $request, Event $event, int $notice, ReviewZelleNotice $service, FinanceSummary $summary)
    {
        $review = $service->execute($request->user(), $event->id, $notice, $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json(['review' => $review, 'finance' => $summary->forEvent($event->fresh())]);
    }
}
