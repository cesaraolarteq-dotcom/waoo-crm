<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Google\GoogleCalendar;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Owner-only Google Calendar connection (route group requires an active owner with confirmed 2FA). */
final class GoogleCalendarController extends Controller
{
    public function connect(Request $request)
    {
        abort_unless(GoogleCalendar::configured(), 404);
        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away(GoogleCalendar::authUrl($state));
    }

    public function callback(Request $request, GoogleCalendar $google)
    {
        abort_unless(GoogleCalendar::configured(), 404);
        $expected = $request->session()->pull('google_calendar_state');
        $back = redirect('/admin/calendario');
        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) return $back->with('google_calendar', 'No se pudo conectar: el enlace venció. Inténtalo otra vez.');
        if ($request->query('error') || blank($request->query('code'))) return $back->with('google_calendar', 'No se conectó: Google no dio el permiso.');
        try {
            $connection = $google->connect($request->user(), (string) $request->query('code'));
            Audit::recordFor($request->user(), 'google_calendar.connected', $connection, [], ['google_email' => $connection->google_email]);
            [$ok, $failed] = $google->syncUpcoming();
        } catch (\Throwable $e) {
            return $back->with('google_calendar', 'No se pudo conectar Google Calendar. '.$e->getMessage());
        }

        return $back->with('google_calendar', 'Google Calendar conectado. '.$ok.' '.($ok === 1 ? 'fiesta enviada' : 'fiestas enviadas').($failed ? ", {$failed} con error (reintenta con «Sincronizar ahora»)" : '').'.');
    }
}
