<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// EXTRAS-01 (owner 2026-11-02/03): the client adds catalog extras or an additional character from her link, up to 7 days
// before, with a checkbox annex. Final and non-refundable; added to the balance; runs at the same time as the service.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->boolean('offered_as_extra')->default(false));
        Schema::create('event_extras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('event_service_id')->constrained('event_services')->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained('portal_grants')->restrictOnDelete();
            $t->string('kind', 20);                 // service | character
            $t->foreignId('service_variant_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('price_version_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('character_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('amount_cents');
            $t->string('locale', 2);
            $t->text('annex_text');                 // exactly what the client read
            $t->char('annex_sha256', 64);
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 500);
            $t->timestamp('accepted_at');
            $t->timestamps();
        });
        // César's list (2026-11-02): only these are offered at first; he can change it in Catálogo.
        DB::table('service_variants')->whereIn('name', ['Pintacaritas agregado a un paquete', 'Cañón de espuma', 'Photo Booth', 'Glitter Mobile', 'Estación de slime',
            '2 chisperos', '4 chisperos', 'Personajes inflables y bolas disco'])->update(['offered_as_extra' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_extras');
        Schema::table('service_variants', fn (Blueprint $t) => $t->dropColumn('offered_as_extra'));
    }
};
