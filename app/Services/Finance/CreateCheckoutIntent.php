<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, FinancialRevision, PaymentIntent, User};
use App\Services\Documents\CreateContract;
use App\Services\Portal\PortalContext;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

final class CreateCheckoutIntent
{
    public function execute(User|PortalContext $actor, int $eventId, array $input, string $key): PaymentIntent
    {
        Sandbox::require();
        if ($actor instanceof User) { $actor = RecordActor::authorize($actor); $identity = 'owner:'.$actor->id; }
        else { $actor->authorize($eventId, 'checkout.create'); $identity = 'customer:'.$actor->customerId; }
        $data = RecordInput::validate($input, ['financial_revision_id' => 'required|integer|min:1', 'purpose' => ['required', Rule::in(['deposit','balance','installment'])], 'amount_cents' => 'sometimes|integer|min:1|max:100000000', 'card_quote_sha256'=>'sometimes|string|regex:/^[a-f0-9]{64}$/D']);
        $data['financial_revision_id'] = (int) $data['financial_revision_id'];
        if (isset($data['amount_cents'])) $data['amount_cents'] = (int) $data['amount_cents'];
        $scope = $identity.":event:{$eventId}:checkout"; $hash = MutationKey::hash($data);
        $intent = DB::transaction(function () use ($actor, $eventId, $data, $scope, $hash, $key) {
            if ($actor instanceof PortalContext) $actor->authorize($eventId, 'checkout.create', true);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            \App\Services\Responsibility\RequireCrmResponsibility::check($event);
            \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($eventId);
            if ($saved = MutationKey::find($scope, $key, $hash)) return PaymentIntent::findOrFail($saved->result_id);
            $revision = FinancialRevision::where('event_id', $eventId)->whereKey($data['financial_revision_id'])->firstOrFail();
            CreateContract::assertCurrent($event, $revision);
            RecordWriteRejected::when(DB::table('payment_exceptions')->where('event_id', $eventId)->where('state', 'open')->exists(), 409, 'Un administrador debe resolver las incidencias de pago antes de abrir otro cobro.');
            RecordWriteRejected::when(PaymentIntent::where('collection_slot', 'event:'.$eventId)->exists(), 409, 'Ya hay un cobro activo. Reutilízalo o resuelve el anterior.');
            $summary = app(FinanceSummary::class)->forEvent($event);
            $amount = match ($data['purpose']) { 'deposit' => $summary['deposit_shortfall_cents'], 'balance' => $summary['balance_cents'], default => (int) ($data['amount_cents'] ?? 0) };
            if ($amount <= 0 || $amount > $summary['balance_cents'] || (isset($data['amount_cents']) && (int) $data['amount_cents'] !== $amount)) {
                throw ValidationException::withMessages(['amount_cents' => 'Revisa el saldo y el importe de este abono. No se cobra de más.']);
            }
            $terms=['customer_fee_cents'=>0,'payable_cents'=>$amount];
            $policy=null;
            if(CardFees::enabled()){
                if(!CardFees::ready())CardFees::reject();
                $terms=CardFees::quote($amount);$policy=CardFees::POLICY;
                $quote=CardFees::publicQuote($eventId,$revision->id,$data['purpose'],$amount);
                if($actor instanceof PortalContext && (!isset($data['card_quote_sha256']) || !hash_equals($quote['card_quote_sha256'],$data['card_quote_sha256'])))CardFees::reject();
            }
            $intent = PaymentIntent::create(['id' => (string) Str::uuid(), 'event_id' => $eventId, 'financial_revision_id' => $revision->id,
                'environment' => 'test', 'purpose' => $data['purpose'], 'currency' => 'USD', 'principal_cents' => $amount, 'customer_fee_cents' => $terms['customer_fee_cents'], 'payable_cents' => $terms['payable_cents'], 'card_fee_policy'=>$policy,
                'status' => 'creating', 'collection_slot' => 'event:'.$eventId, 'expires_at' => now()->addHour()]);
            if($policy!==null){$intent->card_fee_terms_sha256=CardFees::fingerprint($intent);$intent->save();}
            if ($actor instanceof User) \App\Services\Audit::recordFor($actor, 'payment.checkout_intent_created', $intent, [], ['amount_cents' => $amount, 'environment' => 'test']);
            else \App\Services\Audit::recordSystem('payment.checkout_intent_created', $intent, [], ['amount_cents' => $amount, 'portal_grant_id' => $actor->grantId, 'environment' => 'test']);
            MutationKey::store($scope, $key, $hash, 'payment_intent', $intent->id);
            return $intent;
        }, 3);
        if ($intent->status !== 'creating') return $intent;
        if ($intent->created_at->lessThan(now()->subHours(23))) { $this->reconcile($intent, 'provider_retry_window_exceeded'); throw new RecordWriteRejected(409, 'Este intento requiere conciliación antes de reintentar.'); }
        if(!CardFees::valid($intent) || ($intent->card_fee_policy!==null && !CardFees::ready()))CardFees::reject();
        $origin = Sandbox::origin();
        $metadata = ['local_intent_id' => $intent->id, 'event_id' => (string) $eventId, 'financial_revision_id' => (string) $intent->financial_revision_id];
        \App\Services\Responsibility\RequireCrmResponsibility::forEvent($eventId);
        try {
            $session = app(StripeGateway::class)->createCheckout(['mode' => 'payment', 'payment_method_types' => ['card'], 'client_reference_id' => $intent->id,
                'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata], 'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => $intent->payable_cents, 'product_data' => ['name' => 'Waoo Party · test payment']]]],
                'expires_at' => $intent->expires_at->timestamp, 'success_url' => $origin.'/portal/payment-return?intent='.$intent->id, 'cancel_url' => $origin.'/portal/payment-return?intent='.$intent->id.'&cancelled=1'], 'waoo-test:'.$intent->id);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            PaymentIntent::whereKey($intent->id)->update(['last_error' => 'provider_configuration_denied', 'updated_at' => now()]);
            throw $e;
        } catch (\Throwable $e) {
            PaymentIntent::whereKey($intent->id)->update(['last_error' => 'provider_request_failed', 'updated_at' => now()]);
            throw new RecordWriteRejected(503, 'Stripe de pruebas no respondió. Reintenta la misma solicitud; no se ha marcado ningún pago.');
        }
        if (($session['livemode'] ?? null) !== false || ($session['_verified_account'] ?? null) !== config('waoo.stripe_test_account')
            || ($session['client_reference_id'] ?? null) !== $intent->id || (int) ($session['amount_total'] ?? -1) !== $intent->payable_cents || ($session['currency'] ?? null) !== 'usd'
            || parse_url($session['url'] ?? '', PHP_URL_HOST) !== 'checkout.stripe.com' || parse_url($session['url'] ?? '', PHP_URL_SCHEME) !== 'https') {
            $this->reconcile($intent, 'invalid_checkout_response');
            throw new RecordWriteRejected(409, 'La respuesta Stripe necesita conciliación; no se registró un pago.');
        }
        $updated = DB::transaction(function () use ($intent, $session) {
            $event = Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            $locked->stripe_checkout_session_id = $session['id'];
            $locked->stripe_payment_intent_id = is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : $locked->stripe_payment_intent_id;
            if ($event->responsible_system !== 'crm' && in_array($locked->status, ['creating','open'], true)) {
                $locked->fill(['status'=>'reconciliation_required','last_error'=>'responsible_system_honeybook'])->save();
                DB::table('payment_exceptions')->insertOrIgnore(['event_id'=>$locked->event_id,'financial_revision_id'=>$locked->financial_revision_id,
                    'kind'=>'stripe_reconciliation','exception_key'=>hash('sha256',$locked->id.':responsible_system_honeybook'),'state'=>'open',
                    'context'=>json_encode(['intent_id'=>$locked->id,'reason'=>'responsible_system_honeybook'],JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
                \App\Jobs\ExpireTestCheckout::dispatch($locked->id)->afterCommit();
                return $locked;
            }
            if ($locked->status !== 'creating') {
                $locked->save();
                \App\Jobs\ExpireTestCheckout::dispatch($locked->id)->afterCommit();
                return $locked;
            }
            $locked->fill(['checkout_url' => $session['url'], 'status' => 'open', 'last_error' => null])->save();
            return $locked;
        });
        RecordWriteRejected::when(in_array($updated->status, ['superseded','reconciliation_required','failed','expired'], true), 409, 'El intento ya no admite un cobro. Actualiza el saldo.');
        return $updated;
    }
    private function reconcile(PaymentIntent $intent, string $reason): void
    {
        DB::transaction(function () use ($intent, $reason) {
            Event::whereKey($intent->event_id)->lockForUpdate()->firstOrFail();
            PaymentIntent::whereKey($intent->id)->update(['status' => 'reconciliation_required', 'last_error' => $reason, 'updated_at' => now()]);
            DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $intent->event_id, 'financial_revision_id' => $intent->financial_revision_id, 'kind' => 'stripe_reconciliation',
                'exception_key' => hash('sha256', $intent->id.':'.$reason), 'state' => 'open', 'context' => json_encode(['intent_id' => $intent->id, 'reason' => $reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

}
