<?php
namespace App\Services\Records;

use App\Models\{Event, User};
use App\Rules\ExplicitOffset;
use App\Services\ConfirmBooking;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class ConfirmCapacity
{
    public static function rules(): array
    {
        return ['expected_version' => 'required|integer|min:1', 'resource_ids' => 'required|array|min:1|max:100', 'resource_ids.*' => 'required|integer|distinct', 'payment' => 'required|array:method,reference,amount_cents,received_at', 'payment.method' => ['required', Rule::in(['external_zelle', 'external_card', 'external_cash'])], 'payment.reference' => 'required|string|min:3|max:120', 'payment.amount_cents' => 'required|integer|min:1|max:100000000', 'payment.received_at' => ['required', 'date', 'before_or_equal:now', new ExplicitOffset()], 'review' => 'required|array:staff_confirmed,cargo_checked,origin_return_note,short_notice_reason,deposit_exception_reason,transfers', 'review.staff_confirmed' => 'required|accepted', 'review.cargo_checked' => 'required|accepted', 'review.origin_return_note' => 'required|string|min:10|max:2000', 'review.short_notice_reason' => 'nullable|string|min:10|max:2000', 'review.deposit_exception_reason' => 'nullable|string|min:10|max:2000', 'review.transfers' => 'present|array|max:200', 'review.transfers.*' => 'array:from_event_id,to_event_id,from_version,to_version,travel_minutes,contingency_minutes,departure_at,note', 'review.transfers.*.from_event_id' => 'required|integer', 'review.transfers.*.to_event_id' => 'required|integer', 'review.transfers.*.from_version' => 'required|integer|min:1', 'review.transfers.*.to_version' => 'required|integer|min:1', 'review.transfers.*.travel_minutes' => 'required|integer|min:0|max:1440', 'review.transfers.*.contingency_minutes' => 'required|integer|min:1|max:720', 'review.transfers.*.departure_at' => ['required', 'date', new ExplicitOffset()], 'review.transfers.*.note' => 'required|string|min:10|max:2000'];
    }

    public function execute(User $actor, int $eventId, array $input): Event
    {
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, self::rules());
        $data['payment']['received_at'] = CarbonImmutable::parse($data['payment']['received_at'])->utc();
        return app(ConfirmBooking::class)->execute($eventId, $data, $actor)->load('reservations');
    }
}
