<?php

namespace App\Filament\Support;

// Money screens follow Codex's sandbox switch (App\Services\Finance\Sandbox): test environment + WAOO_SANDBOX_FINANCE only.
final class FinanceGate
{
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) && (bool) config('waoo.sandbox_finance', false)
            && class_exists(\App\Services\Finance\RecordZellePayment::class);
    }
}
