<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Each recovery-code generation is shown once, through the enrollment screen or the JSON read, never both.
// Claiming and changing the codes (enable / regenerate / disable) all take the same user row lock, so they serialize:
// a claim returns exactly the generation it marked, and a new generation is reset as "not delivered" in the same
// transaction that writes it. Using a recovery code (RecoveryCodeReplaced) does not reopen delivery.
final class RecoveryCodeDelivery
{
    /** Runs $change (a Fortify 2FA action) under the row lock; resets delivery only if the code set actually changed. */
    public static function underLock(User $user, \Closure $change): void
    {
        DB::transaction(function () use ($user, $change) {
            $before = User::whereKey($user->getKey())->lockForUpdate()->value('two_factor_recovery_codes');
            $change();
            $after = User::whereKey($user->getKey())->value('two_factor_recovery_codes');
            if ($after !== $before) {
                User::whereKey($user->getKey())->update(['two_factor_recovery_codes_delivered_at' => null]);
            }
        });
    }

    /** Atomically claims delivery of the current set. Returns that exact set, or null if already delivered or none. */
    public static function claim(User $user): ?array
    {
        return DB::transaction(function () use ($user) {
            $row = User::whereKey($user->getKey())->lockForUpdate()
                ->first(['id', 'two_factor_recovery_codes', 'two_factor_recovery_codes_delivered_at']);
            if (! $row?->two_factor_recovery_codes || $row->two_factor_recovery_codes_delivered_at !== null) {
                return null;
            }
            $codes = $row->recoveryCodes(); // decrypted from the locked read, never re-read after the update
            User::whereKey($row->getKey())->update(['two_factor_recovery_codes_delivered_at' => now()]);

            return $codes;
        });
    }
}
