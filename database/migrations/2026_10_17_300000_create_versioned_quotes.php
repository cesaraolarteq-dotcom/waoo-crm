<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $t) {
            $t->id(); $t->string('number')->nullable()->unique();
            $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('draft'); $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('current_revision')->default(1); $t->dateTime('valid_until');
            $t->string('locale', 2); $t->text('owner_note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['status', 'valid_until']);
        });
        Schema::create('quote_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision'); $t->unsignedInteger('event_version'); $t->string('currency', 3);
            $t->json('customer_snapshot'); $t->json('event_snapshot'); $t->json('lines_snapshot');
            $t->json('travel_fee_evidence')->nullable(); $t->json('discounts_snapshot');
            foreach (['subtotal_cents','travel_fee_cents','discount_cents','total_cents','deposit_target_cents'] as $field) $t->unsignedBigInteger($field);
            $t->dateTime('valid_until'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['quote_id', 'revision']);
        });
    }
    public function down(): void { Schema::dropIfExists('quote_revisions'); Schema::dropIfExists('quotes'); }
};
