<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Owner 2026-11-04: many quotes go out before the client knows the start time.
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('time_tbd')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('time_tbd');
        });
    }
};
