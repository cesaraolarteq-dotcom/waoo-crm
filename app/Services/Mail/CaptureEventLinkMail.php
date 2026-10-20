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
    }
    public static function forContract(Contract $contract, ?PortalGrant $grant, User $actor): void
    {
        // Owner retired this email on2026-10-20. Historical occurrences are retained; no new capture.
    }
}
