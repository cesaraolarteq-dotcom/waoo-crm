<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('portal_grants', fn (Blueprint $t) => $t->foreignId('quote_revision_id')->nullable()->constrained('quote_revisions')->restrictOnDelete());
        Schema::create('quote_presentations', function (Blueprint $t) {
            $t->id(); $t->foreignId('quote_revision_id')->unique()->constrained()->restrictOnDelete();
            $t->json('payload'); $t->char('snapshot_sha256',64); $t->char('revision_sha256',64);
            $t->string('pdf_path'); $t->char('pdf_sha256',64); $t->json('consents');
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('quote_pdf_views', function (Blueprint $t) {
            $t->id(); $t->foreignId('quote_presentation_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete(); $t->timestamp('served_at');
            $t->unique(['quote_presentation_id','portal_grant_id']);
        });
        Schema::create('quote_decisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_presentation_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('outcome',10); $t->string('name'); $t->string('email');
            $t->char('decision_sha256',64); $t->char('snapshot_sha256',64); $t->char('pdf_sha256',64);
            $t->string('consent_version',100); $t->text('consent_text'); $t->char('consent_sha256',64);
            $t->string('ip',45)->nullable(); $t->text('user_agent'); $t->timestamp('decided_at'); $t->timestamps();
            $t->unique(['quote_id','quote_revision_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('quote_decisions'); Schema::dropIfExists('quote_pdf_views'); Schema::dropIfExists('quote_presentations');
        Schema::table('portal_grants', function (Blueprint $t) { $t->dropForeign(['quote_revision_id']); $t->dropColumn('quote_revision_id'); });
    }
};
