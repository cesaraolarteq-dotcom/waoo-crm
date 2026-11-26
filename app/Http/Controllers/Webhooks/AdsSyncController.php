<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\Ads\AdsSync;
use Illuminate\Http\Request;

/** Owner 2026-11-26: called by the script inside the Google Ads account (bearer secret; only its SHA-256 is in .env). */
final class AdsSyncController
{
    private function guard(Request $r): void
    {
        $expected = (string) config('waoo.ads_sync_token_sha256');
        abort_unless(strlen($expected) === 64 && strlen((string) $r->bearerToken()) >= 32 && hash_equals($expected, hash('sha256', (string) $r->bearerToken())), 401);
    }

    public function spend(Request $r)
    {
        $this->guard($r);
        $rows = $r->json('rows');
        abort_unless(is_array($rows), 422);
        $stored = app(AdsSync::class)->storeSpend('google', $rows);
        return response()->json(['stored' => $stored, 'conversions' => app(AdsSync::class)->pendingGoogleConversions()]);
    }

    public function ack(Request $r)
    {
        $this->guard($r);
        $ids = $r->json('lead_ids');
        abort_unless(is_array($ids), 422);
        return response()->json(['acknowledged' => app(AdsSync::class)->acknowledge('google', $ids)]);
    }
}
