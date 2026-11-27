<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-26: quote forms left halfway (name + phone typed, never sent). Kept 60 days (privacy policy).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_drafts', function (Blueprint $t) {
            $t->id();
            $t->uuid('draft_key')->unique();
            $t->string('name', 255);
            $t->string('phone_digits', 10)->index();
            $t->string('email', 255)->nullable();
            $t->json('fields');
            $t->foreignId('completed_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_drafts');
    }
};
