<?php
namespace App\Jobs;

use App\Models\WebhookReceipt;
use App\Services\Finance\StripeReceiptProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStripeWebhook implements ShouldQueue
{
    use Queueable;
    public int $tries = 5; public int $timeout = 40;
    public function __construct(public string $receiptId) {}
    public function backoff(): array { return [10,30,120,300]; }
    public function handle(StripeReceiptProcessor $processor): void
    {
        try { $processor->execute($this->receiptId); }
        catch (\Throwable $e) {
            WebhookReceipt::whereKey($this->receiptId)->update(['last_error' => 'processing_retry_required', 'updated_at' => now()]);
            throw $e;
        }
    }
    public function failed(?\Throwable $exception): void
    {
        WebhookReceipt::whereKey($this->receiptId)->whereNotIn('state', ['processed','ignored'])->update(['state' => 'reconciliation_required', 'last_error' => 'processing_retries_exhausted', 'updated_at' => now()]);
    }
}
