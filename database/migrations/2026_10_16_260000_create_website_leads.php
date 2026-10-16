<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_submissions', function (Blueprint $t) {
            $t->id(); $t->uuid('request_key')->unique(); $t->char('payload_sha256', 64);
            $t->json('payload'); $t->dateTime('submitted_at'); $t->dateTime('received_at'); $t->timestamps();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->foreignId('lead_submission_id')->unique()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('new'); $t->unsignedInteger('version')->default(1); $t->text('owner_note')->nullable();
            $t->foreignId('booked_event_id')->nullable()->constrained('events')->restrictOnDelete();
            $t->foreignId('quick_booking_id')->nullable()->unique()->constrained('quick_bookings')->restrictOnDelete();
            $t->timestamps(); $t->index(['status', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('leads'); Schema::dropIfExists('lead_submissions'); }
};
