<?php

namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, QuickStripeCheckoutRequest, QuickStripeLease, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PrepareQuickStripeCheckoutRequest
{
    public function execute(User $actor, int $eventId, array $input, string $key): array
    {
        $actor = QuickStripeLeases::actor($actor);
        $data = QuickStripeLeases::input($input, ['expected_event_version' => 'required|integer|min:1',
            'expected_lease_version' => 'required|integer|min:1', 'expected_terms_sha256' => 'required|string|regex:/\A[a-f0-9]{64}\z/']);
        $scope = "owner:{$actor->id}:event:{$eventId}:quick-stripe-request";
        $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            QuickStripeLeases::actor($actor);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $lease = QuickStripeLease::where('event_id', $eventId)->lockForUpdate()->firstOrFail();
            $source = QuickStripeCheckoutRequests::current($event, $lease, $data);
            $policy = QuickStripeCheckoutRequests::policy();
            if ($saved = MutationKey::find($scope, $key, $hash, true)) {
                RecordWriteRejected::unless($saved->result_type === 'quick_stripe_checkout_request', 409, 'La solicitud original requiere revisión.');
                $request = QuickStripeCheckoutRequest::whereKey($saved->result_id)->lockForUpdate()->firstOrFail();
                ['envelope' => $p] = QuickStripeCheckoutRequests::verify($request, $lease);
                foreach ($policy as $field => $value) {
                    RecordWriteRejected::unless($p[$field] === $value, 409, 'La configuración de Stripe cambió; revisa la solicitud original.');
                }
                RecordWriteRejected::unless($p['lease_version'] === $lease->version && $p['lease_sha256'] === $lease->payload_sha256,
                    409, 'El apartado no coincide con la solicitud original.');
                return QuickStripeCheckoutRequests::view($request, $lease);
            }
            // Current read: a second owner's REPEATABLE READ snapshot may predate the first owner's event lock/commit.
            RecordWriteRejected::unless(!QuickStripeCheckoutRequest::where('quick_stripe_lease_id', $lease->id)->lockForUpdate()->first(),
                409, 'Este apartado ya tiene una solicitud. No se abre otra sin conciliar la anterior.');
            $now = CarbonImmutable::now()->startOfSecond();
            $expiry = QuickStripeTerms::restore($source['terms'])->checkoutExpiresAt($now);
            $id = (string) Str::uuid();
            $p = ['schema_version' => 1, 'request_id' => $id, 'lease_id' => $lease->id, 'lease_version' => $lease->version,
                'lease_sha256' => $lease->payload_sha256, 'event_id' => $event->id, 'event_version' => $event->version,
                'customer_id' => $event->customer_id, 'financial_revision_id' => $lease->financial_revision_id,
                'terms_sha256' => $source['terms']['sha256'], ...$policy, 'created_at' => $now->timestamp,
                'provider_key' => 'waoo-quick-test:'.$id,
                'parameters' => QuickStripeCheckoutRequests::parameters($id, $event->id, $source['terms'],
                    $event->starts_at->setTimezone('America/Chicago')->format('Y-m-d'), $policy['origin'], $policy['requested_methods'], $expiry)];
            $request = QuickStripeCheckoutRequest::create(['id' => $id, 'quick_stripe_lease_id' => $lease->id,
                'event_id' => $eventId, 'customer_id' => $event->customer_id, 'financial_revision_id' => $lease->financial_revision_id,
                'envelope' => $p, 'envelope_sha256' => MutationKey::hash($p), 'created_at' => $now, 'updated_at' => $now]);
            Audit::recordFor($actor, 'quick_stripe_request.prepared', $request, [], ['event_id' => $eventId,
                'lease_id' => $lease->id, 'lease_version' => $lease->version, 'sha256' => $request->envelope_sha256]);
            MutationKey::store($scope, $key, $hash, 'quick_stripe_checkout_request', $id);
            return QuickStripeCheckoutRequests::view($request, $lease);
        }, 3);
    }
}
