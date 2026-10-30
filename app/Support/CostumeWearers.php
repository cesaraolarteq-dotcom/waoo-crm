<?php
namespace App\Support;

use App\Models\{Character, Resource};

/**
 * Who can wear a costume unit (César 2026-10-30):
 * - a unit with people recorded in "Quién puede usarlo" → only those people (sizes, exceptions);
 * - otherwise by the character's style: head (full costume) → anyone with Personaje or Personaje con peluca;
 *   wig (the face shows) → only Personaje con peluca;
 * - a character not marked head or wig proves nobody (owner review).
 */
final class CostumeWearers
{
    public const STYLES = ['head', 'wig'];

    /** @param iterable<object{staff_id:int,costume_id:int}> $compat recorded rows for this unit (any subset) */
    public static function allows(Resource $costume, Resource $staff, ?Character $character, iterable $compat): bool
    {
        if ($staff->kind !== 'staff' || $costume->kind !== 'costume') return false;
        $recorded = false;
        foreach ($compat as $c) {
            if ((int) $c->costume_id !== $costume->id) continue;
            $recorded = true;
            if ((int) $c->staff_id === $staff->id) return true;
        }
        if ($recorded) return false;
        $skills = (array) $staff->skills;
        return match ($character?->costume_style) {
            'head' => in_array('performer', $skills, true) || in_array('performer_wig', $skills, true),
            'wig' => in_array('performer_wig', $skills, true),
            default => false,
        };
    }
}
