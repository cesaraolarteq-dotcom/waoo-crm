<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-29: cancel a party (deposit stays, nothing refunded automatically) and, when the owner decides,
// refund by the original method: card back through Stripe, Zelle sent back by the owner and recorded here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_cancellations', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->string('prior_status', 30); $t->text('reason');
            $t->unsignedInteger('released_reservations')->default(0);
            $t->foreignId('cancelled_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $t) {
            $t->id(); $t->uuid('request_key')->unique();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('receipt_id')->constrained('ledger_entries')->restrictOnDelete();
            $t->string('method', 20); $t->unsignedBigInteger('amount_cents'); $t->text('reason');
            // requested -> succeeded | failed | unknown (Stripe answer lost; retry with the same key)
            $t->string('state', 20)->index(); $t->string('provider_refund_id', 255)->nullable()->unique();
            $t->string('error_code', 100)->nullable();
            $t->foreignId('ledger_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['receipt_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests'); Schema::dropIfExists('event_cancellations');
    }
};
