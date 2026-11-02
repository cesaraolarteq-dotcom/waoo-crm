<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{LedgerEntry, OpeningReceiptAllocation, User};
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

final class OpeningReceiptLink
{
    public static function attach(User $actor, LedgerEntry $entry, array $context): OpeningReceiptAllocation
    {
        RecordWriteRejected::unless(DB::transactionLevel() > 0, 409, 'La asignación requiere una transacción.');
        $entry = LedgerEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
        $proof = OpeningReceiptProof::inspect($entry, $context);
        RecordWriteRejected::unless($proof['eligible'], 409, 'El recibo requiere conciliación; no se asignó al saldo importado.');
        $existing = OpeningReceiptAllocation::where('ledger_entry_id', $entry->id)->lockForUpdate()->first();
        if ($existing) { self::verify($existing, $entry, $context, $proof); return $existing; }
        $payload = ['binding_id' => $context['binding']->id, 'opening_id' => $context['binding']->cutover_opening_agreement_id,
            'ledger_entry_id' => $entry->id, 'ledger_sha256' => $proof['ledger_sha256'], 'receipt_sha256' => $proof['receipt_sha256'],
            'amount_cents' => $entry->amount_cents, 'received_at' => $entry->received_at->toIso8601String(), 'created_by' => $actor->id];
        if ($entry->method === 'stripe') $payload += ['schema_version' => 2, 'principal_cents' => $proof['principal_cents'],
            'customer_fee_cents' => $proof['customer_fee_cents'], 'stripe_evidence_sha256' => $proof['stripe_evidence_sha256']];
        $allocation = OpeningReceiptAllocation::create(['ledger_entry_id' => $entry->id, 'cutover_financial_binding_id' => $context['binding']->id,
            'payload' => $payload, 'payload_sha256' => MutationKey::hash($payload), 'created_by' => $actor->id]);
        Audit::recordFor($actor, 'opening.receipt_allocated', $allocation, [], ['ledger_entry_id' => $entry->id,
            'binding_id' => $context['binding']->id, 'payload_sha256' => $allocation->payload_sha256]);
        return $allocation;
    }
    public static function verify(OpeningReceiptAllocation $row, LedgerEntry $entry, array $context, ?array $proof = null): array
    {
        $proof ??= OpeningReceiptProof::inspect($entry, $context);
        try {
            $p = $row->payload;
            $versionValid = ($p['schema_version'] ?? 1) === 1 && $entry->method === 'zelle';
            if (($p['schema_version'] ?? null) === 2 && $entry->method === 'stripe') {
                $versionValid = ($p['principal_cents'] ?? null) === $proof['principal_cents']
                    && ($p['customer_fee_cents'] ?? null) === $proof['customer_fee_cents']
                    && ($p['stripe_evidence_sha256'] ?? null) === $proof['stripe_evidence_sha256'];
            }
            RecordWriteRejected::unless(is_array($p) && $versionValid && $proof['eligible'] && $row->ledger_entry_id === $entry->id
                && $row->cutover_financial_binding_id === $context['binding']->id && $p['binding_id'] === $row->cutover_financial_binding_id
                && $p['opening_id'] === $context['binding']->cutover_opening_agreement_id && $p['ledger_entry_id'] === $entry->id
                && $p['amount_cents'] === $entry->amount_cents && $p['received_at'] === $entry->received_at->toIso8601String()
                && $p['created_by'] === $row->created_by && $p['ledger_sha256'] === $proof['ledger_sha256']
                && $p['receipt_sha256'] === $proof['receipt_sha256'] && hash_equals($row->payload_sha256, MutationKey::hash($p)),
                409, 'La evidencia de asignación del recibo requiere revisión.');
            return $p;
        } catch (RecordWriteRejected $e) { throw $e; }
        catch (\Throwable) { throw new RecordWriteRejected(409, 'La evidencia de asignación del recibo requiere revisión.'); }
    }
    public static function result(OpeningReceiptAllocation $row, array $p): array
    {
        return ['allocation_id' => $row->id, 'ledger_entry_id' => $row->ledger_entry_id, 'opening_id' => $p['opening_id'],
            'amount_cents' => $p['amount_cents'], 'received_at' => $p['received_at'],
            ...(($p['schema_version'] ?? 1) === 2 ? ['principal_cents' => $p['principal_cents'], 'customer_fee_cents' => $p['customer_fee_cents']] : [])];
    }
}
