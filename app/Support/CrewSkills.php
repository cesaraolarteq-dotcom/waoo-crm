<?php
namespace App\Support;

/** Skills that one member of the existing package crew must cover; never an extra staff seat. */
final class CrewSkills
{
    public static function required(array $requirements): array
    {
        $kit = false; $dedicatedPainter = false;
        foreach ($requirements as $r) {
            if (($r['kind'] ?? null) === 'equipment' && ($r['equipment_kind'] ?? null) === 'face_kit') $kit = true;
            if (($r['kind'] ?? null) === 'staff' && ($r['skill'] ?? null) === 'face_painter') $dedicatedPainter = true;
        }
        return $kit && !$dedicatedPainter ? ['face_painter'] : [];
    }

    public static function missing(array $skills, iterable $crew): array
    {
        $have = [];
        foreach ($crew as $person) foreach ((array) $person->skills as $skill) $have[$skill] = true;
        return array_values(array_diff($skills, array_keys($have)));
    }
}
