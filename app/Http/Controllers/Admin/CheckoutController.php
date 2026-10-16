<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Event, PaymentIntent, WebhookReceipt};
use App\Services\Finance\{CreateCheckoutIntent, Sandbox};
use Illuminate\Http\Request;

final class CheckoutController extends Controller
{
    public static function data(PaymentIntent $intent): array
    {
        return [...$intent->only(['id','event_id','financial_revision_id','environment','purpose','principal_cents','customer_fee_cents','payable_cents','currency','status','expires_at']),
            'checkout_url' => $intent->status === 'open' ? $intent->checkout_url : null];
    }
    public function create(Request $request, Event $event, CreateCheckoutIntent $service)
    {
        return response()->json(self::data($service->execute($request->user(), $event->id, $request->all(), (string) $request->header('Idempotency-Key'))), 201)->header('Cache-Control', 'no-store, private');
    }
    public function show(PaymentIntent $intent) { Sandbox::require(); return response()->json(self::data($intent))->header('Cache-Control', 'no-store, private'); }
    public function webhooks() { Sandbox::require(); return response()->json(WebhookReceipt::orderByDesc('created_at')->paginate(50))->header('Cache-Control', 'no-store, private'); }
    public function retryWebhook(Request $request, WebhookReceipt $receipt)
    {
        Sandbox::require();
        $data = \App\Services\Records\RecordInput::validate($request->all(), ['reason' => 'required|string|min:10|max:2000']);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $receipt, $data) {
            $locked = WebhookReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $before = ['state' => $locked->state, 'last_error' => $locked->last_error];
            $locked->fill(['state' => 'queued', 'last_error' => null, 'processed_at' => null])->save();
            \App\Services\Audit::recordFor($request->user(), 'payment.webhook_retry_requested', $locked, $before, ['reason' => $data['reason']]);
            \App\Jobs\ProcessStripeWebhook::dispatch($locked->id)->afterCommit();
        });
        return response()->json(['queued' => true])->header('Cache-Control', 'no-store, private');
    }

}
