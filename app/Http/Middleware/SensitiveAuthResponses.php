<?php
namespace App\Http\Middleware;

use App\Services\RecoveryCodeDelivery;
use Closure;
use Illuminate\Http\Request;

// Runs on every Fortify route (config/fortify.php).
// R1: nothing from sign-in or 2FA (secret, QR, recovery codes) may be stored by a browser or proxy cache.
// R2: the JSON recovery-code read follows the same one-time delivery as the enrollment screen.
final class SensitiveAuthResponses
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $request->is('user/two-factor-recovery-codes') && $response->getStatusCode() === 200) {
            $codes = RecoveryCodeDelivery::claim($request->user());
            $response = $codes === null
                ? response()->json(['message' => 'Estos códigos ya se mostraron. Genera un juego nuevo si los necesitas.'], 410)
                : response()->json($codes);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
