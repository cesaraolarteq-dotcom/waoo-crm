<?php
namespace App\Services\Booking;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, QuickBookingRecord, User};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, MutationKey, RecordZellePayment, Sandbox};
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\{RecordActor, RecordInput, SaveCustomer, SaveEventDraft};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

final class QuickBook
{
    public function execute(User $actor, array $input, string $key): QuickBooking
    {
        Sandbox::require(); Sandbox::require('sandbox_documents');
        \App\Exceptions\RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'No se pudo iniciar la reserva. Recarga e inténtalo de nuevo.');
        $actor = RecordActor::authorize($actor);
        $data = RecordInput::validate($input, [
            'lead_id' => 'required_with:lead_expected_version|integer|exists:leads,id',
            'lead_expected_version' => 'required_with:lead_id|integer|min:1',
            'customer_id' => 'required_without:new_customer|prohibits:new_customer|integer|exists:customers,id',
            'new_customer' => 'required_without:customer_id|prohibits:customer_id|array:name,phone,email,locale',
            'new_customer.name' => 'required_with:new_customer|string|max:255',
            'new_customer.phone' => 'nullable|string|max:40', 'new_customer.email' => 'nullable|email|max:255',
            'new_customer.locale' => ['required_with:new_customer', Rule::in(['es','en'])],
            'title' => 'required|string|max:255', 'event_type' => ['required', Rule::in(['birthday','baby_shower','gender_reveal','corporate','other'])],
            'starts_at' => ['required','date', new ExplicitOffset], 'ends_at' => ['required','date','after:starts_at', new ExplicitOffset],
            'timezone' => ['required', Rule::in(['America/Chicago'])], 'postal_code' => ['required','string','regex:/^[0-9]{5}(?:-[0-9]{4})?$/'],
            'address' => 'nullable|string|max:255', 'setup_minutes' => 'nullable|integer|min:0|max:720', 'breakdown_minutes' => 'nullable|integer|min:0|max:720',
            'travel_fee_cents' => 'required|integer|min:0|max:100000000', 'services' => 'required|array|min:1|max:10',
            'deposit' => 'required|array:method,amount_cents,reference,received_at,verified_received,note',
            'deposit.method' => ['required', Rule::in(['zelle'])], 'deposit.amount_cents' => 'required|integer|min:1|max:100000000',
            'deposit.reference' => 'required|string|min:3|max:255', 'deposit.received_at' => ['required','date','before_or_equal:now', new ExplicitOffset],
            'deposit.verified_received' => 'required|accepted', 'deposit.note' => 'nullable|string|max:2000',
            'portal' => 'sometimes|array:expires_at',
            'portal.expires_at' => ['required_with:portal','date', new ExplicitOffset],
        ]);
        if (isset($data['lead_id'])) \App\Services\Leads\LeadAccess::owner($actor);
        $scope = "owner:{$actor->id}:quick-book"; $hash = MutationKey::hash($data);
        [$record, $created] = DB::transaction(function () use ($actor, $data, $scope, $hash, $key) {
            // Serialize this owner's first request and identical concurrent retries before any new customer/event.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            RecordActor::authorize($actor);
            if ($saved = MutationKey::find($scope, $key, $hash)) return [QuickBookingRecord::findOrFail($saved->result_id), false];
            $lead = null;
            if (isset($data['lead_id'])) {
                $lead = \App\Models\Lead::whereKey($data['lead_id'])->lockForUpdate()->firstOrFail();
                \App\Exceptions\RecordWriteRejected::unless($lead->version === (int) $data['lead_expected_version']
                    && $lead->booked_event_id === null && in_array($lead->status, ['new','contacted','quoted'], true), 409, 'El prospecto cambió o ya está reservado.');
            }
            if (isset($data['portal']['expires_at'])) RecordInput::validate($data, ['portal.expires_at' => ['date','after:now','before_or_equal:'.now()->addDays(30)->toIso8601String()]]);
            $customer = isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : app(SaveCustomer::class)->execute($actor, $data['new_customer']);
            $eventInput = array_diff_key($data, array_flip(['new_customer','deposit','portal','lead_id','lead_expected_version']));
            $event = app(SaveEventDraft::class)->execute($actor, [...$eventInput, 'customer_id' => $customer->id]);
            $finance = app(CreateFinancialRevision::class)->execute($actor, $event->id, ['expected_version' => $event->version], (string) Str::uuid());
            try {
                $receipt = app(RecordZellePayment::class)->execute($actor, $event->id, [
                    ...array_diff_key($data['deposit'], ['method' => true]), 'financial_revision_id' => $finance->id, 'currency' => 'USD',
                ], (string) Str::uuid());
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ['deposit.'.$field => $messages])->all());
            }
            $record = QuickBookingRecord::create(['event_id' => $event->id, 'customer_id' => $customer->id,
                'initial_financial_revision_id' => $finance->id, 'receipt_id' => $receipt->id,
                'portal_expires_at' => isset($data['portal']['expires_at']) ? CarbonImmutable::parse($data['portal']['expires_at'])->utc() : now()->addDays(14), 'created_by' => $actor->id]);
            if ($lead) {
                $before = $lead->only(['status','version']);
                $lead->forceFill(['status' => 'booked', 'booked_event_id' => $event->id, 'quick_booking_id' => $record->id, 'version' => $lead->version + 1])->save();
                Audit::recordFor($actor, 'lead.booked', $lead, $before, $lead->only(['status','version','booked_event_id','quick_booking_id']));
            }
            Audit::recordFor($actor, 'quick_booking.created', $record, [], ['event_id' => $event->id, 'receipt_id' => $receipt->id]);
            MutationKey::store($scope, $key, $hash, 'quick_booking', $record->id);
            return [$record, true];
        }, 3);

        $url = null;
        if ($created) {
            try {
                $grant = app(IssuePortalGrant::class)->execute($actor, $record->event_id, [
                    'customer_id' => $record->customer_id, 'expires_at' => $record->portal_expires_at->toIso8601String(),
                    'scopes' => ['details.submit','contract.read','contract.sign','payments.read','checkout.create'],
                ]);
                $url = $grant->url;
            } catch (\Throwable $e) {
                // The receipt and the idempotent core result already committed. Do not claim the booking was rolled back.
                DB::table('payment_exceptions')->insertOrIgnore(['event_id' => $record->event_id, 'financial_revision_id' => $record->initial_financial_revision_id,
                    'kind' => 'portal_link_pending', 'exception_key' => hash('sha256', 'quick:'.$record->id.':link'), 'state' => 'open',
                    'context' => json_encode(['quick_booking_id' => $record->id, 'reason' => 'grant_issue_failed'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $record->refresh();
        $grantRow = $record->portal_grant_id ? \App\Models\PortalGrant::find($record->portal_grant_id) : null;
        $event = Event::findOrFail($record->event_id);
        return new QuickBooking($record->id, $event, Customer::findOrFail($record->customer_id), FinancialRevision::findOrFail($record->initial_financial_revision_id),
            LedgerEntry::findOrFail($record->receipt_id), app(FinanceSummary::class)->forEvent($event), $url, $record->portal_expires_at->toIso8601String(),
            !$created && $grantRow !== null, !$grantRow || $grantRow->revoked_at !== null || !$grantRow->expires_at->isFuture());
    }
}
