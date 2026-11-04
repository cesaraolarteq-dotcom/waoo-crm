<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;

/** Resource-priority augmenting matching: maximize assigned drivers, then cover remaining roles. */
final class MatchEventResources
{
    public function execute(array $slots,array $resources,callable $fits):array
    {
        $assigned=$this->partial($slots,$resources,$fits);
        if(count($assigned)!==count($slots))throw ValidationException::withMessages(['booking'=>'No hay una combinación de recursos e intérpretes compatibles que cubra todos los roles simultáneos.'.self::missing(array_diff_key($slots,array_flip($assigned)))]);
        return $assigned;
    }

    /** Structural matching for one selected crew. Temporal/route checks remain the caller's locked responsibility. */
    public function executeForTeam(array $slots, array $resources, callable $fits, array $teamSkills): array
    {
        if ($teamSkills === []) return $this->execute($slots, $resources, $fits);
        $units = []; $resourceIndex = []; $people = 0; $vehicles = 0;
        foreach ($resources as $index => $r) {
            $resourceIndex[$r->id] = $index;
            $units[] = match ($r->kind) {
                'staff' => ['id' => $r->id, 'kind' => 'staff', 'driver' => self::driver($r), 'skills' => (array) $r->skills],
                'vehicle' => ['id' => $r->id, 'kind' => 'vehicle', 'seats' => max(1, (int) $r->seats)],
                default => ['id' => $r->id, 'kind' => $r->kind],
            };
            if ($r->kind === 'vehicle') $vehicles++;
        }
        $roles = [];
        foreach ($slots as $index => $slot) {
            $people += (int) ($slot['kind'] === 'staff');
            $eligible = [];
            foreach ($resources as $r) if ($fits($slot, $r)) $eligible[] = $r->id;
            $roles[] = ['key' => 'slot'.$index, 'kind' => $slot['kind'], 'eligible' => $eligible];
        }
        // With only one job these times impose uniqueness, not an operational reservation or a travel promise.
        $job = ['key' => 'crew', 'starts_at' => 0, 'ends_at' => 1, 'slots' => $roles,
            'minimum_drivers' => max($people > 0 ? 1 : 0, $vehicles), 'team_skills' => $teamSkills];
        $result = (new \App\Services\Availability\PoolCapacitySolver)->solve($units, [$job]);
        if ($result['state'] !== 'feasible') throw ValidationException::withMessages(['booking' =>
            'No hay una combinación de recursos e intérpretes compatibles que cubra todos los roles simultáneos. Falta: '.
            collect($teamSkills)->map(fn ($skill) => \App\Filament\Support\Labels::label(\App\Filament\Support\Labels::SKILL, $skill))->implode(', ').'.']);
        $assigned = [];
        foreach ($result['witness']['crew'] as $slotKey => $id) $assigned[$resourceIndex[$id]] = (int) substr($slotKey, 4);
        ksort($assigned);
        return $assigned;
    }

    /** Read-only suggestions may cover a subset; committing a booking must always call execute(). */
    public function partial(array $slots,array $resources,callable $fits):array
    {
        $order=array_keys($resources);
        usort($order,fn($a,$b)=>(int)self::driver($resources[$b])<=>(int)self::driver($resources[$a])?:$a<=>$b);
        $bySlot=[];
        // Every successful augmentation preserves all previously matched resources. Matching
        // drivers first therefore retains a maximum feasible independent set of drivers while
        // allowing their roles to move; subsequent resources extend it to full role coverage.
        $search=function(int $resource,array &$seen)use(&$search,&$bySlot,$slots,$resources,$fits):bool{
            foreach($slots as $index=>$slot){
                if(isset($seen[$index])||!$fits($slot,$resources[$resource]))continue;
                $seen[$index]=true;
                if(!isset($bySlot[$index])||$search($bySlot[$index],$seen)){$bySlot[$index]=$resource;return true;}
            }return false;
        };
        foreach($order as $resource){$seen=[];$search($resource,$seen);if(count($bySlot)===count($slots))break;}
        $assigned=[];foreach($bySlot as $slot=>$resource)$assigned[$resource]=$slot;ksort($assigned);return $assigned;
    }
    /** Owner 2026-11-04: say what is missing, e.g. " Falta: Traje de Mickey, Animador/a." */
    private static function missing(array $slots):string
    {
        $L=\App\Filament\Support\Labels::class;
        $names=collect($slots)->map(fn($s)=>match($s['kind']??null){
            'costume'=>'Traje de '.(\App\Models\Character::whereKey((int)($s['character_id']??0))->value('name')??'personaje'),
            'equipment'=>$L::label($L::EQUIPMENT,$s['equipment_kind']??null),
            'staff'=>isset($s['costume_unit_id'])?'Persona para usar el traje':$L::label($L::SKILL,$s['skill']??null),
            default=>null})->filter()->unique()->values();
        return $names->isEmpty()?'':' Falta: '.$names->implode(', ').'.';
    }
    public static function driver(object $resource):bool
    {
        return $resource->kind==='staff'&&in_array('driver',$resource->skills??[],true);
    }
}
