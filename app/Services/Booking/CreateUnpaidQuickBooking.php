<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, FinancialRevision, PortalGrant, QuickPaymentBooking, QuickStripeLease, User};
use App\Services\Audit;
use App\Services\Finance\{CreateFinancialRevision, FinanceSummary, MutationKey, Sandbox};
use App\Services\Portal\{PortalTokenVault, ShortPortalLinks};
use App\Services\Records\{RecordActor, RecordInput, SaveEventDraft};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateUnpaidQuickBooking
{
    public function execute(User $actor, array $input, string $key): UnpaidQuickBooking
    {
        QuickPaymentProtocol::gate(); $actor = RecordActor::authorize($actor);
        RecordWriteRejected::unless(DB::transactionLevel() === 0, 409, 'No se pudo iniciar la reserva.');
        $input = \App\Services\Billing\BillingCompany::input($input);
        $data = QuickStripeLeases::input($input, [...\App\Services\Billing\BillingCompany::rules(),
            'deposit_received'=>'required|declined', 'deposit'=>'prohibited',
            'customer_id'=>'required_without:new_customer|prohibits:new_customer|integer|exists:customers,id',
            'new_customer'=>'required_without:customer_id|prohibits:customer_id|array:name,phone,email,locale',
            'customer_update'=>'sometimes|array:expected_version,name,email,phone,locale',
            'title'=>'required|string|max:255','event_type'=>'required|in:birthday,baby_shower,gender_reveal,corporate,other',
            'starts_at'=>['required','date',new \App\Rules\ExplicitOffset], 'ends_at'=>['required','date','after:starts_at',new \App\Rules\ExplicitOffset],
            'timezone'=>'required|in:America/Chicago','postal_code'=>'required|string|regex:/^[0-9]{5}(?:-[0-9]{4})?$/',
            'address'=>'nullable|string|max:255','setup_minutes'=>'nullable|integer|min:0|max:720','breakdown_minutes'=>'nullable|integer|min:0|max:720',
            'travel_fee_cents'=>'required|integer|min:0|max:100000000','travel_fee_quote_id'=>'nullable|integer|min:1',
            'travel_fee_override_note'=>'nullable|string|min:10|max:1000','services'=>'required|array|min:1|max:10',
            'portal'=>'sometimes|array:expires_at','lead_id'=>'sometimes|integer|min:1','lead_expected_version'=>'required_with:lead_id|integer|min:1',
            'promo_code'=>'sometimes|nullable|string|max:20',
        ]);
        if (isset($data['customer_update'])) RecordWriteRejected::unless(isset($data['customer_id']),422,'Selecciona el cliente antes de actualizarlo.');
        $scope = "owner:{$actor->id}:quick-book"; $hash = MutationKey::hash($data);
        return DB::transaction(function()use($actor,$data,$scope,$hash,$key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); RecordActor::authorize($actor);
            // The owner row already serializes this owner-scoped key, before the first snapshot read.
            // Locking a missing receipt also locks an index gap shared by unrelated owners and can deadlock nested writes.
            if ($r=MutationKey::find($scope,$key,$hash)) {
                RecordWriteRejected::unless($r->result_type==='quick_payment_booking',409,'Esta clave corresponde a otra reserva.');
                return $this->result(QuickPaymentBooking::whereKey($r->result_id)->lockForUpdate()->firstOrFail(),null,true);
            }
            $lead=null;
            if(isset($data['lead_id'])) {
                $lead=\App\Models\Lead::whereKey($data['lead_id'])->lockForUpdate()->firstOrFail();
                RecordWriteRejected::unless($lead->version===(int)$data['lead_expected_version'] && $lead->booked_event_id===null
                    && in_array($lead->status,['new','contacted','quoted'],true),409,'El prospecto cambió o ya está reservado.');
            }
            $customer = isset($data['customer_id']) ? Customer::whereKey($data['customer_id'])->lockForUpdate()->firstOrFail()
                : app(SaveQuickBookingCustomer::class)->execute($actor,$data['new_customer']);
            if(isset($data['customer_update'])) $customer=app(SaveQuickBookingCustomer::class)->execute($actor,$data['customer_update'],$customer->id);
            $eventInput=array_diff_key($data,array_flip(['deposit_received','deposit','new_customer','customer_update','portal','lead_id','lead_expected_version','promo_code']));
            $e=app(SaveEventDraft::class)->execute($actor,[...$eventInput,'customer_id'=>$customer->id]);
            if(filled($data['promo_code']??null)) \App\Services\Campaigns\AnniversaryCampaign::redeem($actor,$data['promo_code'],$e);
            $f=app(CreateFinancialRevision::class)->execute($actor,$e->id,['expected_version'=>$e->version],(string)Str::uuid());
            $view=app(PrepareQuickStripeLease::class)->execute($actor,$e->id,['expected_event_version'=>$e->version,'financial_revision_id'=>$f->id],(string)Str::uuid());
            $lease=QuickStripeLease::whereKey($view['lease_id'])->lockForUpdate()->firstOrFail();
            $b=QuickPaymentBooking::create(['event_id'=>$e->id,'customer_id'=>$customer->id,'financial_revision_id'=>$f->id,'quick_stripe_lease_id'=>$lease->id,
                'created_by'=>$actor->id,'version'=>0,'state'=>'awaiting_payment','expires_at'=>$lease->expires_at,
                'reminder_at'=>CarbonImmutable::createFromTimestampUTC($view['terms']['reminder_at'])]);
            if(isset($data['portal'])) RecordInput::validate($data['portal'],['expires_at'=>['required','date',new \App\Rules\ExplicitOffset]]);
            $expiry=isset($data['portal']['expires_at']) ? CarbonImmutable::parse($data['portal']['expires_at'])->utc() : CarbonImmutable::now()->addDays(14);
            RecordInput::validate(['expiry'=>$expiry->toIso8601String()],['expiry'=>'required|date|after:now|before_or_equal:'.now()->addDays(30)->toIso8601String()]);
            $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
            $g=PortalGrant::create(['event_id'=>$e->id,'customer_id'=>$customer->id,'token_hash'=>hash('sha256',$token),
                'scopes'=>['details.submit','contract.read','contract.sign','payments.read','quick_booking.pay'],'expires_at'=>$expiry,'issued_by'=>$actor->id]);
            $g->forceFill(['encrypted_token'=>PortalTokenVault::seal($g,$token)])->save();
            $agreement=['schema_version'=>1,'booking_id'=>$b->id,'event_id'=>$e->id,'customer_id'=>$customer->id,
                'financial_revision_id'=>$f->id,'lease_id'=>$lease->id,'grant_id'=>$g->id,'initial_lease_sha256'=>$lease->payload_sha256];
            $b->fill(['portal_grant_id'=>$g->id,'agreement'=>$agreement,'agreement_sha256'=>MutationKey::hash($agreement)])->save();
            QuickPaymentProtocol::transition($b,'awaiting_payment','owner_created_unpaid',$actor);
            Audit::recordFor($actor,'portal.grant_issued',$g,[],['event_id'=>$e->id,'scopes'=>$g->scopes]);
            if($lead) { $lead->forceFill(['status'=>'booked','booked_event_id'=>$e->id,'version'=>$lead->version+1])->save(); Audit::recordFor($actor,'lead.booked',$lead,[],['event_id'=>$e->id]); }
            MutationKey::store($scope,$key,$hash,'quick_payment_booking',$b->id);
            $url=app(ShortPortalLinks::class)->forLockedGrant($g) ?? Sandbox::origin().'/portal#token='.$token;
            return $this->result($b,$url,false);
        },3);
    }

    private function result(QuickPaymentBooking $b, ?string $url, bool $replay): UnpaidQuickBooking
    {
        $e=Event::findOrFail($b->event_id); QuickPaymentProtocol::verify($b,$e);
        $hold=['state'=>$b->state,'terms'=>['expires_at'=>$b->expires_at->timestamp],'rows'=>[], 'issues'=>[]];
        if(in_array($b->state,['awaiting_payment','zelle_pending','processing','review_required'],true)) {
            $hold=QuickStripeLeases::view(QuickStripeLease::findOrFail($b->quick_stripe_lease_id),$e);
        }
        return new UnpaidQuickBooking($b->id,$e,Customer::findOrFail($b->customer_id),FinancialRevision::findOrFail($b->financial_revision_id),
            app(FinanceSummary::class)->forEvent($e),$url,PortalGrant::findOrFail($b->portal_grant_id)->expires_at->toIso8601String(),$hold,$replay,false,$b->state);
    }
}
