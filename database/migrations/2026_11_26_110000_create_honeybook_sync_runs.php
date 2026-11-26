<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-26: HoneyBook → CRM every 12 hours until the switch; each run keeps its summary and what needs César.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honeybook_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 20); // ok | review | failed
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->json('counts');
            $t->json('items'); // [{kind, project, date, detail}] — what was done and what needs review
            $t->char('source_sha256', 64);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honeybook_sync_runs');
    }
};
