<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quick_bookings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('initial_financial_revision_id')->constrained('financial_revisions')->restrictOnDelete();
            $t->foreignId('receipt_id')->constrained('ledger_entries')->restrictOnDelete();
            $t->foreignId('portal_grant_id')->nullable()->constrained('portal_grants')->restrictOnDelete();
            $t->dateTime('portal_expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('client_details_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision'); $t->unsignedInteger('event_version');
            $t->json('data'); $t->json('base_customer_snapshot');
            $t->string('ip', 45)->nullable(); $t->text('user_agent')->nullable();
            $t->dateTime('submitted_at'); $t->timestamps();
            $t->unique(['event_id', 'revision']);
        });
        Schema::create('client_details_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->unique()->constrained('client_details_submissions')->restrictOnDelete();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('event_version');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->text('review_note'); $t->boolean('travel_fee_confirmed');
            $t->string('requested_start_resolution', 30)->nullable();
            $t->dateTime('reviewed_at'); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_details_reviews');
        Schema::dropIfExists('client_details_submissions');
        Schema::dropIfExists('quick_bookings');
    }
};
