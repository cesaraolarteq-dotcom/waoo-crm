<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('quick_costume_holds', function (Blueprint $t) {
            $t->id(); $t->foreignId('quick_booking_id')->unique()->constrained('quick_bookings')->restrictOnDelete();
            $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('receipt_id')->constrained('ledger_entries')->restrictOnDelete();
            $t->unsignedInteger('version'); $t->string('state', 30); $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
        });
        Schema::create('quick_costume_hold_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('quick_costume_hold_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version'); $t->longText('payload'); $t->char('payload_sha256', 64); $t->timestamps();
            $t->unique(['quick_costume_hold_id','version'], 'quick_costume_hold_version_unique');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('quick_costume_hold_events'); Schema::dropIfExists('quick_costume_holds');
    }
};
