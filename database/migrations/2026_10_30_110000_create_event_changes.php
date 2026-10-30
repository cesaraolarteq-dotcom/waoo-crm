<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CHANGE-A reschedule (César 2026-10-30): one row per date change, kept forever.
    public function up(): void
    {
        Schema::create('event_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20); // reschedule
            $table->string('state', 30); // pending_signature | completed | void
            $table->timestamp('old_starts_at'); $table->timestamp('old_ends_at');
            $table->timestamp('new_starts_at'); $table->timestamp('new_ends_at');
            $table->unsignedInteger('event_version_before'); $table->unsignedInteger('event_version_after');
            $table->string('fee_decision', 10); // charge | waive
            $table->string('fee_reason', 1000);
            $table->unsignedInteger('fee_cents')->default(0);
            $table->unsignedInteger('moved_reservations')->default(0);
            $table->foreignId('financial_revision_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('contract_id')->nullable();
            $table->timestamp('notified_at')->nullable(); $table->timestamp('reminded_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_changes');
    }
};
