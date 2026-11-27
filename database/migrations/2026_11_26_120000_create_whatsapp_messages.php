<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-26: WhatsApp 8830 through Waoo's own Meta app (Cloud API, coexistence with the Business app).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id();
            $t->string('direction', 8); // in | out
            $t->string('source', 12)->default('api'); // api (CRM) | app (sent from the phone, coexistence echo) | client
            $t->string('wa_id', 32); // client's number, digits only
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('type', 24); // text | image | audio | document | template | other
            $t->text('body')->nullable();
            $t->string('template_name', 120)->nullable();
            $t->string('status', 16); // received | sent | delivered | read | failed
            $t->string('error_code', 40)->nullable();
            $t->string('provider_id', 191)->nullable()->unique(); // wamid: the same message never counts twice
            $t->timestamp('provider_at')->nullable();
            $t->timestamps();
            $t->index(['wa_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
