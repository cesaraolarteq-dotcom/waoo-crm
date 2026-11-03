<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-03: a package can have its own price per additional character (Personajes sin show: $200); empty = the
// general one ($150). The booked amount is still snapshotted on event_services.extra_character_cents as before.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->unsignedInteger('extra_character_cents')->nullable());
    }

    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->dropColumn('extra_character_cents'));
    }
};
