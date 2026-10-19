<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_identities', function (Blueprint $t) {
            $t->id(); $t->foreignId('resource_id')->unique()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true); $t->string('locale', 2); $t->text('phone')->nullable();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('staff_access_grants', function (Blueprint $t) {
            $t->id(); $t->foreignId('staff_identity_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('active_staff_id')->nullable()->unique();
            $t->char('token_hash', 64)->unique(); $t->text('encrypted_token')->nullable();
            $t->dateTime('revoked_at')->nullable(); $t->dateTime('last_opened_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('staff_portal_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('staff_access_grant_id')->constrained()->restrictOnDelete();
            $t->char('token_hash', 64)->unique(); $t->dateTime('expires_at')->index(); $t->timestamps();
        });
        Schema::create('staff_job_instructions', function (Blueprint $t) {
            $t->id(); $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version'); $t->text('payload'); $t->char('sha256', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
            $t->unique(['event_id', 'version']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('staff_job_instructions'); Schema::dropIfExists('staff_portal_sessions');
        Schema::dropIfExists('staff_access_grants'); Schema::dropIfExists('staff_identities');
    }
};
