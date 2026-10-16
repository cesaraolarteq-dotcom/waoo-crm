<?php

namespace App\Filament\Support;

// Contract and portal screens follow Codex's sandbox switch (App\Services\Finance\Sandbox 'sandbox_documents').
final class DocumentsGate
{
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing', 'staging']) && (bool) config('waoo.sandbox_documents', false)
            && class_exists(\App\Services\Documents\CreateContract::class);
    }
}
