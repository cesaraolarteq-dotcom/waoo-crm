<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('owner_report_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->string('environment', 10);
            $t->string('kind', 10);
            $t->date('period_starts_on');
            $t->date('period_ends_before');
            $t->string('recipient');
            // Text preserves the exact bytes covered by SHA-256 across database drivers.
            $t->longText('payload_json');
            $t->char('payload_sha256', 64);
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['environment', 'kind', 'period_starts_on'], 'owner_report_period_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('owner_report_snapshots'); }
};
