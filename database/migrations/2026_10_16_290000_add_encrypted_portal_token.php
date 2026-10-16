<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_grants', fn (Blueprint $t) => $t->text('encrypted_token')->nullable());
    }
    public function down(): void
    {
        // Existing token hashes, grants and client authentication remain valid.
        Schema::table('portal_grants', fn (Blueprint $t) => $t->dropColumn('encrypted_token'));
    }
};
