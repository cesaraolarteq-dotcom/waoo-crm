<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Finance\Sandbox;
use App\Services\Records\RecordActor;
use App\Services\Routing\TravelFeeQuote;
use Illuminate\Http\Request;

final class TravelFeeController extends Controller
{
    public function store(Request $request, TravelFeeQuote $service)
    {
        $data = $request->validate(['address' => 'nullable|string|max:255', 'zip' => ['nullable','string','regex:/^\d{5}(?:-\d{4})?$/']]);
        return response()->json($service->forAddress($request->user(), $data['address'] ?? '', $data['zip'] ?? null), 201);
    }
    public function evidence(Request $request, Event $event)
    {
        Sandbox::require('sandbox_routes'); RecordActor::authorize($request->user());
        return response()->json(['event_id' => $event->id, 'evidence' => $event->travel_fee_evidence]);
    }
}
