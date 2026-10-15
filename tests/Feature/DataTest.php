<?php
namespace Tests\Feature;

use App\Models\{User, Customer, Event, Resource};
use Database\Seeders\{DatabaseSeeder, DemoSeeder};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataTest extends TestCase
{
    use DatabaseMigrations;

    public function test_demo_is_opt_in_repeatable_and_does_not_invent_accounts_or_prices(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 0);
        $this->seed(DemoSeeder::class); $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('resources', 7);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('price_versions', 0);
        $this->assertDatabaseCount('booking_evidence', 0);
        $this->assertDatabaseHas('service_variants', ['status' => 'draft']);
    }

    public function test_resource_identity_is_unique_and_all_core_tables_use_innodb(): void
    {
        $tables=DB::select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
        $this->assertNotEmpty($tables);
        foreach ($tables as $table) $this->assertSame('InnoDB', $table->ENGINE);
        $data=['code'=>'TEST-UNIT','name'=>'Fictitious unit','kind'=>'equipment','equipment_kind'=>'speaker','skills'=>[]];
        Resource::create($data);
        $this->expectException(QueryException::class);
        Resource::create($data);
    }

    public function test_database_preserves_referenced_customer_instead_of_erasing_history(): void
    {
        $customer=Customer::create(['name'=>'Fictitious client','locale'=>'es']);
        Event::create(['customer_id'=>$customer->id,'title'=>'Fictitious draft','starts_at'=>now()->addDays(14),'ends_at'=>now()->addDays(14)->addHour(),'timezone'=>'America/Chicago']);
        $this->expectException(QueryException::class);
        $customer->delete();
    }

    public function test_filament_permission_does_not_depend_on_local_environment_or_hidden_ui(): void
    {
        $user=User::factory()->create(['role'=>'owner_admin','active'=>true]);
        $panel=\Filament\Panel::make()->id('admin');
        $this->assertFalse($user->canAccessPanel($panel));
        $user->two_factor_secret=encrypt('JBSWY3DPEHPK3PXP');$user->two_factor_confirmed_at=now();
        $this->assertTrue($user->canAccessPanel($panel));
        $this->assertFalse($user->canAccessPanel(\Filament\Panel::make()->id('other')));
        $user->role='staff';$this->assertFalse($user->canAccessPanel($panel));
    }
}
