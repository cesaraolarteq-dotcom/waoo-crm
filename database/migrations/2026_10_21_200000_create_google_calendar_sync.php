<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-21: two-way Google Calendar like HoneyBook. CRM parties go to a separate "Waoo Party fiestas" calendar;
// the owner's primary calendar is read only as busy times (free/busy, never event details).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('google_email')->nullable();
            $t->text('refresh_token');
            $t->text('access_token')->nullable();
            $t->dateTime('access_expires_at')->nullable();
            $t->string('calendar_id')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('event_google_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('google_event_id')->nullable();
            $t->char('synced_hash', 64)->nullable();
            $t->dateTime('synced_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_google_links');
        Schema::dropIfExists('google_calendar_connections');
    }
};
