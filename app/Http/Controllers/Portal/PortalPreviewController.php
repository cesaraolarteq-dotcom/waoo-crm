<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// LOCAL/TESTING ONLY design preview of the client portal with fictitious data (no grant, no database, no secrets).
// The real portal will build the same view-model from PortalContext (DOC-PAY-API + QUICK-BOOKING-API-PROPOSAL).
final class PortalPreviewController extends Controller
{
    public function __invoke(Request $request, string $step = 'details')
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        abort_unless(in_array($step, ['details', 'contract', 'pay', 'done'], true), 404);
        $lang = $request->query('lang') === 'en' ? 'en' : 'es';
        $next = ['details' => 'contract', 'contract' => 'pay', 'pay' => 'done', 'done' => 'done'][$step];
        $es = $lang === 'es';

        return response()->view('portal.show', ['vm' => [
            'lang' => $lang, 'step' => $step, 'test_mode' => true, 'preview' => true, 'steps' => ['details', 'contract', 'pay'], 'first_name' => 'Ana',
            'lang_url' => ['es' => url("/dev/portal/{$step}?lang=es"), 'en' => url("/dev/portal/{$step}?lang=en")],
            'event' => ['package' => $es ? 'Paquete de ejemplo · 1 h' : 'Sample package · 1 h', 'when' => $es ? 'sáb. 21 nov. 2026, 3:00 p. m.' : 'Sat, Nov 21, 2026 at 3:00 pm',
                'start_time' => $es ? '3:00 p. m.' : '3:00 pm', 'total' => '$125', 'travel' => '$25', 'paid' => '$62.50', 'balance' => '$62.50', 'due' => $es ? 'vie. 20 nov., 3:00 p. m.' : 'Fri, Nov 20, 3:00 pm'],
            'prefill' => ['first_name' => 'Ana', 'last_name' => '', 'phone' => '555-0100'],
            'contract' => ['pdf_url' => url('/dev/portal/contract?lang='.$lang.'#pdf'), 'sha256' => str_repeat('0', 64)],
            'zelle_memo' => 'WP-0001',
            'actions' => ['details' => url("/dev/portal/{$next}?lang={$lang}"), 'sign' => url("/dev/portal/{$next}?lang={$lang}"), 'checkout' => url("/dev/portal/{$next}?lang={$lang}")],
        ]])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
