<?php
namespace Tests\Feature;

use App\Models\ServiceVariant;
use App\Support\PackageName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageNameTest extends TestCase
{
    use RefreshDatabase;

    // Owner 2026-11-20: clients see packages in their language, English by default; Spanish clients keep the owner's name.
    public function test_english_by_default_spanish_for_spanish_clients_and_falls_back_when_missing(): void
    {
        ServiceVariant::create(['name' => 'Música y Juegos', 'name_en' => 'Music & Games', 'status' => 'active', 'duration_minutes' => 120, 'requirements' => []]);
        ServiceVariant::create(['name' => 'Sin traducción', 'status' => 'active', 'duration_minutes' => 60, 'requirements' => []]);
        $this->assertSame('Music & Games', PackageName::for('Música y Juegos', 'en'));
        $this->assertSame('Music & Games', PackageName::for('Música y Juegos', null));
        $this->assertSame('Música y Juegos', PackageName::for('Música y Juegos', 'es'));
        $this->assertSame('Sin traducción', PackageName::for('Sin traducción', 'en'));
    }
}
