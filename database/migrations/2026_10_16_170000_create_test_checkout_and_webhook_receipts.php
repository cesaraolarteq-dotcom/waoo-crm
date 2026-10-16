<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('event_id')->constrained()->restrictOnDelete(); $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->string('environment', 10)->default('test'); $t->string('purpose', 20); $t->string('currency', 3)->default('USD');
            $t->unsignedBigInteger('principal_cents'); $t->unsignedBigInteger('customer_fee_cents')->default(0); $t->unsignedBigInteger('payable_cents');
            $t->string('status', 40)->default('creating'); $t->string('collection_slot', 80)->nullable()->unique();
            $t->string('stripe_checkout_session_id')->nullable()->unique(); $t->string('stripe_payment_intent_id')->nullable()->unique();
            $t->text('checkout_url')->nullable(); $t->dateTime('expires_at')->nullable(); $t->string('last_error', 100)->nullable(); $t->timestamps();
        });
        Schema::create('webhook_receipts', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('event_type'); $t->char('raw_sha256', 64); $t->longText('encrypted_payload');
            $t->string('stripe_account_id')->nullable(); $t->dateTime('verified_at'); $t->string('state', 40)->default('queued');
            $t->unsignedInteger('attempts')->default(0); $t->string('last_error', 100)->nullable(); $t->dateTime('processed_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('webhook_receipts'); Schema::dropIfExists('payment_intents'); }
};
