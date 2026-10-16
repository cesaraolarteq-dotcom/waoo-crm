<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('financial_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision'); $t->unsignedInteger('event_version');
            $t->string('currency', 3)->default('USD'); $t->json('lines_snapshot');
            foreach (['subtotal_cents', 'travel_fee_cents', 'tax_cents', 'customer_fee_cents', 'total_cents', 'deposit_target_cents'] as $field) $t->unsignedBigInteger($field);
            $t->dateTime('balance_due_at'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['event_id', 'revision']);
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->string('environment', 10)->default('test'); $t->string('kind', 20); $t->string('method', 20);
            $t->unsignedBigInteger('amount_cents'); $t->string('currency', 3); $t->dateTime('received_at');
            $t->string('external_reference', 255); $t->string('external_identity_key', 100)->unique();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('source_type', 40); $t->string('source_id', 255)->nullable(); $t->text('note')->nullable();
            $t->timestamps(); $t->index(['event_id', 'environment']);
        });
        Schema::create('mutation_receipts', function (Blueprint $t) {
            $t->id(); $t->string('scope', 180); $t->uuid('request_key'); $t->char('request_hash', 64);
            $t->string('result_type', 40); $t->string('result_id', 64); $t->timestamps();
            $t->unique(['scope', 'request_key']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mutation_receipts'); Schema::dropIfExists('ledger_entries'); Schema::dropIfExists('financial_revisions');
    }
};
