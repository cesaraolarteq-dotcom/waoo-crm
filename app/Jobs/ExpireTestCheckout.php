<?php
namespace App\Jobs;
use App\Models\PaymentIntent;
use App\Services\Finance\{Sandbox, StripeGateway};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class ExpireTestCheckout implements ShouldQueue
{
    use Queueable;
    public int $tries = 3; public int $timeout = 40;
    public function __construct(public string $intentId) {}
    public function handle(): void
    {
        Sandbox::require();
        $intent = PaymentIntent::findOrFail($this->intentId);
        if ($intent->stripe_checkout_session_id && (in_array($intent->status, ['superseded','expired'], true) || ($intent->status === 'reconciliation_required' && $intent->last_error === 'responsible_system_honeybook'))) app(StripeGateway::class)->expireCheckout($intent->stripe_checkout_session_id);
    }
}
