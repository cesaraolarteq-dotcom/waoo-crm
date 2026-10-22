<?php
namespace App\Services\Documents;

use App\Models\{Contract, ContractTemplateVersion, SignatureEvidence};
use App\Services\Finance\FinanceSummary;
use App\Models\{Event, LedgerEntry};
use Carbon\CarbonImmutable;

final class ContractPrintContext
{
    public static function money(int $cents): string { return '$'.number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT); }
    public function build(Contract $contract, ContractTemplateVersion $template, ?SignatureEvidence $signature = null): array
    {
        $snapshot = $contract->commercial_snapshot;
        $services = []; $lines = []; $characters = [];
        foreach ($snapshot['services'] as $service) {
            // Owner 2026-10-22: the client sees the package description, not the internal staff/equipment recipe.
            $services[] = ['name' => $service['name'], 'total' => self::money($service['amount_cents']), 'duration' => $service['duration_minutes'].' minutes', 'includes' => [], 'long_description' => $service['long_description'] ?? ''];
            $lines[] = ['label' => $service['name'], 'amount' => self::money($service['amount_cents'])];
            foreach ($service['characters'] ?? [] as $character) $characters[] = $character['quantity'].' × '.\App\Services\Records\CharacterLabel::fromSnapshot($character);
        }
        $lines[] = ['label' => 'Travel fee', 'amount' => self::money($snapshot['travel_fee_cents'])];
        foreach($snapshot['discounts']??[] as $discount)$lines[]=['label'=>'Discount: '.$discount['label'],'amount'=>'−'.self::money($discount['amount_cents'])];
        $payments = $snapshot['payments'];
        $context = ['client' => [...$snapshot['client'], 'typed_signature' => $signature?->signer_name ?? '', 'signed_at' => $signature?->accepted_at->setTimezone('America/Chicago')->format('M j, Y g:i a T') ?? ''],
            'event' => [...$snapshot['event'], 'requires_characters' => $characters !== [], 'characters' => implode(', ', $characters)],
            'provider' => ['signed_at' => null, 'pending' => true], 'services' => $services, 'lines' => $lines,
            'total' => self::money($snapshot['total_cents']), 'payments' => $payments,
            'template' => ['version' => $template->version], 'contract_number' => 'WP-'.$contract->event_id.'-'.$contract->revision,
            'status_label' => $signature ? 'Signed' : 'Awaiting signature', 'signed' => $signature !== null,
            'document' => ['sha256' => $contract->presented_pdf_sha256]];
        if(isset($snapshot['billing_company']))$context['billing_company']=[...$snapshot['billing_company'],'person'=>trim($snapshot['client']['first_name'].' '.$snapshot['client']['last_name'])];
        if ($signature) $context['signer'] = ['name' => $signature->signer_name, 'email' => $signature->signer_email,
            'access_description' => 'through a scoped personal access link associated with this customer record; email delivery and verification were not asserted.',
            'signed_at_local' => $signature->accepted_at->setTimezone('America/Chicago')->format('Y-m-d H:i:s T'), 'signed_at_utc' => $signature->accepted_at->utc()->format('Y-m-d H:i:s'),
            'ip' => $signature->ip, 'user_agent_short' => mb_substr($signature->user_agent ?? '', 0, 120)];
        return $context;
    }
    public function paymentSnapshot(Event $event): array
    {
        $summary = app(FinanceSummary::class)->forEvent($event);
        return ['received' => LedgerEntry::where('event_id', $event->id)->where('environment', 'test')->where('kind', 'receipt')->orderBy('received_at')->get()->map(fn ($entry) => [
            'date' => $entry->received_at->setTimezone('America/Chicago')->format('M j, Y g:i a T'), 'amount' => self::money($entry->amount_cents), 'method' => strtoupper($entry->method)])->all(),
            'has_credit' => $summary['credit_cents'] > 0, 'credit_amount' => self::money($summary['credit_cents']), 'balance_label' => $summary['balance_label'], 'balance_amount' => self::money($summary['balance_cents']),
            'balance_due_date' => $summary['financial_revision']->balance_due_at->setTimezone('America/Chicago')->format('M j, Y g:i a T')];
    }
}
