<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-23: "Mensajes automáticos" page. One row per automatic message with the owner's on/off per channel and
// the hash of the text he approved there; one log row per (source, channel) so a message is decided only once.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_settings', function (Blueprint $t) {
            $t->id(); $t->string('key', 60)->unique();
            $t->boolean('email_enabled')->default(true); $t->boolean('sms_enabled')->default(false);
            $t->char('email_approved_sha256', 64)->nullable(); $t->char('sms_approved_sha256', 64)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('auto_message_log', function (Blueprint $t) {
            $t->id(); $t->char('identity_sha256', 64)->unique(); $t->string('key', 60); $t->string('channel', 5);
            $t->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('outbound_message_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sms_message_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20); $t->string('reason', 60)->nullable(); $t->timestamps();
            $t->index(['key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_message_log');
        Schema::dropIfExists('message_settings');
    }
};
