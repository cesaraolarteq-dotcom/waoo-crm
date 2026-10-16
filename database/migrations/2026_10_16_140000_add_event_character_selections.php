<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('event_type', 30)->nullable()->index();
        });
        Schema::table('service_variants', function (Blueprint $table) {
            $table->unsignedSmallInteger('character_quantity')->default(0);
            $table->json('character_options')->nullable();
        });
        Schema::create('event_service_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('character_name_snapshot');
            $table->timestamps();
            $table->unique(['event_service_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_service_characters');
        Schema::table('service_variants', fn (Blueprint $table) => $table->dropColumn(['character_quantity', 'character_options']));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('event_type'));
    }
};
