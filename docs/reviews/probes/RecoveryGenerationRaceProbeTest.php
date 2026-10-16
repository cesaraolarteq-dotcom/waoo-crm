<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\RecoveryCodeDelivery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Tests\TestCase;

class RecoveryGenerationRaceProbeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_regeneration_between_claim_and_read_must_not_deliver_one_generation_twice(): void
    {
        $owner = User::factory()->create([
            'role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['fixture-old-one', 'fixture-old-two'])),
        ]);
        $interleaved = false;
        // Deterministic scheduling seam, not a claim of running concurrent processes:
        // another password-confirmed request regenerates after the claim UPDATE commits,
        // before claim() calls fresh() to read the codes. Real Fortify action/event resets delivery.
        DB::listen(function (QueryExecuted $query) use ($owner, &$interleaved): void {
            if (! $interleaved && str_starts_with(strtolower($query->sql), 'update `users`')
                && str_contains($query->sql, '`two_factor_recovery_codes_delivered_at` is null')) {
                $interleaved = true;
                app(GenerateNewRecoveryCodes::class)($owner->fresh());
            }
        });
        $first = RecoveryCodeDelivery::claim($owner);
        $second = RecoveryCodeDelivery::claim($owner->fresh());
        $this->assertTrue($interleaved, 'The interleaving hook must have run.');
        $this->assertTrue($first !== null, 'The initial claim should deliver one generation.');
        $this->assertFalse($first === $second && $second !== null,
            'The same newly generated set was returned twice after a concurrent regeneration.');
    }
}
