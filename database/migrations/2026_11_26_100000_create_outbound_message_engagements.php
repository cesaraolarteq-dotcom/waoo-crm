<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-26: see who opened the email and which button they clicked (Resend open/click tracking).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_message_engagements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('outbound_message_id')->constrained('outbound_messages')->restrictOnDelete();
            $t->string('kind', 16); // opened | clicked
            $t->string('link_kind', 16)->nullable(); // book | whatsapp | packages | phone | google | other
            $t->timestamp('occurred_at');
            $t->string('provider_event_id', 120)->unique(); // svix-id: the same event never counts twice
            $t->timestamps();
            $t->index(['outbound_message_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_message_engagements');
    }
};
