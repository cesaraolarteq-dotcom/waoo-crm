<?php

namespace App\Services\Records;

use App\Models\Event;
use App\Models\EventService;
use App\Models\PriceVersion;
use App\Models\ServiceVariant;
use App\Models\User;
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Exceptions\RecordWriteRejected;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SaveEventDraft
{
    public function execute(User $actor, array $input, ?int $eventId = null): Event
    {
        $actor = RecordActor::authorize($actor);
        $id = $eventId;
        $data = RecordInput::validate($input, ['customer_id' => 'required|integer|exists:customers,id', 'title' => 'required|string|max:255', 'event_type' => ['sometimes', 'nullable', Rule::in(['birthday', 'baby_shower', 'gender_reveal', 'corporate', 'other'])], 'starts_at' => ['required', 'date', new ExplicitOffset()], 'ends_at' => ['required', 'date', 'after:starts_at', new ExplicitOffset()], 'timezone' => ['required', Rule::in(['America/Chicago'])], 'address' => 'nullable|string|max:255', 'postal_code' => ['nullable', 'string', 'regex:/^[0-9]{5}(?:-[0-9]{4})?$/'], 'setup_minutes' => 'nullable|integer|min:0|max:720', 'breakdown_minutes' => 'nullable|integer|min:0|max:720', 'travel_fee_cents' => 'nullable|integer|min:0|max:100000000', 'travel_fee_quote_id' => 'sometimes|nullable|integer|min:1', 'travel_fee_override_note' => 'sometimes|nullable|string|min:10|max:1000', 'services' => 'present|array|max:10', 'services.*' => 'array:price_version_id,characters', 'services.*.characters' => 'sometimes|array|max:20', 'services.*.characters.*' => 'array:character_id,quantity,outfit,note', 'services.*.characters.*.character_id' => 'required|integer|exists:characters,id', 'services.*.characters.*.outfit' => 'sometimes|nullable|string|max:60', 'services.*.characters.*.note' => 'sometimes|nullable|string|max:200', 'services.*.characters.*.quantity' => 'required|integer|min:1|max:20', 'services.*.price_version_id' => 'required|integer|distinct|exists:price_versions,id', 'expected_version' => ($id ? 'required' : 'sometimes') . '|integer|min:1']);
        return DB::transaction(function () use ($actor, $data, $id) {
            $event = $id ? Event::whereKey($id)->lockForUpdate()->firstOrFail() : new Event();
            if ($id) {
                RecordWriteRejected::when(\Illuminate\Support\Facades\Schema::hasTable('contracts') && \App\Models\Contract::where('event_id', $id)->where('state', 'signed')->exists(), 409, 'Un contrato firmado requiere una adenda o el flujo de cambios.');
                RecordWriteRejected::unless($event->status === 'draft' && $event->version === $data['expected_version'], 409, 'Solo se editan borradores vigentes. Una reserva requiere el flujo de cambios.');
            }
            $before = $event->getAttributes();
            $services = $data['services'];
            unset($data['services'], $data['expected_version']);
            $data['starts_at'] = CarbonImmutable::parse($data['starts_at'])->utc();
            $data['ends_at'] = CarbonImmutable::parse($data['ends_at'])->utc();
            $evidence = app(\App\Services\Routing\AcceptTravelFee::class)->forDraft($actor, $event, $data);
            unset($data['travel_fee_quote_id'], $data['travel_fee_override_note']);
            $event->fill($data);
            $event->forceFill(['travel_fee_evidence' => $evidence]);
            if ($id) {
                $event->version++;
            }
            $event->save();
            $previous = $id ? $event->services()->with('characters')->get()->keyBy('price_version_id') : collect();
            if ($id) {
                $event->services()->delete();
            }
            // Only a draft; booked snapshots are immutable.
            $auditServices = []; $timingVariants = [];
            foreach ($services as $index => $selection) {
                $price = PriceVersion::findOrFail($selection['price_version_id']);
                $variant = ServiceVariant::whereKey($price->service_variant_id)->lockForUpdate()->firstOrFail();
                $timingVariants[] = $variant;
                RecordWriteRejected::when($variant->status === 'retired', 422, 'La variante fue retirada.');
                $characters = $selection['characters'] ?? ($previous->get($price->id)?->characters->map(fn ($row) => $row->only(['character_id', 'quantity', 'outfit', 'note']))->all() ?? []);
                $compiled = CharacterRecipe::compile($variant, $characters, "services.{$index}.characters");
                $service = EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $compiled['requirements'], 'duration_minutes' => $variant->duration_minutes, 'operational_ready' => $compiled['ready'],
                    'extra_characters' => $compiled['extra'], 'extra_character_cents' => $compiled['extra'] > 0 ? (int) config('waoo.extra_character_cents') : null]);
                $service->characters()->createMany($compiled['characters']);
                $auditServices[] = ['price_version_id' => $price->id, 'characters' => $compiled['characters']];
            }
            if (!$id) {
                foreach (PackageTimingDefaults::fromVariants($timingVariants) as $field => $default) {
                    if (!isset($data[$field])) $event->{$field} = $default;
                }
                if ($event->isDirty()) $event->save();
            }
            if ($id) \App\Services\Booking\QuickCostumeHolds::refreshDraft($actor, $event);
            Audit::recordFor($actor, $id ? 'event.draft_updated' : 'event.created', $event, $before, [...$event->getAttributes(), 'services' => $auditServices]);
            return $event->fresh()->load('services.characters');
        }, 3);
    }
}
