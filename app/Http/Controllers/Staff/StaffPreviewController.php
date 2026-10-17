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
        $vm = [
            'lang' => $lang, 'screen' => $screen, 'preview' => true, 'first_name' => 'Lucía',
            'lang_url' => ['es' => url("/dev/staff/{$screen}?lang=es"), 'en' => url("/dev/staff/{$screen}?lang=en")],
            'nav' => ['inicio' => url("/dev/staff/inicio?lang={$lang}"), 'disponibilidad' => url("/dev/staff/disponibilidad?lang={$lang}")],
            'invites' => [[
                'when' => $es ? 'sáb. 7 nov. · 2:00 p. m.' : 'Sat, Nov 7 · 2:00 pm', 'role' => $es ? 'Personaje: Personaje de ejemplo (Gala)' : 'Character: Sample character (Gala)',
                'arrive' => $es ? '1:15 p. m.' : '1:15 pm', 'party' => $es ? 'Cumpleaños · 2 h' : 'Birthday · 2 h', 'area' => 'Katy, TX 77494',
                'answer_by' => $es ? 'mié. 28 oct.' : 'Wed, Oct 28',
            ]],
            'upcoming' => [[
                'when' => $es ? 'sáb. 24 oct. · 3:00 p. m.' : 'Sat, Oct 24 · 3:00 pm', 'role' => $es ? 'Animadora' : 'Host', 'arrive' => $es ? '2:15 p. m.' : '2:15 pm',
                'party' => $es ? 'Cumpleaños · 2 h' : 'Birthday · 2 h', 'url' => url("/dev/staff/trabajo?lang={$lang}"),
            ]],
            'job' => [
                'when' => $es ? 'sábado 24 de octubre' : 'Saturday, October 24',
                'times' => ['arrive' => $es ? '2:15 p. m.' : '2:15 pm', 'setup' => $es ? '2:30 p. m.' : '2:30 pm', 'start' => $es ? '3:00' : '3:00', 'end' => $es ? '5:00 p. m.' : '5:00 pm'],
                'address' => '123 Sample St, Katy, TX 77494', 'maps' => 'https://maps.google.com/?q=123+Sample+St+Katy+TX+77494',
                'parking' => $es ? 'Estaciona en la calle; la entrada es por el portón del lado izquierdo.' : 'Street parking; enter through the left side gate.',
                'role' => $es ? 'Animadora' : 'Host', 'character' => null,
                'team' => [['name' => 'Lucía', 'role' => $es ? 'Animadora' : 'Host', 'driver' => true], ['name' => 'Mateo', 'role' => $es ? 'Personaje' : 'Character', 'driver' => false]],
                'bring' => $es ? ['Bocina y micrófono', 'Juegos de piso', 'Burbujas'] : ['Speaker and mic', 'Floor games', 'Bubbles'],
                'notes' => $es ? 'La piñata es a las 4:15. El cumpleañero se llama Sebastián.' : 'Piñata at 4:15. The birthday boy is Sebastián.',
                'contact' => null,
            ],
            'availability' => ['slots' => [5 => ['afternoon', 'evening'], 6 => ['morning', 'afternoon']], 'exceptions' => [$es ? 'sáb. 31 oct.' : 'Sat, Oct 31']],
        ];

        return response()->view('staff.'.$screen, ['vm' => $vm])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
