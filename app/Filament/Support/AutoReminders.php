<?php
namespace App\Filament\Support;

use App\Models\{Event, PortalGrant, User};
use App\Services\Mail\PlanEventMail;
use Illuminate\Support\Str;

/**
 * Owner 2026-10-29: when a party is confirmed, its reminders (7 days before, balance, thank you + review) are planned at once
 * instead of waiting for "Programar recordatorios". Planned is not sent: nothing leaves until the owner turns mail on.
 * Uses the client's newest active link that lasts past the party, preferring one that lets her pay.
 */
final class AutoReminders
{
    /** @return string|null a short note for the owner when nothing could be planned */
    public static function afterConfirm(User $actor, int $eventId): ?string
    {
        if (! MailUi::enabled() || \App\Models\ImportedProjectLink::where('event_id', $eventId)->exists()) return null;
        $event = Event::with('customer')->find($eventId);
        if (! $event || $event->status !== 'capacity_reserved' || ! $event->customer) return null;
        $grant = PortalGrant::query()->where('event_id', $event->id)->where('customer_id', $event->customer_id)->whereNull('quote_revision_id')
            ->whereNull('revoked_at')->where('expires_at', '>', $event->ends_at)->orderByDesc('expires_at')->get()
            ->filter(fn ($g) => in_array('payments.read', $g->scopes ?? [], true))
            ->sortByDesc(fn ($g) => in_array('checkout.create', $g->scopes ?? [], true))->first();
        if (! $grant) return 'Los recordatorios no se programaron: el enlace del cliente vence antes de la fiesta. Prográmalos desde la fiesta con un enlace nuevo.';
        try {
            app(PlanEventMail::class)->execute($actor, $event->id, ['expected_event_version' => $event->version,
                'expected_customer_version' => $event->customer->version, 'portal_grant_id' => $grant->id], (string) Str::uuid());
        } catch (\Throwable $e) {
            report($e);
            return 'Los recordatorios no se programaron solos. Prográmalos desde la fiesta (Más → Programar recordatorios).';
        }
        return null;
    }
}
