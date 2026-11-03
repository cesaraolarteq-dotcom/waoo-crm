<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-03: a suggested pay per person and party (rate × hours, plus driving), always editable, with the reason shown.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pay_rates', function (Blueprint $t) {
            $t->id();
            $t->string('role', 40)->unique();       // a skill key (entertainer, host, performer…) or "driving"
            $t->string('per', 10);                  // hour | party
            $t->unsignedInteger('amount_cents');
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('staff_pay_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('resource_id')->constrained('resources')->restrictOnDelete();
            $t->unsignedInteger('amount_cents');    // what the owner decided (may differ from the suggestion)
            $t->unsignedInteger('suggested_cents')->nullable(); // the suggestion when it was decided, to see the difference later
            $t->string('note', 300)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['event_id', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_pay_lines');
        Schema::dropIfExists('staff_pay_rates');
    }
};
