<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_blocks', function (Blueprint $table) {
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resource_blocks', function (Blueprint $table) {
            $table->dropForeign(['released_by']);
            $table->dropColumn(['released_at', 'released_by', 'release_reason']);
        });
    }
};
