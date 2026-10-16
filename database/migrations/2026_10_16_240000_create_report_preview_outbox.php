<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_preview_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('snapshot_id')->unique()->constrained('owner_report_snapshots')->restrictOnDelete();
            $t->string('environment', 10); $t->string('recipient'); $t->char('payload_sha256', 64);
            $t->string('status', 20)->default('pending'); $t->unsignedSmallInteger('attempts')->default(0);
            $t->dateTime('available_at'); $t->dateTime('lease_until')->nullable(); $t->uuid('lease_token')->nullable();
            $t->dateTime('completed_at')->nullable(); $t->string('last_error_code', 40)->nullable(); $t->timestamps();
            $t->index(['status', 'available_at']); $t->index(['status', 'lease_until']);
        });
        Schema::create('report_preview_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('delivery_id')->unique()->constrained('report_preview_deliveries')->restrictOnDelete();
            $t->char('idempotency_key', 64)->unique(); $t->char('payload_sha256', 64); $t->uuid('reference'); $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('report_preview_receipts'); Schema::dropIfExists('report_preview_deliveries');
    }
};
