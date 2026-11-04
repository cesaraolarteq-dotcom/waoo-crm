<?php
namespace App\Services\Changes;

use App\Models\{ClientDetailsSubmission, Customer, Event, EventChange, EventMessagePolicy, NoticeDelivery, PortalGrant, SmsConsent, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Mail\{CampaignOutbox, MailDeliveryGate, MailGuard};
use App\Services\Portal\{PortalTokenVault, ShortPortalLinks};
use App\Services\Sms\{PhoneNumber, SendSms, SmsGate};
use Illuminate\Support\Facades\DB;

/**
 * CHANGE-A part 4 (César 2026-10-30): when a reschedule waits for the client's signature, tell the client by email AND text
 * (texts only to numbers that opted in), and remind once 48 h later if still unsigned. Owner hold: nothing leaves while the
 * mail gate / SMS gate is off; the notice then stays pending and goes out when they are turned on (while still unsigned).
 * The link is the client's own active portal link (their details are bound to it); without one the owner is told instead.
 */
final class RescheduleNotices
{
    public const REMIND_AFTER_HOURS = 48;

    public const TEXTS = [
        'es' => ['subject' => 'Tu fiesta tiene nueva fecha: firma tu contrato', 'reminder_subject' => 'Recordatorio: firma tu contrato con la nueva fecha',
            'body' => 'Hola {name}: cambiamos la fecha de tu fiesta al {when}. Para dejarla confirmada, revisa y firma tu contrato actualizado.{fee}',
            'reminder' => 'Hola {name}: te recordamos firmar tu contrato con la nueva fecha ({when}) para dejarla confirmada.',
            'fee' => ' Incluye el cargo por cambio de fecha de $100.', 'button' => 'Revisar y firmar', 'closing' => '¡Gracias!',
            'sms' => 'Waoo Party: cambiamos tu fiesta al {when}. Firma tu contrato actualizado aquí: {link}', 'sms_reminder' => 'Waoo Party: recuerda firmar tu contrato con la nueva fecha ({when}): {link}'],
        'en' => ['subject' => 'Your party has a new date: please sign your contract', 'reminder_subject' => 'Reminder: please sign your contract for the new date',
            'body' => 'Hi {name}, we moved your party to {when}. To keep it confirmed, please review and sign your updated contract.{fee}',
            'reminder' => 'Hi {name}, a friendly reminder to sign your contract for the new date ({when}) so it stays confirmed.',
            'fee' => ' It includes the $100 date change fee.', 'button' => 'Review and sign', 'closing' => 'Thank you!',
            'sms' => 'Waoo Party: we moved your party to {when}. Please sign your updated contract here: {link}', 'sms_reminder' => 'Waoo Party: reminder to sign your contract for the new date ({when}): {link}'],
    ];

    /**
     * Each notice identity (change + notice/reminder + email/SMS) is planned once together with the change's claim and is
     * never reset (Codex review #163): an unknown provider outcome stays for reconciliation instead of being resent.
     * Nothing is planned for a paused event, a party HoneyBook still manages, a past party or one no longer waiting.
     * Each channel stands on its own: one can succeed while the other is unknown or not possible.
     * @return array{notices:int,reminders:int,held:int,no_link:int,unknown:int}
     */
    public function sendDue(): array
    {
        $out = ['notices' => 0, 'reminders' => 0, 'held' => 0, 'no_link' => 0, 'unknown' => 0];
        foreach (EventChange::where('kind', 'reschedule')->where('state', 'pending_signature')->orderBy('id')->limit(100)->get() as $change) {
            $kind = $change->notified_at === null ? 'notice'
                : ($change->reminded_at === null && $change->notified_at->lessThanOrEqualTo(now()->subHours(self::REMIND_AFTER_HOURS)) ? 'reminder' : null);
            if ($kind === null) continue;
            $event = Event::find($change->event_id); $customer = $event ? Customer::find($event->customer_id) : null;
            if (! $event || ! $customer || $this->blocked($change->fresh(), $event)) continue;
            if (! MailDeliveryGate::enabled() && ! SmsGate::enabled()) { $out['held']++; continue; }
            $link = $this->link($event);
            if ($link === null) { $out['no_link']++; continue; }
            $source = $this->source($change, $event, $customer);
            if ($source === null) { $out['no_link']++; continue; }
            $email = $this->email($event, $customer);
            $wantsEmail = MailDeliveryGate::enabled() && $email !== null && CampaignOutbox::canPlan($email);
            $phone = PhoneNumber::e164($customer->phone);
            $wantsSms = SmsGate::enabled() && $phone !== null && SmsConsent::allows($phone);
            if (! $wantsEmail && ! $wantsSms) { $out['held']++; continue; }
            $texts = $this->texts($change, $event, $customer, $kind, $link);
            // Claim the change and plan every possible channel in one transaction: two runs never plan twice.
            $planned = MailGuard::transaction(function () use ($change, $event, $customer, $kind, $wantsEmail, $wantsSms, $email, $texts) {
                Event::whereKey($event->id)->lockForUpdate()->first();
                $fresh = EventChange::whereKey($change->id)->lockForUpdate()->first();
                if (! $fresh || $this->blocked($fresh, Event::find($event->id)) || ($kind === 'notice' ? $fresh->notified_at !== null : $fresh->reminded_at !== null)) return null;
                $ids = [];
                if ($wantsEmail) {
                    $m = CampaignOutbox::plan('reschedule_'.$kind, 'reschedule|'.$fresh->id.'|'.$kind.'|email', $email, $customer->locale,
                        $texts['mail'], ['customer_id' => $customer->id, 'event_id' => $event->id, 'created_by' => $fresh->created_by]);
                    $ids['email'] = $this->claim($fresh, $kind, 'email', ['outbound_message_id' => $m->id])->id;
                }
                if ($wantsSms) $ids['sms'] = $this->claim($fresh, $kind, 'sms')->id;
                $fresh->forceFill($kind === 'notice' ? ['notified_at' => now()] : ['reminded_at' => now()])->save();
                return $ids;
            });
            if (! $planned) continue;
            $guard = function () use ($change, $source) {
                $fresh = EventChange::whereKey($change->id)->first();
                $event = $fresh ? Event::find($fresh->event_id) : null;
                $customer = $event ? Customer::find($event->customer_id) : null;
                if (! $fresh || ! $event || ! $customer || $this->blocked($fresh, $event)) return 'no_longer_due';
                $current = $this->source($fresh, $event, $customer);
                return $current !== null && hash_equals($source, $current) ? null : 'source_changed';
            };
            $channels = []; $unknown = false;
            if (isset($planned['email'])) {
                $row = NoticeDelivery::findOrFail($planned['email']);
                $status = app(CampaignOutbox::class)->dispatch($row->outbound_message_id, fn () => ! MailDeliveryGate::enabled() ? 'mail_held' : $guard());
                $row->update(['status' => $status]);
                if ($status === 'sent') $channels[] = 'email'; elseif ($status !== 'blocked') $unknown = true;
            }
            if (isset($planned['sms'])) {
                $row = NoticeDelivery::findOrFail($planned['sms']);
                if ($reason = $guard()) { $row->update(['status' => 'blocked', 'reason' => $reason]); }
                else {
                    try {
                        $sms = app(SendSms::class)->execute(User::findOrFail($change->created_by), $customer->id, $event->id, $texts['sms'], $phone);
                        $row->update(['sms_message_id' => $sms->id, 'status' => $sms->status]);
                        if ($sms->status === 'sent') $channels[] = 'sms'; elseif ($sms->status === 'unknown') $unknown = true;
                    } catch (\Illuminate\Validation\ValidationException) {
                        $row->update(['status' => 'blocked', 'reason' => 'sms_not_allowed']); // consent/number changed before sending
                    } catch (\Throwable) {
                        $row->update(['status' => 'unknown', 'reason' => 'sms_outcome_unknown']); $unknown = true;
                    }
                }
            }
            Audit::recordSystem('event.reschedule_'.$kind, $change, [], ['channels' => $channels, 'unknown' => $unknown]);
            if ($channels) $out[$kind === 'notice' ? 'notices' : 'reminders']++;
            if ($unknown) $out['unknown']++;
        }
        return $out;
    }

    /** Not to be told (now): no longer waiting, paused by the owner, managed by HoneyBook, or the new date already passed. */
    private function blocked(?EventChange $change, ?Event $event): bool
    {
        return ! $change || ! $event || $change->state !== 'pending_signature' || $event->status === 'cancelled'
            || $event->responsible_system !== 'crm' || ! $event->starts_at->isFuture()
            || (bool) EventMessagePolicy::where('event_id', $event->id)->value('paused');
    }

    /** Frozen notice source: a different contact, link, party or signed agreement needs a new owner review. No tokens leave here. */
    private function source(EventChange $change, Event $event, Customer $customer): ?string
    {
        $submission = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
        $grant = $submission ? PortalGrant::find($submission->portal_grant_id) : null;
        if (! $grant || ! $grant->canRevealLink() || $grant->event_id !== $event->id || $grant->customer_id !== $customer->id
            || ! in_array('contract.sign', (array) $grant->scopes, true) || ! EventChange::signedIsBeforeChange($event->id)) return null;
        return \App\Services\Finance\MutationKey::hash([
            'change' => $change->only(['id', 'event_id', 'state', 'new_starts_at', 'new_ends_at', 'fee_cents', 'event_version_after', 'financial_revision_id']),
            'event' => $event->only(['id', 'customer_id', 'version', 'status', 'responsible_system', 'starts_at', 'ends_at']),
            'customer' => $customer->only(['id', 'name', 'email', 'phone', 'locale']),
            'submission' => $submission->only(['id', 'revision', 'portal_grant_id', 'data']),
            'grant' => $grant->only(['id', 'token_hash', 'scopes', 'expires_at']),
        ]);
    }

    private function claim(EventChange $change, string $kind, string $channel, array $extra = []): NoticeDelivery
    {
        return NoticeDelivery::create(['identity_sha256' => hash('sha256', 'reschedule|'.$change->id.'|'.$kind.'|'.$channel),
            'source' => 'reschedule', 'source_id' => $change->id, 'kind' => $kind, 'channel' => $channel, ...$extra]);
    }

    /** The client's email on file, or the one they gave in their portal details. */
    private function email(Event $event, Customer $customer): ?string
    {
        $email = $customer->email ?: (ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first()?->data['email'] ?? null);
        return filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** The client's own active link: the grant their details were submitted with (their contract is bound to it). */
    private function link(Event $event): ?string
    {
        $grantId = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->value('portal_grant_id');
        $grant = $grantId ? PortalGrant::whereKey($grantId)->first() : null;
        if (! $grant || ! $grant->canRevealLink() || ! in_array('contract.sign', (array) $grant->scopes, true)) return null;
        try {
            return DB::transaction(function () use ($grant) {
                $locked = PortalGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
                return app(ShortPortalLinks::class)->forLockedGrant($locked) ?? Sandbox::origin().'/portal#token='.PortalTokenVault::open($locked);
            });
        } catch (\Throwable) { return null; }
    }

    /** Approved texts (César 2026-10-30), unchanged. @return array{mail:array{subject:string,html:string,text:string},sms:string} */
    private function texts(EventChange $change, Event $event, Customer $customer, string $kind, string $link): array
    {
        $lang = $customer->locale === 'en' ? 'en' : 'es'; $t = self::TEXTS[$lang];
        $when = $event->starts_at->setTimezone('America/Chicago')->locale($lang)->translatedFormat($lang === 'en' ? 'l, F j, g:i a' : 'l j \\d\\e F, g:i a');
        $name = trim(strtok(trim((string) $customer->name), ' ') ?: '') ?: ($lang === 'en' ? 'there' : '');
        $fill = fn (string $s) => strtr($s, ['{name}' => $name, '{when}' => $when, '{link}' => $link, '{fee}' => $change->fee_cents > 0 ? $t['fee'] : '']);
        $subject = $kind === 'notice' ? $t['subject'] : $t['reminder_subject'];
        $paragraph = $fill($kind === 'notice' ? $t['body'] : $t['reminder']);
        $html = view('mail.layout', ['lang' => $lang, 'logo' => Sandbox::origin().'/img/waoo-logo.png', 'totals' => [],
            'mail' => ['subject' => $subject, 'paragraphs' => [$paragraph], 'closing' => $t['closing'], 'button' => ['label' => $t['button'], 'url' => $link], 'button2' => null]])->render();
        return ['mail' => ['subject' => $subject, 'html' => $html, 'text' => $paragraph."\n\n".$t['button'].': '.$link."\n\n— Waoo Party"],
            'sms' => $fill($kind === 'notice' ? $t['sms'] : $t['sms_reminder'])];
    }
}
