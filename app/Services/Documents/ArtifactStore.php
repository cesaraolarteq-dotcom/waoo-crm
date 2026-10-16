<?php
namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;

final class ArtifactStore
{
    public function put(string $contractId, string $kind, string $bytes, string $extension): array
    {
        $hash = hash('sha256', $bytes);
        $path = "contracts/{$contractId}/{$kind}/{$hash}.{$extension}";
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            if (!hash_equals($hash, hash('sha256', $disk->get($path)))) throw new \RuntimeException('Private artifact integrity check failed.');
        } elseif (! $disk->put($path, $bytes, ['visibility' => 'private'])) throw new \RuntimeException('Private artifact storage failed.');
        return ['path' => $path, 'sha256' => $hash];
    }
    public function read(string $path, string $hash): string
    {
        if (!str_starts_with($path, 'contracts/') || str_contains($path, '..')) throw new \RuntimeException('Invalid private artifact path.');
        $bytes = Storage::disk('local')->get($path);
        if (!hash_equals($hash, hash('sha256', $bytes))) throw new \RuntimeException('Private artifact integrity check failed.');
        return $bytes;
    }
}
