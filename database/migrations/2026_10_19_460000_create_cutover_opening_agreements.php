<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cutover_opening_sets', function (Blueprint $t) {
            $t->id(); $t->foreignId('cutover_reconciliation_id')->unique()->constrained()->restrictOnDelete();
            $t->char('cohort_sha256', 64); $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->timestamp('source_observed_at'); $t->timestamp('expires_at');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('cutover_opening_agreements', function (Blueprint $t) {
            $t->id(); $t->foreignId('cutover_opening_set_id')->constrained()->restrictOnDelete();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('imported_project_link_id')->constrained()->restrictOnDelete();
            $t->foreignId('imported_project_revision_id')->constrained()->restrictOnDelete();
            $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
            $t->unique(['cutover_opening_set_id', 'event_id'], 'opening_set_event_unique');
        });
    }
    public function down(): void
    {
        if (DB::table('cutover_opening_sets')->exists()) throw new RuntimeException('Opening evidence exists; use a forward migration.');
        Schema::dropIfExists('cutover_opening_agreements'); Schema::dropIfExists('cutover_opening_sets');
    }
};
