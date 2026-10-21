<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-21: characters beyond the package's included ones cost a flat amount each (snapshotted per service).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_services', function (Blueprint $t) {
            $t->unsignedSmallInteger('extra_characters')->default(0);
            $t->unsignedInteger('extra_character_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_services', fn (Blueprint $t) => $t->dropColumn(['extra_characters', 'extra_character_cents']));
    }
};
