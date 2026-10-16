<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\SubmitClientDetails;
use Illuminate\Http\Request;

final class ClientDetailsController extends Controller
{
    public function store(Request $request, SubmitClientDetails $service)
    {
        $submission = $service->execute($request->attributes->get('portal_context'), $request->all(), (string) $request->header('Idempotency-Key'));
        $event = \App\Models\Event::findOrFail($submission->event_id);
        return response()->json(['submission' => $submission->only(['id','revision','data','submitted_at']),
            'reviewed' => \App\Services\Booking\ClientDetailsState::portal($event)['reviewed']], 201);
    }
}
