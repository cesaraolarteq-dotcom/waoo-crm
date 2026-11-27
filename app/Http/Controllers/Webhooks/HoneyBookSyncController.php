<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\Imports\HoneyBookSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner 2026-11-26: the cloud job that reads HoneyBook every 12 hours posts the honeybook_future_v1 CSV here.
 * Authenticated by a long secret (only its SHA-256 lives in .env). Off unless WAOO_HONEYBOOK_SYNC=true.
 */
final class HoneyBookSyncController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('waoo.honeybook_sync_enabled') === true, 404);
        $expected = (string) config('waoo.honeybook_sync_token_sha256');
        $given = (string) $request->bearerToken();
        abort_unless(strlen($expected) === 64 && strlen($given) >= 32 && hash_equals($expected, hash('sha256', $given)), 401);
        $body = $request->getContent();
        abort_unless(strlen($body) > 0 && strlen($body) <= 1_048_576, 413);
        $run = app(HoneyBookSync::class)->run($body);

        return response()->json(['run' => $run->id, 'status' => $run->status, 'counts' => $run->counts,
            'review' => collect($run->items)->where('kind', 'review')->map(fn ($i) => $i['project'].' — '.$i['detail'])->values()]);
    }
}
