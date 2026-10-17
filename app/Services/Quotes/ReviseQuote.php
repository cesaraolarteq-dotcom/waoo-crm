<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, Quote, QuoteRevision, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class ReviseQuote
{
    public function execute(User $actor, int $quoteId, array $input, string $key): QuoteRevision
    {
        $actor = QuoteInput::actor($actor); $data = QuoteInput::validate($input, false);
        $scope = "owner:{$actor->id}:quote:{$quoteId}:revise"; $hash = MutationKey::hash($data);
        // Resolve the immutable parent outside the transaction; no snapshot read before the event lock.
        $eventId = Quote::findOrFail($quoteId)->event_id;
        return DB::transaction(function () use ($actor, $quoteId, $eventId, $data, $scope, $hash, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); QuoteInput::actor($actor);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return QuoteRevision::findOrFail($saved->result_id);
            $quote = Quote::whereKey($quoteId)->lockForUpdate()->firstOrFail();
            RecordWriteRejected::unless($quote->status === 'draft' && $quote->valid_until->isFuture() && $quote->version === $data['expected_version'] && $quote->customer_id === $event->customer_id, 409, 'La cotización cambió o ya no es un borrador vigente.');
            $snapshot = QuoteSnapshot::capture($event, $actor, $data);
            $before = $quote->only(['version','current_revision','valid_until']);
            $quote->forceFill(['version' => $quote->version + 1, 'current_revision' => $quote->current_revision + 1,
                'valid_until' => $snapshot['valid_until'], 'locale' => $data['locale'] ?? $quote->locale,
                'owner_note' => array_key_exists('owner_note', $data) ? $data['owner_note'] : $quote->owner_note])->save();
            $revision = QuoteRevision::create(['quote_id' => $quote->id, 'revision' => $quote->current_revision, ...$snapshot]);
            Audit::recordFor($actor, 'quote.revised', $quote, $before, ['version' => $quote->version, 'revision' => $revision->revision, 'total_cents' => $revision->total_cents]);
            MutationKey::store($scope, $key, $hash, 'quote_revision', $revision->id);
            return $revision;
        }, 3);
    }
}
