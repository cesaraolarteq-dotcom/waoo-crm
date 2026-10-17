<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::create('import_staging_locks',fn(Blueprint $t)=>$t->unsignedInteger('id')->primary());
        \Illuminate\Support\Facades\DB::table('import_staging_locks')->insert(['id'=>1]);
        Schema::create('import_batches',function(Blueprint $t){
            $t->id();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->unsignedInteger('version')->default(1);
            $t->string('status',32);$t->char('active_fingerprint',64)->nullable()->unique();$t->json('profile');$t->char('profile_sha256',64);
            $t->char('preview_sha256',64);$t->json('reconciliation');$t->timestamp('received_at');$t->timestamps();
        });
        Schema::create('import_files',function(Blueprint $t){
            $t->id();$t->foreignId('import_batch_id')->constrained()->restrictOnDelete();$t->string('role',16);$t->string('original_name')->nullable();
            $t->string('private_path');$t->char('sha256',64);$t->unsignedInteger('byte_count');$t->json('headers')->nullable();$t->timestamps();$t->unique(['import_batch_id','role']);
        });
        Schema::create('import_rows',function(Blueprint $t){
            $t->id();$t->foreignId('import_batch_id')->constrained()->restrictOnDelete();$t->unsignedInteger('version');$t->string('role',16);$t->unsignedInteger('source_line');
            $t->char('source_key',64)->nullable();$t->string('status',32);$t->json('source');$t->json('normalized');$t->json('errors');$t->json('duplicate_hints');
            $t->string('skip_reason',32)->nullable();$t->char('preview_sha256',64);$t->timestamps();$t->index(['import_batch_id','role','status']);
        });
        Schema::create('import_preview_versions',function(Blueprint $t){
            $t->id();$t->foreignId('import_batch_id')->constrained()->restrictOnDelete();$t->unsignedInteger('version');$t->char('profile_sha256',64);
            $t->char('preview_sha256',64);$t->json('counts');$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->unique(['import_batch_id','version']);
        });
    }
    public function down():void
    {
        Schema::dropIfExists('import_preview_versions');Schema::dropIfExists('import_rows');Schema::dropIfExists('import_files');Schema::dropIfExists('import_batches');Schema::dropIfExists('import_staging_locks');
    }
};
