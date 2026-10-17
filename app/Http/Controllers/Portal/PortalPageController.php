<?php
namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\{Contract, Event, FinancialRevision, SignatureEvidence};
use App\Services\Documents\InHouseSignatureProvider;
use App\Services\Finance\FinanceSummary;
use App\Services\Portal\PortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

// Client portal HTML (Claude). Reads only through PortalContext (Codex) and the same scoped data the portal API returns;
// every write (session, sign, checkout) goes to Codex's /portal/api endpoints from the page script. Never takes IDs from the URL.
final class PortalPageController extends Controller
{
    public function show(Request $request)
    {
        abort_unless(\App\Filament\Support\DocumentsGate::enabled(), 404);
        $lang = $this->lang($request);
        try {
            $client = PortalContext::fromRequest($request);
            // A quote link (scopes quote.read + quote.accept only) has its own page; it never opens the booking steps.
            if (\App\Models\PortalGrant::whereKey($client->grantId)->value('quote_revision_id') !== null) {
                return redirect('/portal/quote');
            }
            $client->authorize($client->eventId, 'payments.read');
        } catch (AuthorizationException) {
            // No session yet: the landing script exchanges the #token for a session (or explains an expired link).
            return $this->respond(view('portal.landing', ['lang' => $lang]));
        }

        return $this->respond(view('portal.show', ['vm' => $this->viewModel($request, $client, $lang)]));
    }

    /**
     * QUOTE-01 B client page. Everything shown and every value the decision sends (revision, snapshot/PDF hashes,
     * each outcome's frozen consent version/hash/text) comes from ONE read of Codex's ReadQuoteViewModel, so a submit
     * never picks up a newer revision. Accept/reject go to /portal/api/quote/{accept,reject} from the page script.
     */
    public function quote(Request $request)
    {
        abort_unless(\App\Filament\Support\DocumentsGate::enabled() && config('waoo.sandbox_quotes', false), 404);
        $lang = $this->lang($request);
        try {
            $client = PortalContext::fromRequest($request);
            $read = app(\App\Services\Quotes\ReadQuoteViewModel::class)->read($client, $lang);
        } catch (AuthorizationException) {
            return $this->respond(view('portal.landing', ['lang' => $lang]));
        } catch (\App\Exceptions\RecordWriteRejected) {
            // Revoked/replaced revision or unverifiable presentation: show nothing of the offer.
            return $this->respond(view('portal.quote-unavailable', ['lang' => $lang]));
        }
        $vm = $read['view_model'];
        $vm['decide'] = [
            'snapshot_sha256' => $read['snapshot_sha256'], 'pdf_sha256' => $read['pdf_sha256'],
            // Exact frozen wording per outcome, shown verbatim; never draft configuration.
            'accepted' => collect($read['consents']['accepted'] ?? [])->only(['version', 'sha256', 'text'])->all(),
            'rejected' => collect($read['consents']['rejected'] ?? [])->only(['version', 'sha256', 'text'])->all(),
            'pdf_opened' => \Illuminate\Support\Facades\DB::table('quote_pdf_views')->where('portal_grant_id', $client->grantId)->exists(),
        ];

        return $this->respond(view('portal.quote', ['vm' => $vm]));
    }

    /** Stripe returns here; this page never records a payment (only the verified webhook does). */
    public function paymentReturn(Request $request)
    {
        abort_unless(\App\Filament\Support\DocumentsGate::enabled(), 404);
        $request->session()->flash('portal_notice', $request->boolean('cancelled') ? 'cancelled' : 'processing');

        return redirect('/portal');
    }

    private function viewModel(Request $request, PortalContext $client, string $lang): array
    {
        $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->firstOrFail();
        $f = app(FinanceSummary::class)->forEvent($event);
        $revision = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
        $grant = $client->authorize($event->id, 'payments.read');
        $contract = in_array('contract.read', $grant->scopes, true)
            ? Contract::where('event_id', $event->id)->where('signer_customer_id', $client->customerId)->whereIn('state', ['issued', 'signed'])->orderByDesc('revision')->first()
            : null;
        $signed = $contract?->state === 'signed';
        $balance = $f['balance_cents'];
        // Quick bookings: the client first sends her details; the owner approves them before a contract is issued.
        $canDetails = in_array('details.submit', $grant->scopes, true);
        $details = $canDetails ? \App\Services\Booking\ClientDetailsState::portal($event) : null;
        $step = match (true) {
            $canDetails && ! $signed && ! $details['submission'] => 'details',
            $canDetails && ! $signed && ! $details['reviewed'] => 'details_sent',
            ! $contract => 'waiting',
            ! $signed => 'contract',
            ($balance ?? 0) > 0 => 'pay',
            default => 'done',
        };
        $tz = 'America/Chicago';
        $start = CarbonImmutable::instance($event->starts_at)->setTimezone($tz)->locale($lang);
        $fmt = fn ($c) => $c === null ? '—' : '$'.number_format($c / 100, $c % 100 ? 2 : 0);
        $services = collect($revision?->lines_snapshot ?? [])->map(fn ($l) => ($l['name'] ?? '').(isset($l['duration_minutes']) ? ' · '.($l['duration_minutes'] >= 60 ? rtrim(rtrim(number_format($l['duration_minutes'] / 60, 1), '0'), '.').' h' : $l['duration_minutes'].' min') : ''))->implode(' + ');
        $due = $revision ? CarbonImmutable::instance($revision->balance_due_at)->setTimezone($tz)->locale($lang) : null;

        $approvedSms = collect((array) config('waoo.sms_consent_versions', []))->filter(fn ($text) => is_string($text) && $text !== '');
        $smsVersion = $approvedSms->keys()->first();

        return [
            'lang' => $lang, 'step' => $step, 'steps' => $canDetails ? ['details', 'contract', 'pay'] : ['contract', 'pay'], 'test_mode' => true,
            'details' => $details ? ['expected_event_version' => $details['expected_event_version'], 'expected_revision' => $details['expected_revision'],
                'action' => url('/portal/api/details'),
                'sms_consent' => $smsVersion !== null ? ['version' => $smsVersion, 'text' => $approvedSms[$smsVersion]] : null] : null,
            'prefill' => $details && $details['submission'] ? $details['submission']['data']
                : ['phone' => $event->customer?->phone, 'email' => $event->customer?->email, 'event_address' => $event->address, 'event_postal_code' => $event->postal_code],
            // The contracting party's own first name when a contract exists; otherwise the owner's record.
            'first_name' => trim((string) ($contract?->commercial_snapshot['client']['first_name'] ?? explode(' ', (string) $event->customer?->name)[0] ?? '')),
            'notice' => $request->session()->get('portal_notice'),
            'lang_url' => ['es' => url('/portal?lang=es'), 'en' => url('/portal?lang=en')],
            'event' => [
                'package' => $services ?: $event->title,
                'characters' => collect($revision?->lines_snapshot ?? [])->flatMap(fn ($l) => $l['characters'] ?? [])->map(fn ($c) => $c['quantity'].' × '.\App\Services\Records\CharacterLabel::fromSnapshot($c))->implode(', '),
                'when' => $lang === 'es' ? $start->translatedFormat('D j M Y, g:i a') : $start->format('D, M j, Y \a\t g:i a'),
                'start_time' => $lang === 'es' ? $start->translatedFormat('g:i a') : $start->format('g:i a'),
                'total' => $fmt($revision?->total_cents), 'travel' => $fmt($revision?->travel_fee_cents),
                'paid' => $fmt($f['net_received_cents']), 'balance' => $fmt($balance),
                'due' => $due ? ($lang === 'es' ? $due->translatedFormat('D j M, g:i a') : $due->format('D, M j, g:i a')) : '—',
            ],
            'contract' => $contract ? ['id' => $contract->id, 'version' => $contract->version, 'sha256' => $contract->presented_pdf_sha256,
                'pdf_url' => url('/portal/api/contracts/'.$contract->id.'/pdf'), 'final_url' => $contract->final_pdf_path ? url('/portal/api/contracts/'.$contract->id.'/pdf?artifact=final') : null,
                'signed_name' => $signed ? SignatureEvidence::where('contract_id', $contract->id)->value('signer_name') : null] : null,
            'consent' => ['version' => InHouseSignatureProvider::CONSENT_VERSION, 'text' => InHouseSignatureProvider::CONSENT],
            'pay' => ['revision_id' => $revision?->id, 'can_checkout' => in_array('checkout.create', $grant->scopes, true) && ($balance ?? 0) > 0],
            'zelle_memo' => 'WP-'.$event->id,
            // Her own notices, through Codex's client projection only (never the owner's review note, bank reference or ledger).
            'zelle' => ['can_notify' => in_array('checkout.create', $grant->scopes, true) && ($balance ?? 0) > 0, 'action' => url('/portal/api/zelle-notices'),
                'amount' => $balance ? number_format($balance / 100, 2, '.', '') : '',
                'notices' => \App\Models\ZelleNotice::where('event_id', $event->id)->where('customer_id', $client->customerId)->with('review.ledgerEntry')->orderByDesc('id')->limit(10)->get()
                    ->map(fn ($n) => \App\Services\Portal\ZelleNoticeView::client($n))
                    ->map(fn ($n) => ['state' => $n['state'], 'amount' => $fmt($n['amount_cents']), 'verified' => $n['verified_amount_cents'] !== null ? $fmt($n['verified_amount_cents']) : null,
                        'when' => CarbonImmutable::instance($n['submitted_at'])->setTimezone($tz)->locale($lang)->{$lang === 'es' ? 'translatedFormat' : 'format'}($lang === 'es' ? 'D j M, g:i a' : 'D, M j, g:i a')])->all()],
            'actions' => ['details' => '#', 'sign' => $contract ? url('/portal/api/contracts/'.$contract->id.'/sign') : '#', 'checkout' => url('/portal/api/checkout-intents')],
        ];
    }

    private function lang(Request $request): string
    {
        $lang = $request->query('lang');
        if (in_array($lang, ['es', 'en'], true)) {
            $request->session()->put('portal_lang', $lang);
        }

        return $request->session()->get('portal_lang', 'es');
    }

    private function respond($view)
    {
        return response($view)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }
}
