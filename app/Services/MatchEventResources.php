<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;

/** Resource-priority augmenting matching: maximize assigned drivers, then cover remaining roles. */
final class MatchEventResources
{
    public function execute(array $slots,array $resources,callable $fits):array
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
        if(count($bySlot)!==count($slots))throw ValidationException::withMessages(['booking'=>'No hay una combinación de recursos e intérpretes compatibles que cubra todos los roles simultáneos.']);
        $assigned=[];foreach($bySlot as $slot=>$resource)$assigned[$resource]=$slot;ksort($assigned);return $assigned;
    }
    public static function driver(object $resource):bool
    {
        return $resource->kind==='staff'&&in_array('driver',$resource->skills??[],true);
    }
}
