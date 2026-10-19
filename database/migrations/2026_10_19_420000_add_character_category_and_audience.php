<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('characters', function (Blueprint $t) {
            $t->string('category', 120)->nullable();
            $t->enum('audience', ['girls','boys','all'])->default('all');
        });
    }
    public function down(): void {
        Schema::table('characters', fn (Blueprint $t) => $t->dropColumn(['category','audience']));
    }
};
