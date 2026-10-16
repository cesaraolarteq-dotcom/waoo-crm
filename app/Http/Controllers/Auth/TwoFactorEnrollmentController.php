<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enrollment screen. Fortify's own POST routes enable, confirm and regenerate; this only renders the right step.
final class TwoFactorEnrollmentController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user->active && $user->role === 'owner_admin', 403, 'Acceso exclusivo de administración.');

        $justConfirmed = session('status') === 'two-factor-authentication-confirmed';
        if ($user->two_factor_confirmed_at && ! $justConfirmed) {
            return redirect('/admin');
        }

        $step = match (true) {
            $justConfirmed => 'codes',
            (bool) $user->two_factor_secret => 'scan',
            default => 'start',
        };

        return response()->view('auth.two-factor-enroll', [
            'step' => $step,
            'qr' => $step === 'scan' ? $user->twoFactorQrCodeSvg() : null,
            'setupKey' => $step === 'scan' ? decrypt($user->two_factor_secret) : null,
            'codes' => $step === 'codes' ? $user->recoveryCodes() : [],
        ])->header('Cache-Control', 'no-store, private');
    }
}
