<?php
namespace App\Services\Mail;

use App\Models\{Contract, Event, PortalGrant, QuickBookingRecord, User};
use App\Services\Booking\ClientDetailsState;

final class CaptureEventLinkMail
{
    // Called only inside an existing source transaction; no new link, lock inversion or send.
    public static function forGrant(Event $event, PortalGrant $grant, User $actor): void
    {
        if (!CaptureMailOccurrence::enabled() || $grant->quote_revision_id !== null || $grant->revoked_at || !$grant->expires_at->isFuture()) return;
        if (in_array('details.submit', $grant->scopes, true) && ClientDetailsState::forEvent($event) === 'waiting_details') {
            $quick = QuickBookingRecord::where('event_id', $event->id)->first();
            if ($quick) app(CaptureMailOccurrence::class)->record('details_request', $quick->id, [
                'event_id' => $event->id, 'customer_id' => $event->customer_id, 'quick_booking_id' => $quick->id,
                'financial_revision_id' => $quick->initial_financial_revision_id, 'portal_grant_id' => $grant->id], $actor);
        }
        if (in_array('contract.read', $grant->scopes, true) && in_array('contract.sign', $grant->scopes, true)) {
            $contract = Contract::where('event_id', $event->id)->where('state', 'issued')->orderByDesc('revision')->first();
            if ($contract) self::forContract($contract, $grant, $actor);
        }
    }
    public static function forContract(Contract $contract, ?PortalGrant $grant, User $actor): void
    {
        if (!CaptureMailOccurrence::enabled()) return;
        if (!$grant) {
            $grant = PortalGrant::where('event_id', $contract->event_id)->where('customer_id', $contract->signer_customer_id)
                ->whereNull('quote_revision_id')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->orderByDesc('id')->get()->first(fn ($g) => in_array('contract.read', $g->scopes, true) && in_array('contract.sign', $g->scopes, true));
        }
        if (!$grant) return;
        app(CaptureMailOccurrence::class)->record('contract_ready', $contract->id, ['event_id' => $contract->event_id,
            'customer_id' => $contract->signer_customer_id, 'contract_id' => $contract->id,
            'financial_revision_id' => $contract->financial_revision_id, 'portal_grant_id' => $grant->id], $actor);
    }
}
