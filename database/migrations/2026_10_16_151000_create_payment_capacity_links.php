<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('capacity_payment_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete(); $t->json('ledger_entry_ids');
            $t->unsignedBigInteger('amount_cents'); $t->string('method', 30); $t->string('reference')->unique(); $t->dateTime('received_at'); $t->timestamps();
        });
        Schema::create('payment_exceptions', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete(); $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->string('kind', 50); $t->char('exception_key', 64)->unique(); $t->string('state', 20)->default('open'); $t->json('context'); $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('payment_exceptions'); Schema::dropIfExists('capacity_payment_links'); }
};
