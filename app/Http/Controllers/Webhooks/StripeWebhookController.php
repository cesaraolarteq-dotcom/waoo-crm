<?php
namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhook;
use App\Models\WebhookReceipt;
use App\Services\Finance\Sandbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Stripe\Webhook;

final class StripeWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        Sandbox::require(); $raw = $request->getContent(); $secret = (string) config('waoo.stripe_test_webhook_secret');
        abort_unless(str_starts_with($secret, 'whsec_'), 403, 'Webhook de pruebas no configurado.');
        abort_if(strlen($raw) > 1024 * 1024, 413);
        try { $event = Webhook::constructEvent($raw, (string) $request->header('Stripe-Signature'), $secret, 300)->toArray(); }
        catch (\Throwable $e) { return response()->json(['message' => 'Invalid Stripe signature or payload.'], 400); }
        if (($event['livemode'] ?? null) !== false || !is_string($event['id'] ?? null) || strlen($event['id']) > 255 || !is_string($event['type'] ?? null) || strlen($event['type']) > 255
            || !is_array($event['data']['object'] ?? null) || (isset($event['account']) && $event['account'] !== config('waoo.stripe_test_account'))) {
            return response()->json(['message' => 'Test mode/account/event required.'], 400);
        }
        // Verify raw bytes first, then persist only allocation facts. Card/payment-method/billing data is never retained.
        $object = $event['data']['object'];
        $minimal = ['id' => is_string($object['id'] ?? null) ? mb_substr($object['id'], 0, 255) : null,
            'object' => is_string($object['object'] ?? null) ? mb_substr($object['object'], 0, 60) : null];
        $minimal['payment_intent'] = is_string($object['payment_intent'] ?? null) && preg_match('/^pi_[A-Za-z0-9_]{1,200}$/', $object['payment_intent']) ? $object['payment_intent'] : null;
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $minimal['metadata'] = [];
        if (is_string($metadata['quick_booking_request_id'] ?? null) && \Illuminate\Support\Str::isUuid($metadata['quick_booking_request_id'])) $minimal['metadata']['quick_booking_request_id']=$metadata['quick_booking_request_id'];
        if (is_string($metadata['local_intent_id'] ?? null) && \Illuminate\Support\Str::isUuid($metadata['local_intent_id'])) $minimal['metadata']['local_intent_id'] = $metadata['local_intent_id'];
        if (array_key_exists('kind',$metadata) || array_key_exists('local_tip_intent_id',$metadata) || array_key_exists('grant_id',$metadata)) {
            $minimal['metadata']['kind']=($metadata['kind']??null)==='tip'?'tip':'invalid';
            if(is_string($metadata['local_tip_intent_id']??null)&&\Illuminate\Support\Str::isUuid($metadata['local_tip_intent_id']))$minimal['metadata']['local_tip_intent_id']=$metadata['local_tip_intent_id'];
            if(is_scalar($metadata['grant_id']??null)&&ctype_digit((string)$metadata['grant_id']))$minimal['metadata']['grant_id']=(string)$metadata['grant_id'];
        }
        foreach (['event_id','financial_revision_id'] as $field) if (is_scalar($metadata[$field] ?? null) && ctype_digit((string) $metadata[$field])) $minimal['metadata'][$field] = (string) $metadata[$field];
        $payload = ['id' => $event['id'], 'type' => $event['type'], 'livemode' => false, 'account' => $event['account'] ?? null, 'created' => $event['created'] ?? null, 'data' => ['object' => $minimal]];
        $inserted = false;
        DB::transaction(function () use ($event, $raw, $payload, &$inserted) {
            $inserted = WebhookReceipt::query()->insertOrIgnore(['id' => $event['id'], 'event_type' => $event['type'], 'raw_sha256' => hash('sha256', $raw),
                'encrypted_payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'stripe_account_id' => $event['account'] ?? null,
                'verified_at' => now(), 'state' => 'queued', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) ProcessStripeWebhook::dispatch($event['id'])->afterCommit();
        });
        // Owner 2026-10-21: a quick booking payment confirms right away instead of waiting for the 5-minute cron (there is no
        // queue worker). Runs after the response is sent; any failure stays queued for the cron's retry, nothing is lost.
        if (isset($payload['data']['object']['metadata']['quick_booking_request_id']) && \App\Services\Booking\QuickPaymentProtocol::enabled()) {
            $id = $event['id'];
            app()->terminating(function () use ($id) {
                try { app(\App\Services\Booking\ProcessQuickPaymentReceipt::class)->execute($id); } catch (\Throwable) {}
            });
        } elseif ($inserted && config('waoo.process_after_response')) {
            // Owner 2026-10-28: portal deposits/balances and tips confirm right away too (the client saw the balance still open for ~45 s).
            // Same router as the queued job; a failure leaves the receipt queued for the cron's retry.
            $id = $event['id'];
            app()->terminating(function () use ($id) {
                try { app(\App\Services\Finance\StripeReceiptRouter::class)->execute($id, app(\App\Services\Finance\StripeReceiptProcessor::class)); } catch (\Throwable) {}
            });
        }
        return response()->json(['received' => true]);
    }
}
