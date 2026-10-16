<?php
namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class ConcurrentWorkers
{
    public static function run(array $workers): array
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) throw new \RuntimeException('Real concurrency checks require MariaDB and pcntl.');
        $dir = sys_get_temp_dir().'/waoo-financial-race-'.bin2hex(random_bytes(6)); mkdir($dir, 0700); $children = [];
        DB::disconnect();
        try {
            foreach (array_values($workers) as $index => $worker) {
                $pid = pcntl_fork();
                if ($pid === -1) throw new \RuntimeException('Could not fork the concurrency worker.');
                if ($pid === 0) {
                    DB::purge(); $deadline = microtime(true) + 10;
                    while (!file_exists($dir.'/go') && microtime(true) < $deadline) usleep(1000);
                    try { $result = $worker(); }
                    catch (\Throwable $e) { $result = 'error:'.get_class($e); }
                    file_put_contents($dir.'/'.$index, json_encode($result, JSON_THROW_ON_ERROR)); DB::disconnect(); exit(0);
                }
                $children[] = $pid;
            }
            touch($dir.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new \RuntimeException('Concurrency worker failed.'); }
            $children = []; DB::purge(); $results = [];
            foreach (array_keys(array_values($workers)) as $index) $results[] = json_decode(file_get_contents($dir.'/'.$index), true, flags: JSON_THROW_ON_ERROR);
            return $results;
        } finally {
            touch($dir.'/go'); foreach ($children as $pid) pcntl_waitpid($pid, $status); DB::purge();
            foreach (glob($dir.'/*') as $file) unlink($file); rmdir($dir);
        }
    }
}
