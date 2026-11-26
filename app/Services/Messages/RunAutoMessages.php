<?php
namespace App\Services\Messages;

use App\Models\{AutoMessageLog, Contract, Customer, Event, EventMessagePolicy, LeadSubmission, LedgerEntry, MailOccurrence, MailOccurrenceState, OutboundMessage, SmsConsent, User};
use App\Services\Mail\{CampaignOutbox, MailDates, MailGuard};
use App\Services\Sms\{PhoneNumber, SendSms, SmsGate};
use App\Support\AutoMessages;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Owner 2026-11-23: sends the automatic messages of the "Mensajes automáticos" page (every minute, waoo:messages).
 * - Solicitud recibida / Pago recibido: from the occurrences the CRM already records when a lead or payment arrives.
 * - Contrato firmado: contracts signed in the last 2 hours.
 * - Reminder texts: when a planned reminder email is due (the email itself goes through waoo:mail-due as before).
 * Each (source, channel) is decided once (auto_message_log, unique). Only events from the last 2 hours: switching a
 * message on never sends old ones. Email goes through the durable outbox (CampaignOutbox), so test mode and bounce
 * suppression apply; texts go through SendSms, so consent/STOP and the test-number list apply.
 */
final class RunAutoMessages
{
    public const WINDOW_HOURS = 2;
    private const OCCURRENCE_KEYS = ['inquiry_received', 'payment_received'];

    private array $out = ['email' => 0, 'sms' => 0, 'held' => 0, 'skipped' => 0];

    public function run(): array
    {
        if (! \App\Support\WaooRuntime::trusted()) return $this->out;
        $this->out = array_fill_keys(array_keys($this->out), 0);
        $this->occurrences();
        $this->contracts();
        $this->reminderTexts();
        $this->thankYous();
        return $this->out;
    }

    private function since() { return now()->subHours(self::WINDOW_HOURS); }

    private function occurrences(): void
    {
        $pending = MailOccurrenceState::where('status', 'pending')->select('mail_occurrence_id');
        $rows = MailOccurrence::whereIn('id', $pending)->whereIn('key', self::OCCURRENCE_KEYS)->orderBy('id')->limit(50)->get();
        foreach ($rows as $o) {
            $state = MailOccurrenceState::where('mail_occurrence_id', $o->id)->first();
            if ($o->occurred_at->lessThan($this->since())) { $this->close($state, 'skipped', 'too_old'); continue; }
            try {
                [$status, $messageId] = $o->key === 'inquiry_received' ? $this->inquiry($o) : $this->payment($o);
            } catch (\Throwable $e) {
                report($e); [$status, $messageId] = ['skipped', null];
                $this->close($state, 'skipped', $this->code($e)); continue;
            }
            $this->close($state, $status, null, $messageId);
        }
    }

    private function inquiry(MailOccurrence $o): array
    {
        $p = LeadSubmission::findOrFail((int) $o->source_id)->payload;
        $locale = ($p['locale'] ?? 'en') === 'es' ? 'es' : 'en';
        $date = empty($p['start_time']) ? MailDates::due($p['event_date'].' 12:00', $locale) : MailDates::full(\Carbon\CarbonImmutable::parse($p['event_date'].' '.$p['start_time'], 'America/Chicago'), $locale);
        $vars = ['first_name' => explode(' ', trim((string) $p['name']))[0], 'event_date' => $date, 'zip' => (string) $p['zip']];
        $this->leadText('occ:'.$o->id, (string) ($p['phone'] ?? ''), $locale, $vars);
        return $this->email('inquiry_received', 'occ:'.$o->id, (string) $p['email'], $locale, fn () => AutoMessages::renderEmail('inquiry_received', $locale, $vars), []);
    }

    private function payment(MailOccurrence $o): array
    {
        $entry = LedgerEntry::findOrFail((int) $o->source_id);
        $src = AutoMessageSource::forEvent((int) $o->event_id, (int) $entry->amount_cents);
        $this->text('payment_received', 'occ:'.$o->id, $src);
        return $this->email('payment_received', 'occ:'.$o->id, (string) $src['customer']->email, $src['locale'],
            fn () => AutoMessages::renderEmail('payment_received', $src['locale'], $src['vars']), ['customer_id' => $src['customer']->id, 'event_id' => $src['event']->id]);
    }

    private function contracts(): void
    {
        $contracts = Contract::where('state', 'signed')->whereBetween('signed_at', [$this->since(), now()]);
        foreach ($this->undecided($contracts, 'contract:', ['email', 'sms'], 50) as $contract) {
            $id = 'contract:'.$contract->id;
            try {
                $src = AutoMessageSource::forEvent((int) $contract->event_id);
            } catch (\Throwable $e) {
                $this->log('contract_signed', $id, 'email', $contract->event_id, 'skipped', $this->code($e));
                $this->log('contract_signed', $id, 'sms', $contract->event_id, 'skipped', $this->code($e));
                continue;
            }
            $this->email('contract_signed', $id, (string) $src['customer']->email, $src['locale'],
                fn () => AutoMessages::renderEmail('contract_signed', $src['locale'], $src['vars']), ['customer_id' => $src['customer']->id, 'event_id' => $src['event']->id]);
            $this->text('contract_signed', $id, $src);
        }
    }

    /** The text that goes with a due reminder (7 days before, balance, thank you). The email keeps its own path. */
    private function reminderTexts(): void
    {
        $due = OutboundMessage::whereIn('template_key', ['balance_reminder', 'week_before'])
            ->whereBetween('due_at', [$this->since(), now()])->whereNotNull('event_id');
        foreach ($this->undecided($due, 'reminder:', ['sms'], 50) as $m) {
            $id = 'reminder:'.$m->id;
            $event = Event::find($m->event_id);
            if (! $event || $event->status !== 'capacity_reserved') { $this->log($m->template_key, $id, 'sms', $m->event_id, 'skipped', 'event_not_confirmed'); continue; }
            if (EventMessagePolicy::where('event_id', $event->id)->value('paused')) { $this->log($m->template_key, $id, 'sms', $event->id, 'skipped', 'event_paused'); continue; }
            try { $src = AutoMessageSource::forEvent($event->id); }
            catch (\Throwable $e) { $this->log($m->template_key, $id, 'sms', $event->id, 'skipped', $this->code($e)); continue; }
            if ($m->template_key === 'balance_reminder' && $src['balance_cents'] <= 0) { $this->log($m->template_key, $id, 'sms', $event->id, 'skipped', 'no_balance'); continue; }
            $this->text($m->template_key, $id, $src);
        }
    }

    /**
     * Gracias + reseña: the day after the party at 10 a.m. (Houston), for confirmed CRM parties. With tips on, the tip link
     * is issued automatically (IssueTipGrant::automatic); with tips off the email goes without the tip sentence.
     */
    private function thankYous(): void
    {
        $today = now('America/Chicago')->startOfDay();
        $due = $today->copy()->setTime(10, 0);
        if ($due->isFuture() || $due->lessThan($this->since())) return;
        $events = Event::where('status', 'capacity_reserved')->where('responsible_system', 'crm')
            ->where('ends_at', '>=', $today->copy()->subDay()->utc())
            ->where('ends_at', '<', $today->copy()->utc());
        foreach ($this->undecided($events, 'thanks:', ['email', 'sms'], 100) as $event) {
            $id = 'thanks:'.$event->id;
            if (EventMessagePolicy::where('event_id', $event->id)->value('paused')) {
                foreach (['email', 'sms'] as $ch) if (! AutoMessageLog::where('identity_sha256', $this->sha($id, $ch))->exists()) $this->log('thank_you_review', $id, $ch, $event->id, 'skipped', 'event_paused');
                continue;
            }
            if (AutoMessages::tipsOn() && AutoMessages::emailOn('thank_you_review') && $this->owner()) {
                try { app(\App\Services\Tips\IssueTipGrant::class)->automatic($this->owner(), $event->id, (string) Customer::whereKey($event->customer_id)->value('locale')); }
                catch (\Throwable $e) { report($e); } // without a link the email goes without the tip sentence
            }
            try { $src = AutoMessageSource::forEvent($event->id); }
            catch (\Throwable $e) {
                foreach (['email', 'sms'] as $ch) if (! AutoMessageLog::where('identity_sha256', $this->sha($id, $ch))->exists()) $this->log('thank_you_review', $id, $ch, $event->id, 'skipped', $this->code($e));
                continue;
            }
            if (! AutoMessages::tipsOn()) $src['vars']['tip_link'] = null;
            $this->email('thank_you_review', $id, (string) $src['customer']->email, $src['locale'],
                fn () => AutoMessages::renderEmail('thank_you_review', $src['locale'], $src['vars']), ['customer_id' => $src['customer']->id, 'event_id' => $event->id]);
            $this->text('thank_you_review', $id, $src);
        }
    }

    /** Count the batch after filtering decided sources, so the first page cannot starve later rows. */
    private function undecided(\Illuminate\Database\Eloquent\Builder $query, string $prefix, array $channels, int $limit): iterable
    {
        $remaining = $limit;
        foreach ($query->lazyById($limit) as $row) {
            $identities = array_map(fn ($channel) => $this->sha($prefix.$row->id, $channel), $channels);
            if (AutoMessageLog::whereIn('identity_sha256', $identities)->count() === count($channels)) continue;
            yield $row;
            if (--$remaining === 0) return;
        }
    }

    private function leadText(string $identity, string $phone, string $locale, array $vars): void
    {
        $sha = $this->sha($identity, 'sms');
        if (AutoMessageLog::where('identity_sha256', $sha)->exists()) return;
        $to = PhoneNumber::e164($phone); $only = (array) config('waoo.sms_allowlist', []);
        $reason = match (true) {
            ! AutoMessages::smsOn('inquiry_received') => 'owner_off',
            ! SmsGate::enabled() => 'sms_off',
            ! $to => 'no_mobile',
            $only !== [] && ! in_array($to, $only, true) => 'test_mode',
            ! SmsConsent::allows($to) => 'no_consent',
            default => null,
        };
        if ($reason) { $this->log('inquiry_received', $identity, 'sms', null, $reason === 'test_mode' ? 'held' : 'skipped', $reason); return; }
        try { $body = AutoMessages::renderSms('inquiry_received', $locale, $vars); }
        catch (\Throwable $e) { $this->log('inquiry_received', $identity, 'sms', null, 'skipped', $this->code($e)); return; }
        try { $row = $this->log('inquiry_received', $identity, 'sms', null, 'sending'); } catch (UniqueConstraintViolationException) { return; }
        try {
            $sms = app(SendSms::class)->toLead($this->owner(), $to, $body, $locale);
            $row->fill(['status' => $sms->status, 'sms_message_id' => $sms->id])->save();
            if (in_array($sms->status, ['sent', 'delivered'], true)) $this->out['sms']++;
        } catch (\Throwable $e) { $row->fill(['status' => 'skipped', 'reason' => $this->code($e)])->save(); }
    }

    /** @return array{0:string,1:?int} final status and outbox message id */
    private function email(string $key, string $identity, string $recipient, string $locale, \Closure $render, array $links): array
    {
        $sha = $this->sha($identity, 'email');
        if ($done = AutoMessageLog::where('identity_sha256', $sha)->first()) return [$done->status, $done->outbound_message_id];
        if (! AutoMessages::emailOn($key)) { $this->log($key, $identity, 'email', $links['event_id'] ?? null, 'skipped', 'owner_off'); return ['skipped', null]; }
        $recipient = mb_strtolower(trim($recipient));
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) { $this->log($key, $identity, 'email', $links['event_id'] ?? null, 'skipped', 'recipient_required'); return ['skipped', null]; }
        if (! CampaignOutbox::canPlan($recipient, $key)) {
            // Test mode (client not on the test list), bounced address or no provider: decided once, never sent later.
            $this->out['held']++;
            $this->log($key, $identity, 'email', $links['event_id'] ?? null, 'held', MailGuard::allows($recipient, $key) ? 'transport_or_suppressed' : 'test_mode');
            return ['held', null];
        }
        $mail = $render();
        $owner = $this->owner();
        try {
            $message = MailGuard::transaction(function () use ($key, $identity, $sha, $recipient, $locale, $mail, $links, $owner) {
                $m = CampaignOutbox::plan($key, 'auto|'.$identity.'|email', $recipient, $locale, $mail, [...$links, 'created_by' => $owner?->id]);
                AutoMessageLog::create(['identity_sha256' => $sha, 'key' => $key, 'channel' => 'email', 'event_id' => $links['event_id'] ?? null,
                    'outbound_message_id' => $m->id, 'status' => 'planned']);
                return $m;
            });
        } catch (UniqueConstraintViolationException) { return ['planned', null]; }
        $status = app(CampaignOutbox::class)->dispatch($message->id, fn () => AutoMessages::emailOn($key) ? null : 'owner_off');
        AutoMessageLog::where('identity_sha256', $sha)->update(['status' => $status]);
        if ($status === 'sent') $this->out['email']++;
        return [$status, $message->id];
    }

    private function text(string $key, string $identity, array $src): void
    {
        $sha = $this->sha($identity, 'sms'); $eventId = $src['event']->id;
        if (AutoMessageLog::where('identity_sha256', $sha)->exists()) return;
        $reason = match (true) {
            ! (AutoMessages::FLOW[$key]['sms'] ?? false) => 'no_text_version',
            ! AutoMessages::smsOn($key) => 'owner_off',
            ! SmsGate::enabled() => 'sms_off',
            default => null,
        };
        $to = PhoneNumber::e164($src['customer']->phone);
        $only = (array) config('waoo.sms_allowlist', []);
        $reason ??= match (true) {
            ! $to => 'no_mobile',
            $only !== [] && ! in_array($to, $only, true) => 'test_mode',
            ! SmsConsent::allows($to) => 'no_consent',
            default => null,
        };
        if ($reason) { $this->log($key, $identity, 'sms', $eventId, $reason === 'test_mode' ? 'held' : 'skipped', $reason); return; }
        try { $body = AutoMessages::renderSms($key, $src['locale'], $src['vars']); }
        catch (\Throwable $e) { $this->log($key, $identity, 'sms', $eventId, 'skipped', $this->code($e)); return; }
        // Claim first: a crash after this line never sends the same text twice.
        try { $row = $this->log($key, $identity, 'sms', $eventId, 'sending'); } catch (UniqueConstraintViolationException) { return; }
        try {
            $sms = app(SendSms::class)->execute($this->owner(), $src['customer']->id, $eventId, $body, $to);
            $row->fill(['status' => $sms->status, 'sms_message_id' => $sms->id])->save();
            if (in_array($sms->status, ['sent', 'delivered'], true)) $this->out['sms']++;
        } catch (\Throwable $e) {
            $row->fill(['status' => 'skipped', 'reason' => $this->code($e)])->save();
        }
    }

    private function log(string $key, string $identity, string $channel, ?int $eventId, string $status, ?string $reason = null): AutoMessageLog
    {
        if ($status === 'skipped') $this->out['skipped']++;
        return AutoMessageLog::create(['identity_sha256' => $this->sha($identity, $channel), 'key' => $key, 'channel' => $channel,
            'event_id' => $eventId, 'status' => $status, 'reason' => $reason ? mb_substr($reason, 0, 60) : null]);
    }

    private function close(?MailOccurrenceState $state, string $status, ?string $reason, ?int $messageId = null): void
    {
        if (! $state) return;
        $state->fill(['status' => mb_substr($status, 0, 20), 'reason' => $reason ? mb_substr($reason, 0, 60) : null, 'version' => $state->version + 1,
            ...($messageId ? ['outbound_message_id' => $messageId] : [])])->save();
    }

    private function sha(string $identity, string $channel): string { return hash('sha256', 'auto-message|'.$identity.'|'.$channel); }

    private function code(\Throwable $e): string
    {
        $raw = $e instanceof \Illuminate\Validation\ValidationException ? (string) collect($e->errors())->flatten()->first() : class_basename($e);
        return preg_match('/^[a-z0-9_]{1,60}$/', $raw) ? $raw : 'source_unavailable';
    }

    private ?User $ownerCache = null;
    private function owner(): ?User
    {
        return $this->ownerCache ??= User::where('role', 'owner_admin')->where('active', true)->whereNotNull('two_factor_secret')->whereNotNull('two_factor_confirmed_at')->orderBy('id')->first();
    }
}
