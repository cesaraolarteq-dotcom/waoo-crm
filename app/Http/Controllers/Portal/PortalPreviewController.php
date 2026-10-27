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
        $lang = $request->query('lang') === 'en' ? 'en' : 'es';
        if (str_starts_with($step, 'quote')) {
            return $this->quote($step, $lang);
        }
        if (str_starts_with($step, 'tip')) {
            return $this->tip($step, $lang);
        }
        abort_unless(in_array($step, ['details', 'contract', 'pay', 'done'], true), 404);
        $next = ['details' => 'contract', 'contract' => 'pay', 'pay' => 'done', 'done' => 'done'][$step];
        $es = $lang === 'es';

        return response()->view('portal.show', ['vm' => [
            'lang' => $lang, 'step' => $step, 'reserved' => $step === 'pay', 'test_mode' => true, 'preview' => true, 'steps' => ['details', 'contract', 'pay'], 'first_name' => 'Ana',
            'lang_url' => ['es' => url("/dev/portal/{$step}?lang=es"), 'en' => url("/dev/portal/{$step}?lang=en")],
            'event' => ['package' => $es ? 'Paquete de ejemplo · 1 h' : 'Sample package · 1 h', 'when' => $es ? 'sáb. 21 nov. 2026, 3:00 p. m.' : 'Sat, Nov 21, 2026 at 3:00 pm',
                'start_time' => $es ? '3:00 p. m.' : '3:00 pm', 'time_range' => $es ? '3:00 p. m. – 4:00 p. m.' : '3:00 pm – 4:00 pm', 'total' => '$125', 'travel' => '$25', 'paid' => '$62.50', 'balance' => '$62.50', 'due' => $es ? 'vie. 20 nov., 3:00 p. m.' : 'Fri, Nov 20, 3:00 pm'],
            // Design-only sample; this controller is closed in production and never records consent.
            'details' => ['sms_consent' => ['version' => 'preview-only', 'text' => \App\Support\PortalCopy::get($lang, 'sms')]],
            'prefill' => ['first_name' => 'Ana', 'last_name' => '', 'phone' => '555-0100'],
            'contract' => ['pdf_url' => url('/dev/portal/contract?lang='.$lang.'#pdf'), 'sha256' => str_repeat('0', 64), 'final_url' => in_array($step, ['pay', 'done'], true) ? url('/dev/portal/contract?lang='.$lang.'&signed=1') : null],
            'zelle_memo' => 'WP-0001',
            'actions' => ['details' => url("/dev/portal/{$next}?lang={$lang}"), 'sign' => url("/dev/portal/{$next}?lang={$lang}"), 'checkout' => url("/dev/portal/{$next}?lang={$lang}")],
        ]])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    /** QUOTE-01 B design preview: the client quote page with fictitious data (states: open, accepted, expired, superseded, rejected). */
    private function quote(string $step, string $lang)
    {
        $state = ['quote' => 'open', 'quote-accepted' => 'accepted', 'quote-expired' => 'expired', 'quote-superseded' => 'superseded', 'quote-rejected' => 'rejected'][$step] ?? null;
        abort_unless($state !== null, 404);
        $es = $lang === 'es';

        return response()->view('portal.quote', ['vm' => [
            'lang' => $lang, 'test_mode' => true, 'preview' => true, 'first_name' => 'Ana',
            'lang_url' => ['es' => url("/dev/portal/{$step}?lang=es"), 'en' => url("/dev/portal/{$step}?lang=en")],
            'quote' => ['number' => 'Q-2026-000123', 'revision' => 2, 'state' => $state, 'valid_until' => $es ? 'vie. 30 oct. 2026' : 'Fri, Oct 30, 2026',
                'when' => $es ? 'sáb. 21 nov. 2026, 3:00 – 5:00 p. m.' : 'Sat, Nov 21, 2026, 3:00 – 5:00 pm', 'where' => '123 Sample St, Katy, TX 77494',
                'lines' => [['name' => $es ? 'Música y Juegos 2.0' : 'Music & Games 2.0', 'duration' => '2 h', 'characters' => $es ? '1 × Personaje de ejemplo' : '1 × Sample character', 'amount' => '$600']],
                'travel' => '$40', 'discounts' => [['label' => $es ? 'Descuento por reservar hoy' : 'Book-today discount', 'amount' => '$25']],
                'total' => '$615', 'deposit' => '$307.50', 'pdf_url' => null],
            'consent' => ['version' => 'preview-only', 'text' => $es ? '(Texto de ejemplo; el texto real lo aprueba César.) Acepto esta cotización.' : '(Sample text; the real wording is approved by César.) I accept this quote.'],
            'decide' => ['snapshot_sha256' => str_repeat('0', 64), 'pdf_sha256' => str_repeat('0', 64), 'pdf_opened' => true,
                'accepted' => ['version' => 'preview-only', 'sha256' => str_repeat('0', 64), 'text' => $es ? 'Me interesa esta cotización y quiero reservar. Sé que esto todavía no aparta mi fecha ni cobra nada: Waoo Party me enviará el contrato y el depósito para confirmarla.' : 'I like this quote and want to book. I know this does not reserve my date or charge anything yet: Waoo Party will send me the contract and deposit to confirm it.'],
                'rejected' => ['version' => 'preview-only', 'sha256' => str_repeat('0', 64), 'text' => $es ? 'Por ahora no me interesa. Puedo pedir una cotización nueva cuando quiera.' : 'Not for now. I can ask for a new quote anytime.']],
            'prefill' => ['name' => 'Ana Ejemplo', 'email' => 'ana@example.test'],
            'actions' => ['accept' => url("/dev/portal/quote-accepted?lang={$lang}"), 'reject' => url("/dev/portal/quote-rejected?lang={$lang}")],
        ]])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    /** TIP-01 design preview: card tip page after the party (open, paid, closed). Party total $615 → 5/10/15 % = $31/$62/$92. */
    private function tip(string $step, string $lang)
    {
        $state = ['tip' => 'open', 'tip-thanks' => 'paid', 'tip-closed' => 'closed'][$step] ?? null;
        abort_unless($state !== null, 404);
        $es = $lang === 'es';
        // Same half-up whole-dollar rule as TIP-01-API: intdiv(T*p + 5000, 10000) * 100 cents.
        $options = array_map(fn ($p) => ['key' => 'pct_'.$p, 'percent' => $p, 'amount_cents' => $c = intdiv(61500 * $p + 5000, 10000) * 100, 'amount' => '$'.number_format($c / 100)], [5, 10, 15]);

        return response()->view('portal.tip', ['vm' => [
            'lang' => $lang, 'test_mode' => true, 'preview' => true, 'first_name' => 'Ana',
            'lang_url' => ['es' => url("/dev/portal/{$step}?lang=es"), 'en' => url("/dev/portal/{$step}?lang=en")],
            'tip' => ['state' => $state, 'when' => $es ? 'sábado 21 de noviembre' : 'Saturday, November 21', 'team' => 'Lucía, Carlos', 'total' => '$615', 'options' => $options,
                'financial_revision_id' => 1, 'snapshot_sha256' => str_repeat('0', 64)],
            'review_url' => config('waoo.mail_review_url'),
            'actions' => ['checkout' => url("/dev/portal/tip-thanks?lang={$lang}")],
        ]])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
