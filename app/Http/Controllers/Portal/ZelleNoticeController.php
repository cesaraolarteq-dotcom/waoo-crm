<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\{Event, ZelleNotice};
use App\Services\Finance\Sandbox;
use App\Services\Portal\{SubmitZelleNotice, ZelleNoticeView};
use Illuminate\Http\Request;

final class ZelleNoticeController extends Controller
{
    public function store(Request $request, SubmitZelleNotice $service)
    {
        $notice = $service->execute($request->attributes->get('portal_context'), $request->all(), (string) $request->header('Idempotency-Key'));
        return response()->json(['notice' => ZelleNoticeView::client($notice)], 201);
    }
    public function index(Request $request)
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        $client = $request->attributes->get('portal_context'); $client->authorize($client->eventId, 'payments.read');
        Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->firstOrFail();
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return response()->json(ZelleNotice::where('event_id', $client->eventId)->where('customer_id', $client->customerId)
            ->with('review.ledgerEntry')->orderByDesc('id')->paginate($data['per_page'] ?? 25)->through(fn ($row) => ZelleNoticeView::client($row)));
    }
}
