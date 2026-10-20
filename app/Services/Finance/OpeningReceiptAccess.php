<?php
namespace App\Services\Finance;
use App\Models\User;
use App\Services\Records\RecordActor;

final class OpeningReceiptAccess
{
    public static function gates(): void
    {
        foreach (['sandbox_finance', 'sandbox_imports', 'sandbox_cutover', 'sandbox_cutover_bridge'] as $flag) Sandbox::require($flag);
        \App\Services\Portal\PortalContext::requireTransport(request());
    }
    public static function actor(User $actor, bool $current = false): User { self::gates(); return RecordActor::authorize($actor, $current); }
}
