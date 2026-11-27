<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner 2026-11-23: the owner's own test client; its parties and payments never count in reports.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->boolean('is_test')->default(false));
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('is_test'));
    }
};
