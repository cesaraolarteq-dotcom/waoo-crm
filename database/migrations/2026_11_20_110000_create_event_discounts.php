<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-20: a custom discount the owner gives in Reserva rápida ($ off or % off packages and add-ons, never travel).
// One row per event, kept in every financial revision of that booking, like the anniversary code.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_discounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->string('kind', 10); $t->unsignedInteger('value'); // fixed: cents · percent: 1–100
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_discounts');
    }
};
