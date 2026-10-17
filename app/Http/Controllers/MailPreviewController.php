<?php

namespace App\Http\Controllers;

use App\Support\MailTemplatesDraft;
use Illuminate\Http\Request;

// LOCAL/TESTING ONLY: design preview of the MAIL-01 draft emails with fictitious data. Sends nothing.
final class MailPreviewController extends Controller
{
    public function __invoke(Request $request, ?string $key = null)
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $lang = $request->query('lang') === 'en' ? 'en' : 'es';
        if ($key === null) {
            return response(collect(MailTemplatesDraft::KEYS)->map(fn ($k) => "<li><a href='/dev/mail/{$k}?lang=es'>{$k}</a> · <a href='/dev/mail/{$k}?lang=en'>EN</a></li>")->prepend('<h1>MAIL-01 borradores</h1><ul>')->push('</ul>')->implode(''));
        }
        abort_unless(in_array($key, MailTemplatesDraft::KEYS, true), 404);
        $es = $lang === 'es';
        $vars = ['first_name' => 'Ana', 'event_date' => $es ? 'sábado 21 de noviembre a las 3:00 pm' : 'Sat, Nov 21 at 3:00 pm', 'subject_date' => $es ? 'sábado 21 de noviembre' : 'Sat, Nov 21', 'address' => '123 Sample St, Katy, TX', 'package' => $es ? 'Música y Juegos 2.0' : 'Music & Games 2.0',
            'deposit' => '$307.50', 'paid' => '$307.50', 'amount' => '$307.50', 'balance' => '$307.50', 'balance_due' => $es ? ' (vence el viernes 20 de noviembre)' : ' (due Fri, Nov 20)', 'due' => $es ? 'viernes 20 de noviembre' : 'Fri, Nov 20',
            'valid_until' => $es ? 'viernes 30 de octubre' : 'Fri, Oct 30', 'link' => url('/dev/portal/quote?lang='.$lang), 'review_link' => 'https://g.page/r/sample'];
        $totals = [['label' => $es ? 'Paquete' : 'Package', 'amount' => '$600'], ['label' => $es ? 'Costo de transporte' : 'Travel fee', 'amount' => '$40'],
            ['label' => $es ? 'Descuento por reservar hoy' : 'Book-today discount', 'amount' => '−$25', 'discount' => true], ['label' => 'Total', 'amount' => '$615', 'strong' => true]];

        return response()->view('mail.layout', ['lang' => $lang, 'mail' => MailTemplatesDraft::render($key, $lang, $vars), 'totals' => $totals, 'logo' => url('/img/waoo-logo.png')])
            ->header('Cache-Control', 'no-store, private');
    }
}
