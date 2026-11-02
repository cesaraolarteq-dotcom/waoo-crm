<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, PaymentIntent, QuickPaymentAttempt, QuickStripeCheckoutRequest, User};
use App\Services\Audit;
use App\Services\Finance\{QuickStripeGateway, StripeGateway};
use App\Services\Records\RecordActor;
use Illuminate\Support\Facades\DB;

/**
 * Lost checkout recovery (pre-go-live item after Codex's #169): a quick deposit attempt was prepared but its Stripe response
 * (and so its session ID) never came back. The owner asks the CRM to look for it at Stripe. A session found for our exact
 * request is verified and bound to the attempt, so the existing deadline job can close it or the payment webhook can credit it.
 * Nothing here charges, refunds, extends the original deadline, releases stock or treats "not found" as "not paid".
 */
final class RecoverQuickCheckout
{
    /** Search a little around the request: clock skew between our server and Stripe. */
    public const SLACK_SECONDS = 300;

    /** @return array{state:string} found_open|found_paid|found_expired|not_found */
    public function execute(User $actor, string $intentId): array
    {
        QuickPaymentProtocol::gate();
        RecordActor::authorize($actor);
        RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'No se pudo buscar el pago.');
        [$intent, $request] = $this->lost($intentId);
        $envelope = QuickPaymentCheckout::verify($request);
        $gateway = app(StripeGateway::class);
        RecordWriteRejected::unless($gateway instanceof QuickStripeGateway, 409, 'Los métodos de Stripe aún no están verificados.');
        // The session can only have been created between the request and its own expiry (Stripe refuses later creation).
        $from = $envelope['created_at'] - self::SLACK_SECONDS;
        $to = $envelope['parameters']['expires_at'] + self::SLACK_SECONDS;
        try {
            $session = $gateway->findCheckout($request->id, $from, $to); // network call: no DB locks held
        } catch (\Illuminate\Auth\Access\AuthorizationException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new RecordWriteRejected(503, 'Stripe no respondió. Intenta de nuevo; no se cambió nada.');
        }
        if ($session === null) {
            Audit::recordFor($actor, 'quick_payment.recovery_not_found', $intent, [], ['request_id' => $request->id, 'window' => [$from, $to]]);
            return ['state' => 'not_found'];
        }
        QuickPaymentCheckout::session($session, $request);
        return DB::transaction(function () use ($actor, $intent, $request, $session) {
            Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($locked->stripe_checkout_session_id === null || $locked->stripe_checkout_session_id === $session['id'], 409, 'La sesión cambió. Recarga la página.');
            $changes = ['stripe_checkout_session_id' => $session['id']];
            // Only a still-prepared attempt becomes "open": the deadline job then closes it at Stripe or learns it was paid.
            if ($locked->status === 'prepared') $changes['status'] = 'open';
            $locked->fill($changes)->save();
            $state = match (true) {
                ($session['payment_status'] ?? null) === 'paid' => 'found_paid',
                ($session['status'] ?? null) === 'expired' => 'found_expired',
                default => 'found_open',
            };
            Audit::recordFor($actor, 'quick_payment.recovery_bound', $locked, [], ['request_id' => $request->id, 'state' => $state]);
            return ['state' => $state];
        }, 3);
    }

    /** Attempts whose Stripe outcome is unknown because no session ID was ever stored. */
    public static function lostFor(int $eventId)
    {
        return PaymentIntent::where('event_id', $eventId)->where('purpose', 'quick_deposit')->where('status', 'prepared')
            ->whereNull('stripe_checkout_session_id')->where('created_at', '<=', now()->subMinutes(2))->orderBy('created_at');
    }

    /** @return array{0:PaymentIntent,1:QuickStripeCheckoutRequest} */
    private function lost(string $intentId): array
    {
        $intent = PaymentIntent::whereKey($intentId)->where('purpose', 'quick_deposit')->first();
        RecordWriteRejected::unless($intent !== null && $intent->status === 'prepared' && $intent->stripe_checkout_session_id === null, 409, 'Este intento ya no está pendiente de buscar.');
        $attempt = QuickPaymentAttempt::where('payment_intent_id', $intent->id)->first();
        RecordWriteRejected::unless($attempt !== null, 409, 'No hay una solicitud de pago para buscar.');
        return [$intent, QuickStripeCheckoutRequest::findOrFail($attempt->id)];
    }
}
