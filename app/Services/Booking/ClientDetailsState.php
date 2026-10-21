<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsReview, ClientDetailsSubmission, Event, QuickBookingRecord};
use Illuminate\Support\Facades\{DB, Schema};

final class ClientDetailsState
{
    public static function assertApproved(int $eventId, int $financialRevisionId): void
    {
        if (!Schema::hasTable('client_details_submissions')) return;
        $latest = ClientDetailsSubmission::where('event_id', $eventId)->orderByDesc('revision')->first();
        if (!$latest && !QuickBookingRecord::where('event_id', $eventId)->exists()) return;
        $review = $latest ? ClientDetailsReview::where('submission_id', $latest->id)->first() : null;
        RecordWriteRejected::unless($review && $review->financial_revision_id === $financialRevisionId, 409, 'Primero revisa y aprueba los datos vigentes del cliente.');
    }

    public static function portal(Event $event): array
    {
        $latest = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
        return ['submission' => $latest?->only(['id','revision','data','submitted_at']),
            'reviewed' => $latest && in_array(self::forEvent($event), ['ready_to_issue','waiting_signature','signed'], true),
            'expected_revision' => $latest?->revision ?? 0, 'expected_event_version' => $event->version];
    }

    public static function forEvent(Event $event): string
    {
        if (\App\Models\Contract::where('event_id', $event->id)->where('state', 'signed')->exists()) return 'signed';
        $latest = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
        if (!$latest) return 'waiting_details';
        $review = ClientDetailsReview::where('submission_id', $latest->id)->first();
        $currentFinance = \App\Models\FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->first();
        $capacityLinked = $event->status === 'capacity_reserved' && $review && DB::table('capacity_payment_links')->where('event_id', $event->id)->where('financial_revision_id', $review->financial_revision_id)->exists();
        if (!$review || !$currentFinance || $review->financial_revision_id !== $currentFinance->id || ($review->event_version !== $event->version && !$capacityLinked)) return 'review_details';
        return \App\Models\Contract::where('event_id', $event->id)->where('financial_revision_id', $review->financial_revision_id)->where('state', 'issued')->exists() ? 'waiting_signature' : 'ready_to_issue';
    }
}
