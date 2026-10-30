<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-28: text messages through Twilio. Who agreed to texts (and who said STOP), and every text sent or received.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_consents', function (Blueprint $t) {
            $t->id();
            $t->string('phone_e164', 20)->unique();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('status', 12); // opted_in | opted_out
            $t->string('source', 30); // website_form | inbound_keyword
            $t->text('evidence')->nullable();
            $t->timestamp('changed_at');
            $t->timestamps();
        });
        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->string('direction', 3); // out | in
            $t->string('phone_e164', 20)->index();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $t->text('body'); // encrypted: a text may carry a private portal link
            $t->string('status', 20);
            $t->string('error_code', 60)->nullable();
            $t->string('provider_sid', 64)->nullable()->unique();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_consents');
    }
};
