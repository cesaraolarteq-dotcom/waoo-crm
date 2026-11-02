<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

// One row per notice identity (source + kind + channel): the durable claim for reschedule notices (Codex review #163).
// Planned once, never reset: an unknown provider outcome stays for reconciliation instead of being resent.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notice_deliveries', function (Blueprint $t) {
            $t->id();
            $t->char('identity_sha256', 64)->unique();
            $t->string('source', 20); $t->unsignedBigInteger('source_id'); $t->string('kind', 20); $t->string('channel', 10);
            $t->foreignId('outbound_message_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('sms_message_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('status', 20)->default('claimed'); $t->string('reason', 60)->nullable();
            $t->timestamps();
            $t->index(['source', 'source_id']);
        });
    }

    /**
     * Rolling back removes this feature with its SANDBOX outbox evidence (campaign messages have no party, which the older
     * outbox migration cannot roll back). Anything outside the sandbox is never deleted: the rollback refuses instead.
     */
    public function down(): void
    {
        $campaign = DB::table('outbound_messages')->whereIn('template_key', ['anniversary_main', 'anniversary_reminder', 'reschedule_notice', 'reschedule_reminder']);
        if ((clone $campaign)->where('environment', '!=', 'sandbox')->exists()) {
            throw new RuntimeException('Campaign outbox evidence outside the sandbox requires a forward migration.');
        }
        $ids = (clone $campaign)->pluck('id');
        Schema::dropIfExists('notice_deliveries');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('outbound_message_events')->whereIn('outbound_message_id', $chunk)->delete();
            DB::table('outbound_message_states')->whereIn('outbound_message_id', $chunk)->delete();
            DB::table('outbound_messages')->whereIn('id', $chunk)->delete();
        }
    }
};
