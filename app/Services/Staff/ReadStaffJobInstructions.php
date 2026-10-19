<?php
namespace App\Services\Staff;

use App\Exceptions\RecordWriteRejected;
use App\Models\{Event, StaffJobInstructions, User};
use App\Services\Finance\MutationKey;
use Illuminate\Support\Facades\DB;

final class ReadStaffJobInstructions
{
    public function execute(User $actor, int $eventId): array
    {
        $actor = StaffGuard::owner($actor);
        return DB::transaction(function () use ($actor, $eventId) {
            StaffGuard::owner($actor);
            $event = Event::whereKey($eventId)->lockForUpdate()->firstOrFail();
            $revision = StaffJobInstructions::where('event_id', $eventId)->latest('version')->lockForUpdate()->first();
            try { $payload = $revision?->payload ?? []; }
            catch (\Throwable) { throw new RecordWriteRejected(409, 'Las instrucciones requieren revisión.'); }
            RecordWriteRejected::unless(is_array($payload) && (!$revision || hash_equals($revision->sha256, MutationKey::hash($payload))), 409, 'Las instrucciones requieren revisión.');
            $empty = ['parking' => null, 'access' => null, 'bring' => [], 'instructions' => null, 'contact_name' => null, 'contact_phone' => null];
            return ['event_id' => $event->id, 'event_version' => $event->version, 'version' => $revision?->version ?? 0,
                'instructions' => array_replace($empty, array_intersect_key($payload, $empty))];
        }, 3);
    }
}
