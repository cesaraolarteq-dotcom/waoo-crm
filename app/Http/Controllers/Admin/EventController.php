<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Rules\ExplicitOffset;
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class EventController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date', new ExplicitOffset()], 'to' => ['nullable', 'date', 'after:from', new ExplicitOffset()]]);
        $q = Event::with(['customer', 'services.characters', 'reservations'])->orderBy('starts_at');
        if (isset($data['from'])) {
            $q->where('ends_at', '>', CarbonImmutable::parse($data['from'])->utc());
        }
        if (isset($data['to'])) {
            $q->where('starts_at', '<', CarbonImmutable::parse($data['to'])->utc());
        }
        return $q->paginate(50);
    }
    public function show(Event $event)
    {
        return $event->load(['customer', 'services.characters', 'reservations']);
    }
    public function store(Request $request)
    {
        return $this->save($request);
    }
    public function update(Request $request, Event $event)
    {
        return $this->save($request, $event->id);
    }
    private function save(Request $request, ?int $id = null)
    {
        $event = app(\App\Services\Records\SaveEventDraft::class)->execute($request->user(), $request->all(), $id);
        return response()->json($event, $id ? 200 : 201);
    }
    public function confirm(Request $request, Event $event, \App\Services\Records\ConfirmCapacity $capacity)
    {
        return $capacity->execute($request->user(), $event->id, $request->all());
    }
    public function audit(Event $event)
    {
        return DB::table('audit_entries')->where('subject_type', 'events')->where('subject_id', $event->id)->orderByDesc('id')->paginate(50);
    }
}
