<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quick_stripe_checkout_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('quick_stripe_lease_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $table->longText('envelope');
            $table->char('envelope_sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('quick_stripe_checkout_requests') && DB::table('quick_stripe_checkout_requests')->exists()) {
            throw new LogicException('Preserve durable Stripe request evidence; use a forward migration.');
        }
        Schema::dropIfExists('quick_stripe_checkout_requests');
    }
};
