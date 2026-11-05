<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old services keep their historical meaning; never infer it from today's mutable catalog.
        Schema::table('event_services', function (Blueprint $t) {
            $t->boolean('extends_event_snapshot')->default(false);
            $t->boolean('covers_event_snapshot')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('event_services', fn (Blueprint $t) => $t->dropColumn(['extends_event_snapshot','covers_event_snapshot']));
    }
};
