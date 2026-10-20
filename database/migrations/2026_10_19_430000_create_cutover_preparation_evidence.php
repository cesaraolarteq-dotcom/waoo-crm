<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cutover_original_documents', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('imported_project_link_id')->constrained()->restrictOnDelete();
            $t->char('commercial_sha256', 64); $t->char('sha256', 64); $t->unsignedInteger('byte_count');
            $t->longText('document_ciphertext'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['event_id', 'commercial_sha256', 'sha256'], 'cutover_original_unique');
        });
        Schema::create('honeybook_cutover_reviews', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete(); $t->unsignedInteger('version');
            $t->foreignId('cutover_original_document_id')->constrained()->restrictOnDelete();
            $t->char('binding_sha256', 64); $t->char('payload_sha256', 64); $t->longText('payload');
            $t->dateTime('source_observed_at'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['event_id', 'version']);
        });
        Schema::create('cutover_reconciliations', function (Blueprint $t) {
            $t->id(); $t->char('cohort_sha256', 64)->unique(); $t->char('payload_sha256', 64); $t->longText('payload');
            $t->dateTime('source_observed_at'); $t->dateTime('expires_at'); $t->unsignedInteger('policy_max_age_minutes');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $table) {
            if (\Illuminate\Support\Facades\DB::table($table)->exists()) throw new RuntimeException('Cutover originals and review evidence require a forward migration.');
        }
        foreach (['cutover_reconciliations', 'honeybook_cutover_reviews', 'cutover_original_documents'] as $table) Schema::dropIfExists($table);
    }
};
