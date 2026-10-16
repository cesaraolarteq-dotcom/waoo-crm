<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'characters'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        }
    }

    public function down(): void
    {
        foreach (['customers', 'characters'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('version'));
        }
    }
};
