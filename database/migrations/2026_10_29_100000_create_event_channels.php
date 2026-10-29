<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-28: how the client found Waoo (Instagram, WhatsApp, Facebook, web, other), chosen with one tap when booking. Feeds Reportes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $t->string('channel', 20);
            $t->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_channels');
    }
};
