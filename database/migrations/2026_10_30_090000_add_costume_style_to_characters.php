<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // César 2026-10-30: head characters (full costume) vs wig characters (the face shows). Decides who can play them.
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('costume_style', 10)->nullable()->after('audience'); // head | wig | null (not marked yet)
        });
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn('costume_style'));
    }
};
