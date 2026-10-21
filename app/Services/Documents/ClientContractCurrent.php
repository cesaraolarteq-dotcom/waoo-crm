<?php
namespace App\Services\Documents;

use App\Exceptions\RecordWriteRejected;
use App\Models\Contract;
use App\Services\Finance\{MutationKey, Sandbox};
use App\Services\Portal\PortalContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** The sole narrow alternative to a genuine owner review for a B1 document. */
final class ClientContractCurrent
{
    public static function check(PortalContext $client, Contract $contract): void
    {
        Sandbox::require(); Sandbox::require('sandbox_documents'); Sandbox::require('sandbox_immediate_contracts');
        if ($client->adminAuthenticated) throw new AuthorizationException('Una sesión administrativa no puede firmar por la clienta.');
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => self::check($client, $contract), 3);
            return;
        }
        $snapshot = $contract->commercial_snapshot;
        $p = $snapshot['client_details'] ?? null;
        RecordWriteRejected::unless(is_array($p) && count($p) === 4
            && ($p['schema_version'] ?? null) === 1 && is_int($p['submission_id'] ?? null)
            && is_int($p['submission_revision'] ?? null) && is_string($p['submission_sha256'] ?? null), 409, 'Revisa el contrato vigente.');
        [$event, $customer, $finance, $submission, $values, $provenance] = ClientContractSource::read($client,
            ['expected_event_version' => $contract->event_version, 'expected_revision' => $p['submission_revision']]);
        $expected = ClientContractSource::snapshot($event, $finance, $values, $provenance);
        $actual = array_intersect_key($snapshot, $expected);
        RecordWriteRejected::unless($p === $provenance && $contract->event_id === $event->id
            && $contract->signer_customer_id === $customer->id && $contract->signer_email === $values['email']
            && $contract->financial_revision_id === $finance->id && $contract->issued_by === null
            && $contract->issued_at !== null && $contract->revision === (int) Contract::where('event_id', $event->id)->max('revision')
            && hash_equals(MutationKey::hash($expected), MutationKey::hash($actual)), 409, 'El acuerdo o los datos cambiaron. Recarga antes de firmar.');
    }
}
