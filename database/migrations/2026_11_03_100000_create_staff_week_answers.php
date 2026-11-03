<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-03: each person confirms the parties they are given, and on Monday says which days they can work.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_job_answers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->foreignId('resource_id')->constrained('resources')->restrictOnDelete();
            $t->string('answer', 10); // yes | no
            $t->string('note', 300)->nullable();
            // The answer is about this party at this time; a moved party asks again.
            $t->timestamp('event_starts_at');
            $t->timestamp('answered_at');
            $t->timestamps();
            $t->unique(['event_id', 'resource_id']);
        });
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id();
            $t->foreignId('resource_id')->constrained('resources')->restrictOnDelete();
            $t->date('week_start'); // Monday (Houston) of the week asked about
            $t->json('days');       // {"sat":"yes|no","fri":"…","sun":"…","weekday":"…"}
            $t->string('note', 300)->nullable();
            $t->timestamp('answered_at');
            $t->timestamps();
            $t->unique(['resource_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_availability');
        Schema::dropIfExists('staff_job_answers');
    }
};
