<?php
namespace Tests\Feature;

use App\Models\{Character, Customer, Event, EventService, PriceVersion, Resource, ServiceVariant, User};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use DatabaseMigrations;

    private function owner(array $attributes = []): User
    {
        return User::factory()->create([...['name' => 'Fictitious Owner', 'role' => 'owner_admin', 'active' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()], ...$attributes]);
    }

    private function fixtures(User $owner): void
    {
        $customer = Customer::create(['name' => 'Fictitious Client', 'phone' => '555-0100', 'email' => 'client@example.test', 'locale' => 'en']);
        $hero = Character::create(['name' => 'Fictitious Hero', 'active' => true]);
        Resource::create(['code' => 'COS-HERO-1', 'name' => 'Hero costume 1', 'kind' => 'costume', 'status' => 'active', 'character_id' => $hero->id, 'skills' => []]);
        Resource::create(['code' => 'COS-HERO-2', 'name' => 'Hero costume 2', 'kind' => 'costume', 'status' => 'maintenance', 'character_id' => $hero->id, 'skills' => []]);
        Resource::create(['code' => 'STF-1', 'name' => 'Fictitious Performer', 'kind' => 'staff', 'status' => 'active', 'skills' => ['performer', 'driver']]);
        Resource::create(['code' => 'SPK-1', 'name' => 'Speaker 1', 'kind' => 'equipment', 'status' => 'active', 'equipment_kind' => 'speaker', 'skills' => []]);
        $variant = ServiceVariant::create(['name' => 'Fictitious Package', 'status' => 'active', 'duration_minutes' => 90, 'requirements' => [['kind' => 'staff', 'quantity' => 1, 'skill' => 'performer'], ['kind' => 'equipment', 'quantity' => 1, 'equipment_kind' => 'speaker']]]);
        PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 11100, 'currency' => 'USD', 'created_by' => $owner->id]);
        $price = PriceVersion::create(['service_variant_id' => $variant->id, 'amount_cents' => 12300, 'currency' => 'USD', 'created_by' => $owner->id]);
        $event = Event::create(['customer_id' => $customer->id, 'title' => 'Fictitious Party', 'starts_at' => now()->addDays(20)->setTime(20, 0), 'ends_at' => now()->addDays(20)->setTime(21, 30), 'timezone' => 'America/Chicago', 'postal_code' => '00001', 'travel_fee_cents' => null]);
        EventService::create(['event_id' => $event->id, 'service_variant_id' => $variant->id, 'price_version_id' => $price->id, 'requirements_snapshot' => $variant->requirements, 'duration_minutes' => 90, 'operational_ready' => true]);
    }

    public function test_owner_sees_every_list_in_spanish_with_real_values(): void
    {
        $owner = $this->owner();
        $this->fixtures($owner);
        $this->actingAs($owner);

        $this->get('/admin')->assertOk()->assertSee('Clientas')->assertSee('Catálogo')->assertSee('Staff y trajes')->assertSee('Personajes')->assertSee('Eventos');
        $this->get('/admin/clientas')->assertOk()->assertSee('Fictitious Client')->assertSee('Inglés');
        $this->get('/admin/catalogo')->assertOk()->assertSee('Fictitious Package')->assertSee('$123')->assertDontSee('$111')
            ->assertSee('1 h 30 min')->assertSee('1 personaje · 1 bocina')->assertSee('+ costo de transporte');
        $this->get('/admin/staff-y-trajes')->assertOk()->assertSee('Fictitious Performer')->assertSee('Personaje, Conductor/a')
            ->assertSee('En mantenimiento')->assertSee('Trajes');
        $this->get('/admin/personajes')->assertOk()->assertSee('Fictitious Hero')->assertSee('1 de 2');
        // Unknown travel fee is "Pendiente", never $0, and the total waits for it.
        $this->get('/admin/eventos')->assertOk()->assertSee('Fictitious Party')->assertSee('Pendiente')->assertSee('Falta transporte')->assertSee('Borrador')->assertDontSee('$0');
    }

    public function test_lists_are_read_only_for_now(): void
    {
        $this->actingAs($this->owner());
        foreach (['clientas', 'catalogo', 'staff-y-trajes', 'personajes', 'eventos'] as $slug) {
            $this->get("/admin/{$slug}")->assertOk();
            $this->get("/admin/{$slug}/create")->assertNotFound();
        }
    }

    public function test_empty_lists_explain_what_comes_next(): void
    {
        $this->actingAs($this->owner());
        $this->get('/admin/catalogo')->assertSee('No se inventa ningún precio');
        $this->get('/admin/eventos')->assertSee('Todavía no hay eventos');
    }

    public function test_staff_and_unenrolled_users_cannot_open_the_lists(): void
    {
        foreach (['clientas', 'catalogo', 'staff-y-trajes', 'personajes', 'eventos'] as $slug) {
            $this->actingAs($this->owner(['role' => 'staff']))->get("/admin/{$slug}")->assertForbidden();
            $this->actingAs($this->owner(['two_factor_confirmed_at' => null]))->get("/admin/{$slug}")->assertRedirect(route('security.two-factor'));
        }
        auth()->logout();
        $this->get('/admin/clientas')->assertRedirect('/login');
    }
}
