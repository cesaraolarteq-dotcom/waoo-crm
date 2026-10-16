<?php
namespace App\Http\Controllers;

use App\Services\Leads\ReceiveWebsiteLead;
use Illuminate\Http\Request;

final class WebsiteLeadController extends Controller
{
    public function __invoke(Request $request, ReceiveWebsiteLead $service)
    {
        abort_unless($request->isSecure() || app()->environment(['local','testing']), 403, 'HTTPS required.');
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/json', 415);
        $service->execute($request->getContent(), (string) $request->header('X-Waoo-Timestamp'), (string) $request->header('Idempotency-Key'), (string) $request->header('X-Waoo-Signature'));
        return response()->json(['accepted' => true], 202);
    }
}
