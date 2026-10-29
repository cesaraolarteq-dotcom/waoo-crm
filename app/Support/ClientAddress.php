<?php
namespace App\Support;

use App\Models\{ClientDetailsSubmission, Event};

/**
 * Owner 2026-10-29: a quick booking may be saved without the street address; the client writes it in her link and it goes
 * into the contract. Until the event row has its own address, screens, the staff week and the calendar show the client's one
 * (same ZIP only: a different ZIP is still for the owner to review).
 */
final class ClientAddress
{
    /** @return array{0: ?string, 1: bool} [address, written by the client] */
    public static function forEvent(Event $event): array
    {
        if (filled($event->address)) return [$event->address, false];
        $data = ClientDetailsSubmission::where('event_id', $event->id)->orderByDesc('revision')->value('data');
        $data = is_string($data) ? json_decode($data, true) : $data;
        $address = is_array($data) ? trim((string) ($data['event_address'] ?? '')) : '';
        $sameZip = ($data['event_postal_code'] ?? $event->postal_code) === $event->postal_code;

        return $address !== '' && $sameZip ? [$address, true] : [null, false];
    }
}
