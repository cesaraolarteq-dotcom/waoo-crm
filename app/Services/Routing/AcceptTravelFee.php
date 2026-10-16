<?php
namespace App\Services\Routing;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, TravelFeeQuoteRecord, User};
use App\Services\Finance\Sandbox;
use Illuminate\Validation\ValidationException;

final class AcceptTravelFee
{
    public function forDraft(User $actor, Event $event, array $data): ?array
    {
        $actor = \App\Services\Records\RecordActor::authorize($actor);
        $address = array_key_exists('address', $data) ? (string) $data['address'] : (string) $event->address;
        $zip = array_key_exists('postal_code', $data) ? $data['postal_code'] : $event->postal_code;
        $fee = array_key_exists('travel_fee_cents', $data) ? ($data['travel_fee_cents'] === null ? null : (int) $data['travel_fee_cents']) : $event->travel_fee_cents;
        $inputs = TravelFeeRules::normalize($address, $zip); $note = $data['travel_fee_override_note'] ?? null; $prior = $event->travel_fee_evidence;
        $base = ['inputs' => $inputs, 'accepted_fee_cents' => $fee, 'accepted_by' => $actor->id, 'accepted_at' => now()->toIso8601String()];
        if (isset($data['travel_fee_quote_id'])) {
            Sandbox::require('sandbox_routes');
            $quote = TravelFeeQuoteRecord::findOrFail($data['travel_fee_quote_id']);
            RecordWriteRejected::unless($quote->expires_at->isFuture() && hash_equals($quote->rules_hash, TravelFeeRules::hash())
                && hash_equals($quote->input_hash, TravelFeeRules::inputHash($inputs)), 409, 'La dirección o las reglas cambiaron; calcula de nuevo.');
            $calculated = $quote->status === 'ok' && $quote->fee_cents === $fee;
            if (!$calculated && !$note) throw ValidationException::withMessages(['travel_fee_override_note' => 'Explica el costo manual antes de usarlo.']);
            return [...$base, 'mode' => $calculated ? 'calculated' : 'edited', 'note' => $note, 'quote_id' => $quote->id,
                'rules' => $quote->rules, 'rules_hash' => $quote->rules_hash, 'quoted_at' => $quote->created_at->toIso8601String(), 'calculation' => $quote->breakdown, 'quote_status' => $quote->status, 'quoted_fee_cents' => $quote->fee_cents];
        }
        if ($prior && $prior['accepted_fee_cents'] === $fee && TravelFeeRules::inputHash($prior['inputs']) === TravelFeeRules::inputHash($inputs)) return $prior;
        if ($prior && !$note) throw ValidationException::withMessages(['travel_fee_override_note' => 'Explica el cambio del costo de transporte o calcula de nuevo.']);
        return $note ? [...$base, 'mode' => 'manual', 'note' => $note, 'previous_quote_id' => $prior['quote_id'] ?? $prior['previous_quote_id'] ?? null] : null;
    }
}
