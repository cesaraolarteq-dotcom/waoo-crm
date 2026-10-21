<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\{ClientDetailsSubmission, Customer, Event, FinancialRevision};
use App\Services\Booking\QuickCostumeHolds;
use App\Services\Finance\MutationKey;
use App\Services\Portal\PortalContext;
use Illuminate\Support\Facades\DB;

/** Shared locked source check; no document, receipt, review or resource writes. */
final class ClientContractSource
{
    public static function read(PortalContext $client, array $data): array
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('Client agreement reads require a transaction.');
        foreach (['details.submit','contract.read','contract.sign'] as $scopeName) $client->authorize($client->eventId, $scopeName, true);
        $event = Event::whereKey($client->eventId)->where('customer_id', $client->customerId)->lockForUpdate()->firstOrFail();
        $customer = Customer::whereKey($event->customer_id)->lockForUpdate()->firstOrFail();
        \App\Services\Responsibility\RequireCrmResponsibility::check($event);
        \App\Services\Imports\ImportedAgreement::blockCrmLifecycle($event->id);
        $booking = \App\Services\Booking\QuickPaymentProtocol::booking($event->id);
        if ($booking) {
            \App\Services\Booking\QuickPaymentProtocol::gate();
            \App\Services\Booking\QuickPaymentProtocol::verify($booking,$event);
            \App\Services\Booking\QuickPaymentProtocol::submission($booking,$event);
            if(in_array($booking->state,['paid','paid_needs_review'],true)){
                $receipt=\App\Models\LedgerEntry::whereKey($booking->receipt_id)->sharedLock()->first();
                RecordWriteRejected::unless($receipt && $receipt->event_id===$event->id && $receipt->financial_revision_id===$booking->financial_revision_id && $receipt->kind==='receipt' && $receipt->environment==='test' && $receipt->amount_cents>0 && (($receipt->source_type==='owner_verified' && $receipt->verified_by!==null) || QuickCostumeHolds::verifiedStripeReceipt($receipt)),409,'Revisa la evidencia del pago.');
                if($booking->state==='paid'){ $paid=QuickCostumeHolds::record($event);RecordWriteRejected::unless($paid->id===$booking->quick_booking_id && $paid->receipt_id===$booking->receipt_id,409,'Revisa el depósito de esta reserva.'); }
            }
            RecordWriteRejected::unless($booking->portal_grant_id===$client->grantId && (in_array($booking->state,['paid','paid_needs_review'],true) || (in_array($booking->state,['zelle_pending','review_required'],true) && \App\Services\Booking\QuickPaymentProtocol::pendingNotice($booking))),409,'Primero elige tu pago. / Choose your payment first.');
        }
        $quick = $booking ? null : QuickCostumeHolds::record($event);
        $finance = FinancialRevision::where('event_id', $event->id)->orderByDesc('revision')->firstOrFail();
        if(!$booking) CreateContract::assertCurrent($event, $finance);
        else RecordWriteRejected::unless(in_array($event->status,['draft','capacity_reserved'],true) && $finance->id===$booking->financial_revision_id && ($event->status!=='draft'||$event->version===$finance->event_version),409,'El acuerdo cambió.');
        $submission = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->first();
        RecordWriteRejected::unless($submission && $submission->customer_id === $customer->id
            && $submission->portal_grant_id === $client->grantId && $submission->revision === $data['expected_revision']
            && $submission->event_version === $event->version && $event->version === $data['expected_event_version']
            && $finance->id === ($booking?->financial_revision_id ?? $quick->initial_financial_revision_id) && $finance->currency === 'USD' && $finance->quote_revision_id === null, 409, 'La reserva o los datos cambiaron. Recarga antes de preparar el contrato.');
        RecordWriteRejected::unless(MutationKey::hash($customer->only(['name','email','phone','locale'])) === MutationKey::hash($submission->base_customer_snapshot),
            409, 'La ficha del cliente cambió. Envía nuevamente sus datos.');
        $values = $submission->data;
        RecordWriteRejected::unless((($booking && blank($event->address) && filled($values['event_address'] ?? null)) || (filled($event->address) && $event->address === ($values['event_address'] ?? null)))
            && $event->postal_code === ($values['event_postal_code'] ?? null) && ($values['start_time_confirmed'] ?? false) === true
            && empty($values['requested_start_at']), 409, 'La dirección o la hora requieren revisión del dueño. Tus datos siguen guardados.');
        $services = $event->services()->with('characters')->get();
        RecordWriteRejected::unless($services->isNotEmpty() && !$services->contains(fn ($s) => !$s->operational_ready)
            && count($finance->lines_snapshot) === $services->count(), 409, 'Revisa los servicios acordados antes de preparar el contrato.');
        foreach ($finance->lines_snapshot as $line) {
            $service = $services->firstWhere('id', $line['event_service_id'] ?? null);
            $characters = $service?->characters->map(fn ($row) => ['character_id' => $row->character_id, 'quantity' => $row->quantity,
                'name' => $row->character_name_snapshot, ...($row->outfit !== null ? ['outfit' => $row->outfit] : [])])->all();
            RecordWriteRejected::unless($service && filled($line['name'] ?? null)
                && (int) ($line['price_version_id'] ?? 0) === $service->price_version_id
                && (int) ($line['duration_minutes'] ?? 0) === $service->duration_minutes
                && ($line['requirements_snapshot'] ?? null) === $service->requirements_snapshot
                && ($characters === [] || $characters === ($line['characters'] ?? null)), 409, 'La receta acordada requiere revisión.');
        }
        $provenance = ClientSubmissionFingerprint::capture($submission);
        return [$event, $customer, $finance, $submission, $values, $provenance];
    }

    public static function snapshot(Event $event, FinancialRevision $finance, array $values, array $provenance): array
    {
        $booking = \App\Services\Booking\QuickPaymentProtocol::booking($event->id);
        return [...($booking ? ['quick_payment_source'=>['schema_version'=>1,'booking_id'=>$booking->id,'agreement_sha256'=>$booking->agreement_sha256]] : []), 'client' => ['first_name' => $values['first_name'], 'last_name' => $values['last_name'], 'address' => $values['billing_address'],
            'email' => $values['email'], 'phone' => $values['phone']], 'client_details' => $provenance,
            'event' => ['date_long' => $event->starts_at->setTimezone('America/Chicago')->locale('en')->translatedFormat('D, M j, Y'),
                'start_time' => $event->starts_at->setTimezone('America/Chicago')->format('g:i a'), 'end_time' => $event->ends_at->setTimezone('America/Chicago')->format('g:i a'), 'address' => $event->address ?: $values['event_address']],
            'services' => $finance->lines_snapshot, 'travel_fee_cents' => $finance->travel_fee_cents, 'total_cents' => $finance->total_cents,
            'discounts' => array_map(fn ($d) => ['label' => $d['label'], 'amount_cents' => $d['amount_cents']], $finance->discounts_snapshot ?? []),
            'balance_due_at' => $finance->balance_due_at?->toIso8601String()];
    }
}
