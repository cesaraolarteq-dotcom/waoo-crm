<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-28: when a website lead is lost, say why (date full, price, no reply, other vendor, other). Feeds Reportes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_loss_reasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->unique()->constrained('leads')->cascadeOnDelete();
            $t->string('reason', 20);
            $t->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_loss_reasons');
    }
};
