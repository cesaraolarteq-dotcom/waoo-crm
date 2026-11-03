<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/** Owner 2026-11-02: who works each party, chosen the week of the party ("Planear el fin de semana"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_team_plans', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->json('slots');            // [{key,label,skill,resource_id}]
            $t->json('costumes');         // [{key,character_id,unit_id,wearer_id}]
            $t->foreignId('driver_resource_id')->nullable()->constrained('resources')->restrictOnDelete();
            $t->boolean('external_driver')->default(false);
            $t->json('reservation_ids');  // reservations this plan created (replaced on every save)
            $t->char('payload_sha256', 64);
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('event_team_plans')->exists()) throw new RuntimeException('Team plans exist; use a forward migration.');
        Schema::dropIfExists('event_team_plans');
    }
};
