<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RecoveryCodeDelivery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use RuntimeException;
use Tests\TestCase;

class RecoveryCodeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_regeneration_waits_for_a_claim_and_new_generation_is_delivered_once(): void
    {
        $this->race('claim');
    }

    public function test_claim_waits_for_regeneration_and_its_reset_to_commit_together(): void
    {
        $this->race('regenerate');
    }

    private static function waitFor(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (! file_exists($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out at the recovery-code synchronization barrier.');
            }
            usleep(1000);
        }
    }

    private function race(string $first): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(function_exists('pcntl_fork'), 'This lock test requires pcntl and MariaDB.');
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['fixture-old-one', 'fixture-old-two'])),
        ]);
        $dir = sys_get_temp_dir().'/waoo-recovery-lock-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $children = [];
        DB::disconnect();
        try {
            foreach (['a', 'b'] as $worker) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    DB::purge();
                    try {
                        $user = User::findOrFail($owner->id);
                        $operation = $worker === 'a' ? $first : ($first === 'claim' ? 'regenerate' : 'claim');
                        if ($worker === 'a') {
                            $paused = false;
                            DB::listen(function (QueryExecuted $query) use ($dir, $operation, &$paused): void {
                                $column = $operation === 'claim' ? 'two_factor_recovery_codes_delivered_at' : 'two_factor_recovery_codes';
                                if (! $paused && str_starts_with(strtolower($query->sql), 'update `users` set `'.$column.'`')) {
                                    $paused = true;
                                    touch($dir.'/locked');
                                    self::waitFor($dir.'/release');
                                }
                            });
                        } else {
                            self::waitFor($dir.'/locked');
                            DB::connection()->beforeExecuting(function (string $sql) use ($dir): void {
                                if (str_contains(strtolower($sql), 'for update')) {
                                    touch($dir.'/attempted');
                                }
                            });
                        }
                        if ($operation === 'claim') {
                            $codes = RecoveryCodeDelivery::claim($user);
                            $result = ['delivered' => $codes !== null,
                                'old' => $codes === ['fixture-old-one', 'fixture-old-two'],
                                'matches_current' => $codes === $user->fresh()->recoveryCodes()];
                        } else {
                            app(GenerateNewRecoveryCodes::class)($user);
                            $result = ['regenerated' => true];
                        }
                        file_put_contents($dir.'/'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $e) {
                        // Never write code values, encrypted secrets or exception payloads to the test output.
                        file_put_contents($dir.'/'.$worker, json_encode(['error' => get_class($e)]));
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            self::waitFor($dir.'/attempted');
            $this->assertFileDoesNotExist($dir.'/b', 'The second operation must wait while the first holds the user lock.');
            touch($dir.'/release');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $children = [];
            DB::purge();
            $a = json_decode(file_get_contents($dir.'/a'), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode(file_get_contents($dir.'/b'), true, flags: JSON_THROW_ON_ERROR);
            if ($first === 'claim') {
                $this->assertTrue($a['delivered']);
                $this->assertTrue($a['old']);
                $this->assertTrue($b['regenerated']);
                $new = RecoveryCodeDelivery::claim($owner->fresh());
                $this->assertTrue($new !== null && $new !== ['fixture-old-one', 'fixture-old-two']);
            } else {
                $this->assertTrue($a['regenerated']);
                $this->assertTrue($b['delivered']);
                $this->assertFalse($b['old']);
                $this->assertTrue($b['matches_current']);
            }
            $this->assertNull(RecoveryCodeDelivery::claim($owner->fresh()), 'The new generation must not be delivered twice.');
        } finally {
            touch($dir.'/release');
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            foreach (glob($dir.'/*') as $file) unlink($file);
            rmdir($dir);
        }
    }
}
