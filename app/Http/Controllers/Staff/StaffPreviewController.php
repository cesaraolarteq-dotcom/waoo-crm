<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// LOCAL/TESTING ONLY: design preview of the STAFF-01 phone portal (docs/plans/PHASE-6.md) with fictitious data.
// The real routes wait for Codex's STAFF-01 API and will feed the same view-model keys. Never shows money.
final class StaffPreviewController extends Controller
{
    public function __invoke(Request $request, string $screen = 'inicio')
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless(in_array($screen, ['inicio', 'trabajo', 'disponibilidad'], true), 404);
        $lang = $request->query('lang') === 'en' ? 'en' : 'es';
        $es = $lang === 'es';
        // Keys follow docs/STAFF-01-API.md: GET /staff/api/me (staff, availability, upcoming, alerts) and
        // GET /staff/api/invitations/{id}/job (event, assignment, day_contact). Never money, no team directory.
        $vm = [
            'lang' => $lang, 'screen' => $screen, 'preview' => true,
            'lang_url' => ['es' => url("/dev/staff/{$screen}?lang=es"), 'en' => url("/dev/staff/{$screen}?lang=en")],
            'nav' => ['inicio' => url("/dev/staff/inicio?lang={$lang}"), 'disponibilidad' => url("/dev/staff/disponibilidad?lang={$lang}")],
            'staff' => ['display_name' => 'Lucía'],
            'upcoming' => [
                ['id' => 41, 'version' => 1, 'status' => 'pending', 'deadline' => $es ? 'mié. 28 oct., 6:00 p. m.' : 'Wed, Oct 28, 6:00 pm', 'instruction_revision' => 1,
                    'event' => ['date' => $es ? 'sáb. 7 nov.' : 'Sat, Nov 7', 'hours' => $es ? '2:00 – 4:00 p. m.' : '2:00 – 4:00 pm', 'arrival' => $es ? '1:15 p. m.' : '1:15 pm', 'area' => 'Katy, TX 77494'],
                    'assignment' => ['role' => $es ? 'Personaje' : 'Character', 'character' => $es ? 'Personaje de ejemplo' : 'Sample character', 'outfit_version' => 'Gala']],
                ['id' => 37, 'version' => 2, 'status' => 'accepted', 'deadline' => null, 'instruction_revision' => 1, 'url' => url("/dev/staff/trabajo?lang={$lang}"),
                    'event' => ['date' => $es ? 'sáb. 24 oct.' : 'Sat, Oct 24', 'hours' => $es ? '3:00 – 5:00 p. m.' : '3:00 – 5:00 pm', 'arrival' => $es ? '2:15 p. m.' : '2:15 pm', 'area' => 'Katy, TX 77494'],
                    'assignment' => ['role' => $es ? 'Animadora' : 'Host', 'character' => null, 'outfit_version' => null]],
            ],
            'job' => [
                'event' => ['date' => $es ? 'sábado 24 de octubre' : 'Saturday, October 24', 'arrival' => $es ? '2:15 p. m.' : '2:15 pm', 'setup_minutes' => 30,
                    'hours' => $es ? '3:00 – 5:00 p. m.' : '3:00 – 5:00 pm', 'address' => '123 Sample St, Katy, TX 77494', 'maps' => 'https://maps.google.com/?q=123+Sample+St+Katy+TX+77494',
                    'parking' => $es ? 'Estaciona en la calle.' : 'Street parking.', 'access' => $es ? 'Entrada por el portón del lado izquierdo.' : 'Enter through the left side gate.'],
                'assignment' => ['role' => $es ? 'Animadora' : 'Host', 'character' => null, 'outfit_version' => null, 'is_driver' => true,
                    'bring' => $es ? ['Bocina y micrófono', 'Juegos de piso', 'Burbujas'] : ['Speaker and mic', 'Floor games', 'Bubbles'],
                    'instructions' => $es ? 'La piñata es a las 4:15. El cumpleañero se llama Sebastián.' : 'Piñata at 4:15. The birthday boy is Sebastián.'],
                'day_contact' => null,
            ],
            // Weekly intervals in Houston time; a weekday with no rule is "sin decir" (unknown), never available.
            'availability' => ['weekly' => [5 => [['14:00', '20:00']], 6 => [['10:00', '13:00'], ['15:00', '19:00']]],
                'exceptions' => [['date' => $es ? 'sáb. 31 oct.' : 'Sat, Oct 31', 'available' => false]]],
        ];

        return response()->view('staff.'.$screen, ['vm' => $vm])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
