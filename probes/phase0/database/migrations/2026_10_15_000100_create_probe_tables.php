<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id', 120);
            $table->string('type', 120);
            $table->longText('payload');
            $table->timestamp('received_at');
            $table->unique(['provider', 'event_id']);
        });
        Schema::create('probe_counters', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
        Schema::dropIfExists('probe_counters');
    }
};
