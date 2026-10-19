<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_occurrences', function (Blueprint $t) {
            $t->id(); $t->string('key', 60); $t->string('source_id', 128); $t->char('dedupe_sha256', 64)->unique();
            $t->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->longText('locator'); $t->dateTime('occurred_at'); $t->timestamps();
        });
        Schema::table('outbound_messages', function (Blueprint $t) {
            foreach (['event_id', 'customer_id', 'financial_revision_id', 'created_by'] as $column) $t->unsignedBigInteger($column)->nullable()->change();
            foreach (['event_version', 'customer_version'] as $column) $t->unsignedInteger($column)->nullable()->change();
            $t->foreignId('lead_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('source_occurrence_id')->nullable()->unique()->constrained('mail_occurrences')->restrictOnDelete();
        });
        Schema::create('mail_occurrence_states', function (Blueprint $t) {
            $t->id(); $t->foreignId('mail_occurrence_id')->unique()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('pending')->index(); $t->unsignedInteger('version')->default(1);
            $t->string('reason', 60)->nullable();
            $t->foreignId('outbound_message_id')->nullable()->unique()->constrained()->restrictOnDelete(); $t->timestamps();
        });
    }
    public function down(): void
    {
        if (DB::table('outbound_messages')->whereNull('event_id')->orWhereNull('customer_id')
            ->orWhereNull('financial_revision_id')->orWhereNull('created_by')->orWhereNull('event_version')->orWhereNull('customer_version')->exists()) {
            throw new RuntimeException('Mail occurrence evidence requires a forward migration; no domain linkage will be invented.');
        }
        Schema::dropIfExists('mail_occurrence_states');
        Schema::table('outbound_messages', function (Blueprint $t) {
            $t->dropForeign(['source_occurrence_id']); $t->dropUnique(['source_occurrence_id']); $t->dropColumn('source_occurrence_id');
            $t->dropForeign(['lead_id']); $t->dropColumn('lead_id');
            foreach (['event_id', 'customer_id', 'financial_revision_id', 'created_by'] as $column) $t->unsignedBigInteger($column)->nullable(false)->change();
            foreach (['event_version', 'customer_version'] as $column) $t->unsignedInteger($column)->nullable(false)->change();
        });
        Schema::dropIfExists('mail_occurrences');
    }
};
