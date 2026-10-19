<?php
namespace App\Services\Imports;

use App\Models\User;
use App\Services\Finance\Sandbox;

final class CutoverAccess
{
    public static function actor(User $actor): User
    {
        Sandbox::require('sandbox_cutover');
        return ImportInput::actor($actor);
    }
    public static function maxAge(): ?int
    {
        // Never accept a config typo that silently extends the owner's approved four hours.
        return config('waoo.cutover_reconciliation_max_age_minutes') === 240 ? 240 : null;
    }
}
