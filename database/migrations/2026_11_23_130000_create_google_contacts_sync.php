<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-23: every client who books is saved in the owner's Google Contacts (txwaooparty@gmail.com, synced to his
// iPhone) as «María González · Waoo dic 2026» in the group «Clientes Waoo», so he knows who is writing next time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_contact_connections', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->string('google_email')->nullable();
            $t->text('refresh_token'); $t->text('access_token')->nullable(); $t->dateTime('access_expires_at')->nullable();
            $t->string('group_resource_name')->nullable(); $t->string('last_error', 255)->nullable(); $t->timestamps();
        });
        Schema::create('customer_google_contacts', function (Blueprint $t) {
            $t->id(); $t->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $t->string('resource_name')->nullable(); $t->string('google_email')->nullable();
            $t->string('status', 20); $t->string('error', 255)->nullable(); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_google_contacts');
        Schema::dropIfExists('google_contact_connections');
    }
};
