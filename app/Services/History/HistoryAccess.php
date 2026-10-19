<?php
namespace App\Services\History;

use App\Models\User;
use App\Services\Finance\Sandbox;
use App\Services\Portal\PortalContext;
use App\Services\Records\RecordActor;

final class HistoryAccess
{
    public static function actor(User $actor): User
    {
        Sandbox::require('sandbox_history');
        PortalContext::requireTransport(request());
        return RecordActor::authorize($actor);
    }
}
