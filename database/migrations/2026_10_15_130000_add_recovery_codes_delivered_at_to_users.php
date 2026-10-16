<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One-time delivery of each recovery-code generation (Codex review R2). Null = the current set has not been shown yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->timestamp('two_factor_recovery_codes_delivered_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('two_factor_recovery_codes_delivered_at'));
    }
};
