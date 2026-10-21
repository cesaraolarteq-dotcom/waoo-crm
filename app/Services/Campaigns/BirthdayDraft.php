<?php

namespace App\Services\Campaigns;

use Carbon\CarbonImmutable;

/** Preparation only. This class cannot issue coupons or create messages. */
final class BirthdayDraft
{
    public function prepare(array $anchors, array $laterEvents, string $asOf): array
    {
        $latest = [];
        $today = CarbonImmutable::parse($asOf, 'America/Chicago');
        foreach ($anchors as $anchor) {
            if ($anchor['status'] !== 'completed' || $anchor['event_type'] !== 'birthday') continue;
            $date = $anchor['date'];
            $valid = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if (!$valid || $valid->format('Y-m-d') !== $date || $date >= $asOf) continue;
            $id = $anchor['customer_id'];
            if (!isset($latest[$id]) || $date > $latest[$id]['date']) $latest[$id] = $anchor;
        }
        $items = [];
        foreach ($latest as $id => $anchor) {
            $date = CarbonImmutable::parse($anchor['date'], 'America/Chicago');
            $anniversaryYear = $today->year;
            if ($date->format('m-d') <= $today->format('m-d')) $anniversaryYear++;
            $validAnniversary = checkdate($date->month, $date->day, $anniversaryYear);
            $anniversary = $validAnniversary ? sprintf('%04d-%02d-%02d', $anniversaryYear, $date->month, $date->day) : null;
            $reasons = ['marketing_permission_unverified', 'copy_unapproved'];
            if (!$validAnniversary) $reasons[] = 'leap_day_requires_review';
            foreach ($laterEvents as $event) {
                if ($event['customer_id'] === $id && $event['date'] > $anchor['date'] && $event['status'] !== 'cancelled') {
                    $reasons[] = 'already_rebooked'; break;
                }
            }
            $items[] = ['customer_id' => $id, 'anchor' => $anchor['key'], 'source_date' => $anchor['date'],
                'anniversary' => $anniversary, 'proposed_review_on' => $anniversary ? CarbonImmutable::parse($anniversary, 'America/Chicago')->subMonthNoOverflow()->toDateString() : null,
                'sendable' => false, 'status' => 'draft_blocked', 'reasons' => $reasons];
        }
        usort($items, fn ($a, $b) => [$a['proposed_review_on'] ?? '9999', $a['customer_id']] <=> [$b['proposed_review_on'] ?? '9999', $b['customer_id']]);
        return ['schema_version' => 1, 'as_of' => $asOf, 'timezone' => 'America/Chicago', 'status' => 'draft',
            'candidate_count' => count($items), 'sendable_count' => 0, 'items' => $items,
            'copy' => ['approved' => false,
                'es' => 'Nos encantaría volver a celebrar contigo. Borrador: 10 % en el precio regular del paquete, costo de transporte aparte. Sujeto a las condiciones de la campaña.',
                'en' => 'We would love to celebrate with you again. Draft: 10% off the regular package price, travel fee separate. Subject to campaign terms.'],
            'delivery_enabled' => false, 'codes_issued' => 0];
    }
}
