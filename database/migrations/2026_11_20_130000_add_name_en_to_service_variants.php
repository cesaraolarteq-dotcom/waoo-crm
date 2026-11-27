<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-20: English package names (approved list) for clients who use English, the default, and for the contract.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->string('name_en')->nullable()->after('name'));
        $map = [
            'Estrella (Star)' => 'Star Package', 'Mágico (Magic)' => 'Magic Package', 'Música y Juegos' => 'Music & Games',
            'Música y Juegos 2.0' => 'Music & Games 2.0', 'Waoo' => 'Waoo Package', 'Waoo sin personaje' => 'Waoo Package without character',
            'Personajes sin show' => 'Characters Only', 'Personajes sin show · 3 personajes' => 'Characters Only · 3 characters',
            'Personajes sin show · 4 personajes' => 'Characters Only · 4 characters', 'Pool Party' => 'Pool Party', 'Pool Party 2.0' => 'Pool Party 2.0',
            'Pool Party + Cañón de espuma' => 'Pool Party + Foam Cannon', 'Baby Shower o Gender Reveal' => 'Baby Shower or Gender Reveal',
            'Glam Glitter Bar' => 'Glam Glitter Bar', 'Glitter Mobile' => 'Glitter Mobile', 'Photo Booth' => 'Photo Booth',
            'Arte en lienzo' => 'Art on Canvas Station', 'Mini Chef' => 'Mini Chef Station', 'Mural creativo' => 'Creative Mural Station',
            'Estación de slime' => 'Slime Station', 'Decorar lentes de sol' => 'Sunglasses Decorating', 'Estación didáctica' => 'Learning Station',
            'Estación de personalización' => 'Customization Station', 'Spa Premium' => 'Premium Spa',
            'Estación de pintacaritas' => 'Face Painting Station', 'Estación de pintacaritas · 3 h' => 'Face Painting Station · 3 h',
            'Estación de pintacaritas · 4 h' => 'Face Painting Station · 4 h', 'Pintacaritas agregado a un paquete' => 'Face Painting Add-on',
            'Pintacaritas agregado a un paquete · 1 h' => 'Face Painting Add-on · 1 h', 'Cañón de espuma' => 'Foam Cannon',
            'Show de Robot LED bajo' => 'Low LED Robot Show', 'Show de Robot LED alto' => 'Tall LED Robot Show',
            'Pistola de humo + Robot bajo' => 'Smoke Gun + Low Robot Combo', 'Pistola de humo + Robot alto' => 'Smoke Gun + Tall Robot Combo',
            'Personajes inflables y bolas disco' => 'Inflatable Characters + Disco Balls', '2 chisperos' => '2 Cold Sparklers', '4 chisperos' => '4 Cold Sparklers',
            'Baile en las nubes' => 'Dancing on the Clouds', 'Baile en las nubes + 4 chisperos' => 'Dancing on the Clouds + 4 Cold Sparklers',
            'Recreadora extra' => 'Extra Party Host', 'Recreadora extra · 30 min' => 'Extra Party Host · 30 min', 'Recreadora extra · 45 min' => 'Extra Party Host · 45 min',
            'Recreadora extra · 2 h' => 'Extra Party Host · 2 h', 'Recreadora extra · 3 h' => 'Extra Party Host · 3 h',
            'Hora extra' => 'Extra Hour', 'Hora extra · Pool Party' => 'Extra Hour · Pool Party', '30 min extra' => '30 Extra Minutes',
        ];
        foreach ($map as $es => $en) DB::table('service_variants')->where('name', $es)->whereNull('name_en')->update(['name_en' => $en]);
    }

    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->dropColumn('name_en'));
    }
};
