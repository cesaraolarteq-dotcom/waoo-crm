<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('historical_events', function (Blueprint $t) {
            $t->id(); $t->char('project_key', 64)->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->timestamps();
        });
        Schema::create('historical_event_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('historical_event_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version'); $t->char('source_sha256', 64); $t->char('facts_sha256', 64);
            $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['historical_event_id', 'version']);
        });
        Schema::table('historical_events', fn (Blueprint $t) => $t->foreign('current_revision_id')->references('id')->on('historical_event_revisions')->restrictOnDelete());
        Schema::create('external_customer_links', function (Blueprint $t) {
            $t->id(); $t->string('source_system', 24)->default('honeybook'); $t->char('contact_key', 64);
            $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->unsignedInteger('version')->default(1);
            $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['source_system', 'contact_key']);
        });
        Schema::create('history_import_decisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('history_import_batch_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('staged_row_id'); $t->unsignedInteger('source_line');
            $t->char('request_sha256', 64); $t->longText('payload'); $t->char('payload_sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['history_import_batch_id', 'staged_row_id'], 'history_decision_row_unique');
        });
    }
    public function down(): void
    {
        foreach (['historical_events', 'external_customer_links', 'history_import_decisions'] as $table) {
            if (DB::table($table)->exists()) throw new RuntimeException('Historical evidence exists; use a forward migration.');
        }
        Schema::dropIfExists('history_import_decisions'); Schema::dropIfExists('external_customer_links');
        Schema::table('historical_events', fn (Blueprint $t) => $t->dropForeign(['current_revision_id']));
        Schema::dropIfExists('historical_event_revisions'); Schema::dropIfExists('historical_events');
    }
};
