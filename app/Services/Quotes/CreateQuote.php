<?php
namespace App\Services\Quotes;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Customer, Event, Quote, QuoteRevision, User};
use App\Services\Audit;
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class CreateQuote
{
    public function execute(User $actor, array $input, string $key): QuoteRevision
    {
        $actor = QuoteInput::actor($actor); $data = QuoteInput::validate($input, true);
        $scope = "owner:{$actor->id}:quote:create"; $hash = MutationKey::hash($data);
        return DB::transaction(function () use ($actor, $data, $scope, $hash, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail(); QuoteInput::actor($actor);
            $event = Event::whereKey($data['event_id'])->lockForUpdate()->firstOrFail();
            if ($saved = MutationKey::find($scope, $key, $hash)) return QuoteRevision::findOrFail($saved->result_id);
            RecordWriteRejected::unless(!Quote::where('event_id', $event->id)->lockForUpdate()->first(), 409, 'Este borrador ya tiene una cotización. Crea una revisión.');
            $snapshot = QuoteSnapshot::capture($event, $actor, $data);
            $quote = Quote::create(['event_id' => $event->id, 'customer_id' => $event->customer_id, 'lead_id' => $data['lead_id'] ?? null,
                'status' => 'draft', 'version' => 1, 'current_revision' => 1, 'valid_until' => $snapshot['valid_until'],
                'locale' => $data['locale'] ?? Customer::findOrFail($event->customer_id)->locale, 'owner_note' => $data['owner_note'] ?? null, 'created_by' => $actor->id]);
            $quote->forceFill(['number' => 'Q-'.now('America/Chicago')->format('Y').'-'.str_pad((string) $quote->id, 6, '0', STR_PAD_LEFT)])->save();
            $revision = QuoteRevision::create(['quote_id' => $quote->id, 'revision' => 1, ...$snapshot]);
            Audit::recordFor($actor, 'quote.created', $quote, [], ['event_id' => $event->id, 'revision' => 1, 'total_cents' => $revision->total_cents]);
            MutationKey::store($scope, $key, $hash, 'quote_revision', $revision->id);
            return $revision;
        }, 3);
    }
}
