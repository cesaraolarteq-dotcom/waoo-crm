<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-10-28: the client may attach a screenshot of the Zelle to her notice. Separate immutable row: the notice itself never changes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zelle_notice_screenshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notice_id')->unique()->constrained('zelle_notices')->restrictOnDelete();
            $t->string('path', 255);
            $t->string('mime', 60);
            $t->unsignedInteger('size_bytes');
            $t->char('sha256', 64);
            $t->timestamp('uploaded_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zelle_notice_screenshots');
    }
};
