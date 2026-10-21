<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quick_payment_bookings', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('quick_stripe_lease_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $t->foreignId('quick_booking_id')->nullable()->constrained('quick_bookings')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(0); $t->string('state', 30); $t->longText('agreement')->nullable();
            $t->char('agreement_sha256', 64)->nullable(); $t->char('head_sha256', 64)->nullable();
            $t->dateTime('expires_at')->index(); $t->dateTime('reminder_at')->index(); $t->timestamps();
        });
        Schema::create('quick_payment_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('quick_payment_booking_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version'); $t->json('payload'); $t->char('sha256', 64); $t->timestamps();
            $t->unique(['quick_payment_booking_id', 'version'], 'quick_payment_event_version');
        });
        Schema::table('quick_stripe_checkout_requests', function (Blueprint $t) {
            $t->index('quick_stripe_lease_id', 'quick_stripe_request_lease'); $t->dropUnique(['quick_stripe_lease_id']);
        });
        Schema::create('quick_payment_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreign('id')->references('id')->on('quick_stripe_checkout_requests')->restrictOnDelete();
            $t->foreignId('quick_payment_booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $t->uuid('payment_intent_id')->unique(); $t->foreign('payment_intent_id')->references('id')->on('payment_intents')->restrictOnDelete();
            $t->unsignedInteger('booking_version'); $t->char('request_sha256', 64); $t->timestamps();
        });
        Schema::create('quick_payment_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('quick_payment_booking_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id'); $t->foreign('request_id')->references('id')->on('quick_payment_attempts')->restrictOnDelete();
            $t->foreignId('ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('fee_entry_id')->nullable()->unique()->constrained('ledger_entries')->restrictOnDelete();
            $t->string('external_identity_key', 100)->unique(); $t->string('webhook_id', 255);
            $t->unsignedBigInteger('principal_cents'); $t->unsignedBigInteger('customer_fee_cents'); $t->unsignedBigInteger('gross_cents');
            $t->longText('verification'); $t->char('verification_sha256', 64); $t->timestamps();
        });
        Schema::create('quick_payment_notifications', function (Blueprint $t) {
            $t->id(); $t->foreignId('quick_payment_booking_id')->constrained()->restrictOnDelete();
            $t->char('identity_sha256', 64)->unique(); $t->string('kind', 30); $t->string('status', 30);
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->dateTime('due_at');
            $t->string('error_code', 80)->nullable(); $t->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('quick_payment_bookings')->exists()) throw new LogicException('Preserve quick payment evidence; use a forward migration.');
        if (DB::table('quick_stripe_checkout_requests')->select('quick_stripe_lease_id')->groupBy('quick_stripe_lease_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Cannot collapse historical checkout requests.');
        }
        foreach (['quick_payment_notifications', 'quick_payment_receipts', 'quick_payment_attempts', 'quick_payment_events', 'quick_payment_bookings'] as $table) Schema::dropIfExists($table);
        Schema::table('quick_stripe_checkout_requests', function (Blueprint $t) { $t->unique('quick_stripe_lease_id'); $t->dropIndex('quick_stripe_request_lease'); });
    }
};
