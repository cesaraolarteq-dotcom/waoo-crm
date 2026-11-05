<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable: historical quotes/imports/agreements keep their own target and evidence unchanged.
        Schema::table('financial_revisions', fn (Blueprint $t) => $t->unsignedTinyInteger('deposit_percent_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('financial_revisions', fn (Blueprint $t) => $t->dropColumn('deposit_percent_snapshot'));
    }
};
