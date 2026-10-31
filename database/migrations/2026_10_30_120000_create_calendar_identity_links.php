<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_identity_links', function (Blueprint $t) {
            $t->id();
            $t->char('scope_sha256', 64);
            $t->char('uid_sha256', 64);
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['scope_sha256', 'uid_sha256'], 'calendar_source_uid_unique');
            $t->unique(['scope_sha256', 'event_id'], 'calendar_source_event_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('calendar_identity_links')->exists()) throw new RuntimeException('Calendar identity evidence must be retained.');
        Schema::dropIfExists('calendar_identity_links');
    }
};
