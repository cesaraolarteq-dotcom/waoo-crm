<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->string('name')->nullable()->change());
    }
    public function down(): void
    {
        if (DB::table('customers')->whereNull('name')->exists()) {
            throw new \LogicException('Confirm provisional customer names before tightening this schema; no name is fabricated.');
        }
        Schema::table('customers', fn (Blueprint $t) => $t->string('name')->nullable(false)->change());
    }
};
