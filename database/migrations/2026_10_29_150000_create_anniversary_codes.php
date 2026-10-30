<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ANNIV-01 (owner spec 2026-10-15, design 2026-10-29): one row per client and anchor party, issued or skipped.
    public function up(): void
    {
        Schema::create('anniversary_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('anchor_key', 40);
            $table->date('anchor_date');
            $table->string('code', 16)->nullable()->unique();
            $table->string('state', 20); // ready (mail off) | sent | redeemed | skipped
            $table->string('skip_reason', 40)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->foreignId('redeemed_event_id')->nullable()->unique()->constrained('events')->restrictOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'anchor_key']);
            $table->index(['state', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anniversary_codes');
    }
};
