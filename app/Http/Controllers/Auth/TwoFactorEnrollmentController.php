<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RecoveryCodeDelivery;
use Illuminate\Http\Request;

// Enrollment screen. Fortify's own POST routes enable, confirm and regenerate; this only renders the right step.
// Recovery codes appear once per generation (RecoveryCodeDelivery), right after confirming or regenerating.
final class TwoFactorEnrollmentController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user->active && $user->role === 'owner_admin', 403, 'Acceso exclusivo de administración.');

        if ($user->two_factor_confirmed_at) {
            $offered = in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);
            $codes = $offered ? RecoveryCodeDelivery::claim($user) : null;
            if ($codes === null) {
                return redirect('/admin');
            }

            return $this->view('codes', ['codes' => $codes]);
        }

        if ($user->two_factor_secret) {
            return $this->view('scan', ['qr' => $user->twoFactorQrCodeSvg(), 'setupKey' => decrypt($user->two_factor_secret)]);
        }

        return $this->view('start');
    }

    private function view(string $step, array $data = [])
    {
        return response()->view('auth.two-factor-enroll', ['step' => $step, 'qr' => null, 'setupKey' => null, 'codes' => [], ...$data])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
