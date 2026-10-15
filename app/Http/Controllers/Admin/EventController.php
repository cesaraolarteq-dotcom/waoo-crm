<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Event, EventService, PriceVersion, ServiceVariant};
use App\Rules\ExplicitOffset;
use App\Services\{Audit, ConfirmBooking};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class EventController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['nullable', 'date', new ExplicitOffset()], 'to' => ['nullable', 'date', 'after:from', new ExplicitOffset()]]);
        $q = Event::with(['customer', 'services', 'reservations'])->orderBy('starts_at');
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
        return $event->load(['customer', 'services', 'reservations']);
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
        $data = $request->validate(['customer_id' => 'required|integer|exists:customers,id', 'title' => 'required|string|max:255', 'starts_at' => ['required', 'date', new ExplicitOffset()], 'ends_at' => ['required', 'date', 'after:starts_at', new ExplicitOffset()], 'timezone' => ['required', Rule::in(['America/Chicago'])], 'address' => 'nullable|string|max:255', 'setup_minutes' => 'nullable|integer|min:0|max:720', 'breakdown_minutes' => 'nullable|integer|min:0|max:720', 'travel_fee_cents' => 'nullable|integer|min:0|max:100000000', 'services' => 'present|array|max:10', 'services.*' => 'array:price_version_id', 'services.*.price_version_id' => 'required|integer|distinct|exists:price_versions,id', 'expected_version' => ($id ? 'required' : 'sometimes') . '|integer|min:1']);
        return DB::transaction(function () use ($data, $id) {
            $event = $id ? Event::whereKey($id)->lockForUpdate()->firstOrFail() : new Event();
            if ($id) {
                abort_unless($event->status === 'draft' && $event->version === $data['expected_version'], 409, 'Solo se editan borradores vigentes. Una reserva requiere el flujo de cambios.');
            }
            $before = $event->getAttributes();
            $services = $data['services'];
            unset($data['services'], $data['expected_version']);
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();
            $event->fill($data);
            if ($id) {
                $event->version++;
            }
            $event->save();
            if ($id) {
                $event->services()->delete();
            }
            // Only a draft; booked snapshots are immutable.
            foreach ($services as $selection) {
                $price = PriceVersion::findOrFail($selection['price_version_id']);
                $variant = ServiceVariant::whereKey($price->service_variant_id)->lockForUpdate()->firstOrFail();
                abort_if($variant->status === 'retired', 422, 'La variante fue retirada.');
                EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => $variant->duration_minutes, 'operational_ready' => $variant->status === 'active']);
            }
            Audit::record($id ? 'event.draft_updated' : 'event.created', $event, $before, [...$event->getAttributes(), 'services' => $services]);
            return response()->json($event->fresh()->load('services'), $id ? 200 : 201);
        });
    }
    public function confirm(Request $request, Event $event, ConfirmBooking $booking)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1', 'resource_ids' => 'required|array|min:1|max:100', 'resource_ids.*' => 'required|integer|distinct', 'payment' => 'required|array:method,reference,amount_cents,received_at', 'payment.method' => ['required', Rule::in(['external_zelle', 'external_card', 'external_cash'])], 'payment.reference' => 'required|string|min:3|max:120', 'payment.amount_cents' => 'required|integer|min:1|max:100000000', 'payment.received_at' => ['required', 'date', 'before_or_equal:now', new ExplicitOffset()], 'review' => 'required|array:staff_confirmed,cargo_checked,origin_return_note,short_notice_reason,deposit_exception_reason,transfers', 'review.staff_confirmed' => 'required|accepted', 'review.cargo_checked' => 'required|accepted', 'review.origin_return_note' => 'required|string|min:10|max:2000', 'review.short_notice_reason' => 'nullable|string|min:10|max:2000', 'review.deposit_exception_reason' => 'nullable|string|min:10|max:2000', 'review.transfers' => 'present|array|max:200', 'review.transfers.*' => 'array:from_event_id,to_event_id,from_version,to_version,travel_minutes,contingency_minutes,departure_at,note', 'review.transfers.*.from_event_id' => 'required|integer', 'review.transfers.*.to_event_id' => 'required|integer', 'review.transfers.*.from_version' => 'required|integer|min:1', 'review.transfers.*.to_version' => 'required|integer|min:1', 'review.transfers.*.travel_minutes' => 'required|integer|min:0|max:1440', 'review.transfers.*.contingency_minutes' => 'required|integer|min:1|max:720', 'review.transfers.*.departure_at' => ['required', 'date', new ExplicitOffset()], 'review.transfers.*.note' => 'required|string|min:10|max:2000']);
        $data['payment']['received_at'] = CarbonImmutable::parse($data['payment']['received_at'])->utc();
        return $booking->execute($event->id, $data)->load('reservations');
    }
    public function audit(Event $event)
    {
        return DB::table('audit_entries')->where('subject_type', 'events')->where('subject_id', $event->id)->orderByDesc('id')->paginate(50);
    }
}
