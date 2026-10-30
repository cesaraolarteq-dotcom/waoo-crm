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
                // Optional pairing (who can wear which costume): 'pair' names another slot of this job, 'pairs' lists the allowed
                // [this unit, partner unit] combinations. Both slots then hold one allowed combination for the whole job.
                $pairs = null;
                if (array_key_exists('pair', $slot)) {
                    if (!is_string($slot['pair']) || $slot['pair'] === $slot['key'] || !is_array($slot['pairs'] ?? null) || count($slot['pairs']) > 4096) return $unknown('invalid_pairing');
                    $pairs = [];
                    foreach ($slot['pairs'] as $pr) {
                        if (!is_array($pr) || count($pr) !== 2 || !is_int($pr[0] ?? null) || !is_int($pr[1] ?? null)) return $unknown('invalid_pairing');
                        $pairs[$pr[0].':'.$pr[1]] = true;
                    }
                }
                $keys[$slot['key']] = true; $indices[] = count($tasks);
                $staff += (int)($slot['kind'] === 'staff'); $vehicles += (int)($slot['kind'] === 'vehicle');
                $tasks[] = ['job' => $j['key'], 'key' => $slot['key'], 'kind' => $slot['kind'], 'eligible' => array_keys($ids),
                    'start' => $j['starts_at'], 'end' => $j['ends_at'], 'pair' => $pairs === null ? null : $slot['pair'], 'pairs' => $pairs];
                if (count($tasks) > 512) return $unknown('input_limit');
            }
            // Resolve each pairing to its partner task, both ways; a partner outside this job is invalid.
            foreach ($indices as $i) {
                if ($tasks[$i]['pair'] === null) continue;
                $partner = null;
                foreach ($indices as $k) if ($tasks[$k]['key'] === $tasks[$i]['pair']) $partner = $k;
                if ($partner === null || isset($tasks[$partner]['partner']) || $tasks[$partner]['pair'] !== null) return $unknown('invalid_pairing');
                $tasks[$i]['partner'] = $partner; $tasks[$i]['own'] = true;
                $tasks[$partner]['partner'] = $i; $tasks[$partner]['own'] = false; $tasks[$partner]['pairs'] = $tasks[$i]['pairs'];
            }
            // 'people' => false checks physical units only (vehicles without drivers or seat counts).
            $people = $j['people'] ?? true;
            if (!is_bool($people)) return $unknown('incomplete_job');
            $groups[$j['key']] = ['indices' => $indices, 'staff' => $staff, 'vehicles' => $people ? $vehicles : 0, 'drivers' => $people ? max($j['minimum_drivers'], $vehicles) : $j['minimum_drivers']];
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
        // A paired task may only take a unit that forms an allowed combination with its partner's chosen unit.
        $paired = static function (int $i, int $id) use (&$chosen, $tasks): bool {
            $t = $tasks[$i];
            if (!isset($t['partner']) || !isset($chosen[$t['partner']])) return true;
            $other = $chosen[$t['partner']];
            return isset($t['pairs'][$t['own'] ? $id.':'.$other : $other.':'.$id]);
        };
        $search = function () use (&$search, &$chosen, &$busy, &$states, &$exhausted, $tasks, $groups, $units, $free, $paired, $deadline): bool {
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
                $available = array_values(array_filter($task['eligible'], fn ($id) => $free($task, $id) && $paired($i, $id)));
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
