<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-20: a party with less than 24 h of notice may be offered with a payment link only after the owner confirms it.
// One immutable row per event; it lets the payment window close 1 h before the party instead of 24 h.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('short_notice_approvals', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_notice_approvals');
    }
};
