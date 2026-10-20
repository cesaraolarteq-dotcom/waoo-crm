<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->string('external_reference', 255)->nullable()->change());
    }
    public function down(): void
    {
        if (DB::table('ledger_entries')->whereNull('external_reference')->exists()) {
            throw new \LogicException('Cannot require bank references while reference-less receipts exist; never fabricate bank evidence.');
        }
        Schema::table('ledger_entries', fn (Blueprint $t) => $t->string('external_reference', 255)->nullable(false)->change());
    }
};
