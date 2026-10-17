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
        return response(MailTemplatesDraft::preview($key, $lang))
            ->header('Cache-Control', 'no-store, private');
    }
}
