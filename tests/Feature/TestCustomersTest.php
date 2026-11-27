<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, LedgerEntry};
use App\Support\TestCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Owner 2026-11-23: his own test client stays out of reports (parties now and future, and their payments).
class TestCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclusion_keeps_rows_without_a_party_and_drops_only_the_test_client(): void
    {
        $real = Customer::create(['name' => 'María González', 'locale' => 'es']);
        $test = Customer::create(['name' => 'César Prueba', 'locale' => 'es']);
        $test->forceFill(['is_test' => true])->save();
        $e1 = new Event(); $e1->forceFill(['customer_id' => $real->id, 'title' => 'Fiesta', 'status' => 'draft', 'starts_at' => now(), 'ends_at' => now()->addHour()])->save();
        $e2 = new Event(); $e2->forceFill(['customer_id' => $test->id, 'title' => 'Prueba', 'status' => 'draft', 'starts_at' => now(), 'ends_at' => now()->addHour()])->save();
        $this->assertSame([$test->id], TestCustomers::ids());
        $this->assertSame([$e2->id], TestCustomers::eventIds());
        $kept = Event::where(fn ($q) => TestCustomers::exclude($q, 'customer_id', TestCustomers::ids()))->pluck('id')->all();
        $this->assertSame([$e1->id], $kept);
        $this->assertSame(0, Event::where(fn ($q) => TestCustomers::exclude($q, 'id', []))->whereNotIn('id', [$e1->id, $e2->id])->count());
    }
}
