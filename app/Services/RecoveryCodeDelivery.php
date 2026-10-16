<?php
namespace App\Services;

use App\Models\User;

// Each recovery-code generation is shown once, through the enrollment screen or the JSON read, never both.
// A new generation (2FA enabled, codes regenerated, 2FA disabled) reopens delivery; re-confirming does not.
final class RecoveryCodeDelivery
{
    public static function reset(User $user): void
    {
        User::whereKey($user->getKey())->update(['two_factor_recovery_codes_delivered_at' => null]);
    }

    /** Atomically claims delivery of the current set. Returns the codes, or null if already delivered. */
    public static function claim(User $user): ?array
    {
        if (! $user->two_factor_recovery_codes) {
            return null;
        }
        $claimed = User::whereKey($user->getKey())
            ->whereNull('two_factor_recovery_codes_delivered_at')
            ->whereNotNull('two_factor_recovery_codes')
            ->update(['two_factor_recovery_codes_delivered_at' => now()]);

        return $claimed === 1 ? $user->fresh()->recoveryCodes() : null;
    }
}
