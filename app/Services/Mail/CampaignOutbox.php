<?php
namespace App\Services\Mail;

use App\Models\{OutboundMessage, OutboundMessageState};
use App\Services\Finance\MutationKey;

/**
 * Durable outbox for CRM-written notices that are not event reminders (anniversary campaign, reschedule notices).
 * Same protocol as DispatchOutboundMail (Codex review on #163/#167):
 * - one immutable OutboundMessage per notice identity (intent hash unique: source, kind, channel), planned in the caller's
 *   transaction together with the caller's own claim, so a notice is never planned twice;
 * - the claim to "dispatching" re-checks allowlist, bounce/complaint suppression, the caller's guard and the transport;
 * - after crossing into the transport there is no reset and no automatic resend: a timeout or crash stays
 *   "delivery_unknown" / "dispatching" for reconciliation.
 */
final class CampaignOutbox
{
    /** Whether a message to this recipient may be planned now (allowlisted, not bounced/complained, transport configured). */
    public static function canPlan(string $recipient): bool
    {
        return in_array(mb_strtolower($recipient), MailGuard::allowlist(), true) && ! self::suppressed($recipient)
            && app(MailTransport::class)->configured();
    }

    public static function suppressed(string $recipient): bool
    {
        return OutboundMessageState::whereIn('outbound_message_id', OutboundMessage::where('recipient_sha256', hash('sha256', mb_strtolower($recipient)))->select('id'))
            ->whereIn('status', ['bounced', 'complained'])->exists();
    }

    /**
     * Plans one email inside the caller's transaction. Returns the existing message when the identity was already planned.
     * @param array{subject:string,html:string,text:string} $mail
     * @param array{customer_id?:int,event_id?:int,created_by?:int} $links
     */
    public static function plan(string $template, string $identity, string $recipient, string $locale, array $mail, array $links = []): OutboundMessage
    {
        $recipient = mb_strtolower($recipient);
        $intent = hash('sha256', 'campaign-outbox|'.$identity);
        if ($existing = OutboundMessage::where('intent_sha256', $intent)->lockForUpdate()->first()) return $existing;
        $payload = ['recipient' => $recipient, 'subject' => $mail['subject'], 'html' => $mail['html'], 'text' => $mail['text']];
        $m = OutboundMessage::create([...array_intersect_key($links, array_flip(['customer_id', 'event_id', 'created_by'])),
            'template_key' => $template, 'template_version' => $template.'@1', 'locale' => $locale === 'en' ? 'en' : 'es', 'channel' => 'email', 'environment' => 'sandbox',
            'template_sha256' => hash('sha256', $template), 'recipient_sha256' => hash('sha256', $recipient), 'intent_sha256' => $intent,
            'source_sha256' => MutationKey::hash($payload), 'due_at' => now(), 'payload' => $payload]);
        OutboundMessageState::create(['outbound_message_id' => $m->id, 'status' => 'scheduled']);
        MailGuard::event($m->id, 'scheduled:'.$m->id, 'scheduled');
        return $m;
    }

    /**
     * Claims and sends one planned message. $guard runs under the claim lock and returns null to proceed, or a reason to
     * block (for example the client unsubscribed or the event was paused). Returns the final state of this attempt.
     */
    public function dispatch(int $messageId, ?\Closure $guard = null): string
    {
        $m = OutboundMessage::findOrFail($messageId); $transport = app(MailTransport::class);
        $claimed = MailGuard::transaction(function () use ($m, $transport, $guard) {
            $state = OutboundMessageState::where('outbound_message_id', $m->id)->lockForUpdate()->firstOrFail();
            if ($state->status !== 'scheduled') return $state->status;
            $p = $m->payload;
            try { $intact = hash_equals($m->source_sha256, MutationKey::hash($p)) && hash_equals($m->recipient_sha256, hash('sha256', (string) $p['recipient'])); }
            catch (\Throwable) { $intact = false; }
            if (! $intact) return $this->transition($m, $state, 'blocked', 'payload_integrity_failed');
            if ($reason = $guard ? $guard() : null) return $this->transition($m, $state, 'blocked', $reason);
            if (! in_array($p['recipient'], MailGuard::allowlist(), true)) return $this->transition($m, $state, 'blocked', 'recipient_not_allowed');
            if (self::suppressed($p['recipient'])) return $this->transition($m, $state, 'blocked', 'recipient_suppressed');
            if (! $transport->configured()) return $this->transition($m, $state, 'blocked', 'transport_unconfigured');
            $this->transition($m, $state, 'dispatching', null, true);
            return true;
        });
        if ($claimed !== true) return $claimed;
        // No automatic resend after crossing this boundary; a crash leaves dispatching for reconciliation.
        try {
            $p = $m->payload;
            $receipt = $transport->send(new OutboundEnvelope($m->id, $p['recipient'], $p['subject'], $p['html'], $p['text']));
            if (! preg_match('/^[a-z][a-z0-9_-]{0,59}$/D', $receipt->provider) || $receipt->messageId === '' || strlen($receipt->messageId) > 255) throw new \RuntimeException();
        } catch (\Throwable) {
            return MailGuard::transaction(function () use ($m) {
                $s = OutboundMessageState::where('outbound_message_id', $m->id)->lockForUpdate()->firstOrFail();
                return $s->status === 'dispatching' ? $this->transition($m, $s, 'delivery_unknown', 'transport_outcome_unknown') : $s->status;
            });
        }
        return MailGuard::transaction(function () use ($m, $receipt) {
            $s = OutboundMessageState::where('outbound_message_id', $m->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'dispatching') return $s->status;
            $s->fill(['provider' => $receipt->provider, 'provider_message_id' => $receipt->messageId,
                'provider_message_sha256' => hash('sha256', $receipt->provider."\0".$receipt->messageId), 'sent_at' => now()]);
            return $this->transition($m, $s, 'sent');
        });
    }

    public static function status(int $messageId): ?string
    {
        return OutboundMessageState::where('outbound_message_id', $messageId)->value('status');
    }

    private function transition(OutboundMessage $m, OutboundMessageState $s, string $status, ?string $error = null, bool $attempt = false): string
    {
        $s->fill(['status' => $status, 'version' => $s->version + 1, 'error_code' => $error, 'attempt_count' => $s->attempt_count + ($attempt ? 1 : 0)])->save();
        MailGuard::event($m->id, 'state:'.$m->id.':'.$s->version, $status, ['error_code' => $error]);
        return $status;
    }
}
