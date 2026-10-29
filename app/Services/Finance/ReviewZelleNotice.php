<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, User, ZelleNotice, ZelleNoticeReview};
use App\Services\Audit;
use App\Services\Records\{RecordActor, RecordInput};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ReviewZelleNotice
{
    public function execute(User $actor, int $eventId, int $noticeId, array $input, string $key): ZelleNoticeReview
    {
        Sandbox::require(); Sandbox::require('sandbox_documents'); $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, ['decision' => ['required', Rule::in(['verified','rejected'])], 'review_note' => 'required|string|min:10|max:2000']);
        // Normalize/validate actual bank evidence even on replay; never fall back to the client's claim.
        if ($data['decision'] === 'verified') {
            $payment = RecordInput::validate($input, ['financial_revision_id' => 'required|integer|min:1', 'amount_cents' => 'required|integer|min:1|max:100000000',
                'currency' => ['required', Rule::in(['USD'])], 'reference' => 'nullable|string|min:3|max:255',
                'received_at' => ['required','date','before_or_equal:now',new \App\Rules\ExplicitOffset], 'verified_received' => 'required|accepted', 'note' => 'nullable|string|max:2000']);
            $payment['financial_revision_id'] = (int) $payment['financial_revision_id']; $payment['amount_cents'] = (int) $payment['amount_cents'];
            $payment['received_at'] = \Carbon\CarbonImmutable::parse($payment['received_at'])->utc()->startOfSecond()->toIso8601String();
            $payment['reference'] ??= null;
            $payment['verified_received'] = true; $payment['note'] ??= null;
            $data['payment'] = $payment;
        }
        $scope = "owner:{$actor->id}:event:{$eventId}:zelle-notice:{$noticeId}"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $eventId, $noticeId, $data, $scope, $hash, $key) {
            \App\Services\Scheduling\DailyCapacity::lock();
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $notice = ZelleNotice::whereKey($noticeId)->where('event_id', $eventId)->where('customer_id', $event->customer_id)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return ZelleNoticeReview::findOrFail($saved->result_id);
            RecordWriteRejected::when($notice->review()->exists(), 409, 'Este aviso ya fue revisado. Actualiza el historial.');
            $entry = $data['decision'] === 'verified' ? app(RecordZellePayment::class)->execute($actor, $eventId, $data['payment'], $key) : null;
            $quick=\App\Services\Booking\QuickPaymentProtocol::booking($eventId);
            if($quick && $data['decision']==='rejected' && $quick->receipt_id===null) {\App\Services\Booking\QuickPaymentProtocol::gate();\App\Services\Booking\QuickPaymentProtocol::verify($quick,$event);\App\Services\Booking\QuickPaymentProtocol::transition($quick,'awaiting_payment','owner_rejected_zelle_claim',$actor);}
            $review = ZelleNoticeReview::create(['notice_id' => $notice->id, 'event_id' => $eventId, 'ledger_entry_id' => $entry?->id,
                'decision' => $data['decision'], 'review_note' => $data['review_note'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            Audit::recordFor($actor, 'payment.zelle_notice_reviewed', $review, [], $review->getAttributes());
            MutationKey::store($scope, $key, $hash, 'zelle_notice_review', $review->id);
            return $review;
        }, 3);
    }
}
