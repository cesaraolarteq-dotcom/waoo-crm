<?php
namespace Tests\Unit;

use App\Services\Availability\PoolCapacitySolver;
use PHPUnit\Framework\TestCase;

final class PoolCapacitySolverTest extends TestCase
{
    private function staff(int $id, bool $driver = false): array { return ['id' => $id, 'kind' => 'staff', 'driver' => $driver]; }
    private function slot(string $key, array $eligible, string $kind = 'staff'): array { return compact('key', 'eligible', 'kind'); }
    private function job(string $key, array $slots, int $start = 1000, int $end = 2000, int $drivers = 0): array
    { return ['key' => $key, 'starts_at' => $start, 'ends_at' => $end, 'slots' => $slots, 'minimum_drivers' => $drivers]; }

    public function test_one_multiskilled_person_cannot_cover_two_simultaneous_roles(): void
    {
        $j = $this->job('party', [$this->slot('animador', [1]), $this->slot('recreador', [1])]);
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve([$this->staff(1)], [$j])['state']);
    }
    public function test_flexible_person_is_preserved_for_the_specialist_role_when_needed(): void
    {
        $j = $this->job('party', [$this->slot('recreador', [1, 2]), $this->slot('pintacaritas', [1])]);
        $r = (new PoolCapacitySolver)->solve([$this->staff(1), $this->staff(2)], [$j]);
        $this->assertSame('feasible', $r['state']);
        $this->assertSame(1, $r['witness']['party']['pintacaritas']);
        $this->assertSame(2, $r['witness']['party']['recreador']);
    }
    public function test_driver_is_a_member_of_the_selected_team_not_an_extra_person(): void
    {
        $j = $this->job('party', [$this->slot('animador', [1, 2]), $this->slot('recreador', [2, 3])], drivers: 1);
        $r = (new PoolCapacitySolver)->solve([$this->staff(1, true), $this->staff(2), $this->staff(3)], [$j]);
        $this->assertSame('feasible', $r['state']); $this->assertCount(2, $r['witness']['party']);
        $this->assertContains(1, $r['witness']['party']);
    }
    public function test_one_driver_cannot_count_for_two_overlapping_teams(): void
    {
        $jobs = [$this->job('a', [$this->slot('role', [1, 2])], drivers: 1), $this->job('b', [$this->slot('role', [1, 2])], drivers: 1)];
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve([$this->staff(1, true), $this->staff(2)], $jobs)['state']);
    }
    public function test_same_physical_pool_covers_sequential_jobs_but_not_overlapping_jobs(): void
    {
        $r = [['id' => 1, 'kind' => 'equipment']];
        $a = $this->job('a', [$this->slot('maleta', [1], 'equipment')], 1000, 2000);
        $b = $this->job('b', [$this->slot('maleta', [1], 'equipment')], 2000, 3000);
        $this->assertSame('feasible', (new PoolCapacitySolver)->solve($r, [$a, $b])['state']);
        $b['starts_at'] = 1999;
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve($r, [$a, $b])['state']);
    }
    public function test_owner_three_party_example_needs_nine_distinct_people_and_two_face_paint_kits(): void
    {
        $resources = [];
        for ($id = 1; $id <= 9; $id++) $resources[] = $this->staff($id, $id <= 3);
        $resources[] = ['id' => 10, 'kind' => 'equipment']; $resources[] = ['id' => 11, 'kind' => 'equipment'];
        $jobs = [];
        foreach (['waoo1', 'waoo2', 'music'] as $key) {
            $slots = [$this->slot('animador', [1, 2, 3]), $this->slot('recreador', [4, 5, 6, 7, 8, 9]),
                $this->slot('painter_or_host', $key === 'music' ? [4, 5, 6, 7, 8, 9] : [4, 5])];
            if ($key !== 'music') $slots[] = $this->slot('maleta', [10, 11], 'equipment');
            $jobs[] = $this->job($key, $slots, drivers: 1);
        }
        $solver = new PoolCapacitySolver;
        $r = $solver->solve($resources, $jobs);
        $this->assertSame('feasible', $r['state']);
        $used = array_merge(...array_map('array_values', array_values($r['witness']))); $this->assertCount(11, array_unique($used));
        $jobs[] = $this->job('addon', [$this->slot('maleta', [10, 11], 'equipment')]);
        $this->assertSame('infeasible', $solver->solve($resources, $jobs)['state']);
    }
    public function test_active_time_off_blocks_a_person_without_changing_inventory(): void
    {
        $units = [$this->staff(1)]; $jobs = [$this->job('a', [$this->slot('host', [1])])];
        $blocks = [['resource_id' => 1, 'starts_at' => 1500, 'ends_at' => 1600]];
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve($units, $jobs, $blocks)['state']);
        $this->assertSame([['id' => 1, 'kind' => 'staff', 'driver' => false]], $units);
    }
    public function test_joint_vehicle_seats_and_driver_requirements_are_enforced(): void
    {
        $units = [$this->staff(1, true), $this->staff(2), ['id' => 3, 'kind' => 'vehicle', 'seats' => 1]];
        $job = $this->job('a', [$this->slot('host', [1]), $this->slot('helper', [2]), $this->slot('car', [3], 'vehicle')]);
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve($units, [$job])['state']);
        $units[2]['seats'] = 2;
        $this->assertSame('feasible', (new PoolCapacitySolver)->solve($units, [$job])['state']);
    }
    public function test_fixed_existing_assignments_are_not_silently_moved_to_make_room(): void
    {
        $units = [$this->staff(1), $this->staff(2)];
        $jobs = [$this->job('booked', [$this->slot('host', [1])]), $this->job('candidate', [$this->slot('painter', [1])])];
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve($units, $jobs)['state']);
    }
    public function test_budget_exhaustion_is_unknown_and_solver_can_be_reused_after_failure(): void
    {
        $job = $this->job('a', [$this->slot('role', [1])]);
        $r = (new PoolCapacitySolver(1))->solve([$this->staff(1)], [$job]);
        $this->assertSame('unknown', $r['state']); $this->assertSame('evaluation_budget', $r['reason']);
        $solver = new PoolCapacitySolver;
        $this->assertSame('infeasible', $solver->solve([], [$this->job('b', [$this->slot('role', [])])])['state']);
        $this->assertSame('feasible', $solver->solve([$this->staff(1)], [$job])['state']);
    }
    public function test_incomplete_or_duplicated_inventory_never_becomes_zero_capacity(): void
    {
        $job = $this->job('a', [$this->slot('role', [1])]);
        $solver = new PoolCapacitySolver;
        $this->assertSame('unknown', $solver->solve([['id' => 1, 'kind' => 'staff']], [$job])['state']);
        $this->assertSame('unknown', $solver->solve([$this->staff(1), $this->staff(1)], [$job])['state']);
        $this->assertSame('unknown', $solver->solve([$this->staff(1)], [$job, $job])['state']);
    }
    public function test_continuous_party_cannot_swap_people_at_the_midpoint_to_hide_a_conflict(): void
    {
        $units = [$this->staff(1), $this->staff(2)];
        $first = $this->job('first', [$this->slot('specialist_a', [1])], 1000, 2000);
        $second = $this->job('second', [$this->slot('specialist_b', [2])], 2000, 3000);
        $candidate = $this->job('candidate', [$this->slot('host', [1, 2])], 1000, 3000);
        $solver = new PoolCapacitySolver;
        // Separate instantaneous checks pass, but nobody can cover the complete candidate party.
        $this->assertSame('feasible', $solver->solve($units, [$first, $candidate])['state']);
        $this->assertSame('feasible', $solver->solve($units, [$second, $candidate])['state']);
        $this->assertSame('infeasible', $solver->solve($units, [$first, $second, $candidate])['state']);
    }

    public function test_two_overlapping_blocks_on_one_unit_do_not_remove_a_second_unit(): void
    {
        $units = [['id' => 1, 'kind' => 'equipment'], ['id' => 2, 'kind' => 'equipment']];
        $job = $this->job('candidate', [$this->slot('speaker', [1, 2], 'equipment')]);
        $blocks = [['resource_id' => 1, 'starts_at' => 1000, 'ends_at' => 1700],
            ['resource_id' => 1, 'starts_at' => 1500, 'ends_at' => 2000]];
        $r = (new PoolCapacitySolver)->solve($units, [$job], $blocks);
        $this->assertSame('feasible', $r['state']);
        $this->assertSame(2, $r['witness']['candidate']['speaker']);
    }

    public function test_required_team_is_matched_jointly_instead_of_binding_pins_to_role_order(): void
    {
        $job = $this->job('party', [$this->slot('host', [1, 2, 3]), $this->slot('painter', [1])]);
        $job['required'] = [1, 2];
        $r = (new PoolCapacitySolver)->solve([$this->staff(1, true), $this->staff(2), $this->staff(3)], [$job]);
        $this->assertSame('feasible', $r['state']);
        $this->assertSame(2, $r['witness']['party']['host']);
        $this->assertSame(1, $r['witness']['party']['painter']);
        $job['required'] = [1, 2, 3];
        $this->assertSame('infeasible', (new PoolCapacitySolver)->solve([$this->staff(1), $this->staff(2), $this->staff(3)], [$job])['state']);
    }

    public function test_unproven_transfer_cannot_reuse_a_unit_even_when_party_times_do_not_overlap(): void
    {
        $a = $this->job('a', [$this->slot('host', [1, 2])], 1000, 2000);
        $b = $this->job('b', [$this->slot('host', [1, 2])], 9000, 10000);
        $edges = [['first' => 'a', 'second' => 'b', 'resources' => [1, 2]]];
        $solver = new PoolCapacitySolver;
        $r = $solver->solve([$this->staff(1), $this->staff(2)], [$a, $b], [], $edges);
        $this->assertSame('feasible', $r['state']);
        $this->assertNotSame($r['witness']['a']['host'], $r['witness']['b']['host']);
        $a['required'] = $b['required'] = [1];
        $this->assertSame('infeasible', $solver->solve([$this->staff(1), $this->staff(2)], [$a, $b], [], $edges)['state']);
        $this->assertSame('feasible', $solver->solve([$this->staff(1), $this->staff(2)], [$a, $b])['state']);
        $edges[0]['second'] = 'missing';
        $this->assertSame('unknown', $solver->solve([$this->staff(1), $this->staff(2)], [$a, $b], [], $edges)['state']);
    }

}
