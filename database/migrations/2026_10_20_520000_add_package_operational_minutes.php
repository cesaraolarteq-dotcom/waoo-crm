<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('setup_minutes')->nullable();
            $table->unsignedSmallInteger('breakdown_minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $table) => $table->dropColumn(['setup_minutes', 'breakdown_minutes']));
    }
};
