<?php
namespace Tests\Feature;

use App\Filament\Resources\Events\EventResource;
use App\Models\{PriceVersion, ServiceVariant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-04: the extra recreadora has no minimum; she stays the whole package at $70 per hour. */
class SameLengthExtraTest extends TestCase
{
    use RefreshDatabase;

    public function test_extra_recreadora_follows_the_package_length(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $price = fn (string $name, int $min, int $cents) => (string) PriceVersion::create(['service_variant_id' => ServiceVariant::create(['name' => $name, 'status' => 'active', 'duration_minutes' => $min, 'requirements' => []])->id,
            'amount_cents' => $cents, 'currency' => 'USD', 'created_by' => $owner->id])->id;
        $one = $price('Recreadora extra', 60, 7000); $two = $price('Recreadora extra · 2 h', 120, 14000);
        $mj = $price('Música y Juegos', 120, 52500); $magic = $price('Mágico (Magic)', 60, 37500); $star = $price('Estrella (Star)', 30, 27500);
        $photo = $price('Photo Booth', 180, 42000);
        $this->assertSame([$two, $photo], EventResource::matchPackageDuration($mj, [$one, $photo]));
        $this->assertSame([$one], EventResource::matchPackageDuration($magic, [$two]));
        $this->assertSame([$one], EventResource::matchPackageDuration($star, [$one]), 'No 30-minute version: the chosen one stays.');
        $this->assertSame([$one], EventResource::matchPackageDuration(null, [$one]));
    }

    public function test_duration_suggestion_does_not_choose_an_arbitrary_price_from_two_active_variants(): void
    {
        $owner = User::factory()->create(['role' => 'owner_admin', 'active' => true]);
        $prices = [];
        foreach ([['Música y Juegos', 120, 52500], ['Recreadora extra', 60, 7000], ['Recreadora extra · 2 h A', 120, 14000], ['Recreadora extra · 2 h B', 120, 15000]] as [$name, $duration, $amount]) {
            $v = ServiceVariant::create(['name' => $name, 'status' => 'active', 'duration_minutes' => $duration, 'requirements' => []]);
            $prices[] = (string) PriceVersion::create(['service_variant_id' => $v->id, 'amount_cents' => $amount, 'currency' => 'USD', 'created_by' => $owner->id])->id;
        }
        $this->assertSame([$prices[1]], EventResource::matchPackageDuration($prices[0], [$prices[1]]));
        $this->assertSame([$prices[2]], EventResource::matchPackageDuration($prices[0], [$prices[2]]), 'an explicitly chosen matching version is kept');
    }
}
