<?php
namespace App\Services\Messages;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, PortalGrant, TipGrant};
use App\Services\Finance\Sandbox;
use App\Services\Mail\{ApprovedMail, MailDates};
use App\Services\Portal\PortalTokenVault;

/**
 * Current data of one party for an automatic message (contract signed, payment received, reminder texts). Read-only:
 * never creates a link. The client link is her newest active booking link (not a quote link), preferring one that pays.
 */
final class AutoMessageSource
{
    /** @return array{event:Event,customer:Customer,locale:string,vars:array,totals:array} */
    public static function forEvent(int $eventId, ?int $amountCents = null): array
    {
        $e = Event::findOrFail($eventId);
        if ($e->responsible_system !== 'crm') ApprovedMail::fail('responsible_system_honeybook');
        $c = Customer::findOrFail($e->customer_id);
        $locale = $c->locale === 'es' ? 'es' : 'en'; // owner 2026-11-20: English unless the client chose Spanish
        $f = FinancialRevision::where('event_id', $e->id)->orderByDesc('revision')->firstOrFail();
        $net = (int) LedgerEntry::where('event_id', $e->id)->where('environment', \App\Support\WaooRuntime::ledgerEnvironment())->get()
            ->sum(fn ($l) => $l->kind === 'receipt' ? $l->amount_cents : ($l->kind === 'refund' ? -$l->amount_cents : 0));
        $money = fn (int $v) => '$'.number_format(intdiv($v, 100), 0, '.', ',').'.'.str_pad((string) ($v % 100), 2, '0', STR_PAD_LEFT);
        $balance = max(0, $f->total_cents - $net);
        $grant = PortalGrant::where('event_id', $e->id)->where('customer_id', $c->id)->whereNull('quote_revision_id')->whereNull('revoked_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->get()
            ->sortByDesc(fn ($g) => in_array('checkout.create', $g->scopes ?? [], true))->first();
        $link = null;
        if ($grant) { try { $link = Sandbox::origin().'/portal#token='.PortalTokenVault::open($grant); } catch (\Throwable) { $link = null; } }
        $tip = TipGrant::where('event_id', $e->id)->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
        $tipLink = null;
        if ($tip) { try { $tipLink = \App\Services\Tips\TipGuard::link($tip); } catch (\Throwable) { $tipLink = null; } }
        $vars = ['first_name' => explode(' ', trim((string) $c->name))[0], 'event_date' => MailDates::full($e->starts_at, $locale),
            'subject_date' => MailDates::subject($e->starts_at, $locale), 'address' => $e->address,
            'package' => implode(' + ', array_map(fn ($n) => \App\Support\PackageName::for($n, $locale), array_column($f->lines_snapshot ?? [], 'name'))),
            'total' => $money($f->total_cents), 'paid' => $money(max(0, $net)), 'balance' => $money($balance), 'has_balance' => $balance > 0,
            'due' => $f->balance_due_at === null ? '' : MailDates::due($f->balance_due_at, $locale),
            'balance_due' => $balance > 0 && $f->balance_due_at ? ($locale === 'es' ? ' · vence ' : ' · due ').MailDates::full($f->balance_due_at, $locale) : '',
            'amount' => $money($amountCents ?? max(0, $net)), 'link' => $link, 'review_link' => config('waoo.mail_review_url'), 'tip_link' => $tipLink];

        return ['event' => $e, 'customer' => $c, 'locale' => $locale, 'vars' => $vars, 'net' => $net, 'balance_cents' => $balance, 'finance' => $f];
    }
}
