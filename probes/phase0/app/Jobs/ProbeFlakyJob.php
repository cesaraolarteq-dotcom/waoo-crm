<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProbeFlakyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [5];

    public function __construct(public string $runId) {}

    public function handle(): void
    {
        $attempt = $this->attempts();
        $log = Log::build(['driver' => 'single', 'path' => storage_path('logs/probe.log')]);
        if ($attempt === 1) {
            $log->info("P1 {$this->runId} attempt=1 FAIL (intentional)");
            throw new RuntimeException('Intentional first-attempt failure');
        }
        $log->info("P1 {$this->runId} attempt={$attempt} SUCCESS");
    }
}
