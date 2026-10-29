<?php
namespace App\Services\Availability;

/** Internal exact temporal pool matching. Feasible is NOT a public availability promise. */
final class PoolCapacitySolver
{
    public function __construct(private readonly int $maxStates = 50000, private readonly int $maxMilliseconds = 200) {}

    public function solve(array $resources, array $jobs, array $blocks = []): array
    {
        $unknown = fn (string $reason) => ['scope' => 'resource_pools', 'state' => 'unknown', 'reason' => $reason, 'witness' => []];
        if ($this->maxStates < 1 || $this->maxStates > 100000 || $this->maxMilliseconds < 1 || $this->maxMilliseconds > 1000
            || count($resources) > 256 || count($jobs) > 64 || count($blocks) > 4096) return $unknown('input_limit');
        $deadline = hrtime(true) + $this->maxMilliseconds * 1000000;
        $units = []; $tasks = []; $groups = []; $busy = [];
        foreach ($resources as $r) {
            if (!is_array($r) || !is_int($r['id'] ?? null) || $r['id'] < 1 || isset($units[$r['id']])
                || !in_array($r['kind'] ?? null, ['staff', 'equipment', 'costume', 'vehicle'], true)
                || ($r['kind'] === 'staff' && !is_bool($r['driver'] ?? null))
                || ($r['kind'] === 'vehicle' && (!is_int($r['seats'] ?? null) || $r['seats'] < 1))) return $unknown('incomplete_resource');
            $units[$r['id']] = $r;
        }
        foreach ($blocks as $b) {
            if (!is_array($b) || !is_int($b['resource_id'] ?? null) || !isset($units[$b['resource_id']]) || !self::interval($b)) return $unknown('invalid_block');
            $busy[$b['resource_id']][] = [$b['starts_at'], $b['ends_at']];
        }
        foreach ($jobs as $j) {
            if (!is_array($j) || !is_string($j['key'] ?? null) || $j['key'] === '' || strlen($j['key']) > 100 || isset($groups[$j['key']])
                || !self::interval($j) || !is_int($j['minimum_drivers'] ?? null) || $j['minimum_drivers'] < 0
                || !is_array($j['slots'] ?? null) || !array_is_list($j['slots']) || $j['slots'] === []) return $unknown('incomplete_job');
            $keys = []; $indices = []; $staff = 0; $vehicles = 0;
            foreach ($j['slots'] as $slot) {
                if (!is_array($slot) || !is_string($slot['key'] ?? null) || $slot['key'] === '' || strlen($slot['key']) > 100 || isset($keys[$slot['key']])
                    || !in_array($slot['kind'] ?? null, ['staff', 'equipment', 'costume', 'vehicle'], true)
                    || !is_array($slot['eligible'] ?? null) || !array_is_list($slot['eligible']) || count($slot['eligible']) > 256) return $unknown('incomplete_slot');
                $ids = [];
                foreach ($slot['eligible'] as $id) {
                    if (!is_int($id) || !isset($units[$id]) || $units[$id]['kind'] !== $slot['kind'] || isset($ids[$id])) return $unknown('invalid_eligibility');
                    $ids[$id] = true;
                }
                $keys[$slot['key']] = true; $indices[] = count($tasks);
                $staff += (int)($slot['kind'] === 'staff'); $vehicles += (int)($slot['kind'] === 'vehicle');
                $tasks[] = ['job' => $j['key'], 'key' => $slot['key'], 'kind' => $slot['kind'], 'eligible' => array_keys($ids),
                    'start' => $j['starts_at'], 'end' => $j['ends_at']];
                if (count($tasks) > 512) return $unknown('input_limit');
            }
            $groups[$j['key']] = ['indices' => $indices, 'staff' => $staff, 'vehicles' => $vehicles, 'drivers' => max($j['minimum_drivers'], $vehicles)];
        }
        if ($tasks === []) return $unknown('empty_demand');
        $chosen = []; $states = 0; $exhausted = false; $checks = 0;
        $free = static function (array $task, int $id) use (&$busy, &$checks, &$exhausted, $deadline): bool {
            if ($exhausted) return false;
            foreach ($busy[$id] ?? [] as [$start, $end]) {
                if (++$checks > 500000 || (($checks & 127) === 0 && hrtime(true) >= $deadline)) { $exhausted = true; return false; }
                if ($start < $task['end'] && $end > $task['start']) return false;
            }
            return true;
        };
        $search = function () use (&$search, &$chosen, &$busy, &$states, &$exhausted, $tasks, $groups, $units, $free, $deadline): bool {
            if (++$states > $this->maxStates || hrtime(true) >= $deadline) { $exhausted = true; return false; }
            // Bound driver/seat feasibility using distinct still-eligible units, never independent positive role counts.
            foreach ($groups as $group) {
                if ($exhausted) return false;
                $drivers = 0; $remainingDrivers = []; $seats = 0; $remainingVehicles = []; $unassignedVehicles = 0;
                foreach ($group['indices'] as $i) {
                    $task = $tasks[$i];
                    if (isset($chosen[$i])) {
                        $r = $units[$chosen[$i]];
                        if ($r['kind'] === 'staff' && $r['driver']) $drivers++;
                        if ($r['kind'] === 'vehicle') $seats += $r['seats'];
                    } else {
                        if ($task['kind'] === 'vehicle') $unassignedVehicles++;
                        foreach ($task['eligible'] as $id) if ($free($task, $id)) {
                            $r = $units[$id];
                            if ($r['kind'] === 'staff' && $r['driver']) $remainingDrivers[$id] = true;
                            if ($r['kind'] === 'vehicle') $remainingVehicles[$id] = $r['seats'];
                        }
                    }
                }
                if ($drivers + count($remainingDrivers) < $group['drivers']) return false;
                rsort($remainingVehicles);
                if ($group['vehicles'] > 0 && $seats + array_sum(array_slice($remainingVehicles, 0, $unassignedVehicles)) < $group['staff']) return false;
            }
            if (count($chosen) === count($tasks)) return true;
            $selected = null; $domain = [];
            foreach ($tasks as $i => $task) {
                if (isset($chosen[$i])) continue;
                $available = array_values(array_filter($task['eligible'], fn ($id) => $free($task, $id)));
                if ($exhausted) return false;
                if ($available === []) return false;
                if ($selected === null || count($available) < count($domain)) { $selected = $i; $domain = $available; }
            }
            usort($domain, fn ($a, $b) => (int)($units[$b]['driver'] ?? false) <=> (int)($units[$a]['driver'] ?? false) ?: $a <=> $b);
            $task = $tasks[$selected];
            foreach ($domain as $id) {
                $chosen[$selected] = $id; $busy[$id][] = [$task['start'], $task['end']];
                if ($search()) return true;
                array_pop($busy[$id]); unset($chosen[$selected]);
                if ($exhausted) return false;
            }
            return false;
        };
        if (!$search()) return $exhausted ? $unknown('evaluation_budget') : ['scope' => 'resource_pools', 'state' => 'infeasible', 'reason' => null, 'witness' => []];
        $witness = [];
        foreach ($chosen as $i => $id) $witness[$tasks[$i]['job']][$tasks[$i]['key']] = $id;
        return ['scope' => 'resource_pools', 'state' => 'feasible', 'reason' => null, 'witness' => $witness];
    }

    private static function interval(array $v): bool
    {
        return is_int($v['starts_at'] ?? null) && is_int($v['ends_at'] ?? null) && $v['starts_at'] < $v['ends_at'];
    }
}
