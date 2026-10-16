<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('zelle_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('portal_grant_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('amount_cents');
            $t->string('reference')->nullable(); $t->string('payer_name', 120)->nullable();
            $t->dateTime('sent_at')->nullable(); $t->text('note')->nullable();
            $t->string('ip', 45)->nullable(); $t->text('user_agent')->nullable();
            $t->dateTime('submitted_at'); $t->timestamps();
            $t->index(['event_id', 'customer_id', 'id']);
        });
        Schema::create('zelle_notice_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notice_id')->unique()->constrained('zelle_notices')->restrictOnDelete();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('ledger_entry_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('decision', 20); $t->text('review_note');
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->dateTime('reviewed_at'); $t->timestamps();
            $t->index(['decision', 'notice_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('zelle_notice_reviews');
        Schema::dropIfExists('zelle_notices');
    }
};
