<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_variants', fn (Blueprint $table) => $table->text('long_description')->nullable());
    }
    public function down(): void
    {
        Schema::table('service_variants', fn (Blueprint $table) => $table->dropColumn('long_description'));
    }
};
