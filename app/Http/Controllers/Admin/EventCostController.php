<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Event, EventCostSnapshot};
use App\Services\Costs\{EventMarginSummary, RecordCostSnapshot};
use App\Services\Finance\Sandbox;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EventCostController extends Controller
{
    public function store(Request $request, Event $event, RecordCostSnapshot $service)
    {
        return response()->json($service->execute($request->user(), $event->id, $request->all(), (string) $request->header('Idempotency-Key')), 201);
    }
    public function summary(Request $request, Event $event, EventMarginSummary $service)
    {
        return response()->json($service->forEvent($request->user(), $event->id));
    }
    public function index(Request $request, Event $event)
    {
        Sandbox::require();
        $data = $request->validate(['kind' => ['sometimes',Rule::in(['estimate','actual'])], 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50']);
        $query = EventCostSnapshot::where('event_id', $event->id)->orderByDesc('id');
        if (isset($data['kind'])) $query->where('kind', $data['kind']);
        return response()->json($query->paginate($data['per_page'] ?? 25));
    }
}
