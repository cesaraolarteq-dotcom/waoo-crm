<?php
namespace App\Services\Booking;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsSubmission, Event, FinancialRevision, PortalGrant, QuickPaymentBooking, QuickPaymentEvent, QuickStripeLease, QuickStripeLeaseEvent, User, ZelleNotice};
use App\Services\Audit;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use Illuminate\Support\Facades\DB;

final class QuickPaymentProtocol
{
    public static function gate(bool $worker = false): void
    {
        foreach (['sandbox_finance','sandbox_documents','sandbox_quick_stripe','sandbox_quick_payments'] as $flag) Sandbox::require($flag);
        if (!$worker || !app()->runningInConsole()) PortalContext::requireTransport(request());
    }

    public static function enabled(): bool
    {
        return app()->environment(['local','testing','staging']) && config('waoo.sandbox_quick_payments') === true;
    }

    public static function booking(int $eventId, bool $lock = true): ?QuickPaymentBooking
    {
        $q = QuickPaymentBooking::where('event_id', $eventId);
        return ($lock ? $q->lockForUpdate() : $q)->first();
    }

    public static function portal(PortalContext $client): array
    {
        self::gate();
        if ($client->adminAuthenticated) throw new \Illuminate\Auth\Access\AuthorizationException('Abre el enlace como cliente. / Open the customer link separately.');
        $grant = $client->authorize($client->eventId, 'quick_booking.pay', true);
        $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
        \App\Services\Responsibility\RequireCrmResponsibility::check($event);
        $b = self::booking($event->id) ?? abort(404);
        RecordWriteRejected::unless($b->portal_grant_id === $grant->id && $b->customer_id === $client->customerId, 403, 'Este enlace no corresponde a la reserva.');
        self::verify($b, $event);
        return [$b, $event, $grant];
    }

    public static function verify(QuickPaymentBooking $b, Event $event, bool $current = true, bool $historical = false): array
    {
        try {
            $a = $b->agreement;
            $keys = ['schema_version','booking_id','event_id','customer_id','financial_revision_id','lease_id','grant_id','initial_lease_sha256'];
            $h = QuickStripeLeaseEvent::where('quick_stripe_lease_id', $b->quick_stripe_lease_id)->where('version', 1)->sharedLock()->firstOrFail();
            $s = $h->payload;
            $f = FinancialRevision::whereKey($b->financial_revision_id)->sharedLock()->firstOrFail();
            $head = QuickPaymentEvent::where('quick_payment_booking_id', $b->id)->where('version', $historical ? 1 : $b->version)->sharedLock()->firstOrFail();
            RecordWriteRejected::unless(is_array($a) && count($a) === count($keys) && array_diff($keys, array_keys($a)) === []
                && $a['schema_version'] === 1 && $a['booking_id'] === $b->id && $a['event_id'] === $event->id && $b->event_id === $event->id
                && $a['customer_id'] === $b->customer_id && $b->customer_id === $event->customer_id
                && $a['financial_revision_id'] === $f->id && $f->event_id === $event->id
                && $a['lease_id'] === $b->quick_stripe_lease_id && $a['grant_id'] === $b->portal_grant_id
                && hash_equals($b->agreement_sha256, MutationKey::hash($a))
                && $a['initial_lease_sha256'] === $h->payload_sha256 && $h->payload_sha256 === MutationKey::hash($s)
                && $s['state'] === 'active' && $s['event_id'] === $event->id && $s['customer_id'] === $b->customer_id
                && $s['financial_revision_id'] === $f->id && $s['financial_sha256'] === QuickStripeLeases::financialHash($f)
                && QuickStripeTerms::restore($s['terms'])->snapshot()['total_cents'] === $f->total_cents
                && $s['terms']['expires_at'] === $b->expires_at->timestamp && $s['terms']['reminder_at'] === $b->reminder_at->timestamp
                && ($historical || $head->sha256 === $b->head_sha256) && $head->sha256 === MutationKey::hash($head->payload)
                && $head->payload['booking_id'] === $b->id && $head->payload['state'] === ($historical ? 'awaiting_payment' : $b->state)
                && $head->payload['version'] === ($historical ? 1 : $b->version) && $head->payload['receipt_id'] === ($historical ? null : $b->receipt_id)
                && $head->payload['agreement_sha256'] === $b->agreement_sha256, 409, 'La evidencia de la reserva requiere revisión.');
            $audit=DB::table('audit_entries')->where('action','quick_payment.'.$head->payload['state'])->where('subject_type',$b->getTable())->where('subject_id',$b->id)->get()
                ->first(fn($r)=>($v=json_decode($r->after,true)) && ($v['version']??null)===$head->version && ($v['sha256']??null)===$head->sha256);
            RecordWriteRejected::unless($audit!==null,409,'El historial de la reserva requiere revisión.');
            if ($current) {
                $original = clone $event; $original->version = $s['event_version']; $original->status = 'draft';
                RecordWriteRejected::unless(QuickStripeLeases::eventHash($original) === $s['event_sha256']
                    && MutationKey::hash(QuickCostumeHolds::recipe($event)) === $s['recipe_sha256']
                    && (int) FinancialRevision::where('event_id', $event->id)->max('revision') === $f->revision,
                    409, 'El acuerdo cambió; revisa la reserva antes de continuar.');
            }
            return $s;
        } catch (RecordWriteRejected $e) { throw $e;
        } catch (\Illuminate\Database\QueryException $e) { throw $e;
        } catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia de la reserva requiere revisión.'); }
    }

    public static function transition(QuickPaymentBooking $b, string $state, string $reason, ?User $actor = null, array $changes = []): void
    {
        $p = ['schema_version'=>1,'booking_id'=>$b->id,'event_id'=>$b->event_id,'version'=>$b->version + 1,
            'state'=>$state,'receipt_id'=>$changes['receipt_id'] ?? $b->receipt_id,'agreement_sha256'=>$b->agreement_sha256,
            'reason'=>$reason,'previous_sha256'=>$b->head_sha256];
        $hash = MutationKey::hash($p);
        $b->fill([...$changes, 'state'=>$state,'version'=>$p['version'],'head_sha256'=>$hash])->save();
        QuickPaymentEvent::create(['quick_payment_booking_id'=>$b->id,'version'=>$b->version,'payload'=>$p,'sha256'=>$hash]);
        $meta = ['event_id'=>$b->event_id,'version'=>$b->version,'state'=>$state,'sha256'=>$hash];
        $actor ? Audit::recordFor($actor,'quick_payment.'.$state,$b,[],$meta) : Audit::recordSystem('quick_payment.'.$state,$b,[],$meta);
    }

    public static function pendingNotice(QuickPaymentBooking $b): ?ZelleNotice
    {
        return ZelleNotice::where('event_id',$b->event_id)->where('portal_grant_id',$b->portal_grant_id)->whereDoesntHave('review')->orderByDesc('id')->lockForUpdate()->first();
    }

    public static function submission(QuickPaymentBooking $b, Event $event): ClientDetailsSubmission
    {
        $s = ClientDetailsSubmission::where('event_id',$event->id)->orderByDesc('revision')->sharedLock()->first();
        RecordWriteRejected::unless($s && $s->portal_grant_id === $b->portal_grant_id && $s->customer_id === $b->customer_id
            && $s->event_version === $event->version, 409, 'Completa y confirma tus datos primero. / Complete your details first.');
        foreach (['first_name','last_name','phone','email','billing_address','event_address','event_postal_code','child_name','event_language','setup_area'] as $field) {
            RecordWriteRejected::unless(filled($s->data[$field] ?? null), 422, 'Completa todos los datos de la fiesta. / Complete all party details.');
        }
        RecordWriteRejected::unless(isset($s->data['child_age'],$s->data['children_count']) && $s->data['start_time_confirmed'] === true,
            422, 'Confirma la hora y los datos de la fiesta. / Confirm the party time and details.');
        return $s;
    }

    public static function pin(QuickPaymentBooking $b, array $data): void
    {
        RecordWriteRejected::unless($b->version === (int)$data['expected_booking_version']
            && hash_equals($b->agreement_sha256, $data['expected_payment_sha256']), 409, 'La reserva cambió. Actualiza antes de continuar. / Refresh this booking.');
    }

    public static function input(array $input, array $extra = []): array
    {
        return QuickStripeLeases::input($input, ['expected_booking_version'=>'required|integer|min:1',
            'expected_payment_sha256'=>'required|string|regex:/\A[a-f0-9]{64}\z/', ...$extra]);
    }
}
