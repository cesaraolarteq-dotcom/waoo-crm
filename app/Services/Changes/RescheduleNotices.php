<?php
namespace App\Services\Changes;

use App\Models\{ClientDetailsSubmission, Customer, Event, EventChange, PortalGrant, User};
use App\Services\Audit;
use App\Services\Finance\Sandbox;
use App\Services\Mail\{MailDeliveryGate, MailTransport, OutboundEnvelope};
use App\Services\Portal\{PortalTokenVault, ShortPortalLinks};
use App\Services\Sms\{SendSms, SmsGate};
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

    /** @return array{notices:int,reminders:int,held:int,no_link:int} */
    public function sendDue(): array
    {
        $out = ['notices' => 0, 'reminders' => 0, 'held' => 0, 'no_link' => 0];
        foreach (EventChange::where('kind', 'reschedule')->where('state', 'pending_signature')->orderBy('id')->limit(100)->get() as $change) {
            $kind = $change->notified_at === null ? 'notice'
                : ($change->reminded_at === null && $change->notified_at->lessThanOrEqualTo(now()->subHours(self::REMIND_AFTER_HOURS)) ? 'reminder' : null);
            if ($kind === null) continue;
            if (! MailDeliveryGate::enabled() && ! SmsGate::enabled()) { $out['held']++; continue; }
            $event = Event::find($change->event_id); $customer = $event ? Customer::find($event->customer_id) : null;
            $link = $event ? $this->link($event) : null;
            if (! $event || ! $customer || $link === null) { $out['no_link']++; continue; }
            // Claim first so two runs never send twice.
            $claimed = DB::transaction(function () use ($change, $kind) {
                $fresh = EventChange::whereKey($change->id)->lockForUpdate()->first();
                if (! $fresh || $fresh->state !== 'pending_signature' || ($kind === 'notice' ? $fresh->notified_at !== null : $fresh->reminded_at !== null)) return false;
                $fresh->forceFill($kind === 'notice' ? ['notified_at' => now()] : ['reminded_at' => now()])->save();
                return true;
            });
            if (! $claimed) continue;
            $channels = $this->deliver($change, $event, $customer, $kind, $link);
            if ($channels === []) { // nothing could go out: undo so the next run retries
                EventChange::whereKey($change->id)->update($kind === 'notice' ? ['notified_at' => null] : ['reminded_at' => null]);
                continue;
            }
            Audit::recordSystem('event.reschedule_'.$kind, $change, [], ['channels' => $channels]);
            $out[$kind === 'notice' ? 'notices' : 'reminders']++;
        }
        return $out;
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

    /** @return list<string> channels that went out */
    private function deliver(EventChange $change, Event $event, Customer $customer, string $kind, string $link): array
    {
        $lang = $customer->locale === 'en' ? 'en' : 'es'; $t = self::TEXTS[$lang];
        $when = $event->starts_at->setTimezone('America/Chicago')->locale($lang)->translatedFormat($lang === 'en' ? 'l, F j, g:i a' : 'l j \d\e F, g:i a');
        $name = trim(strtok(trim((string) $customer->name), ' ') ?: '') ?: ($lang === 'en' ? 'there' : '');
        $fill = fn (string $s) => strtr($s, ['{name}' => $name, '{when}' => $when, '{link}' => $link, '{fee}' => $change->fee_cents > 0 ? $t['fee'] : '']);
        $channels = [];
        // The client's email on file, or the one they gave in their portal details.
        $email = $customer->email ?: (ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first()?->data['email'] ?? null);
        if (MailDeliveryGate::enabled() && filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $subject = $kind === 'notice' ? $t['subject'] : $t['reminder_subject'];
            $paragraph = $fill($kind === 'notice' ? $t['body'] : $t['reminder']);
            $html = view('mail.layout', ['lang' => $lang, 'logo' => Sandbox::origin().'/img/waoo-logo.png', 'totals' => [],
                'mail' => ['subject' => $subject, 'paragraphs' => [$paragraph], 'closing' => $t['closing'], 'button' => ['label' => $t['button'], 'url' => $link], 'button2' => null]])->render();
            try {
                app(MailTransport::class)->send(new OutboundEnvelope($change->id, $email, $subject, $html, $paragraph."\n\n".$t['button'].': '.$link."\n\n— Waoo Party"));
                $channels[] = 'email';
            } catch (\Throwable) { /* never log the address or provider payload */ }
        }
        if (SmsGate::enabled()) {
            try {
                $sms = app(SendSms::class)->execute(User::findOrFail($change->created_by), $customer->id, $event->id, $fill($kind === 'notice' ? $t['sms'] : $t['sms_reminder']));
                if ($sms->status === 'sent') $channels[] = 'sms';
            } catch (\Throwable) { /* no consent or no valid mobile: email only */ }
        }
        return $channels;
    }
}
