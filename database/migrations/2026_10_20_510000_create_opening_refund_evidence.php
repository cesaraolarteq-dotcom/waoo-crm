<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::create('opening_refund_observations', function (Blueprint $t) {
            $t->id(); $t->string('webhook_receipt_id')->unique();
            $t->foreign('webhook_receipt_id')->references('id')->on('webhook_receipts')->restrictOnDelete();
            $t->foreignId('parent_ledger_entry_id')->nullable()->constrained('ledger_entries')->restrictOnDelete();
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
        });
        Schema::create('opening_refund_evidence', function (Blueprint $t) {
            $t->id(); $t->foreignId('ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('parent_ledger_entry_id')->constrained('ledger_entries')->restrictOnDelete();
            $t->foreignId('opening_refund_observation_id')->unique('refund_observation_unique')->constrained('opening_refund_observations')->restrictOnDelete();
            $t->index(['parent_ledger_entry_id', 'id'], 'refund_parent_evidence_index');
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
        });
    }
    public function down(): void
    {
        if (DB::table('opening_refund_observations')->exists() || DB::table('opening_refund_evidence')->exists())
            throw new RuntimeException('Refund evidence exists; use a forward migration.');
        Schema::dropIfExists('opening_refund_evidence'); Schema::dropIfExists('opening_refund_observations');
    }
};
