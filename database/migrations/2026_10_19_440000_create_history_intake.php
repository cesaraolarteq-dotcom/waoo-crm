<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('history_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('state', 32);
            $table->string('profile', 40);
            $table->char('active_fingerprint', 64)->nullable()->unique();
            $table->char('source_sha256', 64);
            $table->unsignedInteger('byte_count');
            $table->char('coverage_sha256', 64);
            $table->longText('artifact')->nullable();
            $table->longText('manifest');
            $table->timestamp('cutoff');
            $table->timestamps();
        });
        Schema::create('history_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('history_import_batch_id')->constrained('history_import_batches')->restrictOnDelete();
            $table->unsignedInteger('source_line');
            $table->unsignedInteger('version')->default(1);
            $table->char('preview_sha256', 64);
            $table->longText('payload');
            $table->timestamps();
            $table->unique(['history_import_batch_id', 'source_line']);
        });
    }

    public function down(): void
    {
        if (DB::table('history_import_batches')->exists()) {
            throw new RuntimeException('Private history evidence exists; use a forward migration.');
        }
        Schema::dropIfExists('history_import_rows');
        Schema::dropIfExists('history_import_batches');
    }
};
