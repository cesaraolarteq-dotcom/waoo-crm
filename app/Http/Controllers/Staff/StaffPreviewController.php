<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// LOCAL/TESTING ONLY: design preview of the STAFF-01 staff page with fictitious data (docs/plans/PHASE-6.md).
// Owner 2026-10-18: read-only. One personal link per person that does not change and always shows that person's
// parties of the current week (Mon–Sun, Houston). No accept/decline, no availability (the owner asks directly).
// Never money, no phone numbers of teammates.
final class StaffPreviewController extends Controller
{
    public function __invoke(Request $request, string $screen = 'semana')
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless(in_array($screen, ['semana', 'vacia'], true), 404);
        $lang = $request->query('lang') === 'en' ? 'en' : 'es';
        $es = $lang === 'es';
        $vm = [
            'lang' => $lang, 'preview' => true,
            'lang_url' => ['es' => url("/dev/staff/{$screen}?lang=es"), 'en' => url("/dev/staff/{$screen}?lang=en")],
            'staff' => ['display_name' => 'Lucía'],
            'week' => ['from' => $es ? 'lun. 19 oct.' : 'Mon, Oct 19', 'to' => $es ? 'dom. 25 oct.' : 'Sun, Oct 25'],
            'updated_at' => $es ? 'vie. 23 oct., 6:10 p. m.' : 'Fri, Oct 23, 6:10 pm',
            'jobs' => $screen === 'vacia' ? [] : [
                ['event_id' => 1, 'answer' => null, 'answer_note' => null, 'date' => $es ? 'sábado 24 de octubre' : 'Saturday, October 24', 'hours' => $es ? '3:00 – 5:00 p. m.' : '3:00 – 5:00 pm',
                    'arrival' => $es ? '2:15 p. m.' : '2:15 pm', 'setup_minutes' => 30,
                    'address' => '123 Sample St, Katy, TX 77494', 'maps' => 'https://maps.google.com/?q=123+Sample+St+Katy+TX+77494',
                    'parking' => $es ? 'En la calle.' : 'Street parking.', 'access' => $es ? 'Portón del lado izquierdo.' : 'Left side gate.',
                    'role' => $es ? 'Animadora' : 'Host', 'character' => null, 'outfit_version' => null, 'is_driver' => true,
                    'team' => [['first_name' => 'Ana', 'drives' => false]],
                    'bring' => $es ? ['Bocina y micrófono', 'Juegos de piso', 'Burbujas'] : ['Speaker and mic', 'Floor games', 'Bubbles'],
                    'instructions' => $es ? 'La piñata es a las 4:15. El cumpleañero se llama Sebastián.' : 'Piñata at 4:15. The birthday boy is Sebastián.',
                    'day_contact' => null],
                ['event_id' => 2, 'answer' => 'yes', 'answer_note' => null, 'date' => $es ? 'domingo 25 de octubre' : 'Sunday, October 25', 'hours' => $es ? '1:00 – 3:00 p. m.' : '1:00 – 3:00 pm',
                    'arrival' => $es ? '12:15 p. m.' : '12:15 pm', 'setup_minutes' => 30,
                    'address' => '456 Example Ave, Sugar Land, TX 77479', 'maps' => 'https://maps.google.com/?q=456+Example+Ave+Sugar+Land+TX+77479',
                    'parking' => null, 'access' => null,
                    'role' => $es ? 'Personaje' : 'Character', 'character' => $es ? 'Personaje de ejemplo' : 'Sample character', 'outfit_version' => 'Gala', 'is_driver' => false,
                    'team' => [['first_name' => 'Carlos', 'drives' => true]],
                    'bring' => [], 'instructions' => null, 'day_contact' => null],
            ],
            'ask' => ['week' => '2026-10-26', 'answered' => false, 'note' => null, 'answered_at' => null, 'days' => [
                'sat' => ['label' => $es ? 'sábado 31 de octubre' : 'Saturday, October 31', 'parties' => 9, 'answer' => null],
                'fri' => ['label' => $es ? 'viernes 30 de octubre' : 'Friday, October 30', 'parties' => 2, 'answer' => null],
                'sun' => ['label' => $es ? 'domingo 1 de noviembre' : 'Sunday, November 1', 'parties' => 4, 'answer' => null],
                'weekday' => ['label' => $es ? 'Entre semana (lun–jue)' : 'Weekdays (Mon–Thu)', 'parties' => 1, 'answer' => null]]],
        ];

        return response()->view('staff.semana', ['vm' => $vm])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
