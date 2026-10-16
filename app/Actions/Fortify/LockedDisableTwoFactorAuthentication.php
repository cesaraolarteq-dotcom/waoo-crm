<?php
namespace App\Actions\Fortify;

use App\Services\RecoveryCodeDelivery;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

// Same Fortify action, run under the user row lock shared with RecoveryCodeDelivery::claim(),
// so a new recovery-code generation and its "not delivered yet" reset happen atomically (Codex review R2 v2).
final class LockedDisableTwoFactorAuthentication extends DisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        RecoveryCodeDelivery::underLock($user, fn () => parent::__invoke($user));
    }
}
