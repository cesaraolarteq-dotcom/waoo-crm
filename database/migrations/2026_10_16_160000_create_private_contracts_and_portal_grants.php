<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_entries', fn (Blueprint $t) => $t->string('subject_id', 255)->change());
        Schema::create('contract_template_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedInteger('version')->unique(); $t->string('state', 20)->default('draft'); $t->string('locale', 2)->default('en');
            $t->longText('source_html'); $t->char('source_sha256', 64); $t->longText('terms_html'); $t->char('terms_sha256', 64); $t->text('change_note');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete(); $t->dateTime('approved_at')->nullable(); $t->timestamps();
        });
        Schema::create('contract_template_registry', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary(); $t->unsignedInteger('next_version')->default(1);
            $t->foreignId('active_version_id')->nullable()->constrained('contract_template_versions')->restrictOnDelete();
        });
        \Illuminate\Support\Facades\DB::table('contract_template_registry')->insert(['id' => 1, 'next_version' => 1]);
        Schema::create('contracts', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('event_id')->constrained()->restrictOnDelete(); $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('template_version_id')->constrained('contract_template_versions')->restrictOnDelete(); $t->unsignedInteger('event_version'); $t->unsignedInteger('revision'); $t->unsignedInteger('version')->default(1);
            $t->string('state', 20)->default('draft'); $t->foreignId('signer_customer_id')->constrained('customers')->restrictOnDelete(); $t->string('signer_email'); $t->json('commercial_snapshot');
            foreach (['presented_html', 'presented_pdf', 'final_pdf'] as $artifact) { $t->string($artifact.'_path')->nullable(); $t->char($artifact.'_sha256', 64)->nullable(); }
            $t->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete(); $t->dateTime('issued_at')->nullable(); $t->dateTime('signed_at')->nullable(); $t->uuid('superseded_by')->nullable(); $t->timestamps();
            $t->unique(['event_id', 'revision']);
        });
        Schema::create('portal_grants', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete(); $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->char('token_hash', 64)->unique(); $t->json('scopes'); $t->dateTime('expires_at'); $t->dateTime('revoked_at')->nullable();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('contract_presentations', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('contract_id')->constrained('contracts')->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete(); $t->char('presented_pdf_sha256', 64); $t->dateTime('served_at');
            $t->unique(['contract_id', 'portal_grant_id', 'presented_pdf_sha256'], 'contract_presentation_unique');
        });
        Schema::create('signature_evidence', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('contract_id')->unique()->constrained('contracts')->restrictOnDelete(); $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->foreignId('presentation_id')->constrained('contract_presentations')->restrictOnDelete(); $t->string('signer_name'); $t->string('signer_email'); $t->string('identity_method', 50);
            $t->string('consent_version', 50); $t->text('consent_text'); $t->char('consent_sha256', 64); $t->char('presented_pdf_sha256', 64); $t->char('template_source_sha256', 64); $t->char('template_terms_sha256', 64);
            $t->string('ip', 45)->nullable(); $t->text('user_agent')->nullable(); $t->dateTime('accepted_at'); $t->timestamps();
        });
    }
    public function down(): void
    {
        // Keep widened polymorphic audit IDs; shrinking would destroy UUID history.
        Schema::dropIfExists('signature_evidence'); Schema::dropIfExists('contract_presentations'); Schema::dropIfExists('portal_grants'); Schema::dropIfExists('contracts'); Schema::dropIfExists('contract_template_registry'); Schema::dropIfExists('contract_template_versions');
    }
};
