<?php
namespace App\Services\Booking;

use App\Models\{Customer, Event, FinancialRevision, LedgerEntry, QuickBookingRecord, User};
use App\Rules\ExplicitOffset;
use App\Services\Audit;
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, MutationKey, RecordZellePayment, Sandbox};
use App\Services\Portal\IssuePortalGrant;
use App\Services\Records\{RecordActor, RecordInput, SaveEventDraft};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

final class QuickBook
{
    public function execute(User $actor, array $input, string $key): QuickBooking|UnpaidQuickBooking
    {
        if (array_key_exists('deposit_received', $input)) {
            RecordInput::validate($input, ['deposit_received'=>'required|boolean']);
            if (!(bool)$input['deposit_received']) return app(CreateUnpaidQuickBooking::class)->execute($actor,$input,$key);
            unset($input['deposit_received']);
        }
        Sandbox::require(); Sandbox::require('sandbox_documents');
        \App\Exceptions\RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'No se pudo iniciar la reserva. Recarga e inténtalo de nuevo.');
        $actor = RecordActor::authorize($actor);
        $input = \App\Services\Billing\BillingCompany::input($input);
        $data = RecordInput::validate($input, [...\App\Services\Billing\BillingCompany::rules(),
            'lead_id' => 'required_with:lead_expected_version|integer|exists:leads,id',
            'lead_expected_version' => 'required_with:lead_id|integer|min:1',
            'customer_id' => 'required_without:new_customer|required_with:customer_update|prohibits:new_customer|integer|exists:customers,id',
            'customer_update' => 'sometimes|required|prohibits:new_customer|array:expected_version,name,email,phone,locale',
            'customer_update.expected_version' => 'required_with:customer_update|integer|min:1',
            'customer_update.name' => 'present_with:customer_update|nullable|string|max:255',
            'customer_update.email' => 'present_with:customer_update|nullable|email|max:255',
            'customer_update.phone' => 'present_with:customer_update|nullable|string|max:40',
            'customer_update.locale' => ['required_with:customer_update', Rule::in(['es','en'])],
            'new_customer' => 'required_without:customer_id|prohibits:customer_id|array:name,phone,email,locale',
            'new_customer.name' => 'nullable|string|max:255',
            'new_customer.phone' => 'nullable|string|max:40', 'new_customer.email' => 'nullable|email|max:255',
            'new_customer.locale' => ['required_with:new_customer', Rule::in(['es','en'])],
            'title' => 'required|string|max:255', 'event_type' => ['required', Rule::in(['birthday','baby_shower','gender_reveal','corporate','other'])],
            'starts_at' => ['required','date', new ExplicitOffset], 'ends_at' => ['required','date','after:starts_at', new ExplicitOffset],
            'timezone' => ['required', Rule::in(['America/Chicago'])], 'postal_code' => ['required','string','regex:/^[0-9]{5}(?:-[0-9]{4})?$/'],
            'address' => 'nullable|string|max:255', 'setup_minutes' => 'nullable|integer|min:0|max:720', 'breakdown_minutes' => 'nullable|integer|min:0|max:720',
            'travel_fee_quote_id' => 'sometimes|nullable|integer|min:1', 'travel_fee_override_note' => 'sometimes|nullable|string|min:10|max:1000',
            'travel_fee_cents' => 'required|integer|min:0|max:100000000', 'services' => 'required|array|min:1|max:10',
            'deposit' => 'required|array:method,amount_cents,reference,received_at,received_on,verified_received,note',
            'deposit.method' => ['required', Rule::in(['zelle'])], 'deposit.amount_cents' => 'required|integer|min:1|max:100000000',
            'deposit.reference' => 'nullable|string|min:3|max:255',
            'deposit.received_at' => ['required_without:deposit.received_on','prohibits:deposit.received_on','date','before_or_equal:now', new ExplicitOffset],
            'deposit.received_on' => ['required_without:deposit.received_at','prohibits:deposit.received_at','date_format:Y-m-d','before_or_equal:'.now('America/Chicago')->format('Y-m-d')],
            'deposit.verified_received' => 'required|accepted', 'deposit.note' => 'nullable|string|max:2000',
            'portal' => 'sometimes|array:expires_at',
            'portal.expires_at' => ['required_with:portal','date', new ExplicitOffset],
            'promo_code' => 'sometimes|nullable|string|max:20',
        ]);
        $data['deposit']['reference'] = $data['deposit']['reference'] ?? null;
        if (isset($data['lead_id'])) \App\Services\Leads\LeadAccess::owner($actor);
        $scope = "owner:{$actor->id}:quick-book"; $hash = MutationKey::hash($data);
        [$record, $created] = DB::transaction(function () use ($actor, $data, $scope, $hash, $key) {
            \App\Services\Scheduling\DailyCapacity::lock();
            // Serialize this owner's first request and identical concurrent retries before any new customer/event.
            if (blank($data['deposit']['reference'] ?? null)) User::where('role', 'owner_admin')->orderBy('id')->lockForUpdate()->get();
            else User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            RecordActor::authorize($actor);
            if ($saved = MutationKey::find($scope, $key, $hash)) {
                \App\Exceptions\RecordWriteRejected::unless($saved->result_type==='quick_booking',409,'Esta clave corresponde a otro tipo de reserva.');
                return [QuickBookingRecord::findOrFail($saved->result_id), false];
            }
            $lead = null;
            if (isset($data['lead_id'])) {
                $lead = \App\Models\Lead::whereKey($data['lead_id'])->lockForUpdate()->firstOrFail();
                \App\Exceptions\RecordWriteRejected::unless($lead->version === (int) $data['lead_expected_version']
                    && $lead->booked_event_id === null && in_array($lead->status, ['new','contacted','quoted'], true), 409, 'El prospecto cambió o ya está reservado.');
            }
            if (isset($data['portal']['expires_at'])) RecordInput::validate($data, ['portal.expires_at' => ['date','after:now','before_or_equal:'.now()->addDays(30)->toIso8601String()]]);
            $customer = $this->customer($actor, $data);
            if (blank($data['deposit']['reference'] ?? null)) app(GuardUnreferencedQuickBooking::class)->check($customer, $data);
            $eventInput = array_diff_key($data, array_flip(['new_customer','customer_update','deposit','portal','lead_id','lead_expected_version','promo_code']));
            $event = app(SaveEventDraft::class)->execute($actor, [...$eventInput, 'customer_id' => $customer->id]);
            \App\Services\Scheduling\DailyCapacity::assertRoom($event);
