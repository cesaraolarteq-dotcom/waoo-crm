<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per Houston day: admission serializes on these rows (AdmitEventCapacity). Holds no data.
    public function up(): void
    {
        Schema::create('capacity_day_locks', function (Blueprint $table) {
            $table->date('day')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_day_locks');
    }
};
