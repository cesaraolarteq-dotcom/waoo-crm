<?php

namespace App\Services\Mail;

use App\Exceptions\RecordWriteRejected;
use App\Models\{OutboundMessageEvent, OutboundMessageState};
use App\Services\Finance\{MutationKey, Sandbox};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class RecordMailDelivery
{
    // Internal normalization seam. Public traffic must use an authenticated adapter.
    public function execute(string $provider, string $providerMessageId, string $providerEventId, string $outcome, CarbonImmutable $occurredAt): void
    {
        Sandbox::require('sandbox_mail');
        if (!in_array($outcome, ['delivered', 'bounced', 'complained'], true)) {
            throw ValidationException::withMessages(['mail' => 'invalid_provider_event']);
        }
        $this->record($provider, $providerMessageId, $providerEventId, $outcome, $occurredAt);
    }

    public function authenticatedBounce(string $provider, string $providerMessageId, string $providerEventId, string $outcome, CarbonImmutable $occurredAt): void
    {
        Sandbox::require('sandbox_mail_bounces');
        if (!in_array($outcome, ['bounced', 'complained'], true)) {
            throw ValidationException::withMessages(['mail' => 'invalid_bounce_outcome']);
        }
        $this->record($provider, $providerMessageId, $providerEventId, $outcome, $occurredAt);
    }

    // Called only after adapter authentication and exact send/recipient binding.
    public function authenticatedObservation(string $provider, string $providerMessageId, string $providerEventId, string $outcome, CarbonImmutable $occurredAt, array $evidence): void
    {
        Sandbox::require('sandbox_mail_bounces');
        $validInactive = array_key_exists('inactive', $evidence)
            && ($evidence['inactive'] === null || is_bool($evidence['inactive']));
        if (!in_array($outcome, ['bounced', 'complained', 'provider_notice'], true)
            || array_diff(array_keys($evidence), ['type', 'inactive']) !== []
            || !is_string($evidence['type'] ?? null)
            || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $evidence['type'])
            || !$validInactive) {
            throw ValidationException::withMessages(['mail' => 'invalid_provider_observation']);
        }
        $this->record($provider, $providerMessageId, $providerEventId, $outcome, $occurredAt, $evidence, 5);
    }

    private function record(string $provider, string $providerMessageId, string $providerEventId, string $outcome, CarbonImmutable $occurredAt, array $evidence = [], int $futureMinutes = 0): void
    {
        if (!in_array($outcome, ['delivered', 'bounced', 'complained', 'provider_notice'], true)
            || !preg_match('/^[a-z][a-z0-9_-]{0,59}$/D', $provider)
            || $providerMessageId === '' || strlen($providerMessageId) > 255
            || $providerEventId === '' || strlen($providerEventId) > 255
            || $occurredAt->greaterThan(CarbonImmutable::now()->addMinutes($futureMinutes))) {
            throw ValidationException::withMessages(['mail' => 'invalid_provider_event']);
        }
        $key = MutationKey::hash(['provider' => $provider, 'event_id' => $providerEventId]);
        $intent = MutationKey::hash(['provider' => $provider, 'message_id' => $providerMessageId,
            'outcome' => $outcome, 'at' => $occurredAt->toIso8601String(),
            ...($evidence !== [] ? ['evidence' => $evidence] : [])]);

        MailGuard::transaction(function () use ($provider, $providerMessageId, $outcome, $occurredAt, $key, $intent, $evidence) {
            $state = OutboundMessageState::where('provider_message_sha256', hash('sha256', $provider."\0".$providerMessageId))
                ->lockForUpdate()->firstOrFail();
            if ($old = OutboundMessageEvent::where('event_key', $key)->lockForUpdate()->first()) {
                RecordWriteRejected::unless($old->outbound_message_id === $state->outbound_message_id
                    && ($old->metadata['intent_sha256'] ?? null) === $intent, 409, 'mail_provider_event_conflict');
                return;
            }

            // Observations are evidence only: they cannot suppress, unsuppress or mark delivery.
            if ($outcome !== 'provider_notice') {
                $next = match ($outcome) {
                    'complained' => 'complained',
                    'bounced' => $state->status === 'complained' ? 'complained' : 'bounced',
                    'delivered' => in_array($state->status, ['bounced', 'complained'], true) ? $state->status : 'delivered',
                };
                $state->fill(['status' => $next, 'version' => $state->version + 1,
                    ...($outcome === 'delivered' && $state->delivered_at === null ? ['delivered_at' => $occurredAt] : [])])->save();
            }
            OutboundMessageEvent::create(['outbound_message_id' => $state->outbound_message_id,
                'event_key' => $key, 'kind' => $outcome,
                'metadata' => ['intent_sha256' => $intent, ...$evidence], 'occurred_at' => $occurredAt]);
        });
    }
}
