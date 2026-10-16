<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_cost_snapshots', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('financial_revision_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('event_version'); $t->string('kind', 20); $t->unsignedInteger('revision');
            $t->string('currency', 3); $t->json('lines_snapshot');
            $t->unsignedBigInteger('known_cost_cents'); $t->unsignedSmallInteger('unknown_line_count');
            $t->boolean('coverage_confirmed'); $t->boolean('complete'); $t->text('note');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['event_id', 'kind', 'revision']);
        });
    }
    public function down(): void { Schema::dropIfExists('event_cost_snapshots'); }
};
