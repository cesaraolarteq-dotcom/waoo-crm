<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\{PortalContext, ShortPortalLinks};
use Illuminate\Http\Request;

final class ShortPortalLinkController extends Controller
{
    public function __invoke(Request $request, #[\SensitiveParameter] string $code, ShortPortalLinks $links)
    {
        try {
            PortalContext::requireTransport($request);
            return redirect()->away($links->destination($code), 302);
        } catch (\Throwable) {
            // Never report a wrapped SQL/crypto exception, request path or bearer credential.
            return response('Este enlace no está disponible. / This link is unavailable.', 404);
        }
    }
}
