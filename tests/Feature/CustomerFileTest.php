<?php
namespace Tests\Feature;

use App\Models\{Customer, Event, User};
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class CustomerFileTest extends TestCase
{
    use DatabaseMigrations;

    public function test_client_file_lists_upcoming_and_past_parties(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]));
        $c = Customer::create(['name' => 'Ana Ficticia', 'phone' => '555-0100', 'email' => 'ana@example.test', 'locale' => 'en']);
        foreach ([['Fiesta 2025', -300], ['Fiesta 2026', 20]] as [$t, $d]) {
            $s = CarbonImmutable::now('America/Chicago')->addDays($d)->setTime(15, 0);
            Event::create(['customer_id' => $c->id, 'title' => $t, 'event_type' => 'birthday', 'starts_at' => $s->utc(), 'ends_at' => $s->addHour()->utc(), 'timezone' => 'America/Chicago']);
        }
        $this->get('/admin/clientas')->assertSee("/admin/clientas/{$c->id}", false);
        $this->get("/admin/clientas/{$c->id}")->assertOk()->assertSee('Ana Ficticia')->assertSee('555-0100')->assertSee('Inglés')
            ->assertSee('Próximas fiestas')->assertSee('Fiesta 2026')->assertSee('Fiestas anteriores')->assertSee('Fiesta 2025')->assertSee('Cumpleaños');
    }
    public function test_provisional_customer_without_name_has_a_working_owner_file(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'owner_admin','active'=>true,'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_confirmed_at'=>now()]));
        $customer=Customer::create(['name'=>null,'phone'=>null,'email'=>null,'locale'=>'es']);
        try {
            $this->get('/admin/clientas/'.$customer->id)->assertOk()->assertSee('Cliente por completar');
            $this->assertNull($customer->fresh()->name);
        } finally {
            // Remove only this unlinked synthetic fixture before the guarded schema rollback.
            $customer->delete();
        }
    }

}
