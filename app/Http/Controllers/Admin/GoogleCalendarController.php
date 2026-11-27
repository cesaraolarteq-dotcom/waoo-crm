<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\Google\{GoogleCalendar, GoogleContacts};
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

    /** Owner 2026-11-23: same Google client and callback, separate permission for the phone contacts. */
    public function connectContacts(Request $request)
    {
        abort_unless(GoogleContacts::configured(), 404);
        $state = 'contacts-'.Str::random(40);
        $request->session()->put('google_contacts_state', $state);

        return redirect()->away(GoogleContacts::authUrl($state));
    }

    public function callback(Request $request, GoogleCalendar $google)
    {
        abort_unless(GoogleCalendar::configured(), 404);
        if (str_starts_with((string) $request->query('state'), 'contacts-')) return $this->contactsCallback($request);
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

    private function contactsCallback(Request $request)
    {
        $expected = $request->session()->pull('google_contacts_state');
        $back = redirect('/admin/clientas');
        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) return $back->with('google_contacts', 'No se pudo conectar: el enlace venció. Inténtalo otra vez.');
        if ($request->query('error') || blank($request->query('code'))) return $back->with('google_contacts', 'No se conectó: Google no dio el permiso.');
        try {
            $contacts = app(GoogleContacts::class);
            $connection = $contacts->connect($request->user(), (string) $request->query('code'));
            Audit::recordFor($request->user(), 'google_contacts.connected', $connection, [], ['google_email' => $connection->google_email]);
            $r = $contacts->sync();
        } catch (\Throwable $e) {
            return $back->with('google_contacts', 'No se pudieron conectar los contactos. '.$e->getMessage());
        }

        return $back->with('google_contacts', 'Contactos conectados ('.$connection->google_email.'). '.$r['saved'].' '.($r['saved'] === 1 ? 'cliente guardado' : 'clientes guardados').'.');
    }
}
