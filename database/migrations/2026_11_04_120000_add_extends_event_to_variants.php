<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-04: «Hora extra» ($200; Pool Party $250) and «+30 min» ($125) make the same team stay longer.
// Such a service adds its minutes to the party instead of needing its own staff.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->boolean('extends_event')->default(false));
    }

    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $t) => $t->dropColumn('extends_event'));
    }
};
