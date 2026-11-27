<?php
namespace App\Http\Controllers;

use App\Services\Leads\ReceiveLeadDraft;
use Illuminate\Http\Request;

/** Owner 2026-11-26: unfinished quote requests from the website (same signed door as /api/leads). */
final class WebsiteLeadDraftController extends Controller
{
    public function __invoke(Request $request, ReceiveLeadDraft $service)
    {
        abort_unless($request->isSecure() || app()->environment(['local','testing']), 403, 'HTTPS required.');
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/json', 415);
        abort_if(strlen($request->getContent()) > 8192, 413);
        $service->execute($request->getContent(), (string) $request->header('X-Waoo-Timestamp'), (string) $request->header('Idempotency-Key'), (string) $request->header('X-Waoo-Signature'));
        return response()->json(['accepted' => true], 202);
    }
}
