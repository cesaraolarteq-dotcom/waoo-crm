<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Event};
use App\Services\Booking\ApproveClientDetails;
use App\Services\Finance\Sandbox;
use Illuminate\Http\Request;

final class ClientDetailsController extends Controller
{
    public function index(Event $event)
    {
        Sandbox::require('sandbox_documents');
        return response()->json(ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->paginate(25)
            ->through(fn ($row) => [...$row->toArray(), 'review' => ClientDetailsReview::where('submission_id', $row->id)->first()]))->header('Cache-Control', 'no-store, private');
    }
    public function approve(Request $request, Event $event, ClientDetailsSubmission $submission, ApproveClientDetails $service)
    {
        return response()->json($service->execute($request->user(), $event->id, $submission->id, $request->all(), (string) $request->header('Idempotency-Key')))->header('Cache-Control', 'no-store, private');
    }
}
