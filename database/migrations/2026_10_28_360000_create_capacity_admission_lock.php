<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('capacity_admission_locks', function (Blueprint $table) { $table->unsignedTinyInteger('id')->primary(); });
        DB::table('capacity_admission_locks')->insert(['id'=>1]);
    }
    public function down(): void { Schema::dropIfExists('capacity_admission_locks'); }
};
