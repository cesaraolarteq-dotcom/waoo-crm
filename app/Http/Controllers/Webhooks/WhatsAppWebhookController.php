<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\WhatsApp\WhatsAppCloud;
use Illuminate\Http\Request;

/** Meta WhatsApp Cloud API webhook (owner 2026-11-26). GET = subscription check, POST = signed events. */
final class WhatsAppWebhookController
{
    public function verify(Request $request)
    {
        abort_unless(config('waoo.whatsapp.enabled') === true, 404);
        $token = (string) config('waoo.whatsapp.verify_token');
        abort_unless($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);
        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        abort_unless(config('waoo.whatsapp.enabled') === true, 404);
        $raw = $request->getContent();
        abort_unless(strlen($raw) <= 1_048_576, 413);
        abort_unless(WhatsAppCloud::validSignature($raw, $request->header('X-Hub-Signature-256')), 401);
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 422);
        app(WhatsAppCloud::class)->receive($payload);
        return response()->json(['ok' => true]);
    }
}
