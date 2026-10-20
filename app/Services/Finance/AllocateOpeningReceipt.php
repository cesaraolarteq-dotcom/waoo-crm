<?php
namespace App\Services\Finance;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, LedgerEntry, OpeningReceiptAllocation, User};
use App\Services\Imports\{ImportInput, ImportPersistence};

final class AllocateOpeningReceipt
{
    public function execute(User $actor, int $eventId, int $ledgerId, array $input, string $key): array
    {
        $actor = OpeningReceiptAccess::actor($actor);
        $d = ImportInput::input($input, ['opening_id' => 'required|integer|min:1',
            'expected_collection_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'expected_receipt_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'post_cutover_received' => 'required|accepted']);
        $d['opening_id'] = (int)$d['opening_id']; $d['post_cutover_received'] = true;
        $hash = MutationKey::hash($d); $scope = 'owner:'.$actor->id.':event:'.$eventId.':opening-receipt:'.$ledgerId;
        return ImportPersistence::transaction(function () use ($actor, $eventId, $ledgerId, $d, $hash, $scope, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); OpeningReceiptAccess::actor($actor, true);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail(); $c = OpeningReceiptContext::current($event);
            RecordWriteRejected::unless($d['opening_id'] === $c['binding']->cutover_opening_agreement_id && hash_equals($c['collection_sha256'], $d['expected_collection_sha256']),
                409, 'El acuerdo cambió. Revisa antes de asignar el recibo.');
            $entry = LedgerEntry::where('event_id', $eventId)->whereKey($ledgerId)->lockForUpdate()->firstOrFail();
            $proof = OpeningReceiptProof::inspect($entry, $c);
            RecordWriteRejected::unless(hash_equals($proof['receipt_sha256'], $d['expected_receipt_sha256']), 409, 'El recibo cambió. Revisa antes de asignarlo.');
            if ($receipt = MutationKey::find($scope, $key, $hash, true)) {
                $a = OpeningReceiptAllocation::whereKey($receipt->result_id)->lockForUpdate()->firstOrFail();
                return OpeningReceiptLink::result($a, OpeningReceiptLink::verify($a, $entry, $c, $proof));
            }
            $a = OpeningReceiptLink::attach($actor, $entry, $c);
            MutationKey::store($scope, $key, $hash, 'opening_receipt_allocation', $a->id);
            return OpeningReceiptLink::result($a, OpeningReceiptLink::verify($a, $entry, $c, $proof));
        });
    }
}
