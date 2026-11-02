<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_revisions', fn (Blueprint $t) => $t->dateTime('balance_due_at')->nullable()->change());
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->index(['event_id', 'id'], 'opening_receipts_event_index'));
        Schema::table('audit_entries', fn (Blueprint $t) => $t->index(['subject_type', 'subject_id', 'action', 'id'], 'opening_receipt_audit_index'));
        Schema::create('cutover_transfers', function (Blueprint $t) {
            $t->id(); $t->foreignId('cutover_opening_set_id')->unique()->constrained()->restrictOnDelete();
            $t->dateTime('effective_at'); $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('cutover_financial_bindings', function (Blueprint $t) {
            $t->id(); $t->foreignId('cutover_transfer_id')->constrained()->restrictOnDelete();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('cutover_opening_agreement_id')->unique('binding_opening_unique')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->unique()->constrained()->restrictOnDelete();
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
            $t->unique(['cutover_transfer_id', 'event_id'], 'transfer_event_binding_unique');
        });
        Schema::create('cutover_active_openings', function (Blueprint $t) {
            $t->foreignId('event_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignId('cutover_financial_binding_id')->unique('active_binding_unique')->constrained()->restrictOnDelete();
        });
        Schema::create('opening_receipt_allocations', function (Blueprint $t) {
            $t->id(); $t->foreignId('ledger_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('cutover_financial_binding_id')->constrained()->restrictOnDelete();
            $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->index(['cutover_financial_binding_id', 'id'], 'opening_receipts_binding_index');
        });
    }
    public function down(): void
    {
        if (DB::table('cutover_transfers')->exists() || DB::table('opening_receipt_allocations')->exists()
            || DB::table('financial_revisions')->whereNull('balance_due_at')->exists()) {
            throw new RuntimeException('Effective agreement evidence or unknown deadlines exist; use a forward migration.');
        }
        Schema::dropIfExists('opening_receipt_allocations'); Schema::dropIfExists('cutover_active_openings');
        Schema::dropIfExists('cutover_financial_bindings'); Schema::dropIfExists('cutover_transfers');
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->dropIndex('opening_receipts_event_index'));
        Schema::table('audit_entries', fn (Blueprint $t) => $t->dropIndex('opening_receipt_audit_index'));
        Schema::table('financial_revisions', fn (Blueprint $t) => $t->dateTime('balance_due_at')->nullable(false)->change());
    }
};
