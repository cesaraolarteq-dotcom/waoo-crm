<?php
namespace App\Console\Commands;

use App\Models\RefundRequest;
use App\Services\Changes\RefundPayment;
use Illuminate\Console\Command;

/**
 * Codex audit 2026-11-17 A2 follow-up: a card refund Stripe left "pending" normally settles by webhook. If that webhook never
 * arrives, this re-reads Stripe for each pending refund (no new refund is ever created) and settles it the same way.
 */
final class RecheckPendingRefunds extends Command
{
    protected $signature = 'waoo:refunds:recheck {--limit=50}';
    protected $description = 'Re-read Stripe for card refunds still pending (never creates a refund)';

    public function handle(RefundPayment $refunds): int
    {
        try { \App\Services\Finance\Sandbox::require(); \App\Services\Finance\Sandbox::require('sandbox_changes'); }
        catch (\Illuminate\Auth\Access\AuthorizationException) { $this->line('disabled'); return self::SUCCESS; }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($limit === false) { $this->error('limit: 1–500'); return self::FAILURE; }
        $done = ['checked' => 0, 'succeeded' => 0, 'failed' => 0, 'still_pending' => 0, 'errors' => 0];
        RefundRequest::where('method', 'stripe')->where('state', 'pending')->whereNotNull('provider_refund_id')
            ->where('updated_at', '<', now()->subMinutes(15))->orderBy('id')->limit($limit)->get()
            ->each(function (RefundRequest $r) use ($refunds, &$done) {
                $done['checked']++;
                try {
                    $state = $refunds->settleFromProvider($r->provider_refund_id)?->state;
                    $key = ['succeeded' => 'succeeded', 'failed' => 'failed'][$state] ?? 'still_pending';
                    $done[$key]++;
                    if ($key === 'still_pending') $r->touch(); // next look in 15+ minutes, not every run
                } catch (\Throwable) { $done['errors']++; }
            });
        $this->line(json_encode($done, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
